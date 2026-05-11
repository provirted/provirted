---
paths:
  - app/Os/*.php
---

# OS Utility Rules

Applies to host-level helpers in `app/Os/`: `Os.php`, `Dhcpd.php`, `Dhcpd6.php`, `Xinetd.php`.

## Namespace and use

```php
namespace App\Os;
use App\Vps;
```

All methods `public static function`. No instance state.

## Config-file conventions

- DHCPv4 hosts → `Dhcpd::getFile()` returns `/etc/dhcp/dhcpd.vps` or `/etc/dhcpd.vps`
- DHCPv4 conf → `Dhcpd::getConfFile()` returns `/etc/dhcp/dhcpd.conf` or `/etc/dhcpd.conf`
- DHCPv6 hosts → `Dhcpd6::getFile()` returns `/etc/dhcp/dhcpd6.vps` or `/etc/dhcpd6.vps`
- VNC xinetd entries → `/etc/xinetd.d/{vzid}` and `/etc/xinetd.d/{vzid}-spice`
- DHCP service name → `Dhcpd::getService()` returns `isc-dhcp-server` (Debian/Ubuntu) or `dhcpd` (RHEL)

## Restart pattern

Use triple-fallback for systemctl/service/init.d (see `Dhcpd::restart()`):

```php
Vps::runCommand("systemctl restart {$svc} 2>/dev/null || service {$svc} restart 2>/dev/null || /etc/init.d/{$svc} restart 2>/dev/null");
```

## Xinetd locking

`Xinetd::lock()` touches `/tmp/_securexinetd` before rebuild operations; `Xinetd::unlock()` removes it. Wrap rebuild loops in lock/unlock.

## Host info source

`Vps::getHostInfo()` reads `~/.provirted/host.json`. Rebuild helpers (`Dhcpd::rebuildHosts()`, `Dhcpd6::rebuildConf()`, `Xinetd::rebuild()`) require `$host['vps']` and/or `$host['vlans']`/`$host['vlans6']` — guard with `is_array($host)` checks.

## Shell escaping still required

Even at host level, escape values that originate from VPS config (`$vzid`, `$mac`, `$ip`) before shell interpolation. Use `Vps::runCommand()`, not backticks.
