<?php
namespace App\Command\HistoryCommand;

use App\Vps;
use CLIFramework\Command;

/**
 * Interactive two-pane viewer for the command history.
 *
 * IMPORTANT: this file must stay PHP 7.4 compatible. The provirted phar
 * runs on PHP 7.4 through 8.5, and every Command file is compiled on every
 * run (for help / completion / dispatch). The actual TUI lives in
 * App\Tui\HistoryTui and the bundled vendor-tui/ libraries, both of which
 * require PHP 8.1+ — they are only loaded AFTER the version gate below
 * passes, so they are never parsed on the 7.4 path.
 */
class TuiCommand extends Command {
	/**
	 * Minimum PHP version the bundled SugarCraft TUI stack supports.
	 *
	 * The libraries currently use `readonly class` (PHP 8.2) syntax, so the
	 * gate is 8.2 even though the goal is 8.1. Once the bundled packages are
	 * genuinely lowered to 8.1 and re-tagged, drop this to 80100 / '8.1'.
	 */
	const MIN_PHP_VERSION_ID = 80200;
	const MIN_PHP_LABEL      = '8.2';

	public function brief() {
		return "interactive two-pane TUI browser for the history entries (requires PHP " . self::MIN_PHP_LABEL . "+)";
	}

	/** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc, docker')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc','docker']);
	}

	/** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
		$logger = Vps::getLogger();

		// --- PHP version gate ------------------------------------------
		if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
			$logger->error('The history TUI requires PHP ' . self::MIN_PHP_LABEL . ' or newer.');
			$logger->error('You are running PHP ' . PHP_VERSION . '. The rest of provirted still works on this version;');
			$logger->error('re-run `provirted history tui` under PHP ' . self::MIN_PHP_LABEL . '+ (or use `history list` / `history show`).');
			return 1;
		}

		// --- locate the bundled TUI dependency set ---------------------
		// dirname(__DIR__, 3) resolves to the project root both on disk
		// (/path/provirted) and inside the phar (phar://.../provirted.phar).
		$autoload = dirname(__DIR__, 3) . '/vendor-tui/autoload.php';
		if (!is_file($autoload)) {
			$logger->error('The TUI components are not bundled in this build.');
			$logger->error('Expected autoloader at: ' . $autoload);
			$logger->error('Rebuild with the TUI bundle:  make tui-deps && make');
			return 1;
		}

		// --- require an interactive terminal ---------------------------
		if (function_exists('stream_isatty') && (!stream_isatty(STDOUT) || !stream_isatty(STDIN))) {
			$logger->error('The history TUI must be run in an interactive terminal (a TTY).');
			return 1;
		}

		$historyFilePath = $_SERVER['HOME'] . '/.provirted/history.json';
		$entries = $this->parseHistory($historyFilePath);

		require_once $autoload;

		try {
			$model   = new \App\Tui\HistoryTui($entries);
			// ProgramOptions positional args: useAltScreen, catchInterrupts, hideCursor.
			$options = new \SugarCraft\Core\ProgramOptions(true, true, true);
			$program = new \SugarCraft\Core\Program($model, $options);
			$program->run();
		} catch (\Throwable $e) {
			$logger->error('TUI exited with an error: ' . $e->getMessage());
			$logger->debug($e->getTraceAsString());
			return 1;
		}

		return 0;
	}

	/**
	 * Parse the JSON-lines history file into [{label, detail}, ...].
	 *
	 * @param string $path
	 * @return array
	 */
	private function parseHistory($path) {
		$entries = array();
		if (!file_exists($path)) {
			return $entries;
		}
		$handle = fopen($path, 'r');
		if ($handle === false) {
			return $entries;
		}
		$id = 0;
		while (($line = fgets($handle)) !== false) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$data = json_decode($line, true);
			if (!is_array($data)) {
				$id++;
				continue;
			}
			$entries[] = array(
				'label'  => $this->buildLabel($id, $data),
				'detail' => $this->buildDetail($data),
			);
			$id++;
		}
		fclose($handle);
		return $entries;
	}

	/**
	 * @param int $id
	 * @param array $data
	 * @return string
	 */
	private function buildLabel($id, array $data) {
		$text  = isset($data[0]['text']) ? $data[0]['text'] : '(unknown)';
		$stamp = '';
		foreach ($data as $item) {
			if (isset($item['type'], $item['start']) && $item['type'] === 'program') {
				$stamp = date('m-d H:i', $item['start']) . '  ';
				break;
			}
		}
		return $id . '  ' . $stamp . $text;
	}

	/**
	 * Render a single history entry to a printable detail block,
	 * mirroring the layout used by `history show`.
	 *
	 * @param array $data
	 * @return string
	 */
	private function buildDetail(array $data) {
		$out      = array();
		$lastType = '';
		foreach ($data as $item) {
			$type = isset($item['type']) ? $item['type'] : '';
			if ($type === 'program') {
				$out[] = '[Command Line] ' . (isset($item['text']) ? $item['text'] : '');
				if (isset($item['start'])) {
					$out[] = '[Started at]   ' . date('Y-m-d H:i:s', $item['start']);
				}
				if (isset($item['end'])) {
					$out[] = '[Ended at]     ' . date('Y-m-d H:i:s', $item['end']);
				}
				if (isset($item['start'], $item['end'])) {
					$out[] = '[Ran for]      ' . ($item['end'] - $item['start']) . ' seconds';
				}
				$out[] = '';
			} elseif ($type === 'output') {
				$out[] = rtrim(isset($item['text']) ? $item['text'] : '', "\n");
			} elseif ($type === 'error') {
				$out[] = '[Error] ' . rtrim(isset($item['text']) ? $item['text'] : '');
			} elseif ($type === 'command') {
				$cmd    = isset($item['command']) ? $item['command'] : '';
				$ret    = isset($item['return']) ? $item['return'] : '';
				$cmdOut = isset($item['output']) ? rtrim($item['output']) : '';
				$line   = '[Command] ' . $cmd . ' [Return: ' . $ret . ']';
				if ($cmdOut !== '') {
					$line .= ' [Output: ' . $cmdOut . ']';
				}
				if (isset($item['error']) && rtrim($item['error']) !== '') {
					$line .= ' [Error: ' . rtrim($item['error']) . ']';
				}
				$out[] = $line;
			}
			$lastType = $type;
		}
		unset($lastType);
		return implode("\n", $out);
	}
}
