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
		if (preg_match_all('/^\s*host\s+(?P<host>\S+)\s+{\s+hardware\s+ethernet\s+(?P<mac>\S+)\s*;\s*fixed-range6\s+(?P<ipv6_range>\S+)\s*;\s*fixed-address6\s+(?P<ipv6_ip>\S+)\s*;\s*}/msuU', $dhcpData, $matches)) {
			foreach ($matches[0] as $idx => $match) {
				$host = $matches['host'][$idx];
				$mac = $matches['mac'][$idx];
				$ipv6_ip = $matches['ipv6_ip'][$idx];
				$ipv6_range = $matches['ipv6_range'][$idx];
				$hosts[$host] = ['ipv6_ip' => $ipv6_ip, 'ipv6_range' => $ipv6_range, 'mac' => $mac];
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
		return file_exists('/etc/apt') ? 'isc-dhcp-server' : 'dhcpd';
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
		Vps::getLogger()->write(Vps::runCommand("echo \"host {$vzid} { hardware ethernet {$mac}; fixed-address6 {$ipv6Ip}; fixed-prefix6 {$ipv6Range}; }\" >> {$dhcpVpsArg}", $return));
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
			foreach ($host['vlans6'] as $vlanId => $vlanData)
				$parts = explode('/', $vlanData['vlans6_networks']);
				$gateway = $parts[0].'1';
				$file .= 'subnet6 '.$vlanData['vlans6_networks'].' {

		# 100 addresses available to clients (the third client should get NoAddrsAvail)
		# range6 2604:a00:50:5::100 2604:a00:50:5::200;
		# Use the whole /64 prefix for temporary addresses (i.e., direct application of RFC 4941)
		# range 2604:a00:50:5:: temporary;
		option dhcp6.name-servers 2606:4700:4700::1111;
		option dhcp6.domain-search "interserver.net","is.cc", "trouble-free.net";
}
';
			$file .= '}';
			if ($display === false) {
				if (@file_put_contents(self::getConfFile(), $file) === false) {
					Vps::getLogger()->error('Could not write '.self::getConfFile().' (check permissions)');
					return false;
				}
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
