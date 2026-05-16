# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[gotcha]** `Kvm::addIp()` / `Kvm::removeIp()` only update the libvirt XML — they do NOT touch `dhcpd.vps` unless you explicitly call `Dhcpd::setup()` / `Dhcpd::remove()` after the XML edit. The other backends (`OpenVz`, `Virtuozzo`, `Lxc`, `Docker`) have their own paths; only KVM relies on DHCP and is the one that historically silently skipped the update.
- **[pattern]** To resolve the MAC for a KVM DHCP entry, use `Kvm::getVpsMac($vzid)` (reads from `virsh dumpxml` via `XmlToArray`). `Dhcpd::setup()` already re-resolves it via `Vps::getVpsMac($vzid)` internally, but pass it anyway to satisfy the signature and to fail fast with a clear error when the interface has no `mac_attr`.
- **[pattern]** When auditing whether a change to `Kvm::addIp` / `Kvm::removeIp` is safe, grep for `self::addIp` / `self::removeIp` recursion inside the same backend — `Lxc.php:240` and `Docker.php:457` call their own `self::addIp` from `defineVps`-like paths, but `Kvm.php` does NOT, so adding DHCP side-effects to `Kvm::addIp` won't double-fire during VPS creation (which already calls `Dhcpd::setup()` directly at `Kvm.php:199`).
- **[convention]** `App\Vps::changeIp()` is only implemented for `virtuozzo` and `openvz` — KVM/LXC/Docker fall through to an `error('Changing an IP is not supported on this platform yet.')`. For KVM, the supported flow is `remove-ip` followed by `add-ip`, so both must keep DHCP in sync on their own.
- **[gotcha]** `Dhcpd::getFile()` returns `/etc/dhcp/dhcpd.vps` if it exists, else `/etc/dhcpd.vps` — never hardcode the path. Same dual-location pattern in `Dhcpd::getConfFile()` (`/etc/dhcp/dhcpd.conf` vs `/etc/dhcpd.conf`) and `Dhcpd::getService()` (`isc-dhcp-server` on Debian/Ubuntu via `/etc/apt` detection, else `dhcpd`).
