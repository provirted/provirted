<?php
namespace App\Command\SnapshotCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class SaveCommand extends Command {
	public function brief() {
		return "Create a new zfs snapshot of a vps disk";
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
	}

	public function execute($vzid) {
		Vps::init($this->getOptions(), ['vzid' => $vzid]);
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
        Vps::stopVps($vzid);
        Vps::getLogger()->error("Creating vz/{$vzid}@first snapshot");
        Vps::getLogger()->write(Vps::runCommand("zfs destroy vz/{$vzid}@third"));
        Vps::getLogger()->write(Vps::runCommand("zfs rename vz/{$vzid}@second vz/{$vzid}@third"));
        Vps::getLogger()->write(Vps::runCommand("zfs rename vz/{$vzid}@first vz/{$vzid}@second"));
        Vps::getLogger()->write(Vps::runCommand("zfs snapshot vz/{$vzid}@first"));
        Vps::startVps($vzid);
	}
}
