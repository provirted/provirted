---
name: add-command
description: Creates a new CLIFramework command in app/Command/ following the project's standard skeleton with brief(), options(), arguments(), and execute() including Vps::init(), isVirtualHost(), and vpsExists() guards. Use when user says 'add command', 'new command', 'create command', or adds a top-level file to app/Command/. Do NOT use for subcommand groups inside subdirectories (e.g. app/Command/FooCommand/) — those follow a different registration pattern.
---
# add-command

## Critical

- **PHP 7.4 only.** No named args, `match`, `?->`, union types, `str_contains/starts_with/ends_with`, enums, readonly, or constructor promotion.
- **Always `escapeshellarg()`** every `$vzid`, `$ip`, `$password`, or user-supplied value before any shell invocation.
- **Always call `Vps::init()` first** in `execute()` — it sets virt type, logger level, and shared state.
- Guard order is mandatory: `isVirtualHost()` check → `vpsExists()` check → business logic.
- Indentation: **tabs**. Opening brace on same line as class/method. No trailing commas.

## Instructions

1. **Create the file** `app/Command/{PascalCase}Command.php`. The class name must be `{PascalCase}Command` and live in namespace `App\Command`.

2. **Add the standard imports** (copy verbatim — unused imports are fine per existing convention):
   ```php
   <?php
   namespace App\Command;
   
   use App\Vps;
   use CLIFramework\Command;
   use CLIFramework\Formatter;
   use CLIFramework\Logger\ActionLogger;
   use CLIFramework\Debug\LineIndicator;
   use CLIFramework\Debug\ConsoleDebug;
   ```

3. **Declare the class** extending `Command` with `brief()`, `options()`, `arguments()`, and `execute()`:
   ```php
   class FooCommand extends Command {
   	public function brief() {
   		return "One-sentence description.";
   	}
   
   	/** @param \GetOptionKit\OptionCollection $opts */
   	public function options($opts) {
   		parent::options($opts);
   		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
   		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc, docker')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc','docker']);
   	}
   
   	/** @param \CLIFramework\ArgInfoList $args */
   	public function arguments($args) {
   		$args->add('vzid')->desc('VPS id/name to use')->isa('string')->validValues([Vps::class, 'getAllVpsAllVirts']);
   	}
   
   	public function execute($vzid) {
   		Vps::init($this->getOptions(), ['vzid' => $vzid]);
   		if (!Vps::isVirtualHost()) {
   			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
   			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
   			return 1;
   		}
   		if (!Vps::vpsExists($vzid)) {
   			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
   			return 1;
   		}
   		// business logic here
   	}
   }
   ```

4. **Add extra options** after the standard `v|verbose` and `t|virt:` lines if needed. Use `array_key_exists('option-name', $opts->keys)` to read them safely in `execute()` (see `DeleteCommand` for `order-id` pattern).

5. **Register the command** in `app/Console.php`. Find the `addCommand` block and add:
   ```php
   $this->addCommand('foo', 'App\\Command\\FooCommand');
   ```
   Verify the command name matches what should appear in the CLI help.

6. **Build and verify** with `make` then run `php provirted.phar foo --help` to confirm the command appears with correct brief and argument/option listing.

## Examples

**User says**: "Add a `pause` command that pauses a running VPS."

**Actions taken**:
- Create `app/Command/PauseCommand.php` with class `PauseCommand`
- `brief()` returns `"Pauses a running Virtual Machine."`
- `options()` includes standard `v|verbose` + `t|virt:` only
- `execute()` calls `Vps::init()`, checks `isVirtualHost()`, checks `vpsExists()`, checks `Vps::isVpsRunning($vzid)` (return 1 if not running), then calls `Vps::pauseVps($vzid)`
- Register in `Console.php`: `$this->addCommand('pause', 'App\\Command\\PauseCommand');`

**Result**: `php provirted.phar pause myvm` runs the guard chain and dispatches to `Vps::pauseVps()`.

## Common Issues

- **"Class App\\Command\\FooCommand not found"**: You forgot to register it in `app/Console.php`, or the file name/class name casing doesn't match. File must be `FooCommand.php`, class must be `FooCommand`.
- **"Call to undefined method Vps::init()"**: Missing `use App\\Vps;` at the top of the file.
- **PHP parse error on `$opts->keys['opt']?->value`**: Nullsafe operator is PHP 8+ only. Use `array_key_exists('opt', $opts->keys) ? $opts->keys['opt']->value : ''` instead.
- **Argument not showing in `--help`**: `arguments()` method is missing or returns before calling `$args->add()`.
- **Virt type never set**: `Vps::init($this->getOptions(), ...)` must be the very first call in `execute()`. Calling `Vps::getVirtType()` before `init()` returns `false`.