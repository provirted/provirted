<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ResetPasswordCommand extends Command {
	public function brief() {
		return "Resets/Clears a Password on a Virtual Machine.";
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
        if (Vps::isVpsRunning($vzid)) {
            Vps::stopVps($vzid);
        }
		$base = Vps::$base;
        $part = Vps::runCommand("virt-inspector --no-applications -d {$vzid} |grep \"mountpoint.*>/<\"|cut -d\\\" -f2");
        Vps::getLogger()->write(Vps::runCommand("guestfish add-domain {$vzid} : run : ntfsfix {$part} : unmount-all"));
        mkdir('/mntpass');
        Vps::getLogger()->write(Vps::runCommand("guestmount -d {$vzid} -i -w /mntpass"));
        Vps::getLogger()->write(Vps::runCommand("{$base}/enable_user_and_clear_password -u Administrator /mntpass/Windows/System32/config/SAM"));
        Vps::getLogger()->write(Vps::runCommand("guestunmount /mntpass"));
        rmdir('/mntpass');
        Vps::startVps($vzid);
	}
}
