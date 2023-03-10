<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class DeleteCommand extends Command {
	public function brief() {
		return "Deletes a Virtual Machine.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
        $opts->add('o|order-id:', 'Order ID')->isa('number');
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
        $opts = $this->getOptions();
        $orderId = array_key_exists('order-id', $opts->keys) ? $opts->keys['order-id']->value : '';
		if (!Vps::vpsExists($vzid)) {
			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
        if (file_exists('/vz/'.$vzid.'/protected')) {
            Vps::getLogger()->error("The VPS '{$vzid}' you specified is protected.");
            return 1;
        }
		Vps::deleteVps($vzid);
        if ($orderId != '') {
            $url = Vps::getUrl();
            Vps::runCommand("curl --connect-timeout 10 --max-time 20 -k -d action=finished -d command=delete -d service={$orderId} '{$url}' < /dev/null > /dev/null 2>&1;");
        }
	}
}
