<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class InstallCpanelCommand extends Command {
	public function brief() {
		return "Runs the CPanel Installation on a Virtual Machine.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('email')->desc('Email Address')->isa('string');
	}

	public function execute($hostname, $email) {
		Vps::init($this->getOptions(), ['hostname' => $hostname, 'email' => $email]);
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (Vps::getVirtType() == 'virtuozzo') {
			$email = escapeshellarg($email);
			echo Vps::runCommand("prlctl exec {$hostname} 'if [ ! -e /usr/bin/screen ]; then yum -y install screen; fi'");
			echo Vps::runCommand("prlctl exec {$hostname} 'if [ ! -e /admin/cpanelinstall ]; then rsync -a rsync://mirror.trouble-free.net/admin /admin; fi'");
			echo Vps::runCommand("prlctl exec {$hostname} '/admin/cpanelinstall {$email}'");
		}
	}
}