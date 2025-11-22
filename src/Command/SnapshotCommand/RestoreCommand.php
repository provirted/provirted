<?php
namespace App\Command\SnapshotCommand;

use App\Vps;
use App\Vps\Virtuozzo;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class RestoreCommand extends Command {
	public function brief() {
		return "Restores a saved snapshot to a VPS.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string')->validValues([Vps::class, 'getAllVpsAllVirts']);
		$args->add('snapshot')->desc('Snapshot Name')->isa('string');
	}

	public function execute($vzid, $snapshot) {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'snapshot' => $snapshot]);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
        if (Vps::getPoolType() != 'zfs') {
            Vps::getLogger()->error("This system is not setup for zfs");
            return 1;
        }
        if (trim(Vps::runCommand("zfs list -t snapshot vz/{$vzid}@{$snapshot} 2>/dev/null || echo no")) == 'no') {
            Vps::getLogger()->error("The specified snapshot {$snapshot} does not seem to exist for VPS {$vzid}");
            return 1;
        }
        Vps::stopVps($vzid, true);
        Vps::getLogger()->error("Restoring vz/{$vzid}@{$snapshot} snapshot");
        Vps::getLogger()->write(Vps::runCommand("zfs rollback vz/{$vzid}@{$snapshot}"));
        Vps::startVps($vzid);
	}
}
