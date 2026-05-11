---
name: add-command
description: Creates a new top-level CLIFramework command in app/Command/ following the project's standard skeleton with brief(), options(), arguments(), and execute() including Vps::init(), isVirtualHost(), and vpsExists() guards. Use when user says 'add command', 'new command', 'create command', or adds a top-level file to app/Command/. Do NOT use for subcommand groups inside subdirectories (e.g. app/Command/FooCommand/) — those follow a different registration pattern; use add-subcommand-group instead. Also do NOT use for OS-level helpers in app/Os/ (use os-utility) or virt-specific backend methods (use vps-operation).
paths:
  - app/Command/*.php
  - app/Console.php
---
# Add Command

Add a new top-level CLI command in `app/Command/` that follows the exact skeleton used by `UpdateCommand.php`, `CreateCommand.php`, etc., and is auto-registered by `App\Console::enableCommandAutoload()`.

## Critical

- **PHP 7.4 ONLY.** Do not use `match`, nullsafe `?->`, union types, enums, named args, constructor promotion, readonly, or `str_contains`/`str_starts_with`/`str_ends_with`. Stick to PHP 7.4 syntax (see `composer.json` `config.platform.php = 7.4.33`).
- **Tabs for indentation, same-line braces.** Class names `PascalCase`, methods `camelCase`, variables `$camelCase`. PHP-CS-Fixer uses `@PSR2` + `@PHP74Migration` with `trailing_comma_in_multiline` disabled — no trailing commas in multiline calls/arrays.
- **NEVER** use `exec()`, `shell_exec()`, or backticks. Always use `Vps::runCommand($cmd, $return)` and wrap output in `Vps::getLogger()->write(...)`.
- **ALWAYS** `escapeshellarg()` every `$vzid`, `$ip`, `$mac`, `$password`, hostname, username, or other user-supplied value before interpolating into a shell command.
- **Do NOT** create a subdirectory under `app/Command/` for this skill — that is the subcommand-group pattern. This skill is for a single top-level command file.
- **Do NOT** manually register the command in `app/Console.php` — `enableCommandAutoload()` discovers it from the filename. Only edit `Console.php::init()` if the command must be added to a `commandGroup(...)` listing.
- Dispatch on virt type via `if/elseif` on `Vps::getVirtType()` — never `switch`. Container types (`docker`, `lxc`) skip VNC/CD-ROM/virsh-XML/storage pools/kpartx; gate with `in_array(Vps::getVirtType(), ['docker', 'lxc'])`.

## Instructions

### Step 1 — Confirm scope and command name

1. Confirm with the user the command name in kebab-case as the user will type it on the CLI (e.g. `reset-password`, `block-smtp`).
2. Convert to `PascalCase` + `Command` suffix for the class/file name: `reset-password` → `ResetPasswordCommand`. The file is `app/Command/ResetPasswordCommand.php`.
3. Verify the file does NOT already exist (`Glob app/Command/<Name>Command.php`). If a subdirectory like `app/Command/<Name>Command/` exists, STOP — this should use the subcommand-group pattern instead.

Verify: `ls app/Command/<Name>Command.php` returns no-such-file before continuing.

### Step 2 — Create the command file with the standard skeleton

Create `app/Command/<Name>Command.php` using this exact template (drop unused option lines, keep the structure intact):

```php
<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class <Name>Command extends Command {
	public function brief() {
		return "<one-line description ending with a period.>";
	}

	/** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc, docker')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc','docker']);
		// add additional options here, e.g.:
		// $opts->add('p|password:', 'Sets the password')->isa('string');
	}

	/** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string')->validValues([Vps::class, 'getAllVpsAllVirts']);
		// add optional positional args:
		// $args->add('value')->desc('Some value')->optional()->isa('string');
	}

	public function execute($vzid) {
		Vps::init($this->getOptions(), ['vzid' => $vzid]);
		$opts = $this->getOptions();
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		// === command body ===
		if (Vps::getVirtType() == 'kvm') {
			Vps::getLogger()->write(Vps::runCommand("virsh ... {$vzid}"));
		} elseif (Vps::getVirtType() == 'virtuozzo') {
			Vps::getLogger()->write(Vps::runCommand("prlctl ... {$vzid}"));
		} elseif (Vps::getVirtType() == 'openvz') {
			Vps::getLogger()->write(Vps::runCommand("vzctl ... {$vzid}"));
		} elseif (Vps::getVirtType() == 'lxc') {
			Vps::getLogger()->write(Vps::runCommand("lxc ... {$vzid}"));
		} elseif (Vps::getVirtType() == 'docker') {
			Vps::getLogger()->write(Vps::runCommand("docker ... {$vzid}"));
		}
	}
}
```

Rules for filling in the body:
- For commands that do NOT take a VPS id, drop the `vzid` argument and the two guards below `isVirtualHost()` (keep the `isVirtualHost` check; remove `vpsExists`). Also remove `validValues([Vps::class, 'getAllVpsAllVirts'])`.
- For host-only commands (e.g. dhcp rebuild), keep only the `isVirtualHost()` guard.
- For `--password`, `--hostname`, `--username` and similar string options, read via `$opts->keys['password']->value` then `escapeshellarg(...)` before interpolating.
- For "is option set?" checks use `array_key_exists('foo', $opts->keys)` (this is the pattern used in `UpdateCommand`).
- If logic is virt-type-specific and complex, factor it into static methods on the appropriate backend (`App\Vps\Kvm`, `Virtuozzo`, `OpenVz`, `Lxc`, `Docker`) and use the vps-operation skill — keep the command body thin.

Verify: `php -l app/Command/<Name>Command.php` returns `No syntax errors detected` before continuing.

### Step 3 — Add to a command group in app/Console.php (only if user-facing)

Open `app/Console.php` and locate `init()`. Add the kebab-case command name to the appropriate `commandGroup(...)` call. Existing groups:

- `'Power'` — `stop`, `start`, `restart`
- `'Provisioning'` — `config`, `create`, `destroy`, `enable`, `delete`, `backup`, `restore`, `test`
- `'Maintanance'` (note: spelled this way in source — preserve the typo) — `install-cpanel`, `reset-password`, `update`, `cd`, `block-smtp`, `add-ip`, `remove-ip`, `change-ip`, `rebuild-dhcp`, `vnc`
- `'Development Commands'` (id `dev`) — `generate-internals`

If the command is purely internal/dev, add it to the `Development Commands` group; otherwise pick the matching user-facing group.

Skip this step if the command is a hidden/internal-only command — autoload still registers it.

Verify: `grep -n '<kebab-name>' app/Console.php` shows it in the right group.

### Step 4 — Regenerate bash completion and exercise the command

1. Run `make completion` to regenerate `provirted_completion`.
2. Run from the project root: `php provirted.php <kebab-name> --help` — confirm the brief, options, and arguments render correctly.
3. If touching dispatch, exercise at least one branch on an existing test VPS (or against `php provirted.php list`).
4. Run `make` (default target) to rebuild `provirted.phar`. The phar MUST be built with `--no-compress` — the `make phar` target already does this; do not change it (compression breaks `pvdisplay`).

Verify: `php provirted.phar <kebab-name> --help` shows your new command before claiming done.

### Step 5 — Sync agent context before commit

Before `git commit`, run:

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```

Then stage `app/Command/<Name>Command.php` (and `app/Console.php` if edited) and commit.

## Examples

### Example 1 — Add `block-smtp` command

User says: "Add a `block-smtp` command that blocks outbound port 25 on a VPS."

Actions:
1. Class/file → `app/Command/BlockSmtpCommand.php`.
2. Create the file from the Step 2 skeleton. Keep both the `isVirtualHost()` and `vpsExists()` guards (it takes a `vzid`).
3. In `execute()`, branch on `Vps::getVirtType()`. For `kvm`/`lxc`/`docker`, call `Vps::runCommand("iptables -I FORWARD -m physdev --physdev-in vnet... -p tcp --dport 25 -j DROP")` (use the project's existing iptables helper if present).
4. Open `app/Console.php` and add `'block-smtp'` to the `Maintanance` group array (already present in this project's actual list — confirm before adding).
5. `php -l app/Command/BlockSmtpCommand.php` → OK.
6. `make completion && make` → phar rebuilds.
7. `php provirted.phar block-smtp --help` shows the brief.

Result: `provirted block-smtp vps1001` dispatches via virt type and logs every shell call through `Vps::getLogger()->write()`.

### Example 2 — Add a host-only `rebuild-dhcp` command (no VPS arg)

User says: "Add a `rebuild-dhcp` command that regenerates `/etc/dhcp/dhcpd.vps` from current VPS data."

Actions:
1. File → `app/Command/RebuildDhcpCommand.php`.
2. Skeleton: remove the `vzid` argument and the `vpsExists()` guard; keep `isVirtualHost()`. `execute()` signature is `public function execute()` (no `$vzid`).
3. Delegate to `App\Os\Dhcpd::rebuild()` (create via the os-utility skill if missing).
4. Add `'rebuild-dhcp'` under `Maintanance` group in `app/Console.php` (it's already listed — verify).
5. `php provirted.php rebuild-dhcp --help` confirms.

## Common Issues

- **`Command not found` when running `php provirted.php <name>`**
  - Filename or class name does not match the kebab-case command. `reset-password` MUST be `ResetPasswordCommand` (PascalCase, `Command` suffix) in `app/Command/ResetPasswordCommand.php`.
  - Check namespace is exactly `namespace App\Command;` and class `extends CLIFramework\Command`.
  - Re-run `composer dump-autoload` (or `make dev`) if PSR-4 autoload is stale.

- **`PHP Parse error: syntax error, unexpected token "match"` (or similar) during `make`**
  - You used PHP 8 syntax. Replace `match` with `if/elseif`, remove nullsafe `?->`, remove union types, remove named arguments. `composer.json` pins platform to 7.4.33.

- **`Call to undefined method Vps::isVirtualHost()` or similar**
  - Missing `use App\Vps;` at the top of the command file. Add it.

- **Command runs but `Vps::getVirtType()` returns wrong type / nothing happens**
  - Forgot `Vps::init($this->getOptions(), ['vzid' => $vzid]);` as the first line of `execute()`. Add it before any guards or virt-type checks.

- **Shell command silently fails or produces nothing in logs**
  - You used raw `exec()`/backticks. Replace with `Vps::getLogger()->write(Vps::runCommand("..."));` — without `getLogger()->write(...)` the output is captured but never displayed.

- **Shell injection / command containing spaces breaks**
  - You forgot `escapeshellarg()` on a variable. Every `$vzid`, `$ip`, `$mac`, `$password`, `$hostname`, `$username` MUST be escaped before interpolation. Example: `$password = escapeshellarg($opts->keys['password']->value);` then `"... --userpasswd root:{$password}"`.

- **`pvdisplay` errors after `make` — phar appears corrupted**
  - Someone changed `make phar` to compress the phar. The build MUST use `--no-compress`. Revert to `php provirted.php archive --composer=composer.json --app-bootstrap --executable --no-compress provirted.phar`.

- **PHP-CS-Fixer adds trailing commas / changes indentation**
  - Ensure `.php-cs-fixer.dist.php` `trailing_comma_in_multiline` is disabled and indentation is tabs. Do not auto-format the whole file — only run the fixer on your new file.

- **Bash completion does not include the new command**
  - Run `make completion` to regenerate `provirted_completion`. Then reload your shell (`source provirted_completion` or new login).