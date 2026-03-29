---
name: add-subcommand-group
description: Creates a parent command file and a matching subdirectory of subcommand classes following the CdCommand/VncCommand/SnapshotCommand pattern. Use when user says 'add subcommand group', 'command with subcommands', 'nested commands', or a feature needs multiple related sub-operations under one parent. Do NOT use for standalone commands with no subcommands — use the regular command pattern instead.
---
# add-subcommand-group

## Critical

- **PHP 7.4 only**: no named args, no `match`, no `?->`, no `str_contains/starts_with/ends_with`, no union types.
- **Tabs** for indentation. No trailing commas in arrays or parameter lists.
- Every user-supplied shell value (`$vzid`, `$ip`, etc.) **must** be wrapped in `escapeshellarg()` before any shell invocation.
- Do NOT edit `app/Console.php` unless the new group needs to appear in a display group — `enableCommandAutoload()` discovers subcommands automatically from the filesystem.
- The parent command class name **must** match the directory name exactly (e.g., `FooCommand.php` + `FooCommand/`).

## Instructions

1. **Create the parent command file** `app/Command/FooCommand.php`.
   Use this exact boilerplate — nothing more:
   ```php
   <?php
   namespace App\Command;
   
   use App\Vps;
   use CLIFramework\Command;
   
   class FooCommand extends Command {
   	public function brief() {
   		return "Brief description of the Foo group.";
   	}
   }
   ```
   Verify the class name is `FooCommand` and namespace is `App\Command`.

2. **Create each subcommand file** under `app/Command/FooCommand/SubnameCommand.php`.
   Use this boilerplate for subcommands that operate on a VPS:
   ```php
   <?php
   namespace App\Command\FooCommand;
   
   use App\Vps;
   use CLIFramework\Command;
   use CLIFramework\Formatter;
   use CLIFramework\Logger\ActionLogger;
   use CLIFramework\Debug\LineIndicator;
   use CLIFramework\Debug\ConsoleDebug;
   
   class SubnameCommand extends Command {
   	public function brief() {
   		return "One-line description of what subname does.";
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
   		// implementation here
   	}
   }
   ```
   For subcommands with **no vzid** (e.g., list/info), omit the `arguments()` block, pass `[]` to `Vps::init()`, and drop the `vpsExists` guard.
   Verify: namespace is `App\Command\FooCommand`, class is `SubnameCommand`.

3. **Register in a display group** (optional). Only if the group should appear under a named section in `provirted help`, add `'foo'` to the relevant `commandGroup()` call in `app/Console.php`:
   ```php
   $this->commandGroup('Maintanance', ['stop', 'start', ..., 'foo']);
   ```
   Verify the slug matches the auto-derived command name (PascalCase class → kebab-case slug: `FooCommand` → `foo`).

4. **Build and verify**:
   ```bash
   make phar
   ./provirted.phar foo --help
   ./provirted.phar foo subname --help
   ```

## Examples

**User says**: "Add a `snapshot` subcommand group with `save` and `list` subcommands."

**Actions**:
- Create `app/Command/SnapshotCommand.php` with `brief()` = `"ZFS snapshot functionality"`.
- Create `app/Command/SnapshotCommand/SaveCommand.php` — namespace `App\Command\SnapshotCommand`, guards `isVirtualHost()` + `vpsExists($vzid)` + `getPoolType() != 'zfs'`.
- Create `app/Command/SnapshotCommand/ListCommand.php` — namespace `App\Command\SnapshotCommand`, no `$vzid` arg, no `vpsExists` guard, pass `[]` to `Vps::init()`.

**Result**: `provirted snapshot save <vzid>` and `provirted snapshot list` both work.

## Common Issues

- **`Command not found: foo`** — class name in the `.php` file does not match the filename (e.g., file is `FooCommand.php` but class is `Foo`). Fix: ensure `class FooCommand extends Command`.
- **Subcommand not found under parent** — directory name does not exactly match the parent class name. `FooCommand.php` requires directory `FooCommand/` (case-sensitive).
- **`PHP Fatal: Call to undefined method`** on `getAllVpsAllVirts` — `validValues([Vps::class, 'getAllVpsAllVirts'])` passes the class and method name as a callable array to CLIFramework; confirm `use App\Vps;` is present at the top of the subcommand file.
- **Trailing comma parse error** — PHP 7.4 forbids trailing commas in function parameter lists. Remove any trailing comma in `validValues([...,])` or `['vzid' => $vzid,]`.
- **`make phar` fails with compression error** — phar is built with `--no-compress`. If you added `make phar` flags, revert to the command in `Makefile`.