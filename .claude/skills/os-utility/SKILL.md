---
name: os-utility
description: Adds static utility methods to host-level service classes in app/Os/ (Os.php, Dhcpd.php, Dhcpd6.php, Xinetd.php). Use when user says 'add OS utility', 'DHCP helper', 'xinetd helper', 'host-level helper', adds isRunning/restart/setup/remove/rebuild methods to /etc/dhcp/ or /etc/xinetd.d/ management, or needs detection helpers for distro/RAM/CPU/IP. Capabilities: static-only method skeletons, Vps::runCommand() with $return exit codes, Vps::getLogger()->write()/->info() output, Debian-vs-RedHat path branching via file_exists('/etc/apt'), service restart fallback chain (systemctl||service||init.d), file_put_contents() to /etc/dhcp/ and /etc/xinetd.d/. Do NOT use for virt-type-specific operations (use vps-operation skill for app/Vps/*.php backends), CLI command implementations (use add-command), or anything that dispatches through Vps::getVirtType().
paths:
  - app/Os/**/*.php
---
# os-utility

Adds static methods to host-level service classes in `app/Os/`. These classes manage host operating-system concerns (DHCP config, xinetd VNC proxy entries, distro detection) that live **outside** the virt-type dispatch (`Kvm/OpenVz/Virtuozzo/Lxc/Docker`). Every method is `public static` — never instantiate.

## Critical

- **PHP 7.4 only.** No `match`, no nullsafe `?->`, no union types, no enums, no `str_contains`/`str_starts_with`/`str_ends_with`, no named arguments, no constructor promotion, no readonly. Use polyfills already in `composer.json` if needed.
- **Tab indentation, same-line braces, no trailing commas.** PHP-CS-Fixer is `@PSR2` + `@PHP74Migration` with `trailing_comma_in_multiline` disabled (`.php-cs-fixer.dist.php`).
- **Never use `exec()`, `shell_exec()`, or backticks.** Use `Vps::runCommand($cmd)` or `Vps::runCommand($cmd, $return)` (the second arg captures the exit status by reference).
- **All output goes through the logger.** Write with `Vps::getLogger()->write(...)`; status lines with `Vps::getLogger()->info(...)`. Never `echo` or `print`.
- **Always `escapeshellarg()`** any `$vzid`, `$ip`, `$mac`, `$password`, or user-supplied string before shell interpolation in `runCommand()`.
- **Distro branching.** When a path differs between Debian and RedHat, pick with `file_exists('/etc/apt')` (Debian) or `file_exists('/etc/redhat-release')` (RedHat). For dhcpd config files use the `file_exists('/etc/dhcp/...') ? '/etc/dhcp/...' : '/etc/...'` pattern.
- **No new files unless the topic is new.** DHCP → `Dhcpd.php`/`Dhcpd6.php`. xinetd → `Xinetd.php`. Distro/host detection → `Os.php`. Only create a new class in `app/Os/` for a genuinely new host service.

## Instructions

1. **Pick the target class.** Open the file under `app/Os/`:
   - DHCPv4 hosts/conf → `app/Os/Dhcpd.php`
   - DHCPv6 hosts/conf → `app/Os/Dhcpd6.php`
   - xinetd VNC/spice proxy entries in `/etc/xinetd.d/` → `app/Os/Xinetd.php`
   - Host detection (IP, RAM, CPU, distro) → `app/Os/Os.php`
   - Anything else: create `app/Os/{Name}.php` with `namespace App\Os;` and `use App\Vps;`.
   Verify the file exists before editing. If creating new, also confirm no existing class already covers the concern.

2. **Add the method with the canonical header.** Every method is `public static`, preceded by a docblock with `@param` and `@return`. Example skeleton (drop into the chosen class):

   ```php
   /**
   * one-line description
   * @param string $vzid
   * @return bool indicates success
   */
   	public static function methodName($vzid) {
   		// body
   	}
   ```
   Use tabs. Verify the docblock lists every parameter before proceeding.

3. **Pick the right execution primitive.** Match one of these patterns exactly:

   - **Run + log + exit code** (e.g. `isRunning`):
     ```php
     Vps::getLogger()->write(Vps::runCommand('pidof xinetd >/dev/null', $return));
     return $return == 0;
     ```
   - **Run + log output only**:
     ```php
     Vps::getLogger()->write(Vps::runCommand("systemctl restart {$svc} 2>/dev/null || service {$svc} restart 2>/dev/null || /etc/init.d/{$svc} restart 2>/dev/null"));
     ```
     Always use this 3-way fallback chain for service restart — see `Dhcpd::restart()` and `Xinetd::restart()`.
   - **Capture command output as a value** (e.g. `getIp`, `getRedhatVersion`):
     ```php
     return trim(Vps::runCommand("ifconfig {$defaultRoute} | grep inet | grep -v inet6 | awk '{ print $2 }' | cut -d: -f2"));
     ```
     Wrap in `trim()`/`floatval()`/`intval()` as appropriate. Do NOT log this — the caller may parse it.
   - **Pure file read** (e.g. `getTotalRam`): use `file_get_contents()` + `preg_match()` directly, no shell.
   - **Pure file write** (e.g. `Xinetd::setup`): build the file contents in a heredoc-style PHP string, then `file_put_contents('/etc/xinetd.d/'.$vzid, $template);`. Never shell out to write config.

4. **For DHCP-style mutations follow the backup/grep-out/append/cleanup sequence.** Copy this verbatim from `Dhcpd::setup()` — do not invent a shorter version:

   ```php
   $dhcpVps = self::getFile();
   Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$dhcpVps} {$dhcpVps}.backup;"));
   Vps::getLogger()->write(Vps::runCommand("grep -v -e \"host {$vzid} \" -e \"fixed-address {$ip};\" {$dhcpVps}.backup > {$dhcpVps}"));
   Vps::getLogger()->write(Vps::runCommand("echo \"host {$vzid} { hardware ethernet {$mac}; fixed-address {$ip}; }\" >> {$dhcpVps}"));
   Vps::getLogger()->write(Vps::runCommand("rm -f {$dhcpVps}.backup;"));
   self::restart();
   ```
   Then verify your method ends with `self::restart()` so the daemon picks up the change.

5. **For xinetd-style mutations write the template directly.** Build the service stanza in PHP, then `file_put_contents('/etc/xinetd.d/'.$vzid, $template);`. The template must include `type = UNLISTED`, `disable = no`, `socket_type = stream`, `wait = no`, `user = nobody`, `redirect = 127.0.0.1 {port}`, `bind = {hostIp}`, `only_from = ... 66.45.240.196 192.64.80.216/29`, `port = {port}`, `nice = 10` (see `Xinetd::setup()`). For removal use `unlink('/etc/xinetd.d/'.$vzid)` gated by `file_exists()`.

6. **For host-info-driven rebuilds bail out early on bad data.** When iterating `Vps::getHostInfo()`, copy this guard from `Dhcpd::rebuildConf()`:

   ```php
   $host = Vps::getHostInfo();
   if (!is_array($host) || !isset($host['vlans'])) {
   	Vps::getLogger()->write('There appears to have been a problem with the host info, perhaps try again?'.PHP_EOL);
   	return false;
   }
   ```
   Methods that rebuild a config file should accept `$display = false` so callers can preview output; when `$display === true`, log `cat > {file} <<EOF\n{contents}\nEOF` instead of writing.

7. **For path/service-name lookups expose a `getFile`/`getConfFile`/`getService` helper.** Always use the `file_exists()` ternary pattern from `Dhcpd::getFile()`:

   ```php
   return file_exists('/etc/dhcp/dhcpd.vps') ? '/etc/dhcp/dhcpd.vps' : '/etc/dhcpd.vps';
   ```
   Service-name picker (Debian vs RedHat):
   ```php
   return file_exists('/etc/apt') ? 'isc-dhcp-server' : 'dhcpd';
   ```
   Call these from your method via `self::getFile()` / `self::getService()` — never hardcode the path in the consumer method.

8. **Run the style fixer and verify before finishing.** From the project root:
   ```bash
   vendor/bin/php-cs-fixer fix app/Os/{File}.php --dry-run --diff
   php -l app/Os/{File}.php
   ```
   Verify both commands return clean (no diff, `No syntax errors`) before claiming the method is done. If `vendor/bin/php-cs-fixer` is missing, run `make dev` first.

## Examples

**User says:** "Add a method to Dhcpd that returns whether a given vzid already has a host entry."

**Actions taken:**
1. Open `app/Os/Dhcpd.php`.
2. Reuse `self::getFile()` for the path and `self::getHosts()` (already returns the parsed array).
3. Add this method (tabs, public static, docblock):
   ```php
   	/**
   	* checks whether a vzid already has a dhcp host entry
   	* @param string $vzid
   	* @return bool
   	*/
   	public static function hasHost($vzid) {
   		$hosts = self::getHosts();
   		return array_key_exists($vzid, $hosts);
   	}
   ```
4. Run `php -l app/Os/Dhcpd.php` and `vendor/bin/php-cs-fixer fix app/Os/Dhcpd.php --dry-run --diff`.

**Result:** Single new static method, no instantiation, no shell call needed because the helper already exists. Matches the style of every other method in the file.

---

**User says:** "Add an Xinetd helper that returns the port a given service name is bound to, or 0 if none."

**Actions taken:**
1. Open `app/Os/Xinetd.php`.
2. Reuse `self::parseEntries()` rather than re-parsing.
3. Add:
   ```php
   	/**
   	* gets the port a service is configured for
   	* @param string $serviceName
   	* @return int port, or 0 if not configured
   	*/
   	public static function getPort($serviceName) {
   		$services = self::parseEntries();
   		if (!isset($services[$serviceName]) || !isset($services[$serviceName]['port']))
   			return 0;
   		return intval($services[$serviceName]['port']);
   	}
   ```
4. Lint + style check as above.

## Common Issues

- **"Class 'App\\Os\\Foo' not found" at runtime.** PSR-4 autoload maps `App\\` → `app/`. Verify the file lives at `app/Os/Foo.php`, the class is exactly `Foo` (PascalCase), and the namespace line is `namespace App\Os;`. If still failing, run `composer dump-autoload` (or rebuild the phar with `make phar`).

- **`$return` is always 0 even when the command failed.** You called `Vps::runCommand($cmd)` instead of `Vps::runCommand($cmd, $return)`. The second parameter must be passed by reference — declare `$return` on the caller side (PHP will create it). See `Dhcpd::isRunning()`.

- **Output goes to STDOUT instead of the logger.** You used `echo`, `print`, or `var_dump`. Replace with `Vps::getLogger()->write(...)` (for command output) or `Vps::getLogger()->info(...)` (for status lines). Bash completion and JSON output modes break if anything writes outside the logger.

- **"Parse error: syntax error, unexpected '?->' " or similar.** You used PHP 8+ syntax. The platform is locked to `7.4.33` in `composer.json` — rewrite as `isset($x->y) ? $x->y : null` or equivalent.

- **"sh: 1: systemctl: not found" on older containers.** You only called `systemctl restart {$svc}`. Always use the full fallback chain: `systemctl restart {$svc} 2>/dev/null || service {$svc} restart 2>/dev/null || /etc/init.d/{$svc} restart 2>/dev/null`. Copy from `Dhcpd::restart()` verbatim.

- **Config file written but daemon ignores it.** You forgot `self::restart()` at the end of the mutation method. Every method that edits `/etc/dhcp/*` or `/etc/xinetd.d/*` must call its class's `restart()` before returning (unless the caller is doing a bulk rebuild and will restart once at the end).

- **`file_put_contents()` returns false / permission denied.** The phar runs as root in production; if you are testing locally, `sudo` the test invocation. Do NOT add a `chmod`/`chown` workaround inside the helper — the production environment is privileged.

- **PHP-CS-Fixer keeps re-adding trailing commas.** `trailing_comma_in_multiline` is **disabled** in `.php-cs-fixer.dist.php`. If you see it being added, you are running a stale config — re-check `vendor/bin/php-cs-fixer` is using the project's `.php-cs-fixer.dist.php` (run from the project root).