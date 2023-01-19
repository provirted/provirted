<?php
namespace App\Command;

use CLIFramework\Command;

class SnapshotCommand extends Command {
	public function brief() {
		return "Saves and Restoreds the Disk/Volume";
	}

	public function execute() {
        echo '
SYNTAX

provirted.phar snapshot <subcommand>

SUBCOMMANDS
	save <vzid>                 save a new snapshot
	restore <vzid> <name>       restore a snapshot
	list [vzid]                 list snapshots

EXAMPLES
	provirted.phar snapshot save vps4000
	provirted.phar snapshot restore vps4000 first
	provirted.phar snapshot list
';
	}
}
