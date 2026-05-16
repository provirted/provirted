<?php
namespace App\Os;

use App\Vps;

class Os
{

    /**
    * returns the systems main ip address
    * @return string the main ip address, or empty string on failure
    */
	public static function getIp() {
		$defaultRoute = trim(Vps::runCommand('ip route list | grep "^default" | sed s#"^default.*dev "#""#g | head -n 1 | cut -d" " -f1'));
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $defaultRoute)) {
			Vps::getLogger()->error("Could not detect default route device (got '{$defaultRoute}')");
			return '';
		}
		$ip = trim(Vps::runCommand("ifconfig ".escapeshellarg($defaultRoute)." | grep inet | grep -v inet6 | awk '{ print $2 }' | cut -d: -f2"));
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			Vps::getLogger()->error("Could not detect IP for {$defaultRoute} (got '{$ip}')");
			return '';
		}
		return $ip;
	}

    /**
    * whether or not the system is a redhat based os
    * @return bool is a redhat based os
    */
	public static function isRedhatBased() {
		return file_exists('/etc/redhat-release');
	}

    /**
    * gets the redhat distro version
    * @return float redhat distro version
    */
	public static function getRedhatVersion() {
		return floatval(trim(Vps::runCommand("cat /etc/redhat-release |sed s#'^[^0-9]* \([0-9\.]*\).*$'#'\\1'#g")));
	}

    /**
    * gets the e2fsprogs version
    * @return float e2fsprogs version
    */
	public static function getE2fsprogsVersion() {
		return floatval(trim(Vps::runCommand("e2fsck -V 2>&1 |head -n 1 | cut -d' ' -f2 | cut -d'.' -f1-2")));
	}

    /**
    * gets the total system memory in kB
    * @return float total system memory in kb, 0 on failure
    */
	public static function getTotalRam() {
		$meminfo = @file_get_contents('/proc/meminfo');
		if ($meminfo === false) {
			Vps::getLogger()->error('Could not read /proc/meminfo');
			return 0.0;
		}
		if (!preg_match('/^MemTotal:\s+(\d+)\skB/', $meminfo, $matches)) {
			Vps::getLogger()->error('No MemTotal line in /proc/meminfo');
			return 0.0;
		}
		return floatval($matches[1]);
	}

    /**
    * gets the usable memory in kb (70% of total memory)
    * @param int $pct (default 95) optional percent of total ram to use
    * @return float usable memory in kb
    */
	public static function getUsableRam(int $pct = 95) {
		$ram = floor(self::getTotalRam() / 100 * $pct);
		return $ram;
	}

    /**
    * gets the numer of cpus/cores
    * @return int the number of cpus/cores
    *
    */
	public static function getCpuCount() {
		$out = Vps::runCommand("lscpu", $return);
		if ($return != 0) {
			Vps::getLogger()->error("lscpu failed (exit {$return})");
			return 0;
		}
		if (!preg_match('/CPU\(s\):\s+(\d+)/', $out, $matches)) {
			Vps::getLogger()->error('Could not detect CPU count from lscpu output');
			return 0;
		}
		return intval($matches[1]);
	}

	/**
	* checks the os dependancies making sure some things are installed
	*/
	public static function checkDeps() {
		Vps::getLogger()->info('Checking for dependancy failures and fixing them');
    	if (self::isRedhatBased() && self::getRedhatVersion() < 7) {
			if (self::getE2fsprogsVersion() <= 1.41) {
				if (!file_exists('/opt/e2fsprogs/sbin/e2fsck')) {
					Vps::getLogger()->write(Vps::runCommand("/admin/ports/install e2fsprogs"));
				}
			}
    	}
	}
}
