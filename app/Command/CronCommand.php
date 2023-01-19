<?php
namespace App\Command;

use CLIFramework\Command;

class CronCommand extends Command {
	public function brief() {
		return "Prepairs and works with a source based version of the project (instead of the phar)";
	}

	public function execute() {
        echo '
SYNTAX

provirted.phar cron <subcommand>

SUBCOMMANDS
	secure [--dry]            removes old and bad entries to maintain security
	setup <vzid> [ip]         create a new mapping
	remove <vzid>             remove a mapping
	restart                   restart the xinetd service
	rebuild [--dry]           removes old and bad entries to maintain security, and recreates all port mappings

EXAMPLES
	provirted.phar cron bw-info
	provirted.phar cron cpu-usage
	provirted.phar cron host-info
	provirted.phar cron host-info-extra
	provirted.phar cron virtuozzo-update
	provirted.phar cron vps-info
';
	}
}
