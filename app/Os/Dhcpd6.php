<?php
namespace App\Os;

use App\Vps;

/**
* DHCPD Service Management Class
*/
class Dhcpd6
{
	/**
	* is the service running (only reports true if both the binary exists and a process is running)
	* @return bool
	*/
	public static function isRunning() {
		Vps::getLogger()->write(Vps::runCommand('command -v dhcpd >/dev/null 2>&1', $return));
		if ($return != 0) {
			Vps::getLogger()->error('dhcpd binary not found in PATH');
			return false;
		}
		Vps::getLogger()->write(Vps::runCommand('pidof dhcpd >/dev/null', $return));
		return $return == 0;
	}

	/**
	* gets an array of hosts and thier ip+mac assignments
	* @return array
	*/
	public static function getHosts() {
		$dhcpFile = self::getFile();
		if (!file_exists($dhcpFile)) {
			Vps::getLogger()->error("DHCPv6 hosts file not found: {$dhcpFile}");
			return [];
		}
		$dhcpData = @file_get_contents($dhcpFile);
		if ($dhcpData === false) {
			Vps::getLogger()->error("Could not read DHCPv6 hosts file: {$dhcpFile} (check permissions)");
			return [];
		}
		$hosts = [];
		// The statement keyword is 'fixed-prefix6' (what setup()/rebuildHosts() write),
		// not 'fixed-range6', and the two writers emit address/prefix in opposite
		// orders. Match the host block loosely and pull each statement out of it so
		// either ordering parses. The old strict pattern never matched anything, so
		// this always returned an empty array.
		if (preg_match_all('/^[ \t]*host[ \t]+(?P<host>\S+)[ \t]*\{(?P<body>[^}]*)\}/m', $dhcpData, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$body = $match['body'];
				if (!preg_match('/hardware\s+ethernet\s+([^;\s]+)\s*;/i', $body, $m))
					continue;
				$mac = $m[1];
				if (!preg_match('/fixed-address6\s+([^;\s]+)\s*;/i', $body, $m))
					continue;
				$ipv6_ip = $m[1];
				$ipv6_range = preg_match('/fixed-(?:prefix|range)6\s+([^;\s]+)\s*;/i', $body, $m) ? $m[1] : '';
				$hosts[$match['host']] = ['ipv6_ip' => $ipv6_ip, 'ipv6_range' => $ipv6_range, 'mac' => $mac];
			}
		}
		return $hosts;
	}

	/**
	* returns the name of the dhcpd config file
	* @return string
	*/
	public static function getConfFile() {
		return file_exists('/etc/dhcp/dhcpd6.conf') ? '/etc/dhcp/dhcpd6.conf' : '/etc/dhcpd6.conf';
	}

	/**
	* Makes sure the DHCPv6 server is installed and its unit is enabled.
	*
	* The v6 daemon comes from the SAME package as the v4 one (isc-dhcp-server
	* ships both /usr/sbin/dhcpd and isc-dhcp-server6.service; there is no
	* separate isc-dhcp-server6 package), so the install itself is delegated to
	* Dhcpd::ensureInstalled(). All that is left here is enabling the second unit,
	* which is NOT enabled by the package on its own -- a host can happily serve
	* IPv4 forever with the v6 daemon installed but dead.
	*
	* @return bool true when the v6 daemon is available
	*/
	public static function ensureInstalled() {
		if (!Dhcpd::ensureInstalled())
			return false;
		Vps::runCommand('systemctl enable '.escapeshellarg(self::getService()).' 2>/dev/null', $rc);
		self::ensureUnitPairing();
		return true;
	}

	/**
	* Ties the v6 unit to the v4 one so they start and restart together.
	*
	* Debian ships isc-dhcp-server.service and isc-dhcp-server6.service as two
	* completely independent units -- no Wants, no PartOf, no BindsTo between
	* them. `systemctl restart isc-dhcp-server` therefore restarts ONLY the IPv4
	* daemon and silently leaves the v6 one running the old config, which looks
	* exactly like "my dhcpd6 changes didn't take effect" and is miserable to
	* debug. (This is the same trap that had getService() returning the v4 name.)
	*
	* PartOf on the v6 side propagates stop/restart from v4 to v6; Wants on the
	* v4 side makes starting v4 bring v6 up. Drop-ins are used rather than
	* editing the shipped units so a package upgrade cannot clobber them.
	*
	* Safe on hosts with no IPv6: the stock v6 unit carries
	* ConditionPathExists=|/etc/dhcp/dhcpd6.conf, so without a v6 config it just
	* no-ops instead of starting a server.
	*
	* @return bool indicates success
	*/
	public static function ensureUnitPairing() {
		if (!is_dir('/etc/systemd/system'))
			return false; // not a systemd host; nothing to pair
		$v4 = Dhcpd::getService();
		$v6 = self::getService();
		$dropins = [
			'/etc/systemd/system/'.$v6.'.service.d/10-provirted-pair.conf' =>
				"# Managed by provirted.\n"
				."# Restarting the v4 unit must also restart this one, otherwise dhcpd6\n"
				."# keeps serving the old config and the change looks like it was ignored.\n"
				."[Unit]\n"
				."PartOf={$v4}.service\n",
			'/etc/systemd/system/'.$v4.'.service.d/10-provirted-pair.conf' =>
				"# Managed by provirted.\n"
				."# Starting the v4 unit should bring the v6 one up alongside it.\n"
				."[Unit]\n"
				."Wants={$v6}.service\n",
		];
		$changed = false;
		foreach ($dropins as $path => $contents) {
			if (file_exists($path) && @file_get_contents($path) === $contents)
				continue;
			$dir = dirname($path);
			if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
				Vps::getLogger()->error("Could not create {$dir}; leaving the dhcp units unpaired");
				return false;
			}
			if (@file_put_contents($path, $contents) === false) {
				Vps::getLogger()->error("Could not write {$path}; leaving the dhcp units unpaired");
				return false;
			}
			$changed = true;
		}
		if ($changed) {
			Vps::runCommand('systemctl daemon-reload 2>/dev/null', $rc);
			Vps::getLogger()->info("Paired {$v6} to {$v4} so restarting either keeps both on the current config");
		}
		return true;
	}

	/**
	* returns the name of the dhcpd hosts file
	* @return string
	*/
	public static function getFile() {
		return file_exists('/etc/dhcp/dhcpd6.vps') ? '/etc/dhcp/dhcpd6.vps' : '/etc/dhcpd6.vps';
	}

	/**
	* returns the name of the dhcp service
	* @return string
	*/
	public static function getService() {
		// NOTE: this is the DHCPv6 daemon, which is a SEPARATE unit from the v4 one.
		// Debian/Ubuntu ship isc-dhcp-server (v4) and isc-dhcp-server6 (v6); RedHat
		// ships dhcpd and dhcpd6. Returning the v4 name here (as this used to) meant
		// every dhcpd6.vps change bounced the wrong daemon and never went live.
		return file_exists('/etc/apt') ? 'isc-dhcp-server6' : 'dhcpd6';
	}

	/**
	* sets up a new host for dhcp
	* @param string $vzid hostname
	* @param string $ip ip address
	* @param string $mac mac address
	*/
	public static function setup($vzid, $ipv6Ip, $ipv6Range, $mac) {
		Vps::getLogger()->info('Setting up DHCPD6');
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $vzid)) {
			Vps::getLogger()->error("Invalid vzid '{$vzid}' for DHCPv6 entry; refusing.");
			return false;
		}
		if (!filter_var($ipv6Ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			Vps::getLogger()->error("Invalid IPv6 '{$ipv6Ip}' for DHCPv6 entry; refusing.");
			return false;
		}
		$resolvedMac = Vps::getVpsMac($vzid);
		if ($resolvedMac != '') {
			$mac = $resolvedMac;
		}
		if (!preg_match('/^[0-9A-Fa-f:]+$/', $mac) || $mac == '') {
			Vps::getLogger()->error("Invalid MAC '{$mac}' for {$vzid}; refusing to write DHCPv6 entry.");
			return false;
		}
		$dhcpVps = self::getFile();
		if (!is_writable(dirname($dhcpVps))) {
			Vps::getLogger()->error("DHCPv6 hosts directory not writable: ".dirname($dhcpVps));
			return false;
		}
		$dhcpVpsArg = escapeshellarg($dhcpVps);
		$backupPath = $dhcpVps.'.backup';
		$backupArg = escapeshellarg($backupPath);
		Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$dhcpVpsArg} {$backupArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("Could not back up {$dhcpVps} (exit {$return})");
			return false;
		}
		Vps::getLogger()->write(Vps::runCommand("grep -v -e \"host {$vzid} \" -e \"fixed-address6 {$ipv6Ip};\" {$backupArg} > {$dhcpVpsArg}", $return));
		if ($return > 1) {
			Vps::getLogger()->error("grep filter of {$dhcpVps} failed (exit {$return}); restoring backup");
			Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$backupArg} {$dhcpVpsArg}"));
			Vps::getLogger()->write(Vps::runCommand("rm -f {$backupArg}"));
			return false;
		}
		// Only emit fixed-prefix6 when a range was actually supplied — writing an
		// empty 'fixed-prefix6 ;' is a config syntax error that takes the whole
		// dhcpd6 server down on next restart, not just this host entry.
		$prefixStmt = '';
		if ($ipv6Range !== false && trim((string)$ipv6Range) !== '' && strpos((string)$ipv6Range, '/') !== false)
			$prefixStmt = ' fixed-prefix6 '.trim($ipv6Range).';';
		Vps::getLogger()->write(Vps::runCommand("echo \"host {$vzid} { hardware ethernet {$mac};{$prefixStmt} fixed-address6 {$ipv6Ip}; }\" >> {$dhcpVpsArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("Could not append host entry to {$dhcpVps} (exit {$return})");
			return false;
		}
		Vps::getLogger()->write(Vps::runCommand("rm -f {$backupArg}"));
		self::restart();
		return true;
	}

	/**
	* regenerates the dhcpd.conf file
	* @param bool $display defaults to false, true to display file contents instead of write them
    * @return bool indicates success
	*/
	public static function rebuildConf($display = false) {
		$host = Vps::getHostInfo();
        if (!is_array($host) || !isset($host['vlans6'])) {
            Vps::getLogger()->error('There appears to have been a problem with the host info, perhaps try again?');
            return false;
        }
		if (count($host['vlans6']) > 0) {
			$file = 'authoritative;
ddns-update-style standard;
ddns-dual-stack-mixed-mode true;
update-conflict-detection true;
update-optimization false;
allow leasequery;
option dhcp6.preference 255;
option dhcp6.rapid-commit;
option dhcp6.info-refresh-time 21600;
include "'.self::getFile().'";

shared-network myvpn {
';
			// NOTE: this foreach was previously written without braces, so only the
			// explode() was in the loop body and the subnet6 block was emitted ONCE,
			// for whichever vlan happened to be last. Hosts with more than one IPv6
			// vlan silently lost every subnet but one.
			foreach ($host['vlans6'] as $vlanId => $vlanData) {
				$file .= 'subnet6 '.$vlanData['vlans6_networks'].' {
		option dhcp6.name-servers 2606:4700:4700::1111;
		option dhcp6.domain-search "interserver.net","is.cc", "trouble-free.net";
}
';
			}
			$file .= '}';
			if ($display === false) {
				if (@file_put_contents(self::getConfFile(), $file) === false) {
					Vps::getLogger()->error('Could not write '.self::getConfFile().' (check permissions)');
					return false;
				}
				// Installed after the config is written so the package's postinst
				// brings the daemon up against a config that already exists. Also
				// enables isc-dhcp-server6, which the package leaves off by default.
				self::ensureInstalled();
			} else {
				Vps::getLogger()->write('cat > '.self::getConfFile().' <<EOF'.PHP_EOL.$file.PHP_EOL.'EOF'.PHP_EOL);
			}
		}
        return true;
	}

	/**
	* regenerates the dhcpd.vps hosts file
	* @param bool $display defaults to false, true to display file contents instead of write them
    * @return bool indicates success
	*/
	public static function rebuildHosts($display = false) {
		$host = Vps::getHostInfo();
        if (!is_array($host) || !isset($host['vps'])) {
            Vps::getLogger()->error('There appears to have been a problem with the host info, perhaps try again?');
            return false;
        }
		if (isset($host['vlans6']) && is_array($host['vlans6']) && count($host['vlans6']) > 0) {
			$lines = [];
			foreach ($host['vps'] as $vps)
				if (isset($vps['ipv6']) && !is_null($vps['ipv6']) && $vps['ipv6'] != '')
					$lines[] = 'host '.$vps['vzid'].' { hardware ethernet '.$vps['mac'].'; fixed-prefix6 '.$vps['ipv6_range'].'; fixed-address6 '.$vps['ipv6'].'; }';
			$file = implode(PHP_EOL, $lines);
			if ($display === false) {
				if (@file_put_contents(self::getFile(), $file) === false) {
					Vps::getLogger()->error('Could not write '.self::getFile().' (check permissions)');
					return false;
				}
			} else {
				Vps::getLogger()->write('cat > '.self::getFile().' <<EOF'.PHP_EOL.$file.PHP_EOL.'EOF'.PHP_EOL);
			}
		}
        return true;
	}

	/**
	* removes a host from dhcp
	* @param string $vzid
	* @return bool indicates success
	*/
	public static function remove($vzid) {
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $vzid)) {
			Vps::getLogger()->error("Invalid vzid '{$vzid}' for DHCPv6 removal; refusing.");
			return false;
		}
		$dhcpVps = self::getFile();
		if (!file_exists($dhcpVps)) {
			Vps::getLogger()->error("DHCPv6 hosts file not found: {$dhcpVps}");
			return false;
		}
		$dhcpVpsArg = escapeshellarg($dhcpVps);
		Vps::getLogger()->write(Vps::runCommand("sed s#\"^host {$vzid} .*$\"#\"\"#g -i {$dhcpVpsArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("sed -i failed on {$dhcpVps} (exit {$return})");
			return false;
		}
		self::restart();
		return true;
	}

	/**
	* restarts the service
	* @return bool indicates success
	*/
	public static function restart() {
		$dhcpService = self::getService();
		$svcArg = escapeshellarg($dhcpService);
		$svcEsc = escapeshellcmd($dhcpService);
		Vps::getLogger()->write(Vps::runCommand("systemctl restart {$svcArg} 2>/dev/null || service {$svcArg} restart 2>/dev/null || /etc/init.d/{$svcEsc} restart 2>/dev/null", $return));
		if ($return != 0) {
			Vps::getLogger()->error("Could not restart {$dhcpService} (exit {$return}); DHCPv6 changes may not be live yet");
			return false;
		}
		return true;
	}
}
