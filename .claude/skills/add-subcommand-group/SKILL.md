---
name: add-subcommand-group
description: Creates a parent command file and a matching subdirectory of subcommand classes following the CdCommand/VncCommand/SnapshotCommand pattern in ProVirted. Use when user says 'add subcommand group', 'command with subcommands', 'nested commands', or a feature needs multiple related sub-operations under one parent (e.g. 'snapshot save / restore / list'). Do NOT use for standalone commands with no subcommands — use the add-command skill instead. Do NOT use for top-level diagnostic helpers or for utilities that belong in app/Os/ (use os-utility) or app/Vps/ (use vps-operation).
paths:
  - app/Command/**/*.php
  - app/Console.php
---
# Add Subcommand Group

Creates a CLIFramework parent command plus its child subcommand classes, matching the project's `CdCommand`/`VncCommand`/`SnapshotCommand`/`HistoryCommand`/`CronCommand` pattern.

## Critical

- **PHP 7.4 only.** No named args, `match`, nullsafe `?->`, union types, enums, `str_contains`, readonly, fibers, constructor promotion. `composer.json` `config.platform.php` is locked to `7.4.33`.
- **Tabs for indentation. Braces on same line.** Class names `PascalCase`, methods `camelCase`, vars `$camelCase`. PHP-CS-Fixer uses `@PSR2` + `@PHP74Migration` with `trailing_comma_in_multiline` disabled — do NOT add trailing commas in multiline arrays/calls.
- **Shell safety:** every `$vzid`, `$ip`, `$mac`, `$password`, or user-supplied value MUST be wrapped with `escapeshellarg()` before interpolation. Never use `exec()`/`shell_exec()`/backticks — call `Vps::runCommand($cmd, $return)` and pipe through `Vps::getLogger()->write(...)`.
- **Virt dispatch is `if/elseif` on `Vps::getVirtType()`** — never `switch`. Container types (`docker`, `lxc`) must be gated out of VNC, CD-ROM, virsh XML, storage pools, and kpartx code paths via `in_array(Vps::getVirtType(), ['docker', 'lxc'])`.
- **Do NOT touch `app/Command/InternalsCommand.php` by hand** — it is regenerated from `app/Resources/*.tpl` via `make internals`.
- **Run `caliber refresh` before committing**, then `git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null`.

## Instructions

### Step 1 — Confirm this skill applies

This skill is for groups like `provirted snapshot save`, `provirted vnc setup`, `provirted cron bw-info`. If the feature is a single verb with no subcommands (e.g. `provirted start <vzid>`), STOP and use the `add-command` skill instead.

Verify before proceeding: there are at least two distinct sub-operations under one shared noun (the parent name).

### Step 2 — Pick names

- Parent class: `{Name}Command` in `App\Command` namespace, file `app/Command/{Name}Command.php` (e.g. `BackupCommand.php`).
- Subcommand directory: `app/Command/{Name}Command/` (matches the parent class name exactly — CLIFramework auto-discovery depends on this).
- Each subcommand class: `{Action}Command` in `App\Command\{Name}Command` namespace, file `app/Command/{Name}Command/{Action}Command.php` (e.g. `app/Command/BackupCommand/CreateCommand.php`).
- CLI invocation will be `provirted.phar {name-kebab} {action-kebab}` (CLIFramework lowercases and kebab-cases automatically: `BwInfoCommand` → `bw-info`).

Verify before proceeding: directory name matches parent class name character-for-character.

### Step 3 — Create the parent command file

Write `app/Command/{Name}Command.php` using this exact skeleton (modeled on `app/Command/SnapshotCommand.php` / `app/Command/VncCommand.php`):

```php
<?php
namespace App\Command;

use CLIFramework\Command;

class {Name}Command extends Command {
	public function brief() {
		return "{One-line description of the whole group}";
	}

	public function execute() {
        echo '
SYNTAX

provirted.phar {name-kebab} <subcommand>

SUBCOMMANDS
	{action1} <vzid>           {short description}
	{action2} <vzid> [arg]     {short description}

EXAMPLES
	provirted.phar {name-kebab} {action1} vps4000
	provirted.phar {name-kebab} {action2} vps4000 foo
';
	}
}
```

Notes that match existing files:
- Parent only imports `CLIFramework\Command` — does NOT import `App\Vps` (it does not touch VPS state).
- The `echo` literal starts at column 8 (spaces, not tab) like `CdCommand.php`/`SnapshotCommand.php` do — preserve that quirk.
- Use single quotes for the heredoc-style `echo` string so `$` is not interpolated.

Verify before proceeding: the parent file matches the indent/quote style of `app/Command/SnapshotCommand.php`.

### Step 4 — Create each subcommand file

For each `{Action}`, write `app/Command/{Name}Command/{Action}Command.php` using this skeleton (modeled on `app/Command/VncCommand/SetupCommand.php` and `app/Command/SnapshotCommand/SaveCommand.php`):

```php
<?php
namespace App\Command\{Name}Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class {Action}Command extends Command {
	public function brief() {
		return "{One-line description}.";
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
		// dispatch via Vps::getVirtType() OR call a Vps::{verb}Vps($vzid) facade method
	}
}
```

Rules for the skeleton:
- Keep the two PHPDoc lines (`@param \GetOptionKit\OptionCollection $opts` and `@param \CLIFramework\ArgInfoList $args`) indented with 4 spaces, not a tab — every existing subcommand does this.
- Always include the `-v|verbose` and `-t|virt:` options (copy verbatim) so the help output and validation stay consistent across the suite.
- For extra positional args, add another `$args->add(...)->desc(...)->isa('string')` and accept it in `execute()` with a default empty value: `public function execute($vzid, $ip = '')` — see `VncCommand/SetupCommand.php`.
- If the subcommand does not act on a single VPS (e.g. `VncCommand/SecureCommand`, `VncCommand/RestartCommand`, `CronCommand/HostInfoCommand`), drop the `$args->add('vzid')` line and the `vpsExists` guard, but keep `Vps::init(...)` and the `isVirtualHost()` guard.
- For pool-specific operations (e.g. zfs), add the gate after `vpsExists`, as in `SnapshotCommand/SaveCommand.php`:
  ```php
  if (Vps::getPoolType() != 'zfs') {
      Vps::getLogger()->error("This system is not setup for zfs");
      return 1;
  }
  ```
- For container-incompatible operations, gate with `if (in_array(Vps::getVirtType(), ['docker', 'lxc'])) { Vps::getLogger()->error("..."); return 1; }` before any virsh/qemu-img/kpartx call.

Verify before proceeding: every `$vzid`/`$ip`/`$url` reaching a shell string is either passed to `Vps::runCommand` through a facade method or wrapped in `escapeshellarg()`.

### Step 5 — Wire underlying logic

- If the action belongs in the virt dispatch (e.g. start/stop/snapshot/backup), implement it via the `vps-operation` skill so every backend in `app/Vps/{Kvm,Virtuozzo,OpenVz,Lxc,Docker}.php` has a static method and `app/Vps.php` has an `if/elseif Vps::getVirtType()` facade.
- If the action is host-level (DHCP, xinetd, IP/RAM detection), put helpers in `app/Os/` via the `os-utility` skill.
- The subcommand's `execute()` should only call facade methods (`Vps::startVps`, `Vps::setupVnc`, etc.) plus thin glue; do NOT inline virsh/prlctl/vzctl/lxc/docker shell strings in the command class except for the cdrom/dumpxml-style probes already present in `CdCommand/EnableCommand.php`.

Verify before proceeding: `grep -n 'getVirtType' app/Vps.php` shows an `if/elseif` chain covering all five backends for any new operation you added.

### Step 6 — Register in `app/Console.php` (only if user-visible at top level)

Open `app/Console.php` and add the kebab-cased parent name to the appropriate `$this->commandGroup(...)` array in `init()` (lines 16-19):

- Power: `stop`, `start`, `restart`
- Provisioning: `config`, `create`, `destroy`, `enable`, `delete`, `backup`, `restore`, `test`
- Maintanance: `install-cpanel`, `reset-password`, `update`, `cd`, `block-smtp`, `add-ip`, `remove-ip`, `change-ip`, `rebuild-dhcp`, `vnc`
- Development Commands: `generate-internals`

If none fit, leave it ungrouped (CLIFramework will still autoload it via `$this->enableCommandAutoload()`).

Verify before proceeding: `php provirted.php list` (after `make dev`) shows the new parent command and `php provirted.php {name-kebab}` echoes the SYNTAX block.

### Step 7 — Build and smoke-test

```bash
make dev            # composer update with dev deps
php provirted.php {name-kebab}              # should print the SYNTAX/SUBCOMMANDS/EXAMPLES block
php provirted.php {name-kebab} {action} --help  # should print options + arguments
```

Then rebuild the phar and regenerate bash completion:

```bash
make                # composer update --no-dev + build phar (no compression!)
make completion     # regenerate provirted_completion
```

Verify before proceeding: `./provirted.phar {name-kebab}` (from project root) prints the same help text, and `./provirted.phar {name-kebab} {action} --help` lists `-v|verbose` and `-t|virt`.

### Step 8 — Sync docs and commit

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```

Then stage the new command files and commit. Do NOT amend a prior commit; create a new one.

## Examples

### Example — Add `provirted backup create|list|delete`

**User says:** "Add a backup subcommand group with create, list, and delete actions, plus a `--dry` flag on create."

**Actions taken:**

1. Create `app/Command/BackupCommand.php`:
   ```php
   <?php
   namespace App\Command;

   use CLIFramework\Command;

   class BackupCommand extends Command {
   	public function brief() {
   		return "Create and manage VPS backups";
   	}

   	public function execute() {
           echo '
   SYNTAX

   provirted.phar backup <subcommand>

   SUBCOMMANDS
   	create <vzid> [--dry]      create a new backup
   	list [vzid]                list backups
   	delete <vzid> <name>       delete a backup

   EXAMPLES
   	provirted.phar backup create vps4000
   	provirted.phar backup create vps4000 --dry
   	provirted.phar backup list
   	provirted.phar backup delete vps4000 2026-05-11
   ';
   	}
   }
   ```

2. Create `app/Command/BackupCommand/CreateCommand.php` using the Step 4 skeleton, adding `$opts->add('dry', 'dry run, do not write')->isa('boolean');` and accepting `$dry = false` in `execute()` after reading `$this->getOptions()->dry`.

3. Create `app/Command/BackupCommand/ListCommand.php` with no `vzid` argument (or optional `vzid`), no `vpsExists` guard, and a loop over `Vps::getAllVpsAllVirts()`.

4. Create `app/Command/BackupCommand/DeleteCommand.php` with two args: `vzid` and `name`.

5. Add a `Vps::createBackup($vzid, $dry)` facade in `app/Vps.php` plus `createBackup` static methods in each `app/Vps/{Kvm,Virtuozzo,OpenVz,Lxc,Docker}.php` (delegate to the `vps-operation` skill).

6. Add `'backup'` to the Provisioning `commandGroup` array in `app/Console.php` (already present — verify it is there).

7. `make dev && php provirted.php backup && php provirted.php backup create --help` — both should print successfully.

8. `make && make completion`, then `caliber refresh && git add ... && git commit`.

**Result:** `provirted.phar backup`, `provirted.phar backup create vps4000`, `provirted.phar backup list`, `provirted.phar backup delete vps4000 2026-05-11` all work; help text is consistent with `snapshot` and `vnc`; bash completion auto-completes subcommand names and `vzid` values.

## Common Issues

- **`Class 'App\Command\FooCommand\BarCommand' not found` when running the subcommand.** Directory name must match the parent class name exactly (including the `Command` suffix). Check: the directory is `app/Command/FooCommand/` not `app/Command/Foo/`. Also verify the namespace declaration in the subcommand file is `namespace App\Command\FooCommand;` (no trailing `\`).

- **Subcommand runs but help shows `Command 'foo' not found` when typing `provirted.phar foo --help`.** The parent file `app/Command/FooCommand.php` is missing or its class is misnamed. CLIFramework needs the parent class even though `execute()` just echoes help.

- **`Error: Unexpected token "match"` or `Cannot use "readonly"` when running the phar.** You used a PHP 8+ feature. The platform is pinned to `7.4.33` in `composer.json` — rewrite using `if/elseif`, ternaries, and plain properties.

- **`provirted.phar foo bar vps4000` runs but `Vps::getVirtType()` returns empty / dispatch is silently skipped.** You forgot `Vps::init($this->getOptions(), ['vzid' => $vzid])` as the first line of `execute()`. Every subcommand that touches VPS state must call `Vps::init` before any `Vps::*` call.

- **Bash completion does not list the new subcommand after `make install`.** Run `make completion` to regenerate `provirted_completion`, then `source /etc/bash_completion.d/provirted` (or re-login).

- **`pvdisplay` shows garbled output after `make phar`.** Someone enabled compression. The phar build MUST stay `--no-compress` (see `Makefile` `phar` target) — compression breaks `pvdisplay`.

- **Subcommand silently runs against the wrong backend on a mixed host.** User passed `--virt` but you typed the option as `'t|virt'` instead of `'t|virt:'`. The trailing colon makes it a value-taking option (matches every existing subcommand).

- **PHP-CS-Fixer rewrites your file and adds trailing commas.** It should not — `trailing_comma_in_multiline` is disabled in `.php-cs-fixer.dist.php`. If you see this, you may be running a stale fixer config; run from the project root so the local `.php-cs-fixer.dist.php` is picked up.