<?php

declare(strict_types=1);

namespace App\Tui;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Color;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Style;

/**
 * Two-pane TUI viewer for the provirted command history.
 *
 *   left  — scrollable / filterable list of history entries
 *   right — full detail (command line, timing, output, sub-commands)
 *           of the selected entry, scrollable
 *
 * This class uses PHP 8.x syntax (enums, named args, match) and the bundled
 * SugarCraft libraries use `readonly class` (8.2), so it is ONLY ever loaded
 * from {@see \App\Command\HistoryCommand\TuiCommand} after that command has
 * gated on PHP_VERSION_ID >= 80200 (TuiCommand::MIN_PHP_VERSION_ID) and pulled in
 * the bundled vendor-tui autoloader. It must never be referenced on the
 * PHP 7.4 code path.
 */
final class HistoryTui implements Model
{
	private const FOCUS_LIST   = 0;
	private const FOCUS_DETAIL = 1;

	private ItemList $list;

	/** @var list<list<string>> per-entry detail, pre-split into lines */
	private array $detail;

	private int $focus  = self::FOCUS_LIST;
	private int $scroll = 0;
	private int $cols   = 80;
	private int $rows   = 24;
	private int $selected = 0;

	private Color $accent;
	private Color $muted;

	/**
	 * @param list<array{label:string, detail:string}> $entries
	 */
	public function __construct(array $entries)
	{
		$items        = [];
		$this->detail = [];
		foreach ($entries as $entry) {
			$items[]        = new StringItem($entry['label']);
			$this->detail[] = explode("\n", $entry['detail']);
		}
		if ($items === []) {
			$items        = [new StringItem('(no history entries)')];
			$this->detail = [['No history has been logged yet.']];
		}

		$this->accent = Color::hex('#00d9ff');
		$this->muted  = Color::hex('#555555');

		[$this->list] = ItemList::new($items, 30, 20)
			->withTitle('History')
			->withShowHelp(false)
			->focus();
	}

	public function init(): ?\Closure
	{
		return null;
	}

	public function update(Msg $msg): array
	{
		if ($msg instanceof WindowSizeMsg) {
			$this->cols = max(20, $msg->cols);
			$this->rows = max(6, $msg->rows);
			$this->list = $this->list->setSize($this->listInner(), $this->paneInnerHeight());
			return [$this, null];
		}

		if (!$msg instanceof KeyMsg) {
			return [$this, null];
		}

		// Ctrl-C always quits, even mid-filter.
		if ($msg->string() === 'ctrl+c') {
			return [$this, Cmd::quit()];
		}

		// While the list is capturing filter text, every key belongs to it.
		if ($this->focus === self::FOCUS_LIST && $this->list->settingFilter()) {
			return $this->routeToList($msg);
		}

		// Global quit keys.
		if ($msg->type === KeyType::Escape
			|| ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))) {
			return [$this, Cmd::quit()];
		}

		// Tab / Left / Right switch the focused pane.
		if ($msg->type === KeyType::Tab || $msg->type === KeyType::Left || $msg->type === KeyType::Right) {
			$this->focus = $this->focus === self::FOCUS_LIST ? self::FOCUS_DETAIL : self::FOCUS_LIST;
			return [$this, null];
		}

		if ($this->focus === self::FOCUS_DETAIL) {
			return $this->scrollDetail($msg);
		}

		return $this->routeToList($msg);
	}

	public function view(): string|\SugarCraft\Core\View
	{
		$left  = $this->renderPane(
			$this->list->view(),
			$this->listInner(),
			$this->focus === self::FOCUS_LIST
		);
		$right = $this->renderPane(
			$this->detailView(),
			$this->detailInner(),
			$this->focus === self::FOCUS_DETAIL
		);

		$body  = Layout::joinHorizontal(0.0, $left, $right);
		$total = max(0, $this->detail[$this->selected] ? count($this->detail[$this->selected]) : 0);
		$pos   = $total > 0 ? min($total, $this->scroll + $this->paneInnerHeight()) . '/' . $total : '0/0';

		$help = Style::new()->foreground($this->muted)->render(
			' ↑/↓ move · Tab switch pane · / filter · g/G top/bottom · q quit   [detail ' . $pos . '] '
		);

		return $body . "\n" . $help;
	}

	public function subscriptions(): ?Subscriptions
	{
		return null;
	}

	// ---- helpers -------------------------------------------------------

	/**
	 * @return array{0:self, 1:?\Closure}
	 */
	private function routeToList(Msg $msg): array
	{
		[$this->list] = $this->list->update($msg);
		$idx = $this->list->index();
		if ($idx !== $this->selected) {
			$this->selected = $idx;
			$this->scroll   = 0;
		}
		return [$this, null];
	}

	/**
	 * @return array{0:self, 1:?\Closure}
	 */
	private function scrollDetail(KeyMsg $msg): array
	{
		$lines  = $this->detail[$this->selected] ?? [];
		$height = $this->paneInnerHeight();
		$maxOff = max(0, count($lines) - $height);

		$this->scroll = match (true) {
			$msg->type === KeyType::Up                              => max(0, $this->scroll - 1),
			$msg->type === KeyType::Down                            => min($maxOff, $this->scroll + 1),
			$msg->type === KeyType::PageUp                          => max(0, $this->scroll - $height),
			$msg->type === KeyType::PageDown                        => min($maxOff, $this->scroll + $height),
			$msg->type === KeyType::Home                            => 0,
			$msg->type === KeyType::End                             => $maxOff,
			$msg->type === KeyType::Char && $msg->rune === 'g'      => 0,
			$msg->type === KeyType::Char && $msg->rune === 'G'      => $maxOff,
			$msg->type === KeyType::Char && $msg->rune === 'k'      => max(0, $this->scroll - 1),
			$msg->type === KeyType::Char && $msg->rune === 'j'      => min($maxOff, $this->scroll + 1),
			default                                                 => $this->scroll,
		};

		return [$this, null];
	}

	private function detailView(): string
	{
		$lines  = $this->detail[$this->selected] ?? [];
		$height = $this->paneInnerHeight();
		$slice  = array_slice($lines, $this->scroll, $height);
		return implode("\n", $slice);
	}

	private function renderPane(string $content, int $innerWidth, bool $focused): string
	{
		return Style::new()
			->border(Border::rounded())
			->borderForeground($focused ? $this->accent : $this->muted)
			->width($innerWidth)
			->height($this->paneInnerHeight())
			->padding(0, 1)
			->render($content);
	}

	private function listOuter(): int
	{
		return max(24, min(48, intdiv($this->cols, 3)));
	}

	private function listInner(): int
	{
		// outer minus border (2) minus horizontal padding (2)
		return max(4, $this->listOuter() - 4);
	}

	private function detailInner(): int
	{
		return max(4, ($this->cols - $this->listOuter()) - 4);
	}

	private function paneInnerHeight(): int
	{
		// total rows minus footer (1) minus top+bottom border (2)
		return max(3, $this->rows - 3);
	}
}
