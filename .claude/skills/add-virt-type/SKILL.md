---
name: add-virt-type
description: Adds a new virtualization backend (e.g. 'add virt type', 'new virtualization', 'support X hypervisor'). Creates app/Vps/NewType.php with all required static methods, wires it into app/Vps.php dispatch branches, $virtBins, and --virt validValues across all 41 command files. Do NOT use for modifying existing virt backends or adding new commands only.
---
# add-virt-type

## Critical

- **PHP 7.4 only** — no named args, `match`, `?->`, union types, `str_contains/starts_with/ends_with`, enums, readonly, fibers, or constructor promotion.
- **Tabs for indentation**, opening brace on same line as class/method.
- Every `$vzid`, `$ip`, or user-supplied value passed to shell commands MUST be wrapped in `escapeshellarg()`.
- No trailing commas in multiline arrays.
- All methods in the backend class MUST be `public static`.
- The dispatch in `app/Vps.php` uses `if/elseif` — never `switch`. Add the new type as the last `elseif` in each method.

## Instructions

### Step 1 — Create `app/Vps/NewType.php`

File: `app/Vps/NewType.php`

```php
<?php
namespace App\Vps;

use App\Vps;

class NewType
{
	public static function getRunningVps() {
		$output = trim(Vps::runCommand("<cli> list --running"));
		return $output == '' ? [] : explode("\n", $output);
	}

	public static function getAllVps() {
		$output = trim(Vps::runCommand("<cli> list"));
		return $output == '' ? [] : explode("\n", $output);
	}

	public static function vpsExists($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::runCommand("<cli> info {$vzid} >/dev/null 2>&1", $return);
		return $return == 0;
	}

	public static function getVps($vzid) {
		$vzid = escapeshellarg($vzid);
		$json = trim(Vps::runCommand("<cli> show {$vzid} --format json 2>/dev/null"));
		$data = json_decode($json, true);
		if (!is_array($data) || count($data) == 0)
			return false;
		return $data[0];
	}

	public static function getVpsMac($vzid) {
		// return MAC string or '' if not found
		return '';
	}

	public static function getVpsIps($vzid) {
		// return array of IP strings
		return [];
	}

	public static function addIp($vzid, $ip) {
		$vzid = escapeshellarg($vzid);
		$ip = escapeshellarg($ip);
		Vps::getLogger()->write(Vps::runCommand("<cli> config {$vzid} --ip-add {$ip}"));
	}

	public static function enableAutostart($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("<cli> config {$vzid} --onboot yes"));
	}

	public static function disableAutostart($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("<cli> config {$vzid} --onboot no"));
	}

	public static function startVps($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("<cli> start {$vzid}"));
	}

	public static function stopVps($vzid, $fast = false) {
		$vzid = escapeshellarg($vzid);
		$flag = $fast ? ' --kill' : '';
		Vps::getLogger()->write(Vps::runCommand("<cli> stop{$flag} {$vzid}"));
	}

	public static function resetVps($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("<cli> restart {$vzid}"));
	}

	public static function destroyVps($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("<cli> delete {$vzid}"));
	}
}
```

Replace `<cli>` with the actual binary (e.g. `/usr/bin/podman`). Replace `NewType` with the PascalCase name (e.g. `Podman`).

Verify all required methods exist before Step 2: `getRunningVps`, `getAllVps`, `vpsExists`, `getVps`, `getVpsMac`, `getVpsIps`, `addIp`, `enableAutostart`, `disableAutostart`, `startVps`, `stopVps`, `resetVps`, `destroyVps`.

### Step 2 — Register binary in `app/Vps.php`

Add the binary entry to `$virtBins` (use the lowercase slug as the key):

```php
public static $virtBins = [
    'virtuozzo' => '/usr/bin/prlctl',
    'openvz'    => '/usr/sbin/vzctl',
    'kvm'       => '/usr/bin/virsh',
    'lxc'       => '/usr/bin/lxc',
    'docker'    => '/usr/bin/docker',
    'newtype'   => '/usr/bin/newtype',  // add here
];
```

Add a `use` import at the top of `app/Vps.php` alongside the existing ones:

```php
use App\Vps\NewType;
```

Verify the binary path is correct on target hosts before Step 3.

### Step 3 — Add `elseif` branches to every dispatch method in `app/Vps.php`

For every method that has a `if/elseif` on `self::getVirtType()`, append a new `elseif` for the new type as the **last** branch before any closing logic. Pattern (replicate for every method: `vpsExists`, `getVpsIps`, `getVpsMac`, `enableAutostart`, `disableAutostart`, `startVps`, `stopVps`, `resetVps`, `destroyVps`, `addIp`, `getAllVps`, `getRunningVps`, `getPoolType`, `getAllVpsAllVirts`):

```php
// existing last elseif:
elseif (self::getVirtType() == 'lxc')
    Lxc::startVps($vzid);
// add:
elseif (self::getVirtType() == 'newtype')
    NewType::startVps($vzid);
```

For `getAllVpsAllVirts` (uses `in_array`, not `getVirtType`):

```php
if (in_array('newtype', $virts))
    $vpsList = array_merge($vpsList, NewType::getAllVps());
```

Verify every dispatch method has the new branch before Step 4.

### Step 4 — Add to `--virt` validValues in all command files

41 command files contain `validValues(['kvm','openvz','virtuozzo','lxc','docker'])`. Add the new slug to every one. Search and replace:

```bash
# Find all affected files:
grep -rl "validValues.*kvm.*openvz" app/Command/

# Verify the pattern to replace:
grep -n "validValues" app/Command/StartCommand.php
```

Change every occurrence of:
```php
->validValues(['kvm','openvz','virtuozzo','lxc','docker'])
```
to:
```php
->validValues(['kvm','openvz','virtuozzo','lxc','docker','newtype'])
```

Verify with `grep -r "validValues" app/Command/ | grep -v newtype` returning no results.

### Step 5 — Build and test

```bash
make        # composer update --no-dev + build provirted.phar
./provirted.phar --virt=newtype start --help   # confirm newtype appears in validValues
./provirted.phar --virt=newtype list            # smoke test dispatch
```

## Examples

**User says:** "Add Podman as a new virt type using `/usr/bin/podman`"

**Actions taken:**
1. Create `app/Vps/Podman.php` — namespace `App\Vps`, class `Podman`, all static methods using `Vps::runCommand("podman ...")`.
2. Add `'podman' => '/usr/bin/podman'` to `$virtBins` in `app/Vps.php`.
3. Add `use App\Vps\Podman;` to `app/Vps.php`.
4. Add `elseif (self::getVirtType() == 'podman') Podman::startVps($vzid);` (and equivalents) to every dispatch method.
5. Add `'podman'` to `validValues` in all 41 command files.
6. Run `make` to rebuild the phar.

**Result:** `./provirted.phar --virt=podman start myvps` dispatches to `Podman::startVps('myvps')`.

## Common Issues

- **`Call to undefined method App\Vps\NewType::someMethod()`**: A required static method is missing from the backend class. Check Step 1's method list — all 13 methods must exist.
- **`Unknown virtualization type 'newtype'`** at runtime: The binary path in `$virtBins` does not exist on the host. Verify with `which newtype` or `ls /usr/bin/newtype` on the target machine.
- **`--virt=newtype` rejected with invalid value error**: At least one command file still has the old `validValues` array. Run `grep -rl "validValues.*kvm.*openvz" app/Command/` — every file listed needs the new slug added.
- **`Class 'App\Vps\NewType' not found`**: Missing `use App\Vps\NewType;` at the top of `app/Vps.php`, or the file is not in `app/Vps/NewType.php` with the correct namespace `App\Vps`.
- **Phar uses old code after edits**: Run `make phar` (or `make`) to rebuild. The phar does not auto-update from source changes.