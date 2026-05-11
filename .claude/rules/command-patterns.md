---
paths:
  - app/Command/**/*.php
---

# Command Patterns

Applies to all `CLIFramework\Command` subclasses in `app/Command/` and its subdirs (`CdCommand/`, `VncCommand/`, `SnapshotCommand/`, `HistoryCommand/`, `CronCommand/`).

## Required execute() guard order

1. `Vps::init($this->getOptions(), ['vzid' => $vzid]);` — must be first
2. `if (!Vps::isVirtualHost()) { ...error; return 1; }` — host check
3. `if (!Vps::vpsExists($vzid)) { ...error; return 1; }` — vzid check
4. Business logic dispatching on `Vps::getVirtType()`

See `app/Command/UpdateCommand.php::execute()` for the canonical sequence.

## Standard options

Every VPS-targeted command must include:

```php
$opts->add('v|verbose', '...')->isa('number')->incremental();
$opts->add('t|virt:', '...')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc','docker']);
```

And a `vzid` argument with `validValues([Vps::class, 'getAllVpsAllVirts'])` for bash completion.

## Subcommand groups

Parent file (e.g. `app/Command/CdCommand.php`) only declares `brief()`. Subcommands live in matching subdir (`app/Command/CdCommand/EnableCommand.php`) with namespace `App\Command\CdCommand`. `enableCommandAutoload()` in `app/Console.php` discovers them — do not register manually.

## Logging

- Use `Vps::getLogger()->info()` / `->error()` / `->debug()` — not `echo`
- Pipe every shell call: `Vps::getLogger()->write(Vps::runCommand("..."));`
- For history-suppressing commands (cron, rebuild), check `--no-log` and call `Vps::getLogger()->disableHistory();` (see `app/Command/VncCommand/RebuildCommand.php`)

## Forbidden

- No `exec()`, `shell_exec()`, backticks — use `Vps::runCommand($cmd, $return)`
- No raw `$vzid`/`$ip`/`$password` in shell strings — wrap in `escapeshellarg()` first
- No PHP 8+ syntax — see PHP 7.4 list in `CLAUDE.md`
