---
name: vps-operation
description: Adds a new virt-specific operation to the App\Vps facade in app/Vps.php and matching static methods in all five backends (Kvm, Virtuozzo, OpenVz, Lxc, Docker). Use when user says 'add operation', 'implement X for all virt types', 'add a lifecycle method', 'extend Vps facade', or extending VPS management across multiple hypervisors. Capabilities: writes the dispatching if/elseif chain on Vps::getVirtType(), generates static backend methods using Vps::runCommand() with escapeshellarg(), and applies the container gate for operations that don't apply to docker/lxc. Do NOT use for adding CLI commands (use add-command), OS-level utilities like DHCP/xinetd (use os-utility), or adding a wholly new virt backend (use add-virt-type).
paths:
  - app/Vps.php
  - app/Vps/*.php
---
# vps-operation

Adds a new virt-specific operation: one facade method in `app/Vps.php` that dispatches to up to five backend implementations under `app/Vps/` (Kvm, Virtuozzo, OpenVz, Lxc, Docker).

## Critical

- **PHP 7.4 only.** No `match`, no nullsafe `?->`, no union types, no enums, no `str_contains`/`str_starts_with`/`str_ends_with`, no constructor promotion, no named arguments.
- **Dispatch with `if/elseif`** on `self::getVirtType()`. Never use `switch`. The canonical order in `app/Vps.php` is: `kvm` → `virtuozzo` → `openvz` → `docker` → `lxc`. Match that order exactly so diffs stay reviewable.
- **Every shell-bound input must be `escapeshellarg()`'d** before string interpolation. Applies to `$vzid`, `$ip`, `$mac`, `$password`, `$device`, `$hostname`, and anything user-supplied.
- **Never use `exec()`, `shell_exec()`, or backticks.** Use `Vps::runCommand($cmd, $return)`. The `$return` arg captures the exit code by reference.
- **Style**: tabs for indent, same-line braces, `camelCase` methods, `PascalCase` classes, `$camelCase` vars. PHP-CS-Fixer config `@PSR2` + `@PHP74Migration` with `trailing_comma_in_multiline` disabled — do not add trailing commas in multi-line arrays/calls.
- **Container gate**: if the operation does not apply to containers (VNC, virsh XML, storage pools, kpartx, CD-ROM), skip the `docker` and `lxc` branches in the facade and do not add the method to `Lxc.php` / `Docker.php`. If only KVM applies, log via `Vps::getLogger()->error(...)` and `return false`/`return 0` for the others — see `Vps::changeIp()` and `Vps::getPoolType()` for the pattern.
- **No `return` keyword for void operations** (lifecycle: start/stop/reset/destroy/addIp). **Use `return` for accessors** (`getXxx`, `xxxExists`, `defineVps`, `installTemplate`).

## Instructions

### Step 1 — Confirm the operation shape

Decide before writing any code:

1. **Operation name** — `camelCase`, verb-first (e.g. `pauseVps`, `getDiskUsage`, `attachIso`).
2. **Return type** — void (lifecycle action) or returns a value (accessor / boolean / array).
3. **Which backends apply** — all five, KVM-only (storage), full-VM-only (skip docker+lxc), or container-only.
4. **Signature** — first arg is almost always `$vzid` (int|string). Keep extra args in the same order across backends.

Verify by grepping for a similar existing op: `grep -n 'public static function startVps' app/Vps.php app/Vps/*.php`. Match its dispatch shape exactly. Do not proceed until you have a concrete signature.

### Step 2 — Add the static method to each applicable backend in `app/Vps/`

Each backend file is `app/Vps/{Kvm,Virtuozzo,OpenVz,Lxc,Docker}.php` and lives in namespace `App\Vps` with `use App\Vps;`. Add a `public static function` with a PHPDoc block.

Canonical backend method template:

```php
	/**
	* {one-line description}
	*
	* @param int|string $vzid
	* @return {bool|array|string|void}
	*/
	public static function pauseVps($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::runCommand("/usr/bin/virsh suspend {$vzid}", $return);
		return $return == 0;
	}
```

Per-backend command prefixes (use the absolute paths from `Vps::$virtBins`):

| Backend | Binary | Style |
|---|---|---|
| `Kvm` | `/usr/bin/virsh` (also `qemu-img`, `virt-xml`) | `virsh <action> {$vzid}` |
| `Virtuozzo` | `/usr/bin/prlctl` | `prlctl <action> {$vzid}` |
| `OpenVz` | `/usr/sbin/vzctl` | `vzctl <action> {$vzid}` |
| `Lxc` | `/usr/bin/lxc` | `lxc <action> {$vzid}` (br0 bridge) |
| `Docker` | `/usr/bin/docker` | `docker <action> {$vzid}` (config from `Docker::getConfig()`) |

If the backend needs structured output, parse with `json_decode($json, true)` (see `Lxc::getVps`) or `XmlToArray` (KVM virsh dumpxml). Never parse with regex unless the format is line-oriented CSV like `lxc list ... --format csv`.

Verify before proceeding: `php -l app/Vps/Kvm.php` (and each modified backend) returns `No syntax errors`.

### Step 3 — Add the dispatching method to `app/Vps.php`

Open `app/Vps.php`. Add the new method near similar operations (lifecycle methods cluster around line ~406, accessors around line ~280). Use this exact dispatch shape:

**Void operation, all five backends** (model after `Vps::startVps`, `Vps::stopVps`):

```php
	public static function pauseVps($vzid) {
		if (self::getVirtType() == 'kvm')
			Kvm::pauseVps($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::pauseVps($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::pauseVps($vzid);
		elseif (self::getVirtType() == 'docker')
			Docker::pauseVps($vzid);
		elseif (self::getVirtType() == 'lxc')
			Lxc::pauseVps($vzid);
	}
```

**Accessor with return** (model after `Vps::vpsExists`, `Vps::getVpsIps`):

```php
	public static function pauseVps($vzid) {
		if (self::getVirtType() == 'kvm')
			return Kvm::pauseVps($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::pauseVps($vzid);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::pauseVps($vzid);
		elseif (self::getVirtType() == 'docker')
			return Docker::pauseVps($vzid);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::pauseVps($vzid);
	}
```

**KVM-only / partial-support** (model after `Vps::resetVps`, `Vps::changeIp`):

```php
	public static function attachIso($vzid, $iso) {
		if (self::getVirtType() == 'kvm')
			return Kvm::attachIso($vzid, $iso);
		self::getLogger()->error('Attaching an ISO is not supported on this platform yet.');
		return false;
	}
```

**Container-skip** (storage/VNC-style — model after `Vps::setupStorage`, `Vps::getPoolType`):

```php
	public static function setupStorage($vzid, $device, $pool, $hd) {
		if (self::getVirtType() == 'kvm')
			Kvm::setupStorage($vzid, $device, $pool, $hd);
		elseif (self::getVirtType() == 'docker')
			Docker::setupStorage($vzid, $device, $pool, $hd);
		elseif (self::getVirtType() == 'lxc')
			Lxc::setupStorage($vzid, $device, $pool, $hd);
	}
```

Keep the same parameter list across the facade and all backends. The only divergence allowed is dropping unused args for backends that don't need them (see `Vps::defineVps` for the precedent — KVM passes `$mac/$device/$pool/$maxRam/$maxCpu/$useAll`, OpenVZ does not).

Add a PHPDoc block above the facade method matching nearby methods:

```php
	/**
	* {one-line description}
	*
	* @param int|string $vzid
	* @return {type or omit for void}
	*/
```

Verify: `php -l app/Vps.php` returns `No syntax errors`.

### Step 4 — Wire the operation into a command (if user requested CLI exposure)

Only if the user asked for a `provirted <verb>` command. Otherwise stop after Step 3. For command scaffolding use the `add-command` skill — do not duplicate that work here.

If wiring into an existing command, call from the command's `execute()` after the standard guards:

```php
Vps::init($this->getOptions(), ['vzid' => $vzid]);
if (!Vps::isVirtualHost()) { Vps::getLogger()->error("No virtualization found."); return 1; }
if (!Vps::vpsExists($vzid)) { Vps::getLogger()->error("VPS '{$vzid}' not found."); return 1; }
Vps::pauseVps($vzid);
```

### Step 5 — Build and verify

Run in this order:

1. `php -l app/Vps.php` and `php -l app/Vps/Kvm.php` (and every modified backend) — must print `No syntax errors detected`.
2. `make dev` — installs dev deps once; skip on subsequent runs.
3. `make phar` — produces `provirted.phar`. Build failure here usually means a backend method is missing or a `use` statement is absent at the top of `app/Vps.php`.
4. `./provirted.phar help` — verify the binary loads.
5. If a command was added/modified: `./provirted.phar <command> --help` to confirm registration.

**Do not run `make install`** unless the user asks — it symlinks into `/usr/local/bin`.

Before committing: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null`.

## Examples

### Example 1 — Pause/resume lifecycle across all five backends

**User says**: "Add a pause operation to all virt types."

**Actions taken**:

1. In `app/Vps/Kvm.php` add `pauseVps($vzid)` calling `/usr/bin/virsh suspend {$vzid}`.
2. In `app/Vps/Virtuozzo.php` add `pauseVps($vzid)` calling `/usr/bin/prlctl pause {$vzid}`.
3. In `app/Vps/OpenVz.php` add `pauseVps($vzid)` calling `/usr/sbin/vzctl suspend {$vzid}`.
4. In `app/Vps/Docker.php` add `pauseVps($vzid)` calling `/usr/bin/docker pause {$vzid}`.
5. In `app/Vps/Lxc.php` add `pauseVps($vzid)` calling `/usr/bin/lxc pause {$vzid}`.
6. In `app/Vps.php` add the void-style dispatching facade (see Step 3 template 1), placed below `Vps::stopVps()`.
7. `php -l app/Vps.php app/Vps/*.php` and `make phar`.

**Result**: `Vps::pauseVps($vzid)` works on whichever hypervisor `Vps::getVirtType()` detects.

### Example 2 — KVM-only ISO attach

**User says**: "Add `attachIso($vzid, $iso)` — KVM only."

**Actions taken**:

1. In `app/Vps/Kvm.php` add `attachIso($vzid, $iso)`: `escapeshellarg` both args, run `virsh attach-disk {$vzid} {$iso} hdc --type cdrom --mode readonly`.
2. In `app/Vps.php` add the partial-support facade template — KVM branch returns the backend call; other virt types log `not supported on this platform yet` and `return false`.
3. No edits to Virtuozzo/OpenVz/Docker/Lxc files.
4. `php -l` + `make phar`.

**Result**: Operation succeeds on KVM hosts and surfaces a clear error message elsewhere.

## Common Issues

- **Error: `Class 'App\Vps\Kvm' not found`** — The `use App\Vps\Kvm;` block at the top of `app/Vps.php` (lines 4–11) is complete by default. If you added a new backend, add the `use` line; if not, re-check the namespace at the top of the backend file is `namespace App\Vps;`.
- **`Call to undefined method App\Vps\Lxc::pauseVps()`** at runtime — A facade branch dispatches to a backend method that wasn't added. Either add the method or drop that branch from the facade dispatch.
- **Phar build fails with `Cannot redeclare ...`** — You duplicated a method (likely from copy-pasting). Search `grep -n 'function pauseVps' app/Vps.php app/Vps/*.php` — each file should show exactly one definition.
- **`make phar` runs but operation silently does nothing** — Forgot the `return` keyword in an accessor-style facade, or the if/elseif chain is missing the branch for the active virt type. Confirm with `./provirted.phar test` which prints the detected virt type.
- **Shell injection / `sh: -c: line 0: syntax error` in tests** — Forgot `escapeshellarg()` on a parameter. Every variable interpolated into a `Vps::runCommand()` string MUST be passed through `escapeshellarg()` first.
- **PHP-CS-Fixer rewrites your code** — You added trailing commas in multi-line calls. The project disables `trailing_comma_in_multiline`; remove them.
- **`make internals` is unrelated** — That target regenerates `app/Command/InternalsCommand` from `app/Resources/*.tpl`; it is not required when adding a new operation. Only run it if you also edited the internals templates.