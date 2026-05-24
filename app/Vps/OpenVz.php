<?php
namespace App\Vps;

use App\Vps;
use App\Os\VpsIps;
use App\Os\Xinetd;

/**
* OpenVZ virtualization backend (vzctl). See `.claude/rules/virt-backends.md`
* for the contract every public static method on this class must satisfy.
*/
class OpenVz
{
	public static $vpsList;


	/**
	* Returns the list of running OpenVZ containers by hostname/ID.
	* @return array
	*/
	public static function getRunningVps() {
		$out = trim(Vps::runCommand("vzlist -1 |sed s#' '#''#g", $return));
		if ($return > 1) {
			Vps::getLogger()->error("vzlist -1 failed (exit {$return})");
			return [];
		}
		return $out == '' ? [] : explode("\n", $out);
	}

	public static function getAllVps() {
		$out = trim(Vps::runCommand("vzlist -a -1 |sed s#' '#''#g", $return));
		if ($return > 1) {
			Vps::getLogger()->error("vzlist -a -1 failed (exit {$return})");
			return [];
		}
		return $out == '' ? [] : explode("\n", $out);
	}

	public static function vpsExists($vzid) {
		$vzidArg = escapeshellarg($vzid);
		/*status CTID
			Shows a container status. This is a line with five or six words, separated by spaces.
			First word is literally CTID.
			Second word is the numeric CT ID.
			Third word is showing whether this container exists or not, it can be either exist or deleted.
			Fourth word is showing the status of the container filesystem, it can be either mounted or unmounted.
			Fifth word shows if the container is running, it can be either running or down.
			Sixth word, if exists, is suspended. It appears if a dump file exists for a stopped container (see suspend).
		*/
		$parts = explode(' ', trim(Vps::runCommand("vzctl status {$vzidArg} 2>/dev/null")));
		return isset($parts[2]) && $parts[2] == 'exist';
	}

	public static function getList() {
		$vpsList = json_decode(Vps::runCommand("vzlist -j -a", $return), true);
		if ($return != 0 || !is_array($vpsList)) {
			Vps::getLogger()->error("vzlist -j -a failed or returned invalid JSON (exit {$return})");
			return [];
		}
		return $vpsList;
	}

	public static function getVps($vzid) {
		$vzidArg = escapeshellarg($vzid);
		$vps = json_decode(Vps::runCommand("vzlist -j {$vzidArg}", $return), true);
		if ($return != 0 || !is_array($vps) || count($vps) == 0) {
			return false;
		}
		return $vps[0];
	}

	public static function getVpsIps($vzid) {
		$vps = self::getVps($vzid);
		if ($vps === false || !isset($vps['ip'])) {
			return [];
		}
		return is_array($vps['ip']) ? $vps['ip'] : [];
	}

	public static function addIp($vzid, $ip) {
		if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
			$ip = $matches[1];
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			Vps::getLogger()->error("Invalid IPv4 address '{$ip}'; refusing to modify VPS.");
			return false;
		}
		$ips = self::getVpsIps($vzid);
		if (in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping adding IP '.$ip.' to '.$vzid.', it already exists in the VPS.');
			return false;
		}
		Vps::getLogger()->info('Adding IP '.$ip.' to '.$vzid);
		$vzidArg = escapeshellarg($vzid);
		$ipArg = escapeshellarg($ip);
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --setmode restart --ipadd {$ipArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("vzctl set --ipadd failed for {$vzid} (exit {$return})");
			return false;
		}
		$mainIp = VpsIps::getMainIp($vzid);
		if ($mainIp === null && empty($ips)) {
			VpsIps::setMainIp($vzid, $ip);
		} elseif ($mainIp !== null) {
			VpsIps::addAddonIp($mainIp, $ip);
		} else {
			// IP existed on the VPS before the registry knew about it -- adopt
			// the pre-existing first IP as main and register the new one as addon
			VpsIps::setMainIp($vzid, $ips[0]);
			VpsIps::addAddonIp($ips[0], $ip);
		}
		return true;
	}

	public static function removeIp($vzid, $ip) {
		if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
			$ip = $matches[1];
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			Vps::getLogger()->error("Invalid IPv4 address '{$ip}'; refusing to modify VPS.");
			return false;
		}
		$ips = self::getVpsIps($vzid);
		if (!in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping removing IP '.$ip.' from '.$vzid.', it does not appear to exist in the VPS.');
			return false;
		}
		Vps::getLogger()->info('Removing IP '.$ip.' from '.$vzid);
		$vzidArg = escapeshellarg($vzid);
		$ipArg = escapeshellarg($ip);
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --setmode restart --ipdel {$ipArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("vzctl set --ipdel failed for {$vzid} (exit {$return})");
			return false;
		}
		$mainIp = VpsIps::getMainIp($vzid);
		if ($mainIp === null) {
			$mainIp = isset($ips[0]) ? $ips[0] : null;
		}
		if ($mainIp === $ip) {
			VpsIps::removeMainIp($vzid);
		} else {
			VpsIps::removeAddonIp($mainIp, $ip);
		}
		return true;
	}

	public static function changeIp($vzid, $ipOld, $ipNew) {
		if (!filter_var($ipNew, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			Vps::getLogger()->error("Invalid new IPv4 address '{$ipNew}'; refusing to modify VPS.");
			return false;
		}
		$ips = self::getVpsIps($vzid);
		if (in_array($ipNew, $ips)) {
			Vps::getLogger()->error('The New IP '.$ipNew.' already exists as one of the IPs ('.implode(',', $ips).') for VPS '.$vzid);
			return false;
		}
		if (!in_array($ipOld, $ips)) {
			Vps::getLogger()->error('The Old IP '.$ipOld.' does not already exist as one of the IPs ('.implode(',', $ips).') for VPS '.$vzid);
			return false;
		}
		$vzidArg = escapeshellarg($vzid);
		$ipOldArg = escapeshellarg($ipOld);
		$ipNewArg = escapeshellarg($ipNew);
		if ($ipOld == $ips[0] && count($ips) > 1) {
			Vps::getLogger()->info("Changing IP from '{$ipOld}' to '{$ipNew}'");
			Vps::getLogger()->info("Removing all existing IPs and adding '{$ipNew}' to ensure it is still a primary IP");
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --ipdel all --ipadd {$ipNewArg}", $return));
			if ($return != 0)
				Vps::getLogger()->error("vzctl set --ipdel all --ipadd failed for {$vzid} (exit {$return})");
			for ($x = 1; $x < count($ips); $x++) {
				Vps::getLogger()->info("Adding IP {$ips[$x]} to {$vzid}");
				$extraArg = escapeshellarg($ips[$x]);
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --ipadd {$extraArg}", $return));
				if ($return != 0)
					Vps::getLogger()->error("vzctl set --ipadd {$ips[$x]} failed for {$vzid} (exit {$return})");
			}
		} else {
			Vps::getLogger()->info("Removing Old IP {$ipOld} from {$vzid}");
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --ipdel {$ipOldArg}", $return));
			if ($return != 0)
				Vps::getLogger()->error("vzctl set --ipdel failed for {$vzid} (exit {$return})");
			Vps::getLogger()->info("Adding New IP {$ipNew} to {$vzid}");
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --ipadd {$ipNewArg}", $return));
			if ($return != 0)
				Vps::getLogger()->error("vzctl set --ipadd failed for {$vzid} (exit {$return})");
		}
		Vps::getLogger()->info("Restarting Virtual Machine '{$vzid}'");
		Vps::getLogger()->write(Vps::runCommand("vzctl restart {$vzidArg}", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl restart failed for {$vzid} (exit {$return})");
		$recordedMain = VpsIps::getMainIp($vzid);
		if ($ipOld == $ips[0]) {
			// main IP changed -- swap mainips entry and re-key any addon entries
			VpsIps::setMainIp($vzid, $ipNew);
		} else {
			$mainKey = $recordedMain !== null ? $recordedMain : $ips[0];
			VpsIps::removeAddonIp($mainKey, $ipOld);
			VpsIps::addAddonIp($mainKey, $ipNew);
		}
		return true;
	}

	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit) {
		$templateArg = escapeshellarg($template);
		if (!file_exists('/vz/template/cache/'.$template)) { // if template doesnt exist download it
			if (strpos($template, '://') !== false) { // if web url
				$localPath = escapeshellarg('/vz/template/cache/'.basename(parse_url($template, PHP_URL_PATH)));
				Vps::getLogger()->write(Vps::runCommand("wget -O {$localPath} {$templateArg}", $return));
				if ($return != 0)
					Vps::getLogger()->error("wget failed for template {$template} (exit {$return})");
			} else {
				Vps::getLogger()->write(Vps::runCommand("vztmpl-dl --gpg-check --update {$templateArg}", $return));
				if ($return != 0)
					Vps::getLogger()->error("vztmpl-dl failed for template {$template} (exit {$return})");
			}
		}
		$pathInfo = pathinfo($template);
		if (isset($pathInfo['extension']) && $pathInfo['extension'] == 'xz') { // if template is .xz recompress it to .gz
			if (file_exists('/vz/template/cache/'.str_replace('.xz', '.gz', $template))) {
				Vps::getLogger()->write("Already Exists in .gz, not changing anything");
			} else {
				Vps::getLogger()->write("Recompressing {$template} to .gz");
				Vps::getLogger()->write(Vps::runCommand("xz -d --keep ".escapeshellarg('/vz/template/cache/'.$template)));
				$uncompressed = escapeshellarg('/vz/template/cache/'.$pathInfo['filename']);
				Vps::getLogger()->write(Vps::runCommand("gzip -9 {$uncompressed}"));
			}
		}
		$uname = posix_uname();
		$limit = $uname['machine'] == 'x86_64' ? '9223372036854775807' : '2147483647';
		$layout = '';
		$force = '';
		if (preg_match('/vzctl set.*--force/', Vps::runCommand('vzctl'))) {
			$layout = trim(Vps::runCommand('mount |grep "^$(df /vz|tail -n 1|cut -d" " -f1)"|cut -d" " -f')) == 'ext3' || (preg_match('/^2\.6\.(\d+)/', $uname['release'], $matches) && intval($matches[1]) < 32) ? '--layout simfs' : '--layout ploop';
			$force = '--force';
		}
		$config = !file_exists('/etc/vz/conf/ve-vps.small.conf') ? '' : '--config vps.small';
		$template = str_replace(['.tar.gz', '.tar.xz'], ['', ''], $template);
		$templateArg = escapeshellarg($template);
		$passwordArg = escapeshellarg($password);
		$hostnameArg = escapeshellarg($hostname);
		$vzidArg = escapeshellarg($vzid);
		$ipArg = escapeshellarg($ip);
		Vps::getLogger()->write(Vps::runCommand("vzctl create {$vzidArg} --ostemplate {$templateArg} {$layout} {$config} --ipadd {$ipArg} --hostname {$hostnameArg}", $return)); // create vps
		if ($return != 0) {
			Vps::getLogger()->error("vzctl create failed for {$vzid} (exit {$return}); retrying with alternate layout");
			Vps::getLogger()->write(Vps::runCommand("vzctl destroy {$vzidArg}"));
			$layout = $layout == '--layout ploop' ? '--layout simfs' : $layout;
			Vps::getLogger()->write(Vps::runCommand("vzctl create {$vzidArg} --ostemplate {$templateArg} {$layout} {$config} --ipadd {$ipArg} --hostname {$hostnameArg}", $return));
			if ($return != 0)
				Vps::getLogger()->error("vzctl create retry also failed for {$vzid} (exit {$return})");
		}
		@mkdir('/vz/root/'.$vzid, 0777, true);
		$slices = $cpu;
		$wiggle = 1000;
		$dCacheWiggle = 400000;
		$cpuUnits = 1500 * $slices;
		$avNumProc = 300 * $slices;
		$avNumProcB = $avNumProc;
		$numProc = 250 * $slices;
		$numProcB = $numProc;
		$numFlock = 8200 * $slices;
		$numFlockB = $numFlock;
		$numIptent = 2000 * $slices;
		$numIptentB = $numIptent;
		$numPty = 35 + (24 * $slices);
		$numPtyB = $numPty;
		$numTcpSock = 1800 + $slices;
		$numTcpSockB = $numTcpSock;
		$numOtherSock = 1900 * $slices;
		$numOtherSockB = $numOtherSock;
		$numFile = 32 * $avNumProc;
		$numFileB = $numFile;
		$dgramRcvBuf = 2075488 * $slices;
		$dgramRcvBufB = $dgramRcvBuf;
		$tcpRcvBuf = 8958464 * $slices;
		$tcpRcvBufB = (2561 * $numTcpSock) + $tcpRcvBuf;
		$tcpSndBuf = 8958464 * $slices;
		$tcpSndBufB = (2561 * $numTcpSock) + $tcpSndBuf;
		$otherSockBuf = 775488 * $slices;
		$otherSockBufB = (2561 * $numOtherSock) + $otherSockBuf;
		$shmPages = 100000 * $slices;
		$shmPagesB = $shmPages;
		$dCacheSize = 384 * $numFile + $dCacheWiggle;
		$dCacheSizeB = 384 * $numFileB + $dCacheWiggle;
		$vmGuarPages = ((256 * 2048) * $slices) - $wiggle;
		$privVmPages = ((256 * 2048) * $slices);
		$privVmPagesB = $privVmPages + $wiggle;
		$oomGuarPages = $vmGuarPages;
		$kMemSize = (45 * 1024 * $avNumProc + $dCacheSize);
		$kMemSizeB = (45 * 1024 * $avNumProcB + $dCacheSizeB);
		$diskSpace = $hd * 1024;
		$diskSpaceB = $diskSpace;
		$ram = floor($ram / 1024);
		$rootPwArg = escapeshellarg('root:'.$password);
		$kvmPwArg = escapeshellarg('kvm:'.$password);
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save {$force} --cpuunits {$cpuUnits} --cpus {$cpu} --diskspace {$diskSpace}:{$diskSpaceB} --numproc {$numProc}:{$numProcB} --numtcpsock {$numTcpSock}:{$numTcpSockB} --numothersock {$numOtherSock}:{$numOtherSockB} --vmguarpages {$vmGuarPages}:{$limit} --kmemsize unlimited:unlimited --tcpsndbuf {$tcpSndBuf}:{$tcpSndBufB} --tcprcvbuf {$tcpRcvBuf}:{$tcpRcvBufB} --othersockbuf {$otherSockBuf}:{$otherSockBufB} --dgramrcvbuf {$dgramRcvBuf}:{$dgramRcvBufB} --oomguarpages {$oomGuarPages}:{$limit} --privvmpages {$privVmPages}:{$privVmPagesB} --numfile {$numFile}:{$numFileB} --numflock {$numFlock}:{$numFlockB} --physpages 0:{$limit} --dcachesize {$dCacheSize}:{$dCacheSizeB} --numiptent {$numIptent}:{$numIptentB} --avnumproc {$avNumProc}:{$avNumProc} --numpty {$numPty}:{$numPtyB} --shmpages {$shmPages}:{$shmPagesB} 2>&1"));
		if (file_exists('/proc/vz/vswap')) {
			Vps::getLogger()->write(Vps::runCommand("/bin/mv -f /etc/vz/conf/{$vzid}.conf /etc/vz/conf/{$vzid}.conf.backup"));
			Vps::getLogger()->write(Vps::runCommand("grep -Ev '^(KMEMSIZE|PRIVVMPAGES)=' > /etc/vz/conf/{$vzid}.conf <  /etc/vz/conf/{$vzid}.conf.backup"));
			Vps::getLogger()->write(Vps::runCommand("/bin/rm -f /etc/vz/conf/{$vzid}.conf.backup"));
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --ram {$ram}M --swap {$ram}M --save"));
			// --reset_ub was removed in newer vzctl; only call if the flag is supported
			if (preg_match('/--reset_ub/', Vps::runCommand('vzctl 2>&1')))
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --reset_ub"));
		}
		if (file_exists('/usr/sbin/vzcfgvalidate')) // validate vps
			Vps::getLogger()->write(Vps::runCommand("/usr/sbin/vzcfgvalidate -r /etc/vz/conf/{$vzid}.conf"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --devices c:1:3:rw --devices c:10:200:rw --capability net_admin:on"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --nameserver '8.8.8.8 64.20.34.50' --searchdomain interserver.net --onboot yes"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --noatime yes 2>/dev/null"));
		foreach ($extraIps as $extraIp) {
			$extraIpArg = escapeshellarg($extraIp);
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --ipadd {$extraIpArg} 2>&1"));
		}
		Vps::getLogger()->write(Vps::runCommand("vzctl start {$vzidArg} 2>&1", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl start failed for {$vzid} (exit {$return})");
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --userpasswd {$rootPwArg} 2>&1"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzidArg} mkdir -p /dev/net"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzidArg} mknod /dev/net/tun c 10 200"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzidArg} chmod 600 /dev/net/tun"));
		Vps::getLogger()->write(Vps::runCommand("/root/cpaneldirect/vzopenvztc.sh > /root/vzopenvztc.sh && sh /root/vzopenvztc.sh"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --userpasswd {$rootPwArg} 2>&1"));
		$sshCnf = glob('/etc/*ssh/sshd_config');
		if (count($sshCnf) > 0) { // setup ssh
			$sshCnf = $sshCnf[0];
			// Note: $sshKey/$ssh_key are not in scope here — block kept commented for reference.
			//if (isset($sshKey)) { // install ssh key (currently never injected)
			//	Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzidArg} \"mkdir -p /root/.ssh\""));
			//	$sshKeyArg = escapeshellarg($sshKey);
			//	Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzidArg} \"echo {$sshKeyArg} >> /root/.ssh/authorized_keys2\""));
			//	Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzidArg} \"chmod go-w /root; chmod 700 /root/.ssh; chmod 600 /root/.ssh/authorized_keys2\""));
			//}
			$sshCnfData = @file_get_contents($sshCnf);
			if ($sshCnfData === false) {
				Vps::getLogger()->error("Could not read {$sshCnf}");
			} elseif (!preg_match('/^PermitRootLogin/', $sshCnfData)) {
				Vps::getLogger()->write('Adding PermitRootLogin line to '.$sshCnf);
				$sshCnfData .= PHP_EOL.'PermitRootLogin yes'.PHP_EOL;
				if (@file_put_contents($sshCnf, $sshCnfData) === false)
					Vps::getLogger()->error("Could not write {$sshCnf} (check permissions)");
				Vps::getLogger()->write(Vps::runCommand('kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]'.$vzidArg.'[[:space:]]" | sed s#"'.$vzidArg.'.*ssh.*$"#""#g)'));
			} elseif (preg_match('/^PermitRootLogin (.*)$/m', $sshCnfData, $matches) && $matches[1] != 'yes') {
				Vps::getLogger()->write('Replacing PermitRootLogin line options in '.$sshCnf);
				$sshCnfData = str_replace($matches[0], str_replace($matches[1], 'yes', $matches[0]), $sshCnfData);
				if (@file_put_contents($sshCnf, $sshCnfData) === false)
					Vps::getLogger()->error("Could not write {$sshCnf} (check permissions)");
				Vps::getLogger()->write(Vps::runCommand('kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]'.$vzidArg.'[[:space:]]" | sed s#"'.$vzidArg.'.*ssh.*$"#""#g)'));
			}
		}
		if ($template == 'centos-7-x86_64-breadbasket') {
			Vps::getLogger()->write("Sleeping for a minute to workaround a known race");
			sleep(60);
			Vps::getLogger()->write("That was a pleasant nap.. back to the grind...");
		}
		if ($template == 'centos-7-x86_64-nginxwordpress') {
			Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzidArg} /root/change.sh {$passwordArg} 2>&1"));
		}
		if ($template == 'ubuntu-15.04-x86_64-xrdp') {
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --userpasswd {$kvmPwArg} 2>&1"));
		}
		self::blockSmtp($vzid);
		return $return == 0;
	}

	public static function enableAutostart($vzid) {
		Vps::getLogger()->info('Enabling On-Boot Automatic Startup of the VPS');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --onboot yes", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl set --onboot yes failed for {$vzid} (exit {$return})");
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --disabled no", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl set --disabled no failed for {$vzid} (exit {$return})");
	}

	public static function disableAutostart($vzid) {
		Vps::getLogger()->info('Disabling On-Boot Automatic Startup of the VPS');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --onboot no", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl set --onboot no failed for {$vzid} (exit {$return})");
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzidArg} --save --disabled yes", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl set --disabled yes failed for {$vzid} (exit {$return})");
	}

	public static function startVps($vzid) {
		Vps::getLogger()->info('Starting the VPS');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("vzctl start {$vzidArg}", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl start failed for {$vzid} (exit {$return})");
	}

	public static function stopVps($vzid, $fast = false) {
		Vps::getLogger()->info('Stopping the VPS');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("vzctl stop {$vzidArg}", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl stop failed for {$vzid} (exit {$return})");
	}

	public static function destroyVps($vzid) {
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("vzctl destroy {$vzidArg}", $return));
		if ($return != 0)
			Vps::getLogger()->error("vzctl destroy failed for {$vzid} (exit {$return})");
	}

	public static function setupRouting($vzid, $id) {
		self::blockSmtp($vzid, $id);
	}

	public static function blockSmtp($vzid, $id = false) {
		Vps::getLogger()->write(Vps::runCommand("/admin/vzenable blocksmtp {$vzid}"));
	}

	public static function setupWebuzo($vzid) {
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y update'", $return, 320));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y remove httpd sendmail xinetd firewalld samba samba-libs samba-common-tools samba-client samba-common samba-client-libs samba-common-libs rpcbind; userdel apache'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install nano net-tools'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'rsync -a rsync://rsync.is.cc/admin /admin;/admin/yumcron;echo \"/usr/local/emps/bin/php /usr/local/webuzo/cron.php\" > /etc/cron.daily/wu.sh && chmod +x /etc/cron.daily/wu.sh'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'wget -N http://files.webuzo.com/install.sh -O /install.sh'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'chmod +x /install.sh;bash -l /install.sh;rm -f /install.sh'", $return, 320));
		Vps::getLogger()->info("Sleeping for a minute to workaround a known race");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
	}

	public static function setupCpanel($vzid) {
		Vps::getLogger()->info("Sleeping for a minute to workaround a known race");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install perl nano screen wget psmisc net-tools'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'wget http://layer1.cpanel.net/latest'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'systemctl disable firewalld.service; systemctl mask firewalld.service; rpm -e firewalld xinetd httpd'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'bash -l latest'", $return, 320));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y remove ea-apache24-mod_ruid2'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'killall httpd; if [ -e /bin/systemctl ]; then systemctl stop httpd.service; else service httpd stop; fi'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-liblsapi'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-apache24-mod_headers'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-apache24-mod_lsapi'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-apache24-mod_env'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-apache24-mod_deflate'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-apache24-mod_expires'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-apache24-mod_suexec'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-php72-php-litespeed'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-php72-php-opcache'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-php72-php-mysqlnd'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-php72-php-mcrypt'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-php72-php-gd'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-php72-php-mbstring'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} '/usr/local/cpanel/bin/rebuild_phpconf  --default=ea-php72 --ea-php72=lsapi'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} '/usr/sbin/whmapi1 php_ini_set_directives directive-1=post_max_size%3A32M directive-2=upload_max_filesize%3A128M directive-3=memory_limit%3A256M version=ea-php72'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'cd /opt/cpanel; for i in \$(find * -maxdepth 0 -name \"ea-php*\"); do /usr/local/cpanel/bin/rebuild_phpconf --default=ea-php72 --\$i=lsapi; done'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} '/scripts/restartsrv_httpd'", $return, 60));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'rsync -a rsync://rsync.is.cc/admin /admin && cd /etc/cron.daily && ln -s /admin/wp/webuzo_wp_cli_auto.sh /etc/cron.daily/webuzo_wp_cli_auto.sh'", $return, 320));
	}
}
