# ProVirted Improvement Plan

A working list of improvements identified during the codebase review.

- **[applied]** — implemented (see § 9 for the full list of what shipped)
- **[applied partial]** — core cases done, edge cases or long-tail callers remain
- **[deferred-big]** — architectural / risk-bearing items intentionally not done in this pass
- **❌** — still outstanding

---

## 1. Bugs (correctness)

- **1.1 `OpenVz::changeIp` / `Virtuozzo::changeIp` off-by-one loop** — `[applied]`
- **1.2 KVM has no `changeIp`** — `[applied]` Kvm/Lxc/Docker all now have `changeIp` that sequences removeIp+addIp, and `Vps::changeIp` dispatches to every backend. `ChangeIpCommand` returns exit 1 on failure.
- **1.3 `OpenVz::removeIp` used `$ips[$ip]` (always undefined)** — `[applied]`
- **1.4 `OpenVz::defineVps` undefined `$vps_os` / `$ssh_key`** — `[applied]`
- **1.5 `OpenVz::defineVps` `--reset_ub` may not exist on modern vzctl** — `[applied]`
- **1.6 `$passsword` typo in Virtuozzo + OpenVz `defineVps`** — `[applied]`
- **1.7 `Kvm::installTemplate` swallows install failures** — `[applied]` Now logs an explicit error and returns the bool. `installTemplateV2` checks exit codes on dumpxml/define and bails with error log.
- **1.8 `Kvm::stopVps` `$stopped` flow cosmetic** — ❌ cosmetic-only; left as-is
- **1.9 `Vps::getVirtType` short-circuit bug (returns first installed)** — ❌ medium; pre-existing, would need redesign for multi-virt hosts
- **1.10 `Kvm::getVpsIps` crashes if domain has no interface** — `[applied]`
- **1.11 `Kvm::getVps` returns garbage if `virsh dumpxml` fails** — `[applied]`
- **1.12 `Kvm::getVncPort` returns string noise on failure** — `[applied]`
- **1.13 `Os::getCpuCount` no-match guard** — `[applied]`
- **1.14 `Os::getTotalRam` unreadable-file guard** — `[applied]`
- **1.15 `Os::getIp` no-default-route guard** — `[applied]`

## 2. Security

- **2.1 `Kvm.php` shell escaping** — `[applied partial]` Critical lifecycle paths + `defineVps` + `installTemplateV2` + `setupStorage` + `setupRouting` + `runBuildEbtables` now escape. Remaining unescaped: `installTemplateV1` (huge legacy method with kpartx/fdisk pipelines), `getPoolType` (hardcoded queries).
- **2.2 `OpenVz.php` / `Virtuozzo.php` $vzid/$password unescaped** — `[applied partial]` `defineVps` fully escaped in both. Remaining: `setupCpanel`, `setupWebuzo`, `blockSmtp` (long yum chains).
- **2.3 `> {$vzid}.xml` to CWD** — `[applied]` `Kvm::addIp` / `removeIp` / `defineVps` / `installTemplateV2` all use `tempnam(sys_get_temp_dir(), 'provirted-kvm-...')`. No remaining CWD-write paths in the modified methods.
- **2.4 `Os::getIp` device-name validation** — `[applied]`
- **2.5 `Dhcpd::setup` escaping** — `[applied]`
- **2.6 `Dhcpd::remove` vzid regex validation** — `[applied]`
- **2.7 `Xinetd::setup` vzid + port validation** — `[applied]`
- **2.8 URL injection in `Vps::lock`/`unlock`/`getHostInfo`** — `[applied]`

## 3. Error handling

- **3.1 Return-code capture on `runCommand`** — `[applied partial]` Lifecycle methods + every DHCP/Xinetd write + OS detection + `defineVps` headers + `installTemplate` outcome propagation. Still uncovered: `setupCpanel`/`setupWebuzo` exec chains (~30 calls each); `installTemplateV1` body.
- **3.2 `json_decode` results unchecked** — `[applied]`
- **3.3 `file_put_contents` / `file_get_contents` unchecked** — `[applied]` (Dhcpd, Dhcpd6, Xinetd, Vps::getHostInfo cache write, Console history append)
- **3.4 `Dhcpd::isRunning` distinguishes binary-missing** — `[applied]`
- **3.5 No verification of helper-script existence** — `[applied]` `Vps::requireScript($relPath)` helper; used in `Kvm::runBuildEbtables`, `Kvm::setupRouting`, `Kvm::setupStorage`, `Lxc::setupRouting`, `Docker::setupRouting`. CLAUDE.md now documents the script contract.
- **3.6 `Vps::getHostInfo` network failure handling** — `[applied]` curl timeout, error with body, cache fallback with mtime in min/hr/day.
- **3.7 `Vps::getVpsMac` dereference guard** — `[applied]`
- **3.8 Xinetd lock race** — ❌ medium; would need `flock()` instead of `touch`.

## 4. Architecture / Refactor

- **4.1 Dispatcher repetition in `app/Vps.php`** — `[deferred-big]` Skipped per user direction.
- **4.2 `defineVps` 18+ args** — `[deferred-big]` Skipped per user direction.
- **4.3 No `VirtBackend` interface** — ❌ medium; would help enforce parity but cosmetic vs the rule doc.
- **4.4 OpenVz/Virtuozzo `setupCpanel` as scripts** — ❌ medium; would require shipping `/vz/templates/*` script files. Skipped because those scripts are outside this repo.
- **4.5 `runCommand` `&$return = 0` default** — ❌ medium; replacing with a `CommandResult` object would change every caller signature. Risky without test coverage.
- **4.6 `Logger::__call` magic dispatch** — `[applied]` Explicit `critical/warn/info/info2/debug/debug2` methods added; magic `__call` kept as backwards-compatible fallback.
- **4.7 Static state on `Vps`** — `[deferred-big]` Skipped per user direction.

## 5. Code Quality / Style

- **5.1 Typos** — `[applied]` "addresss", "Softwawre", "informatoin", "hsitory", "determins", "alreaday" (in OpenVz/Virtuozzo changeIp + Virtuozzo addIp/removeIp), "an ish" (4 places), "exit"→"exist" in OpenVz/Virtuozzo addIp/removeIp.
- **5.2 Dead commented-out code in `Kvm::defineVps`** — `[applied]` Cleaned up during the `defineVps` rewrite (tempnam refactor).
- **5.3 `Vps::$virtInstalled = false` cache key** — ❌ cosmetic
- **5.4 `Console::runWithTry` exception surfacing** — `[applied]` `provirted.php` now propagates the return value: `runWithTry` returning false (exception caught) → exit 1; commands returning an int → that int becomes the process exit code; otherwise 0. `Console::finish` now logs to STDERR if the history file can't be written.
- **5.6 `make phar` uncompressed** — `[document]` already in CLAUDE.md
- **5.7 PHP 7.4 pin** — `[ok]`

## 6. Documentation

- **6.1 Docblocks for Kvm/OpenVz/Virtuozzo** — `[applied partial]` Class-level docblocks added for OpenVz + Virtuozzo. Kvm public-method docblocks added on `getRunningVps`, `getAllVps`, `vpsExists`, `getPool`, `getPoolType`, `removeStorage`, `enableAutostart`, `disableAutostart`, `startVps`, `resetVps`, `stopVps`, `destroyVps`, `changeIp`. The remaining undocumented methods (`installTemplate*`, `installImage`, `setupCgroups`, etc.) are next pass.
- **6.2 No `COMMANDS.md`** — ❌ medium
- **6.3 `~/.provirted/docker.json` schema** — `[applied]` Now in CLAUDE.md (Runtime data section).
- **NEW: External helper scripts table + verbose-level table** — `[applied]` Added to CLAUDE.md.

## 7. Testing

- **7.1 No automated tests** — `[deferred-big]` Skipped per user direction.
- **7.2 `Docker::ipInCidr` edge case coverage** — ❌ medium (depends on 7.1)
- **7.3 `--dry-run` flag for destructive commands** — ❌ medium

## 8. Operational / UX

- **8.1 External `/root/cpaneldirect` scripts not versioned** — `[applied]` Documented in CLAUDE.md with a contract table, and `Vps::requireScript` enforces a clear failure mode when missing.
- **8.2 Verbose mode level mapping** — `[applied]` Documented in CLAUDE.md.
- **8.3 No `--json` output mode** — ❌ medium
- **8.4 `--no-log` session-wide** — `[applied]` `Vps::init` now honors `--no-log` on the active command AND the `PROVIRTED_NO_LOG=1` environment variable. Cron scripts can set the env var once and skip per-command flags.
- **8.5 `add-ip` re-run safety** — `[applied partial]` add/remove now use temp files and return failure cleanly. Full idempotent retry would need state tracking. — ❌ medium for the remainder.

---

## 9. Items applied in this pass (cumulative)

### Vps facade (`app/Vps.php`)
- `getHostInfo` — curl timeout, error log with HTTP body, cache fallback with mtime display in min/hr/day, file_put_contents failure detection.
- `lock` / `unlock` — `urlencode($vzid)`, curl timeout, return bool, error log.
- `addIp` / `removeIp` / `changeIp` — propagate backend return values; `changeIp` dispatches to all 5 backends now.
- `setupVnc` — guard against `Virtuozzo::getVps` returning false; unlocks xinetd on the error path.
- `requireScript($relPath)` — new helper for verifying external helper scripts exist before invocation.
- `init` — honors `--no-log` and `PROVIRTED_NO_LOG=1` env var to disable history session-wide.
- Docblock typo fixes.

### KVM (`app/Vps/Kvm.php`)
- All listing methods (`getRunningVps`, `getAllVps`) — return-code check + empty-list guard + docblocks.
- `vpsExists`, `getVpsXml`, `getVps`, `getVpsMac`, `getVpsIps` — escape vzid, return-code check, undefined-index guards.
- `addIp` / `removeIp` — IPv4 validation, `tempnam`, return-code check per sub-step, propagates Dhcpd::setup/remove result, returns false on partial DHCP failure.
- `removeIp` — pipeline split into two `runCommand` calls so virsh exit code is captured independently of grep.
- `changeIp` — NEW; sequences removeIp + addIp with clear error messages on partial failure.
- `defineVps` — full rewrite: tempnam-based XML file, escape vzid, file_exists guard on the windows.xml template, exit-code checks on virsh destroy/undefine/define, dead commented-out code removed.
- `installTemplate` — explicit error log + return propagation.
- `installTemplateV2` — tempnam-based XML file, escape vzid, intval on numeric limits, exit-code checks.
- `startVps` / `stopVps` / `resetVps` / `destroyVps` / `enableAutostart` / `disableAutostart` — escape, return-code check, docblocks.
- `getVncPort` — escapes vzid, returns 0 on failure with error log.
- `destroyVps` — aborts before removeStorage/Dhcpd::remove if virsh undefine fails (avoids orphaning resources).
- `runBuildEbtables` / `setupRouting` / `setupStorage` — use `Vps::requireScript()` for `/root/cpaneldirect/*.sh`, return-code checks.
- `setupStorage` (zfs) — bounded wait loop (30s) before declaring `/vz/{vzid}` ready.

### Virtuozzo (`app/Vps/Virtuozzo.php`)
- Class-level docblock added.
- `getRunningVps` / `getAllVps` / `getList` / `getVps` — return-code check, JSON validation.
- `vpsExists` — `escapeshellarg`, kept write-wrapper.
- `getVpsIps` — guard `false` return and missing `venet0`, skip empty IPs.
- `getVpsRemotes` — fixed loop-variable shadowing (`$vpsEntry` instead of `$vps`), escape vzid for `prlctl set --vnc-mode`, error log on failure.
- `addIp` / `removeIp` — IPv4 validation, escape, return-code check, `info()` not `error()` for "Adding IP" message, fixed "exit" → "exist" typo.
- `changeIp` — off-by-one fixed, IPv4 validation, escape vzid + both IPs, return-code check per sub-step.
- `defineVps` — escape vzid/template/password/hostname/IP/extraIps, intval on cpu/hd/iolimit, error log on `prlctl create` failure. Removed `$passsword` typo; password now escaped via `escapeshellarg`.
- `startVps` / `stopVps` / `destroyVps` / `enableAutostart` / `disableAutostart` — escape, return-code check.
- "an ish" typo fix.

### OpenVz (`app/Vps/OpenVz.php`)
- Class-level docblock added.
- `getRunningVps` / `getAllVps` / `getList` / `getVps` — return-code check, JSON validation.
- `vpsExists` — fixed array-index crash; now uses `$parts` and `isset($parts[2])`.
- `getVpsIps` — guard `false` return.
- `addIp` / `removeIp` — IPv4 validation, escape, return-code check; `$ips[$ip]` lookup bug fixed.
- `changeIp` — off-by-one fixed, IPv4 validation, escape vzid + both IPs.
- `defineVps` — replaced undefined `$vps_os` with `$template`, removed `$passsword` typo, escape vzid/template/password/hostname/IP/extraIps, `--reset_ub` version-gated, ssh-key block commented out (the var was never injected), file_get_contents/file_put_contents error-checked. "an ish" → "known race".
- `startVps` / `stopVps` / `destroyVps` / `enableAutostart` / `disableAutostart` — escape, return-code check.

### LXC (`app/Vps/Lxc.php`)
- `addIp` / `removeIp` — IPv4-only validation, escape vzid + ip + device name in inner loop, return-code check, removed dead `$json` capture.
- `changeIp` — NEW; sequences removeIp + addIp.
- `startVps` / `resetVps` / `destroyVps` — return-code check.
- `setupRouting` — uses `Vps::requireScript('tclimit')`.

### Docker (`app/Vps/Docker.php`)
- `addIp` / `removeIp` — IP validation, escape vzid + ip + network, return-code check, guard `Networks` array access.
- `changeIp` — NEW; sequences removeIp + addIp.
- `getVpsMac` / `getVpsIps` — guard `NetworkSettings.Networks` array access.
- `startVps` / `resetVps` / `destroyVps` — return-code check.
- `setupRouting` — uses `Vps::requireScript('tclimit')`.

### OS utilities (`app/Os/`)
- `Dhcpd::getHosts` — file_exists + file_get_contents error checks.
- `Dhcpd::setup` — vzid regex validation, IPv4 validation, MAC regex validation, directory-writable check, escape backup path properly, per-step error check, rollback from `.backup` on grep failure, returns bool.
- `Dhcpd::remove` — vzid regex validation, file_exists check, escape, returns bool.
- `Dhcpd::restart` — escape service name, return-code check, returns bool.
- `Dhcpd::isRunning` — `command -v dhcpd` check before `pidof`.
- `Dhcpd::rebuildHosts` / `rebuildConf` — fixed dry-run double-write bug; file_put_contents error check.
- `Dhcpd6::*` — same set of changes.
- `Xinetd::setup` — vzid regex + port range validation, file_put_contents error check, returns bool.
- `Os::getIp` — validate default-route device name, validate result is a valid IP.
- `Os::getCpuCount` — return-code check on lscpu, guard preg_match no-match.
- `Os::getTotalRam` — file_get_contents error check, guard preg_match no-match.

### Logger (`app/Logger.php`)
- `addHistory` — now respects `disableHistory()` toggle.
- Explicit `critical/warn/info/info2/debug/debug2` methods added; magic `__call` kept as backwards-compatible fallback.
- Docblock typos fixed.

### Console / entrypoint
- `Console::finish` — STDERR warning if history append fails.
- `provirted.php` — exit code propagation: false → exit 1, int → that int, else → exit 0.

### Commands
- `AddIpCommand` / `RemoveIpCommand` / `ChangeIpCommand` — exit code now reflects success/failure.

### Docs
- CLAUDE.md gained: `docker.json` schema, external-helper-script contract table, verbose-level mapping table.

---

## What remains (in order of value-per-effort)

1. **2.1/2.2/3.1 (continued)** — Escape + return-code coverage in `setupCpanel`/`setupWebuzo` exec chains (OpenVz + Virtuozzo). Mechanical, ~30 calls per file, high-value because those chains silently fail today.
2. **6.1 (continued)** — Docblocks on remaining bare methods in `Kvm::installTemplateV1`, `installImage`, `installGzImage`, `setupCgroups`, etc.
3. **3.8 `Xinetd::lock()` via `flock()`** — concurrent-run safety on host.
4. **6.2 Auto-generated COMMANDS.md** — `make commands` reading from CLIFramework registry.
5. **7.3 `--dry-run` flag for destructive commands** — useful for `destroy`/`remove-ip`/`delete`.
6. **8.3 `--json` output mode** — for wrapper scripts.
7. **4.3 `VirtBackend` interface** — codify the parity rule.

The **[deferred-big]** items (4.1 dispatcher refactor, 4.2 defineVps value object, 4.7 static-state removal, 7.1 add tests) were skipped per user direction. They are worth discussing before any one lands.
