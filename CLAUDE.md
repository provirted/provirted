# ProVirted

Unified CLI tool managing VPS across KVM, OpenVZ, Virtuozzo, LXC, and Docker via a single phar.

**Entry**: `provirted.php` → `app/Console.php` (extends `CLIFramework\Application`) → `runWithTry($argv)`. Autoload via `App\` → `app/` PSR-4 (`composer.json`).

## Build

```bash
make              # composer update --no-dev + build phar (default target)
make dev          # composer update with dev deps
make phar         # build provirted.phar (no compression — breaks pvdisplay)
make install      # symlink /root/cpaneldirect/provirted.phar -> /usr/local/bin/provirted + bash completion
make completion   # regenerate provirted_completion
make internals    # regenerate app/Command/InternalsCommand from app/Resources/*.tpl
make copy         # copy phar to ../vps_host_server and git push
```

Underlying phar build: `php provirted.php archive --composer=composer.json --app-bootstrap --executable --no-compress provirted.phar`

## PHP Version

**PHP 7.4 only** — `composer.json` `config.platform.php` is locked to `7.4.33`. Do NOT use: named arguments, `match`, nullsafe `?->`, union types, enums, `str_contains`/`str_starts_with`/`str_ends_with`, readonly, fibers, constructor promotion. `symfony/polyfill-php72` is the only polyfill.

## Code Style

- **Indentation**: tabs · **Braces**: same line · **Names**: `PascalCase` classes, `camelCase` methods, `$camelCase` vars
- **PHP-CS-Fixer**: `@PSR2` + `@PHP74Migration` (see `.php-cs-fixer.dist.php`) — `trailing_comma_in_multiline` disabled
- Always `escapeshellarg()` every `$vzid`, `$ip`, `$mac`, `$password`, or user-supplied value before any shell interpolation
- Never use `exec()`/`shell_exec()`/backticks for new code — call `Vps::runCommand($cmd, $return)` and pipe through `Vps::getLogger()->write(...)`

## Architecture

**Core**: `provirted.php` · `app/Console.php` · `app/Vps.php` · `app/Logger.php` · `app/XmlToArray.php`

**Virt backends** (`app/Vps/`): `Kvm.php` (virsh/qemu-img/XML) · `Virtuozzo.php` (prlctl) · `OpenVz.php` (vzctl) · `Lxc.php` (lxc cli, br0 bridge) · `Docker.php` (docker cli, bridge/macvlan via `~/.provirted/docker.json`)

**OS utilities** (`app/Os/`): `Os.php` (IP/RAM/CPU detection) · `Dhcpd.php` / `Dhcpd6.php` (DHCP config at `/etc/dhcp/dhcpd.vps` or `/etc/dhcpd.vps`) · `Xinetd.php` (VNC proxy at `/etc/xinetd.d/`)

**Help topics** (`app/Topic/`): `BasicTopic.php` · `ExamplesTopic.php` extend `CLIFramework\Topic\BaseTopic`

**Resources** (`app/Resources/`): `InternalCommand.php.tpl` · `InternalCommandClass.php.tpl` — consumed by `GenerateInternalsCommand.php` via `make internals`

### Virt dispatch pattern

`App\Vps` is the facade. Every operation uses `if/elseif` on `Vps::getVirtType()` — never `switch`:

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

1. Create `app/Vps/NewType.php` with `public static` methods matching `Kvm.php`/`Lxc.php` interface (see `app/Vps/Lxc.php` for the minimal modern reference)
2. Add `elseif` branches in every dispatch method in `app/Vps.php`
3. Add binary path to `Vps::$virtBins`
4. Add to `--virt` `validValues` array in ALL ~41 command files under `app/Command/`

### Command structure

Commands in `app/Command/`, extend `CLIFramework\Command`. Discovery via `enableCommandAutoload()` in `app/Console.php`. Subcommand groups use subdirs:
`app/Command/CdCommand/` · `app/Command/VncCommand/` · `app/Command/SnapshotCommand/` · `app/Command/HistoryCommand/` · `app/Command/CronCommand/`

Command groups defined in `Console::init()`: Power · Provisioning · Maintanance · dev (`generate-internals`).

Standard skeleton (see `app/Command/UpdateCommand.php` for the canonical example):

```php
class FooCommand extends Command {
    public function brief() { return "Description."; }
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

**Container gate**: `in_array(Vps::getVirtType(), ['docker', 'lxc'])` — skip VNC (`Xinetd.php`), CD-ROM (`virsh attach-disk`), virsh XML editing, storage pools, kpartx for these backends.

### Runtime data

- `Vps::$base` → `/root/cpaneldirect` (host scripts dir: `tclimit`, `vps_refresh_vnc.sh`, `vps_kvm_lvmresize.sh`)
- `/vz/` — VM/container storage root; `/vz/templates/` — Docker `Dockerfile`s and OVZ template tarballs
- `~/.provirted/history.json` — command history (appended in `Console::finish()`; cleaned by `app/Command/HistoryCommand/CleanCommand.php`)
- `~/.provirted/docker.json` — Docker network config read by `Docker::getConfig()`: `network_mode`, `macvlan_interface`, `bridge_network`, `restart_policy` (default `on-failure:5`), `container_command` (default `sleep infinity`)
- `~/.provirted/host.json` — cached host info used by `Vps::getHostInfo()`
- `/etc/dhcp/dhcpd.vps` (or `/etc/dhcpd.vps`) — DHCP host entries managed by `app/Os/Dhcpd.php`
- `/etc/xinetd.d/{vzid}` and `/etc/xinetd.d/{vzid}-spice` — VNC/SPICE port forwarders managed by `app/Os/Xinetd.php`

## No tests

`app/Command/TestCommand.php` is a diagnostic command for host/VPS health checks, not an automated test suite. There is no PHPUnit/Codeception config.

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
