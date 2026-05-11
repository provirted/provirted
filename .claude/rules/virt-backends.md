---
paths:
  - app/Vps/*.php
  - app/Vps.php
---

# Virt Backend Rules

Applies to `app/Vps.php` (facade) and the 5 backends in `app/Vps/`: `Kvm.php`, `Virtuozzo.php`, `OpenVz.php`, `Lxc.php`, `Docker.php`.

## Method signatures

- All backend methods are `public static function` — no instance state
- Namespace `App\Vps;` with `use App\Vps;` for the facade and `use App\Os\Xinetd;` etc. as needed
- Facade methods in `app/Vps.php` dispatch via `if/elseif` on `self::getVirtType()` — never `switch`

## Binary mapping

| Backend | Binary | Detection |
|---|---|---|
| `Kvm.php` | `/usr/bin/virsh` | `Vps::$virtBins` |
| `Virtuozzo.php` | `/usr/bin/prlctl` | `Vps::$virtBins` |
| `OpenVz.php` | `/usr/sbin/vzctl` | `Vps::$virtBins` |
| `Lxc.php` | `/usr/bin/lxc` | `Vps::$virtBins` |
| `Docker.php` | `/usr/bin/docker` | `Vps::$virtBins` |

## Shell discipline

Every user-supplied value is escaped before interpolation:

```php
public static function fooVps($vzid) {
    $vzid = escapeshellarg($vzid);
    Vps::getLogger()->write(Vps::runCommand("virsh foo {$vzid}"));
}
```

For return-code capture:

```php
Vps::getLogger()->write(Vps::runCommand("...", $return));
if ($return != 0) Vps::getLogger()->error('failed');
```

## Container gate (Lxc/Docker)

Skip these operations for `lxc`/`docker` — they are KVM-only:
- VNC setup via `app/Os/Xinetd.php`
- CD-ROM attach/detach (virsh)
- `virsh dumpxml` editing
- libvirt storage pools, `kpartx`, `qemu-img`

Use `in_array(Vps::getVirtType(), ['docker', 'lxc'])` to gate.

## Parity requirement

When adding a method to `app/Vps.php`, implement it in ALL 5 backends — or return early/empty in those that don't support it. The dispatcher does not tolerate missing branches.
