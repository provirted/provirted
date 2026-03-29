---
name: vps-operation
description: Adds a new virt-specific operation to app/Vps.php facade and matching static methods in all five backends (Kvm, Virtuozzo, OpenVz, Lxc, Docker). Use when user says 'add operation', 'implement X for all virt types', 'add a lifecycle method', or extending VPS management. Do NOT use for adding CLI commands (see command-structure skill) or OS-level utilities in app/Os/.
---
# vps-operation

## Critical

- PHP 7.4 only — no `match`, `?->`, named args, union types, `str_contains/starts_with/ends_with`
- **Always** `escapeshellarg()` every `$vzid`, `$ip`, `$password`, or user-supplied value before any shell string
- Indentation: **tabs**. Opening brace on same line. No trailing commas in arrays/params
- VNC, CD-ROM, kpartx, and Xinetd calls are **KVM-only** — gate with `in_array(Vps::getVirtType(), ['docker', 'lxc'])` or skip in those backends
- Never call `exec()` or `shell_exec()` — use `Vps::runCommand($cmd, $return)` exclusively

## Instructions

### Step 1 — Add the facade method in `app/Vps.php`

Insert a `public static` method following the if/elseif chain pattern. Use `self::getVirtType()` for dispatch. Log with `self::getLogger()->info()`.

```php
public static function fooVps($vzid) {
    self::getLogger()->info('Doing foo on the VPS');
    if (self::getVirtType() == 'kvm')
        Kvm::fooVps($vzid);
    elseif (self::getVirtType() == 'virtuozzo')
        Virtuozzo::fooVps($vzid);
    elseif (self::getVirtType() == 'openvz')
        OpenVz::fooVps($vzid);
    elseif (self::getVirtType() == 'docker')
        Docker::fooVps($vzid);
    elseif (self::getVirtType() == 'lxc')
        Lxc::fooVps($vzid);
}
```

If the operation is KVM-only (VNC, snapshots, CD), gate docker/lxc:
```php
    elseif (self::getVirtType() == 'docker' || self::getVirtType() == 'lxc')
        return '';
```

Verify: `Vps.php` has `use App\Vps\Docker;`, `use App\Vps\Kvm;`, `use App\Vps\Lxc;`, `use App\Vps\OpenVz;`, `use App\Vps\Virtuozzo;` at top.

### Step 2 — Implement in `app/Vps/Kvm.php`

Namespace: `App\Vps`. Add `use App\Vps;` if not present. Use `virsh` binary.

```php
public static function fooVps($vzid) {
    Vps::getLogger()->info('Doing foo (KVM)');
    $vzid = escapeshellarg($vzid);
    Vps::getLogger()->write(Vps::runCommand("virsh foo {$vzid}"));
}
```

For commands that check success, capture return code:
```php
Vps::getLogger()->write(Vps::runCommand("virsh foo {$vzid}", $return));
if ($return != 0)
    Vps::getLogger()->error('foo failed for '.$vzid);
```

### Step 3 — Implement in `app/Vps/Virtuozzo.php`

Use `prlctl` binary.

```php
public static function fooVps($vzid) {
    Vps::getLogger()->info('Doing foo (Virtuozzo)');
    $vzid = escapeshellarg($vzid);
    Vps::getLogger()->write(Vps::runCommand("prlctl foo {$vzid}"));
}
```

### Step 4 — Implement in `app/Vps/OpenVz.php`

Use `vzctl` binary.

```php
public static function fooVps($vzid) {
    Vps::getLogger()->info('Doing foo (OpenVZ)');
    $vzid = escapeshellarg($vzid);
    Vps::getLogger()->write(Vps::runCommand("vzctl foo {$vzid}"));
}
```

### Step 5 — Implement in `app/Vps/Docker.php`

Use `docker` binary. Skip VNC/kpartx/XML. Use `$escaped` variable name when re-using `$vzid` after escaping.

```php
public static function fooVps($vzid) {
    Vps::getLogger()->info('Doing foo (Docker)');
    $escaped = escapeshellarg($vzid);
    Vps::getLogger()->write(Vps::runCommand("docker foo {$escaped}"));
}
```

### Step 6 — Implement in `app/Vps/Lxc.php`

Use `lxc` binary. Same container-type restrictions as Docker.

```php
public static function fooVps($vzid) {
    Vps::getLogger()->info('Doing foo (LXC)');
    $escaped = escapeshellarg($vzid);
    Vps::getLogger()->write(Vps::runCommand("lxc foo {$escaped}"));
}
```

### Step 7 — If the operation has a `$fast` or extra param, propagate it everywhere

Match the signature exactly across all 6 locations (facade + 5 backends). Example: `stopVps($vzid, $fast = false)` appears in all 5 backends with the same default.

Verify: grep for the method name across `app/Vps.php` and all `app/Vps/*.php` to confirm all 6 occurrences exist.

## Examples

**User says**: "Add a `suspendVps` operation that pauses execution on all virt types"

**Actions taken**:

1. `app/Vps.php` — add facade:
```php
public static function suspendVps($vzid) {
    self::getLogger()->info('Suspending the VPS');
    if (self::getVirtType() == 'kvm')
        Kvm::suspendVps($vzid);
    elseif (self::getVirtType() == 'virtuozzo')
        Virtuozzo::suspendVps($vzid);
    elseif (self::getVirtType() == 'openvz')
        OpenVz::suspendVps($vzid);
    elseif (self::getVirtType() == 'docker')
        Docker::suspendVps($vzid);
    elseif (self::getVirtType() == 'lxc')
        Lxc::suspendVps($vzid);
}
```

2. `app/Vps/Kvm.php`:
```php
public static function suspendVps($vzid) {
    Vps::getLogger()->info('Suspending the VPS');
    $vzid = escapeshellarg($vzid);
    Vps::getLogger()->write(Vps::runCommand("virsh suspend {$vzid}"));
}
```

3. `app/Vps/Virtuozzo.php`: `prlctl suspend {$vzid}`
4. `app/Vps/OpenVz.php`: `vzctl stop {$vzid}` (no native pause — log a note)
5. `app/Vps/Docker.php`: `docker pause {$escaped}`
6. `app/Vps/Lxc.php`: `lxc pause {$escaped}`

**Result**: `Vps::suspendVps($vzid)` dispatches correctly across all backends.

## Common Issues

- **`Call to undefined method App\Vps\Docker::fooVps()`**: You added the facade dispatch in `Vps.php` but forgot to add the method in one of the backends. Grep: `grep -rn 'fooVps' app/Vps/`
- **Shell injection / unexpected behavior**: You interpolated `$vzid` directly without `escapeshellarg()`. Every `$vzid` that touches a shell string must be wrapped — even if the caller already escaped it, the backend method must escape its own copy.
- **KVM-only method called on LXC/Docker**: If your method touches Xinetd, kpartx, or virsh XML, add the gate in the facade: `elseif (self::getVirtType() == 'docker' || self::getVirtType() == 'lxc') return;`
- **Trailing comma in method params causes parse error on PHP 7.4**: Remove trailing commas — `function foo($a, $b,)` is PHP 8+ only.
- **Method not found after `make phar`**: The phar is built from the current source tree. Run `make` again after adding the new methods before testing.