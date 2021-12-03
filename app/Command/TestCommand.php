<?php
namespace App\Command;

use App\Vps;
use App\Os\Dhcpd;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;

class TestCommand extends Command {
	public function brief() {
		return "Perform various self diagnostics to check on the health and prepairedness of the system.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string');
		$args->add('password')->desc('Password')->isa('string');
	}

	public function execute($vzid, $password = '') {
		$this->getLogger()->writeln('Running Tests on '.$vzid);
		$this->getLogger()->newline();
		//$logger = new ActionLogger(fopen('php://stderr','w'), new Formatter);
		$logger = new ActionLogger(fopen('php://stdout','w'), new Formatter);
		$logAction = $logger->newAction('Virtualization Installed');
		$logAction->setStatus('checking');
		Vps::init($this->getOptions(), ['vzid' => $vzid]);
		if (!Vps::isVirtualHost()) {
			$logAction->setStatus('error');
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		$logAction->setStatus('success');
		$logAction->done();

		$logAction = $logger->newAction('VPS Exists');
		if (!Vps::vpsExists($vzid)) {
			$logAction->setStatus('error');
			$this->getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$logAction->setStatus('success');
		$logAction->done();

		$logAction = $logger->newAction('VPS Running');
		if (!Vps::isVpsRunning($vzid)) {
			$logAction->setStatus('error');
			$this->getLogger()->error("The VPS '{$vzid}' you specified appears to be stopped.");
			return 1;
		}
		$logAction->setStatus('success');
		$logAction->done();

		$logAction = $logger->newAction('DHCP Host Matches');
		$hosts = Dhcpd::getHosts();
		if (!array_key_exists($vzid, $hosts)) {
			$logAction->setStatus('error');
			$this->getLogger()->error("Dhcpd does not appear to have {$vzid} among the configured hosts (".implode(', ', array_keys($hosts)).")");
			return 1;
		}
		$logAction->setStatus('success');
		$logAction->done();

		$logAction = $logger->newAction('DHCP Mac & IP Adddress Matches');
		$mac = Vps::getVpsMac($vzid);
		$ips = Vps::getVpsIps($vzid);
		foreach ($hosts as $name => $data) {
			if ($name == $vzid) {
				if (!in_array($data['ip'], $ips)) {
					$logAction->setStatus('error');
					$this->getLogger()->error("The VPS does not appear to have the right ip in DHCP ({$data['ip']} not in ".implode(', ', $ips).")");
					return 1;
				}
				if ($data['mac'] != $mac) {
					$logAction->setStatus('error');
					$this->getLogger()->error("The VPS does not appear to have the right mac in DHCP ({$data['mac']} != {$mac})");
					return 1;
				}
				$ip = $data['ip'];
			}
		}
		$logAction->setStatus('success');
		$logAction->done();

		$logAction = $logger->newAction('DHCP Running');
		if (!Dhcpd::isRunning()) {
			$logAction->setStatus('error');
			$this->getLogger()->error("Dhcpd does not appear to be running.");
			return 1;
		}
		$logAction->setStatus('success');
		$logAction->done();

		/*grep "DHCPACK on" /var/log/syslog
		Dec  2 10:26:08 builder dhcpd[1597]: DHCPACK on 64.20.46.222 to 00:16:3e:21:93:4e via br0 */

		$logAction = $logger->newAction('XinetD Running');
		if (!Xinetd::isRunning()) {
			$logAction->setStatus('error');
			$this->getLogger()->error("XinetD does not appear to be running.");
			return 1;
		}
		$logAction->done();

		$logAction = $logger->newAction('Host Pings VPS');
		$logAction->setStatus('request');
		$logAction->setStatus('pinging');
		if (trim(Vps::runCommand('ping -c 1 '.$ip.' -q -n >/dev/null && echo yes')) != 'yes') {
			$logAction->setStatus('error');
			$this->getLogger()->error("Did not respond to ping.");
			return 1;
		}
		$logAction->done();

		$logAction = $logger->newAction('SSH Connection');
		$logAction->setStatus('connecting');
		$con = ssh2_connect($ip, 22);
		if (!$con) {
			$logAction->setStatus('error');
			$this->getLogger()->error('SSH Connection failed to "'.$ip.'": '.var_export($con,true));
			return 1;
		}
		$logAction->done();

		$logAction = $logger->newAction('SSH Authentication');
		$logAction->setStatus('authenticating');
		if (!@ssh2_auth_password($con, 'root', $password)) {
			$logAction->setStatus('error');
			$this->getLogger()->error('SSH Password Authentication failed using "'.$password.'": '.var_export($con,true));
			return 1;
		}
	    $logAction->done();

		$logAction = $logger->newAction('VPS Pings Internet');
		$cmd = 'ping -c 1 1.1.1.1 -q -n >/dev/null && echo yes';
		$stream = ssh2_exec($con, $cmd);
		stream_set_blocking($stream, true);
		$response = trim(stream_get_contents($stream));
		fclose($stream);
		if ($response != 'yes') {
			$logAction->setStatus('error');
			$this->getLogger()->error('Pinging 1.1.1.1 on VPS via SSH failed and returned: "'.$response.'"');
			return 1;
		}
	    $logAction->done();
		if ($con) {
			ssh2_disconnect($con);
		}
		$this->getLogger()->writeln($vzid.' passed all tests!');
	}
}
