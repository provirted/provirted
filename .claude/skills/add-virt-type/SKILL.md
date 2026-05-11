---
name: add-virt-type
description: Adds a new virtualization backend to ProVirted (e.g. 'add virt type', 'new virtualization', 'support X hypervisor', 'add podman', 'add proxmox'). Creates app/Vps/NewType.php with all required public static methods, registers it in Vps::$virtBins, adds use statement + elseif dispatch branches across every method in app/Vps.php, and updates --virt validValues in all ~41 command files under app/Command/. Do NOT use for modifying existing virt backends (kvm/openvz/virtuozzo/lxc/docker), adding only commands or subcommands (use add-command / add-subcommand-group), or adding OS-level utilities (use os-utility).
paths:
  - app/Vps.php
  - app/Vps/*.php
  - app/Command/**/*.php
---
# add-virt-type

## Critical

- **PHP 7.4 only** — no named args, `match`, nullsafe `?->`, union types, enums, readonly, fibers, constructor promotion, `str_contains`/`str_starts_with`/`str_ends_with`. `composer.json` is locked to `7.4.33`.
- **Tabs for indentation**, opening brace on the same line as `class`/`function`.
- Every `$vzid`, `$ip`, `$mac`, `$password`, or user-supplied value MUST be wrapped in `escapeshellarg()` before any shell interpolation.
- Never use `exec()`/`shell_exec()`/backticks. Always call `Vps::runCommand($cmd, $return)` and pipe non-empty output through `Vps::getLogger()->write(...)`.
- **No trailing commas** in multiline arrays (`@PHP74Migration` + `trailing_comma_in_multiline` disabled in `.php-cs-fixer.dist.php`).
- All methods on the backend class MUST be `public static`. Backends are stateless facades dispatched from `App\Vps`.
- Dispatch in `app/Vps.php` uses `if/elseif` chains on `self::getVirtType()` — NEVER convert to `switch`. Append the new branch as the **last** `elseif` in every method.
- The phar is built with `--no-compress` (compression breaks `pvdisplay`). After source edits you must run `make` to rebuild `provirted.phar` — do not edit a built phar.

## Instructions

### Step 1 — Create the backend class at `app/Vps/{NewType}.php`

Use `app/Vps/Lxc.php` and `app/Vps/Docker.php` as the canonical templates. Naming: PascalCase class name (e.g. `Podman`), lowercase slug (e.g. `podman`).

```php
<?php
namespace App\Vps;

use App\Vps;

class NewType {
	/**
	* return a list of the running VPSes
	*
	* @return array
	*/
	public static function getRunningVps() {
		$output = trim(Vps::runCommand("newtype list --running --format csv"));
		return $output == '' ? [] : explode("\n", $output);
	}

	public static function getAllVps() {
		$output = trim(Vps::runCommand("newtype list --format csv"));
		return $output == '' ? [] : explode("\n", $output);
	}

	public static function vpsExists($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::runCommand("newtype info {$vzid} >/dev/null 2>&1", $return);
		return $return == 0;
	}

	public static function getVps($vzid) {
		$vzid = escapeshellarg($vzid);
		$json = trim(Vps::runCommand("newtype list {$vzid} --format json 2>/dev/null"));
		$data = json_decode($json, true);
		if (!is_array($data) || count($data) == 0)
			return false;
		return $data[0];
	}

	public static function getVpsMac($vzid) { /* return '' if not found */ return ''; }
	public static function getVpsIps($vzid) { /* return [] of IP strings */ return []; }

	public static function addIp($vzid, $ip) {
		$vzid = escapeshellarg($vzid);
		$ip = escapeshellarg($ip);
		Vps::getLogger()->write(Vps::runCommand("newtype config {$vzid} --ip-add {$ip}"));
	}

	public static function enableAutostart($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("newtype config {$vzid} --onboot yes"));
	}

	public static function disableAutostart($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("newtype config {$vzid} --onboot no"));
	}

	public static function startVps($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("newtype start {$vzid}"));
	}

	public static function stopVps($vzid, $fast = false) {
		$vzid = escapeshellarg($vzid);
		$flag = $fast ? ' --kill' : '';
		Vps::getLogger()->write(Vps::runCommand("newtype stop{$flag} {$vzid}"));
	}

	public static function resetVps($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("newtype restart {$vzid}"));
	}

	public static function destroyVps($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("newtype delete {$vzid}"));
	}
}
```

**Validation gate:** Before Step 2, confirm all 13 required public static methods exist: `getRunningVps`, `getAllVps`, `vpsExists`, `getVps`, `getVpsMac`, `getVpsIps`, `addIp`, `enableAutostart`, `disableAutostart`, `startVps`, `stopVps`, `resetVps`, `destroyVps`. Confirm namespace is `App\Vps` and class name matches the filename.

If the backend has container semantics (no VNC, no virsh XML, no kpartx, no storage pools), mirror `app/Vps/Docker.php` and `app/Vps/Lxc.php` — review them for any extra helpers used by command logic (e.g. config readers, network helpers).

### Step 2 — Register the binary in `app/Vps.php`

Depends on: Step 1 (class must exist and autoload).

Edit `app/Vps.php` near the top of the file:

1. Add a `use` line alongside the existing backend imports (currently `App\Vps\Docker;`, `App\Vps\Kvm;`, `App\Vps\Lxc;`, `App\Vps\Virtuozzo;`, `App\Vps\OpenVz;`):

```php
use App\Vps\NewType;
```

2. Add the binary entry to `Vps::$virtBins` (lowercase slug → absolute binary path). The slug is what `--virt=` accepts and what `getVirtType()` returns:

```php
public static $virtBins = [
	'virtuozzo' => '/usr/bin/prlctl',
	'openvz' => '/usr/sbin/vzctl',
	'kvm' => '/usr/bin/virsh',
	'lxc' => '/usr/bin/lxc',
	'docker' => '/usr/bin/docker',
	'newtype' => '/usr/bin/newtype'
];
```

No trailing comma on the last entry.

**Validation gate:** `grep -n "'newtype'" app/Vps.php` must show both the `use` import and the `$virtBins` entry. Confirm the binary path exists on target hosts (`ls -l /usr/bin/newtype` on a host).

### Step 3 — Add an `elseif` branch to every dispatch method in `app/Vps.php`

Depends on: Step 2 (use import in place).

For every method using `if (self::getVirtType() == '...') ... elseif (...)`, append a new branch as the **last** `elseif`. Mirror the existing `lxc` branch's signature exactly.

Pattern:

```php
// existing last branch:
elseif (self::getVirtType() == 'lxc')
	Lxc::startVps($vzid);
// add:
elseif (self::getVirtType() == 'newtype')
	NewType::startVps($vzid);
```

Methods that need a branch (verify by grepping `self::getVirtType() ==` in `app/Vps.php`): `vpsExists`, `getVps`, `getVpsIps`, `getVpsMac`, `getRunningVps`, `getAllVps`, `addIp`, `enableAutostart`, `disableAutostart`, `startVps`, `stopVps`, `resetVps`, `destroyVps`, plus any others that exist on the facade (`getPoolType`, `setupVnc`, etc. — only add branches where the new backend has a real implementation; container-like backends typically skip VNC/CD-ROM/snapshot dispatch).

Special case — `getAllVpsAllVirts` uses `in_array(..., $virts)` instead of `getVirtType()`:

```php
if (in_array('newtype', $virts))
	$vpsList = array_merge($vpsList, NewType::getAllVps());
```

**Validation gate:** `grep -c "== 'newtype'" app/Vps.php` should return a count equal to the number of `== 'lxc'` occurrences for methods the new backend supports. Run `php -l app/Vps.php` to confirm no syntax errors.

### Step 4 — Add the new slug to `--virt` validValues in every command file

Depends on: Step 3 (dispatch wired up; without this, `getVirtType()` will not return the new slug).

Every command under `app/Command/` declares `--virt` with the exact same pattern (see `app/Command/VncCommand/SetupCommand.php:22`):

```php
$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc, docker')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc','docker']);
```

Find every affected file:

```bash
grep -rl "validValues.*kvm.*openvz" app/Command/
```

In each file, update both the help string AND the array. Use a careful sed/Edit pass:

- Help string: append `, newtype` to the comma-separated list.
- Array: append `,'newtype'` to the array literal.

Resulting line:

```php
$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc, docker, newtype')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc','docker','newtype']);
```

**Validation gate:** `grep -rL "newtype" app/Command/ | xargs grep -l "validValues.*kvm"` MUST return zero files. Also confirm `grep -rn "validValues.*kvm.*openvz.*virtuozzo.*lxc.*docker'\\]" app/Command/ | grep -v newtype` is empty.

### Step 5 — Rebuild and smoke test

Depends on: Steps 1–4 complete and `php -l` clean across changed files.

```bash
make                                              # composer update --no-dev + build provirted.phar (no compression)
./provirted.phar list --help                      # confirm 'newtype' appears in --virt help text
./provirted.phar --virt=newtype list              # smoke test: should NOT error 'invalid value' or 'Class not found'
./provirted.phar --virt=newtype start --help      # confirm 'newtype' accepted in validValues for start
```

Then run `caliber refresh` before committing so the docs (`CLAUDE.md`, `.cursor/`, `.github/copilot-instructions.md`, `AGENTS.md`) stay in sync, per the project's commit workflow.

## Examples

**User says:** "Add Podman as a new virt type. The binary lives at `/usr/bin/podman` and it speaks the same CLI as Docker (podman run, podman start, podman stop, podman ps, podman inspect)."

**Actions taken:**
1. Create `app/Vps/Podman.php` — copy `app/Vps/Docker.php` as a starting point because Podman's CLI is Docker-compatible. Replace every `docker ` shell prefix with `podman `, rename class to `Podman`, keep all method signatures identical, keep `namespace App\Vps;` and `use App\Vps;`.
2. Edit `app/Vps.php`:
   - Add `use App\Vps\Podman;` next to the existing backend imports.
   - Add `'podman' => '/usr/bin/podman'` to `$virtBins`.
   - Add `elseif (self::getVirtType() == 'podman') Podman::startVps($vzid);` (and the equivalent for every other dispatch method) as the last `elseif` in each chain.
   - Add `if (in_array('podman', $virts)) $vpsList = array_merge($vpsList, Podman::getAllVps());` in `getAllVpsAllVirts`.
3. Update all command files matched by `grep -rl "validValues.*kvm.*openvz" app/Command/`: append `,'podman'` to the array and `, podman` to the help string.
4. Run `make` to rebuild `provirted.phar`.
5. Verify: `./provirted.phar --virt=podman list` runs without an "invalid value" or autoloader error.
6. Run `caliber refresh && git add -p` and commit.

**Result:** `./provirted.phar --virt=podman start mycontainer` dispatches to `Podman::startVps('mycontainer')`, which shells out to `podman start 'mycontainer'` via `Vps::runCommand()`.

## Common Issues

- **`Class 'App\Vps\NewType' not found`** when running the phar: Either the `use App\Vps\NewType;` line is missing from `app/Vps.php`, the file path is wrong (must be `app/Vps/NewType.php` with PascalCase matching the class), or you forgot to run `make` after creating the file. Fix: verify the file path with `ls app/Vps/NewType.php`, check the namespace is exactly `App\Vps`, rerun `make`.
- **`Call to undefined method App\Vps\NewType::someMethod()`** at runtime: A required `public static` method is missing or named differently. Re-check the 13-method list in Step 1. Static visibility is required — `public function` (non-static) will also produce this error.
- **`Error: 'newtype' is an invalid value`** when passing `--virt=newtype`: At least one command file still has the old `validValues` array. Run `grep -rL "newtype" app/Command/ | xargs grep -l "validValues.*kvm"` to find stragglers. Every command file under `app/Command/` (including subcommand directories like `VncCommand/`, `SnapshotCommand/`, `CronCommand/`) must be updated.
- **`No virtualization found.`** despite `--virt=newtype`: `Vps::getInstalledVirts()` checks `file_exists()` on each path in `$virtBins`. The binary at the path you registered does not exist on the host. Verify with `ls -l /usr/bin/newtype` on a real host, not your dev machine.
- **Dispatch silently does nothing** for some commands: You added the `elseif` only for `startVps` but not the other dispatch methods (`vpsExists`, `getAllVps`, etc.). Grep `app/Vps.php` for `getVirtType()` and ensure every chain has a `newtype` branch wherever it makes sense for the backend.
- **Phar still uses old code after edits**: The phar is a built artifact. Run `make phar` (or `make`) to rebuild. The phar uses `--no-compress` because compression breaks `pvdisplay` — do not pass `--compress` to the archive command.
- **`Parse error: syntax error, unexpected ','`** after editing arrays: You added a trailing comma. `.php-cs-fixer.dist.php` disables `trailing_comma_in_multiline` for PHP 7.4 compatibility — remove it.
- **`caliber refresh` is not installed**: Run `/setup-caliber` per `CLAUDE.md` to install the pre-commit hook, then retry the commit.