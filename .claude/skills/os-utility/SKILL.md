---
name: os-utility
description: Adds static utility methods to classes in app/Os/ (Os.php, Dhcpd.php, Dhcpd6.php, Xinetd.php) following the project's exact patterns for Vps::runCommand(), Vps::getLogger(), and file I/O. Use when user says 'add OS utility', 'DHCP method', 'xinetd helper', or needs host-level system operations outside the virt dispatch path. Do NOT use for virt-type-specific code (use vps-operation instead) and do NOT use for command implementations in app/Command/.
---
# OS Utility

## Critical

- **PHP 7.4 only.** No `match`, `?->`, `str_contains`, named args, union types, or constructor promotion.
- **Always** wrap every user-supplied `$vzid`, `$ip`, `$mac`, `$password` in `escapeshellarg()` before shell interpolation.
- All methods **must** be `public static function`. No instance state in Os classes.
- Namespace is `App\Os;` with `use App\Vps;` — not `App\Vps\*` unless you need a specific virt class.
- Indentation is **tabs**, braces on the **same line** as the method declaration.
- No trailing commas in multiline arrays or parameter lists.

## Instructions

**Step 1 — Identify the target file.**

- Host detection / RAM / CPU / OS info → `app/Os/Os.php`
- IPv4 DHCP lease management → `app/Os/Dhcpd.php`
- IPv6 DHCP lease management → `app/Os/Dhcpd6.php`
- VNC xinetd proxy management → `app/Os/Xinetd.php`

Read the target file before making any edits.

**Step 2 — Add the method following the exact signature pattern.**

```php
public static function myMethod($param1, $param2 = false) {
    Vps::getLogger()->info('Short description of what this does');
    // body
}
```

- Optional parameters get a default: `$display = false`, `$pct = 95`.
- Return type is implicit (PHP 7.4 style — no return type declarations needed unless already present in the file).

**Step 3 — Run shell commands via `Vps::runCommand()`, log every call.**

```php
// No return code needed:
Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$src} {$dst};"));

// Capture return code:
Vps::getLogger()->write(Vps::runCommand('pidof dhcpd >/dev/null', $return));
return $return == 0;
```

- Pipe the result through `->write()` on every `runCommand()` call.
- Use double-quoted strings with `{$var}` interpolation.
- Escape nested double-quotes with backslash: `"grep -v \"host {$vzid}\" {$file}"`.
- Shell metacharacters (`|`, `>`, `>>`, `;`) pass through unescaped — only **values** need `escapeshellarg()`.

Verify: every `$vzid`, `$ip`, `$mac` coming from a caller is either already escaped upstream or you wrap it here.

**Step 4 — Read / write config files directly.**

```php
$data = file_get_contents(self::getConfFile());
// ... modify $data ...
file_put_contents(self::getConfFile(), $data);
```

- Use `self::getConfFile()` / `self::getFile()` if those helpers exist in the class; otherwise use a bare path string.
- Use `file_exists()`, `touch()`, `unlink()` for existence / creation / deletion — no shell wrappers for these.

**Step 5 — Use the logger for progress reporting.**

```php
Vps::getLogger()->info('Setting up DHCPD');     // section header
Vps::getLogger()->indent();                      // increase indent
Vps::getLogger()->info2('sub-step detail');      // sub-step
Vps::getLogger()->write('raw output'.PHP_EOL);   // raw text / command output
```

**Step 6 — Verify the build still compiles.**

```bash
make dev   # composer update with dev deps — confirms autoload resolves
```

## Examples

**User says:** "Add a method to Dhcpd that removes a host entry by vzid and restarts the service."

**Actions:**
1. Read `app/Os/Dhcpd.php`.
2. Add after the existing `setup()` method:

```php
public static function remove($vzid) {
    $dhcpVps = self::getFile();
    Vps::getLogger()->info('Removing DHCP entry for '.$vzid);
    Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$dhcpVps} {$dhcpVps}.backup;"));
    $vzidEsc = escapeshellarg($vzid);
    Vps::getLogger()->write(Vps::runCommand("grep -v -e \"host {$vzid} \" {$dhcpVps}.backup > {$dhcpVps}"));
    Vps::getLogger()->write(Vps::runCommand("rm -f {$dhcpVps}.backup;"));
    Vps::getLogger()->write(Vps::runCommand('service dhcpd restart 2>/dev/null || /etc/init.d/dhcpd restart 2>/dev/null'));
}
```

3. Run `make dev` to confirm no autoload errors.

**Result:** New static method consistent with `setup()` and `rebuildConf()` patterns already in the file.

## Common Issues

**"Call to undefined method App\\Vps::runCommand()"** — `use App\Vps;` is missing at the top of the file. Add it after `namespace App\Os;`.

**"syntax error, unexpected '->'"** — You used `?->` (nullsafe operator). Replace with `if ($obj !== null) { $obj->method(); }`.

**Command output is silent / not logged** — You called `Vps::runCommand(...)` without wrapping in `Vps::getLogger()->write(...)`. Every `runCommand()` call must be logged.

**File write silently fails** — `file_put_contents()` returns `false` when the directory doesn't exist or permissions are wrong. Check that `/etc/dhcp/` and `/etc/xinetd.d/` are writable by the running user on the target host.

**Variable unexpectedly empty in shell command** — Variable interpolation only works in double-quoted PHP strings. Single-quoted strings like `'rm {$file}'` will pass the literal `{$file}` to the shell.