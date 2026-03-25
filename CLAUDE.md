# ProVirted

Unified CLI tool for managing VPS across KVM, OpenVZ, Virtuozzo, LXC, and Docker.

## Build

```bash
make              # composer update --no-dev + build phar
make dev          # composer update with dev deps
make phar         # build provirted.phar (no compression - breaks pvdisplay)
make install      # symlink phar to /usr/local/bin and install bash completion
make copy         # copy phar to ../vps_host_server and push
```

The phar is built with: `php provirted.php archive --composer=composer.json --app-bootstrap --executable --no-compress provirted.phar`

## PHP Version

Target is **PHP 7.4**. Do not use PHP 8+ syntax (named arguments, match expressions, nullsafe operator `?->`, union types, enums, `str_contains()`, `str_starts_with()`, `str_ends_with()`, readonly properties, fibers, constructor promotion). The `composer.json` platform is locked to 7.4.33 to ensure dependency resolution stays compatible.

## Code Style

- **Indentation**: Tabs
- **Brace style**: Opening brace on same line as class/method declaration
- **Naming**: PascalCase classes, camelCase methods, `$camelCase` variables
- **PHP-CS-Fixer**: `@PSR2` + `@PHP74Migration` rules (see `.php-cs-fixer.dist.php`)
- No trailing commas in multiline arrays/params

## Architecture

### Entry point

`provirted.php` loads composer autoload, instantiates `App\Console` (extends CLIFramework\Application), runs with argv.

### Virtualization dispatch pattern

`App\Vps` is the central facade. All operations dispatch to type-specific classes via if/elseif on `Vps::getVirtType()`:

```
Vps::startVps($vzid)
  -> Kvm::startVps($vzid)       # /usr/bin/virsh
  -> Virtuozzo::startVps($vzid)  # /usr/bin/prlctl
  -> OpenVz::startVps($vzid)     # /usr/sbin/vzctl
  -> Lxc::startVps($vzid)        # /usr/bin/lxc
  -> Docker::startVps($vzid)     # /usr/bin/docker
```

Virt type is auto-detected from binary existence in `Vps::$virtBins`, or forced with `--virt` option.

### Adding a new virt type

1. Create `app/Vps/NewType.php` with static methods matching the interface (see `Kvm.php` or `Lxc.php`)
2. Add `elseif` branches in every dispatch method in `app/Vps.php`
3. Add the binary path to `Vps::$virtBins`
4. Add to `--virt` option validValues in command files

### Command structure

Commands live in `app/Command/` and extend `CLIFramework\Command`. Subcommands use subdirectories (e.g., `CdCommand/EnableCommand.php`).

Standard command pattern:
```php
class FooCommand extends Command {
    public function brief() { return "Description"; }
    public function options($opts) {
        parent::options($opts);
        $opts->add('v|verbose', '...')->isa('number')->incremental();
        $opts->add('t|virt:', '...')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc','docker']);
    }
    public function arguments($args) {
        $args->add('vzid')->desc('...')->isa('string');
    }
    public function execute($vzid) {
        Vps::init($this->getOptions(), ['vzid' => $vzid]);
        // ...
    }
}
```

### Key classes

- `App\Vps` - Facade, dispatches to virt-specific classes, runs commands via `proc_open()`
- `App\Vps\Kvm` - KVM/libvirt (virsh, qemu-img, XML definitions)
- `App\Vps\Docker` - Docker containers as lightweight VPS (macvlan on br0)
- `App\Vps\Lxc` - LXC/LXD containers (bridged on br0)
- `App\Vps\OpenVz` - OpenVZ (vzctl)
- `App\Vps\Virtuozzo` - Virtuozzo (prlctl)
- `App\Os\Os` - Host OS utilities (IP detection, RAM, CPU)
- `App\Os\Dhcpd` / `Dhcpd6` - DHCP configuration management
- `App\Os\Xinetd` - VNC proxy service management
- `App\Console` - CLI app bootstrap, command groups, logger init

### Container types (Docker/LXC) vs VM types (KVM)

Docker and LXC skip KVM-specific operations: VNC, CD-ROM, virsh XML, storage pools, kpartx. Check `in_array(Vps::getVirtType(), ['docker', 'lxc'])` to gate these.

## Directories on target hosts

- `/root/cpaneldirect` - `Vps::$base`, scripts and tools directory
- `/vz/` - VM/container storage root
- `~/.provirted/` - Runtime data (host.json, history.json)

## No tests

There is no test suite. `TestCommand.php` is a diagnostic command, not automated tests.
