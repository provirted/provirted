<?php
namespace App\Os;

use App\Vps;

/**
* DHCPD Service Management Class
*/
class Dhcpd
{
	/**
	* is the service running (only reports true if both the binary exists and a process is running)
	* @return bool
	*/
	public static function isRunning() {
		// pidof exits non-zero if no process is running OR if the binary doesn't exist; differentiate the cases
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
			Vps::getLogger()->error("DHCP hosts file not found: {$dhcpFile}");
			return [];
		}
		$dhcpData = @file_get_contents($dhcpFile);
		if ($dhcpData === false) {
			Vps::getLogger()->error("Could not read DHCP hosts file: {$dhcpFile} (check permissions)");
			return [];
		}
		$hosts = [];
		if (preg_match_all('/^\s*host\s+(?P<host>\S+)\s+{\s+hardware\s+ethernet\s+(?P<mac>\S+)\s*;\s*fixed-address\s+(?P<ip>\S+)\s*;\s*}/msuU', $dhcpData, $matches)) {
			foreach ($matches[0] as $idx => $match) {
				$host = $matches['host'][$idx];
				$mac = $matches['mac'][$idx];
				$ip = $matches['ip'][$idx];
				$hosts[$host] = ['ip' => $ip, 'mac' => $mac];
			}
		}
		return $hosts;
	}

	/**
	* returns the name of the dhcpd config file
	* @return string
	*/
	public static function getConfFile() {
		return file_exists('/etc/dhcp/dhcpd.conf') ? '/etc/dhcp/dhcpd.conf' : '/etc/dhcpd.conf';
	}

	/**
	* returns the name of the dhcpd hosts file
	* @return string
	*/
	public static function getFile() {
		return file_exists('/etc/dhcp/dhcpd.vps') ? '/etc/dhcp/dhcpd.vps' : '/etc/dhcpd.vps';
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
	* @return bool indicates success
	*/
	public static function setup($vzid, $ip, $mac) {
		Vps::getLogger()->info('Setting up DHCPD');
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $vzid)) {
			Vps::getLogger()->error("Invalid vzid '{$vzid}' for DHCP entry; refusing.");
			return false;
		}
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			Vps::getLogger()->error("Invalid IPv4 '{$ip}' for DHCP entry; refusing.");
			return false;
		}
		$resolvedMac = Vps::getVpsMac($vzid);
		if ($resolvedMac != '') {
			$mac = $resolvedMac;
		}
		if (!preg_match('/^[0-9A-Fa-f:]+$/', $mac) || $mac == '') {
			Vps::getLogger()->error("Invalid MAC '{$mac}' for {$vzid}; refusing to write DHCP entry.");
			return false;
		}
		$dhcpVps = self::getFile();
		if (!is_writable(dirname($dhcpVps))) {
			Vps::getLogger()->error("DHCP hosts directory not writable: ".dirname($dhcpVps));
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
		Vps::getLogger()->write(Vps::runCommand("grep -v -e \"host {$vzid} \" -e \"fixed-address {$ip};\" {$backupArg} > {$dhcpVpsArg}", $return));
		if ($return > 1) {
			Vps::getLogger()->error("grep filter of {$dhcpVps} failed (exit {$return}); restoring backup");
			Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$backupArg} {$dhcpVpsArg}"));
			Vps::getLogger()->write(Vps::runCommand("rm -f {$backupArg}"));
			return false;
		}
		Vps::getLogger()->write(Vps::runCommand("echo \"host {$vzid} { hardware ethernet {$mac}; fixed-address {$ip}; }\" >> {$dhcpVpsArg}", $return));
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
        if (!is_array($host) || !isset($host['vlans'])) {
            Vps::getLogger()->write('There appears to have been a problem with the host info, perhaps try again?'.PHP_EOL);
            return false;
        }
		$file = 'authoritative;
option domain-name "interserver.net";
option domain-name-servers 1.1.1.1;
allow bootp;
allow booting;
ddns-update-style interim;
default-lease-time 600;
max-lease-time 7200;
log-facility local7;
include "'.self::getFile().'";

shared-network myvpn {
';
		foreach ($host['vlans'] as $vlanId => $vlanData)
			$file .= 'subnet '.$vlanData['network_ip'].' netmask '.$vlanData['netmask'].' {
	next-server '.$vlanData['hostmin'].';
	#range dynamic-bootp '.long2ip(ip2long($vlanData['hostmin']) + 1).' '.$vlanData['hostmax'].';
	option domain-name-servers 69.10.54.252, 66.45.251.218;
	option domain-name "interserver.net";
	option routers '.long2ip(ip2long($vlanData['hostmin'])).';
	option broadcast-address '.$vlanData['broadcast'].';
	default-lease-time 86400; # 24 hours
	max-lease-time 172800; # 48 hours
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
		$lines = [];
		foreach ($host['vps'] as $vps)
			if ($vps['ip'] != 'none' && $vps['ip'] != '' && $vps['mac'] != '' && $vps['vzid'] != '0')
				$lines[] = 'host '.$vps['vzid'].' { hardware ethernet '.$vps['mac'].'; fixed-address '.$vps['ip'].'; }';
		$file = implode(PHP_EOL, $lines);
		if ($display === false) {
			if (@file_put_contents(self::getFile(), $file) === false) {
				Vps::getLogger()->error('Could not write '.self::getFile().' (check permissions)');
				return false;
			}
		} else {
			Vps::getLogger()->write('cat > '.self::getFile().' <<EOF'.PHP_EOL.$file.PHP_EOL.'EOF'.PHP_EOL);
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
			Vps::getLogger()->error("Invalid vzid '{$vzid}' for DHCP removal; refusing.");
			return false;
		}
		$dhcpVps = self::getFile();
		if (!file_exists($dhcpVps)) {
			Vps::getLogger()->error("DHCP hosts file not found: {$dhcpVps}");
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
			Vps::getLogger()->error("Could not restart {$dhcpService} (exit {$return}); DHCP changes may not be live yet");
			return false;
		}
		return true;
	}
}
