# ProVirted

Unified CLI tool for managing VPS across KVM, OpenVZ, Virtuozzo, LXC, and Docker.

**Entry**: `provirted.php` → `app/Console.php` (extends `CLIFramework\Application`) → `runWithTry($argv)`.

## Build

```bash
make              # composer update --no-dev + build phar
make dev          # composer update with dev deps
make phar         # build provirted.phar (no compression — breaks pvdisplay)
make install      # symlink phar to /usr/local/bin, install bash completion
make completion   # regenerate bash completion script
make internals    # regenerate app/Command/InternalsCommand from app/Resources/ templates
make copy         # copy phar to ../vps_host_server and push
```

Phar build: `php provirted.php archive --composer=composer.json --app-bootstrap --executable --no-compress provirted.phar`

## PHP Version

**PHP 7.4 only.** Do not use: named arguments, `match`, nullsafe `?->`, union types, enums, `str_contains/starts_with/ends_with`, readonly, fibers, constructor promotion. `composer.json` platform locked to `7.4.33`.

## Code Style

- **Indentation**: Tabs · **Braces**: same line · **Names**: `PascalCase` classes, `camelCase` methods, `$camelCase` vars
- **PHP-CS-Fixer**: `@PSR2` + `@PHP74Migration` (see `.php-cs-fixer.dist.php`) — no trailing commas
- Always `escapeshellarg()` every `$vzid`, `$ip`, `$password`, or user-supplied value before any shell invocation

## Architecture

**Core files**: `provirted.php` · `app/Console.php` · `app/Vps.php` · `app/Logger.php` · `app/XmlToArray.php`

**Virt backends** (`app/Vps/`): `Kvm.php` (virsh/qemu-img/XML) · `Virtuozzo.php` (prlctl) · `OpenVz.php` (vzctl) · `Lxc.php` (lxc cli, br0) · `Docker.php` (docker cli, bridge/macvlan; VLAN-aware network selection via `host.json`)

**OS utilities** (`app/Os/`): `Os.php` (IP/RAM/CPU detection) · `Dhcpd.php` / `Dhcpd6.php` (DHCP config at `/etc/dhcp/dhcpd.vps`) · `Xinetd.php` (VNC proxy, `/etc/xinetd.d/`)

**Help topics** (`app/Topic/`): `BasicTopic.php` · `ExamplesTopic.php` — extend `CLIFramework\Topic\BaseTopic`

### Virt dispatch pattern

`App\Vps` is the facade. All operations dispatch via `if/elseif` on `Vps::getVirtType()`:

```
Vps::startVps($vzid)
  -> Kvm::startVps($vzid)        # /usr/bin/virsh
  -> Virtuozzo::startVps($vzid)  # /usr/bin/prlctl
  -> OpenVz::startVps($vzid)     # /usr/sbin/vzctl
  -> Lxc::startVps($vzid)        # /usr/bin/lxc
  -> Docker::startVps($vzid)     # /usr/bin/docker
```

Virt type auto-detected from binary existence in `Vps::$virtBins`, or forced with `--virt`.

### Adding a new virt type

1. Create `app/Vps/NewType.php` with static methods matching `Kvm.php` / `Lxc.php` interface
2. Add `elseif` branches in every dispatch method in `app/Vps.php`
3. Add binary path to `Vps::$virtBins`
4. Add to `--virt` `validValues` in all command files

### Command structure

Commands in `app/Command/`, extend `CLIFramework\Command`. Subcommand groups use subdirs:
`app/Command/CdCommand/` · `app/Command/VncCommand/` · `app/Command/SnapshotCommand/` · `app/Command/HistoryCommand/` · `app/Command/CronCommand/`

Standard command skeleton:

```php
class FooCommand extends Command {
    public function brief() { return "Description"; }
    public function options($opts) {
        parent::options($opts);
        $opts->add('v|verbose', '...')->isa('number')->incremental();
        $opts->add('t|virt:', '...')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc','docker']);
    }
    public function arguments($args) {
        $args->add('vzid')->desc('...')->isa('string')->validValues([Vps::class, 'getAllVpsAllVirts']);
    }
    public function execute($vzid) {
        Vps::init($this->getOptions(), ['vzid' => $vzid]);
        if (!Vps::isVirtualHost()) { Vps::getLogger()->error("No virtualization found."); return 1; }
        if (!Vps::vpsExists($vzid)) { Vps::getLogger()->error("VPS '{$vzid}' not found."); return 1; }
        // dispatch via Vps::getVirtType()
    }
}
```

Container gate: `in_array(Vps::getVirtType(), ['docker', 'lxc'])` — skip VNC, CD-ROM, virsh XML, storage pools, kpartx.

### Runtime data

- `Vps::$base` → `/root/cpaneldirect` (host scripts dir, e.g. `tclimit`, `vps_refresh_vnc.sh`)
- `/vz/` — VM/container storage root; `/vz/templates/` — Docker Dockerfiles and OVZ templates
- `~/.provirted/history.json` — command history (written in `app/Console.php::finish()`)
- `~/.provirted/docker.json` — Docker network config (macvlan/bridge, read by `Docker::getConfig()`); keys: `network_mode`, `macvlan_interface`, `bridge_network`, `restart_policy` (default `on-failure:5`), `container_command` (default `sleep infinity`)
- `~/.provirted/host.json` — cached host info used by `Vps::getHostInfo()`

## No tests

`app/Command/TestCommand.php` is a diagnostic command, not an automated test suite.

<!-- caliber:managed:pre-commit -->
## Before Committing

Run `caliber refresh` before creating git commits to keep docs in sync with code changes.
After it completes, stage any modified doc files before committing:

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `/home/my/.nvm/versions/node/v24.15.0/bin/caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
