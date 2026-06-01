<?php
namespace App\Vps;

use App\XmlToArray;
use App\Vps;
use App\Os\Dhcpd;
use App\Os\Dhcpd6;
use App\Os\VpsIps;
use App\Os\Xinetd;

class Kvm
{
	/**
	* Returns the list of running KVM domains by name.
	* @return array
	*/
	public static function getRunningVps() {
		$out = trim(Vps::runCommand("virsh list --name", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh list --name failed (exit {$return})");
			return [];
		}
		return $out == '' ? [] : explode("\n", $out);
	}

	/**
	* Returns the list of all KVM domains by name (running or stopped).
	* @return array
	*/
	public static function getAllVps() {
		$out = trim(Vps::runCommand("virsh list --all --name", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh list --all --name failed (exit {$return})");
			return [];
		}
		return $out == '' ? [] : explode("\n", $out);
	}

	/**
	* True if libvirt knows about the given domain (running or stopped).
	* @param string $vzid VPS identifier
	* @return bool
	*/
	public static function vpsExists($vzid) {
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh dominfo {$vzidArg} >/dev/null 2>&1", $return));
		return $return == 0;
	}

	/**
	* Returns the parsed `vz` libvirt storage pool XML.
	* @return array
	*/
	public static function getPool() {
		$pool = XmlToArray::go(trim(Vps::runCommand("virsh pool-dumpxml vz 2>/dev/null")));
		return $pool;
	}

	/**
	* Returns the storage pool type (e.g. 'logical', 'zfs', 'dir').
	* Triggers a pool-creation script if no pool exists, and starts the pool if it's defined but inactive.
	* @return string
	*/
	public static function getPoolType() {
		$pool = trim(Vps::runCommand('virsh pool-dumpxml vz 2>/dev/null|grep type|cut -d\\\' -f2'));
		if ($pool == '') {
			$base = Vps::$base;
			Vps::getLogger()->write(Vps::runCommand("{$base}/create_libvirt_storage_pools.sh"));
			$pool = trim(Vps::runCommand('virsh pool-dumpxml vz 2>/dev/null|grep type|cut -d\\\' -f2'));
		}
		if (preg_match('/vz/', Vps::runCommand("virsh pool-list --inactive"))) {
			Vps::getLogger()->write(Vps::runCommand("virsh pool-start vz"));
		}
		return $pool;
	}

	/**
	* gets the vps details in xml format
	*
	* @param string $vzid vps identifier
	* @return string xml formatted vps information, empty string on failure
	*/
	public static function getVpsXml($vzid) {
		$vzidArg = escapeshellarg($vzid);
		$xml = trim(Vps::runCommand("virsh dumpxml {$vzidArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh dumpxml {$vzid} failed (exit {$return})");
			return '';
		}
		return $xml;
	}

	/**
	* gets the vps details in an array
	*
	* @param string $vzid vps identifier
	* @return array|false the array of vps information, false on failure
	*/
	public static function getVps($vzid) {
		$xml = self::getVpsXml($vzid);
		if ($xml == '') {
			return false;
		}
		$vps = XmlToArray::go($xml);
		if (!is_array($vps) || !isset($vps['domain'])) {
			Vps::getLogger()->error("Could not parse virsh XML for {$vzid}");
			return false;
		}
		return $vps;
	}

	public static function getVpsMac($vzid) {
		$response = self::getVps($vzid);
		if ($response === false || !isset($response['domain']['devices']['interface'])) {
			return '';
		}
		$interface = isset($response['domain']['devices']['interface']['mac_attr']) ? $response['domain']['devices']['interface'] : $response['domain']['devices']['interface'][0];
		if (isset($interface['mac_attr']['address'])) {
			return $interface['mac_attr']['address'];
		}
		return '';
	}

	public static function getVpsIps($vzid) {
		$response = self::getVps($vzid);
		if ($response === false || !isset($response['domain']['devices']['interface'])) {
			return [];
		}
		$interface = isset($response['domain']['devices']['interface']['mac_attr']) ? $response['domain']['devices']['interface'] : $response['domain']['devices']['interface'][0];
		if (!isset($interface['filterref']) || !is_array($interface['filterref'])) {
			return [];
		}
		$params = $interface['filterref'];
		$ips = [];
		if (array_key_exists('parameter_attr', $params) && $params['parameter_attr']['name'] == 'IP') {
			$ips[] = $params['parameter_attr']['value'];
		} elseif (isset($params['parameter']) && is_array($params['parameter'])) {
			foreach ($params['parameter'] as $idx => $data) {
				if (array_key_exists('name', $data) && $data['name'] == 'IP') {
					$ips[] = $data['value'];
				}
			}
		}
		return $ips;
	}

	public static function addIp($vzid, $ip) {
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
		$xmlFile = tempnam(sys_get_temp_dir(), 'provirted-kvm-');
		if ($xmlFile === false) {
			Vps::getLogger()->error("Could not create temp file for {$vzid} XML; refusing to proceed.");
			return false;
		}
		$xmlFileArg = escapeshellarg($xmlFile);
		Vps::getLogger()->write(Vps::runCommand("virsh dumpxml --inactive --security-info {$vzidArg} > {$xmlFileArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh dumpxml failed for {$vzid} (exit {$return})");
			@unlink($xmlFile);
			return false;
		}
		Vps::getLogger()->write(Vps::runCommand("sed s#\"</filterref>\"#\"  <parameter name='IP' value='{$ip}'/>\\n    </filterref>\"#g -i {$xmlFileArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("sed failed editing {$xmlFile} (exit {$return})");
			@unlink($xmlFile);
			return false;
		}
		Vps::getLogger()->write(Vps::runCommand("virsh define {$xmlFileArg}", $return));
		@unlink($xmlFile);
		if ($return != 0) {
			Vps::getLogger()->error("virsh define failed for {$vzid} (exit {$return})");
			return false;
		}
		// dhcpd.vps holds exactly one entry per VPS, mapping the MAC to the
		// VPS's main IP. Only (re)write it when this IP is becoming the main
		// IP for this VPS -- otherwise the existing main IP entry would be
		// stomped every time an additional IP is added.
		$hosts = Dhcpd::getHosts();
		$mainIp = isset($hosts[$vzid]) ? $hosts[$vzid]['ip'] : VpsIps::getMainIp($vzid);
		if ($mainIp === null || $mainIp === '') {
			$mac = self::getVpsMac($vzid);
			if ($mac == '') {
				Vps::getLogger()->error('Could not determine MAC for '.$vzid.'; DHCP not updated.');
				return false;
			}
			if (!Dhcpd::setup($vzid, $ip, $mac)) {
				Vps::getLogger()->error('Dhcpd::setup reported failure; libvirt XML was updated but DHCP was not.');
				return false;
			}
			VpsIps::setMainIp($vzid, $ip);
		} else {
			VpsIps::addAddonIp($mainIp, $ip);
		}
		return true;
	}

	public static function removeIp($vzid, $ip) {
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
		$xmlFile = tempnam(sys_get_temp_dir(), 'provirted-kvm-');
		if ($xmlFile === false) {
			Vps::getLogger()->error("Could not create temp file for {$vzid} XML; refusing to proceed.");
			return false;
		}
		$xmlFileArg = escapeshellarg($xmlFile);
		$dumpFile = tempnam(sys_get_temp_dir(), 'provirted-kvm-dump-');
		if ($dumpFile === false) {
			Vps::getLogger()->error("Could not create temp file for {$vzid} dump; refusing to proceed.");
			@unlink($xmlFile);
			return false;
		}
		$dumpFileArg = escapeshellarg($dumpFile);
		// dump first so virsh exit code is captured independently of grep
		Vps::getLogger()->write(Vps::runCommand("virsh dumpxml --inactive --security-info {$vzidArg} > {$dumpFileArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh dumpxml failed for {$vzid} (exit {$return})");
			@unlink($xmlFile);
			@unlink($dumpFile);
			return false;
		}
		// then strip the IP line (grep returning 1 = no matches removed, also fine)
		Vps::getLogger()->write(Vps::runCommand("grep -v \"value='{$ip}'\" {$dumpFileArg} > {$xmlFileArg}", $return));
		@unlink($dumpFile);
		if ($return > 1) {
			Vps::getLogger()->error("grep failed filtering {$dumpFile} (exit {$return})");
			@unlink($xmlFile);
			return false;
		}
		Vps::getLogger()->write(Vps::runCommand("virsh define {$xmlFileArg}", $return));
		@unlink($xmlFile);
		if ($return != 0) {
			Vps::getLogger()->error("virsh define failed for {$vzid} (exit {$return})");
			return false;
		}
		$hosts = Dhcpd::getHosts();
		$dhcpMain = isset($hosts[$vzid]) ? $hosts[$vzid]['ip'] : null;
		$recordedMain = VpsIps::getMainIp($vzid);
		$mainIp = $dhcpMain !== null ? $dhcpMain : $recordedMain;
		if ($mainIp === $ip) {
			if ($dhcpMain !== null && !Dhcpd::remove($vzid)) {
				Vps::getLogger()->error('Dhcpd::remove reported failure; libvirt XML was updated but DHCP entry was not removed.');
				return false;
			}
			// removeMainIp also clears any addon entries keyed off this main IP
			VpsIps::removeMainIp($vzid);
		} else {
			VpsIps::removeAddonIp($mainIp, $ip);
		}
		return true;
	}

	/**
	* Changes one of a VPS's IP addresses by removing the old and adding the new.
	* KVM doesn't have a native rename operation for filterref entries; we sequence
	* removeIp + addIp and surface a single bool so callers can act on the outcome.
	*
	* @param string $vzid VPS identifier
	* @param string $ipOld existing IP to drop
	* @param string $ipNew replacement IP
	* @return bool true on success, false on failure
	*/
	public static function changeIp($vzid, $ipOld, $ipNew) {
		if ($ipOld === $ipNew) {
			Vps::getLogger()->error("changeIp called with identical old and new IP '{$ipOld}'; nothing to do.");
			return false;
		}
		if (!filter_var($ipNew, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			Vps::getLogger()->error("Invalid new IPv4 address '{$ipNew}'; refusing to modify VPS.");
			return false;
		}
		$ips = self::getVpsIps($vzid);
		if (in_array($ipNew, $ips)) {
			Vps::getLogger()->error("The new IP '{$ipNew}' already exists on VPS {$vzid}.");
			return false;
		}
		if (!in_array($ipOld, $ips)) {
			Vps::getLogger()->error("The old IP '{$ipOld}' is not currently assigned to VPS {$vzid}.");
			return false;
		}
		Vps::getLogger()->info("Changing IP on {$vzid} from {$ipOld} to {$ipNew}");
		// If we're changing the main IP, snapshot the addon list so
		// removeMainIp() inside removeIp() doesn't permanently drop them.
		$hosts = Dhcpd::getHosts();
		$dhcpMain = isset($hosts[$vzid]) ? $hosts[$vzid]['ip'] : null;
		$recordedMain = VpsIps::getMainIp($vzid);
		$mainIp = $dhcpMain !== null ? $dhcpMain : $recordedMain;
		$isMain = ($mainIp === $ipOld);
		$savedAddons = $isMain ? VpsIps::getAddonIps($ipOld) : [];
		if (!self::removeIp($vzid, $ipOld)) {
			Vps::getLogger()->error("removeIp({$ipOld}) failed; aborting changeIp.");
			return false;
		}
		if (!self::addIp($vzid, $ipNew)) {
			Vps::getLogger()->error("addIp({$ipNew}) failed after removing {$ipOld}; VPS may now have fewer IPs than before.");
			return false;
		}
		if ($isMain) {
			foreach ($savedAddons as $addon) {
				VpsIps::addAddonIp($ipNew, $addon);
			}
		}
		return true;
	}

	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $maxRam, $maxCpu, $useAll, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit) {
		Vps::getLogger()->info('Creating VPS Definition');
		$base = Vps::$base;
		Vps::getLogger()->indent();
		$vzidArg = escapeshellarg($vzid);
		$xmlFile = tempnam(sys_get_temp_dir(), 'provirted-kvm-define-');
		if ($xmlFile === false) {
			Vps::getLogger()->error("Could not create temp file for {$vzid} XML; aborting defineVps.");
			Vps::getLogger()->unIndent();
			return false;
		}
		$xmlFileArg = escapeshellarg($xmlFile);
		$backupFile = $xmlFile.'.backup';
		$backupArg = escapeshellarg($backupFile);
		if (self::vpsExists($vzid)) {
			Vps::getLogger()->write(Vps::runCommand("virsh destroy {$vzidArg}"));
			Vps::getLogger()->write(Vps::runCommand("virsh dumpxml {$vzidArg} > {$xmlFileArg}", $return));
			if ($return != 0)
				Vps::getLogger()->error("virsh dumpxml failed for {$vzid} (exit {$return})");
			Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$xmlFileArg} {$backupArg}"));
			Vps::getLogger()->write(Vps::runCommand("virsh undefine {$vzidArg}", $return));
			if ($return != 0)
				Vps::getLogger()->error("virsh undefine failed for {$vzid} (exit {$return})");
			Vps::getLogger()->write(Vps::runCommand("mv -f {$backupArg} {$xmlFileArg}"));
		} else {
			if (!file_exists($base.'/windows.xml')) {
				Vps::getLogger()->error("Required template not found: {$base}/windows.xml; aborting defineVps.");
				@unlink($xmlFile);
				Vps::getLogger()->unIndent();
				return false;
			}
			$baseXmlArg = escapeshellarg($base.'/windows.xml');
			if ($pool != 'zfs') {
				Vps::getLogger()->debug('Removing UUID Filterref and IP information');
				Vps::getLogger()->write(Vps::runCommand("grep -v -e uuid -e filterref -e \"<parameter name='IP'\" {$baseXmlArg} | sed s#\"windows\"#\"{$vzid}\"#g > {$xmlFileArg}"));
			} else {
				Vps::getLogger()->debug('Removing UUID information');
				Vps::getLogger()->write(Vps::runCommand("grep -v -e uuid {$baseXmlArg} | sed -e s#\"windows\"#\"{$vzid}\"#g -e s#\"/dev/vz/{$vzid}\"#\"{$device}\"#g > {$xmlFileArg}"));
			}
			if (!file_exists('/usr/libexec/qemu-kvm') && file_exists('/usr/bin/kvm')) {
				Vps::getLogger()->debug('Replacing KVM Binary Path');
				Vps::getLogger()->write(Vps::runCommand("sed s#\"/usr/libexec/qemu-kvm\"#\"/usr/bin/kvm\"#g -i {$xmlFileArg}"));
			}
		}
		if ($useAll == true || $ip == 'none') {
			Vps::getLogger()->debug('Removing IP information');
			Vps::getLogger()->write(Vps::runCommand("sed -e s#\"^.*<parameter name='IP.*$\"#\"\"#g -e  s#\"^.*filterref.*$\"#\"\"#g -i {$xmlFileArg}"));
		} else {
			Vps::getLogger()->debug('Replacing UUID Filterref and IP information');
			$repl = "<parameter name='IP' value='{$ip}'/>";
			if (count($extraIps) > 0)
				foreach ($extraIps as $extraIp)
					$repl = "{$repl}\\n        <parameter name='IP' value='{$extraIp}'/>";
			Vps::getLogger()->write(Vps::runCommand("sed s#\"<parameter name='IP' value.*/>\"#\"{$repl}\"#g -i {$xmlFileArg}"));
		}
		if ($mac != '') {
			Vps::getLogger()->debug('Replacing MAC address');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"<mac address='.*'\"#\"<mac address='{$mac}'\"#g -i {$xmlFileArg}"));
		} else {
			Vps::getLogger()->debug('Removing MAC address');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"^.*<mac address.*$\"#\"\"#g -i {$xmlFileArg}"));
		}
		Vps::getLogger()->debug('Setting CPU limits');
        if ($useAll === true || substr($template, 0, 7) != 'windows') {
            Vps::getLogger()->write(Vps::runCommand("sed s#\"<cpu mode='host-model'/>\"#\"<cpu mode='host-passthrough' check='none'>\\n    <cache mode='passthrough'/>\\n  </cpu>\"#g -i {$xmlFileArg}"));
        }
        Vps::getLogger()->write(Vps::runCommand("sed s#\"<\(vcpu.*\)>.*</vcpu>\"#\"<vcpu placement='static' current='{$cpu}'>{$cpu}</vcpu>\"#g -i {$xmlFileArg}"));
		Vps::getLogger()->debug('Setting Max Memory limits');
        Vps::getLogger()->write(Vps::runCommand("sed s#\"<memory.*memory>\"#\"<memory unit='KiB'>{$ram}</memory>\"#g -i {$xmlFileArg}"));
		Vps::getLogger()->debug('Setting Memory limits');
		Vps::getLogger()->write(Vps::runCommand("sed s#\"<currentMemory.*currentMemory>\"#\"<currentMemory unit='KiB'>{$ram}</currentMemory>\"#g -i {$xmlFileArg}"));
		if (trim(Vps::runCommand("grep -e \"flags.*ept\" -e \"flags.*npt\" /proc/cpuinfo")) != '') {
			Vps::getLogger()->debug('Adding HAP features flag');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"<features>\"#\"<features>\\n    <hap/>\"#g -i {$xmlFileArg}"));
		}
		if (trim(Vps::runCommand("date \"+%Z\"")) == 'PDT') {
			Vps::getLogger()->debug('Setting Timezone to PST');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"America/New_York\"#\"America/Los_Angeles\"#g -i {$xmlFileArg}"));
		}
		if (file_exists('/etc/lsb-release')) {
			if (substr($template, 0, 7) == 'windows') {
				Vps::getLogger()->debug('Adding HyperV block');
				Vps::getLogger()->write(Vps::runCommand("sed -e s#\"</features>\"#\"  <hyperv>\\n      <relaxed state='on'/>\\n      <vapic state='on'/>\\n      <spinlocks state='on' retries='8191'/>\\n    </hyperv>\\n  </features>\"#g -i {$xmlFileArg}"));
				Vps::getLogger()->debug('Adding HyperV timer');
				Vps::getLogger()->write(Vps::runCommand("sed -e s#\"<clock offset='timezone' timezone='\([^']*\)'/>\"#\"<clock offset='timezone' timezone='\\1'>\\n    <timer name='hypervclock' present='yes'/>\\n  </clock>\"#g -i {$xmlFileArg}"));
			}
			Vps::getLogger()->debug('Customizing SCSI controller');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"\(<controller type='scsi' index='0'.*\)>\"#\"\\1 model='virtio-scsi'>\\n      <driver queues='{$cpu}'/>\"#g -i {$xmlFileArg}"));
		}
		Vps::getLogger()->write(Vps::runCommand("virsh define {$xmlFileArg}", $return));
		@unlink($xmlFile);
		Vps::getLogger()->unIndent();
		if ($return != 0) {
			Vps::getLogger()->error("virsh define failed for {$vzid} (exit {$return})");
			return false;
		}
		if ($ip != 'none') {
			Dhcpd::setup($vzid, $ip, $mac);
			VpsIps::setMainIp($vzid, $ip);
			if (is_array($extraIps)) {
				foreach ($extraIps as $extra) {
					if ($extra != '' && $extra !== $ip) {
						VpsIps::addAddonIp($ip, $extra);
					}
				}
			}
		}
		if ($ipv6Ip !== false) {
			Dhcpd6::setup($vzid, $ipv6Ip, $ipv6Range, $mac);
		}
		return true;
	}

	public static function runBuildEbtables() {
		if (Vps::getPoolType() != 'zfs') {
			$script = Vps::requireScript('run_buildebtables.sh');
			if ($script === false)
				return;
			Vps::getLogger()->write(Vps::runCommand("bash ".escapeshellarg($script), $return));
			if ($return != 0)
				Vps::getLogger()->error("run_buildebtables.sh failed (exit {$return})");
		}
	}

	public static function setupCgroups($vzid, $slices) {
		if (file_exists('/cgroup/blkio/libvirt/qemu')) {
			Vps::getLogger()->info('Setting up CGroups');
			$cpushares = $slices * 512;
			$ioweight = 400 + (37 * $slices);
			Vps::getLogger()->write(Vps::runCommand("virsh schedinfo {$vzid} --set cpu_shares={$cpushares} --current;"));
			Vps::getLogger()->write(Vps::runCommand("virsh schedinfo {$vzid} --set cpu_shares={$cpushares} --config;"));
			Vps::getLogger()->write(Vps::runCommand("virsh blkiotune {$vzid} --weight {$ioweight} --current;"));
			Vps::getLogger()->write(Vps::runCommand("virsh blkiotune {$vzid} --weight {$ioweight} --config;"));
		}
	}

	public static function getVpsRemotes($vzid) {
		$vzidArg = escapeshellarg($vzid);
		$attempts = 0;
		$maxAttempts = 10;
		while (true) {
			$xml = self::getVpsXml($vzid);
			$remotes = [];
			$hasGraphics = (bool) preg_match_all('/<graphics type=\'([^\']+)\'\s?.*\sport=\'([^\']+)\'/muU', $xml, $matches);
			if ($hasGraphics) {
				foreach ($matches[1] as $idx => $type) {
					$port = $matches[2][$idx];
					if (is_numeric($port))
						$port = intval($port);
					if (in_array($port, ['-1', '' ,'0']))
						continue;
					$remotes[$type] = $port;
				}
			}
			// libvirt only allocates the autoport VNC/SPICE port a moment after
			// the domain starts. Right after create (virt-install --wait 0, or a
			// fresh startVps) the live XML can still show port='-1', so a single
			// read returns nothing and setupVnc() configures no proxy — which is
			// why VNC only worked after a later manual `vnc setup`. Wait for the
			// port to resolve while the domain is running; return immediately
			// once a real port appears, when there is no graphics device, or when
			// the domain is not running (a stopped VPS has no live port).
			if (count($remotes) === 0 && $hasGraphics && $attempts < $maxAttempts
					&& trim(Vps::runCommand("virsh domstate {$vzidArg} 2>/dev/null")) === 'running') {
				$attempts++;
				sleep(1);
				continue;
			}
			return $remotes;
		}
	}

	public static function getVncPort($vzid) {
		$vzidArg = escapeshellarg($vzid);
		$vncPort = trim(Vps::runCommand("virsh vncdisplay {$vzidArg} | cut -d: -f2 | head -n 1"));
		if ($vncPort == '') {
			sleep(2);
			$vncPort = trim(Vps::runCommand("virsh vncdisplay {$vzidArg} | cut -d: -f2 | head -n 1"));
			if ($vncPort == '') {
				sleep(2);
				$vncPort = trim(Vps::runCommand("virsh dumpxml {$vzidArg} |grep -i 'graphics type=.vnc.' | cut -d\\' -f4"));
				if (!is_numeric($vncPort)) {
					Vps::getLogger()->error("Could not determine VNC port for {$vzid}");
					return 0;
				}
				return intval($vncPort);
			} else {
				$vncPort += 5900;
			}
		} else {
			$vncPort += 5900;
		}
		return is_numeric($vncPort) ? intval($vncPort) : 0;
	}

	public static function setupStorage($vzid, $device, $pool, $hd) {
		Vps::getLogger()->info('Creating Storage Pool');
		$vzidArg = escapeshellarg($vzid);
		if ($pool == 'zfs') {
			Vps::getLogger()->write(Vps::runCommand("zfs create vz/{$vzidArg}", $return));
			if ($return != 0) {
				Vps::getLogger()->error("zfs create vz/{$vzid} failed (exit {$return})");
				return;
			}
			@mkdir('/vz/'.$vzid, 0777, true);
			$waited = 0;
			while (!file_exists('/vz/'.$vzid) && $waited < 30) {
				sleep(1);
				$waited++;
			}
			if (!file_exists('/vz/'.$vzid)) {
				Vps::getLogger()->error("/vz/{$vzid} did not appear after 30s; storage may not be ready");
				return;
			}
		} else {
			$script = Vps::requireScript('vps_kvm_lvmcreate.sh');
			if ($script === false)
				return;
			Vps::getLogger()->write(Vps::runCommand(escapeshellarg($script)." {$vzidArg} ".intval($hd), $return));
			if ($return != 0) {
				Vps::getLogger()->error("vps_kvm_lvmcreate.sh failed for {$vzid} (exit {$return})");
				return;
			}
		}
		Vps::getLogger()->info("{$pool} pool device {$device} created");
	}

	/**
	* Removes storage backing for a destroyed VPS. Picks zfs vs LVM based on pool type.
	* @param string $vzid VPS identifier
	*/
	public static function removeStorage($vzid) {
		$pool = Vps::getPoolType();
		$vzidArg = escapeshellarg($vzid);
		if ($pool == 'zfs') {
			Vps::getLogger()->write(Vps::runCommand("zfs list -t snapshot|grep \"/{$vzid}@\"|cut -d\" \" -f1|xargs -r -n 1 zfs destroy -v"));
			Vps::getLogger()->write(Vps::runCommand("virsh vol-delete --pool vz/os.qcow2 {$vzidArg} 2>/dev/null"));
			Vps::getLogger()->write(Vps::runCommand("virsh vol-delete --pool vz {$vzidArg}"));
			Vps::getLogger()->write(Vps::runCommand("zfs destroy vz/{$vzid}"));
			if (file_exists('/vz/'.$vzid))
				@rmdir('/vz/'.$vzid);
		} else {
			Vps::getLogger()->write(Vps::runCommand("kpartx -dv /dev/vz/{$vzid}"));
			Vps::getLogger()->write(Vps::runCommand("lvremove -f /dev/vz/{$vzid}"));
		}
	}

	/**
	* Enables auto-start on host boot for a VPS.
	* @param string $vzid VPS identifier
	*/
	public static function enableAutostart($vzid) {
		Vps::getLogger()->info('Enabling On-Boot Automatic Startup of the VPS');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh autostart {$vzidArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh autostart failed for {$vzid} (exit {$return})");
		}
	}

	/**
	* Disables auto-start on host boot for a VPS.
	* @param string $vzid VPS identifier
	*/
	public static function disableAutostart($vzid) {
		Vps::getLogger()->info('Disabling On-Boot Automatic Startup of the VPS');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh autostart --disable {$vzidArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh autostart --disable failed for {$vzid} (exit {$return})");
		}
	}

	/**
	* Starts a VPS, cleans stale xinetd VNC entries, and rebuilds ebtables.
	* @param string $vzid VPS identifier
	*/
	public static function startVps($vzid) {
		Vps::getLogger()->info('Starting the VPS');
		Xinetd::remove($vzid);
		Xinetd::restart();
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh start {$vzidArg}", $return));
		if ($return != 0)
			Vps::getLogger()->error("virsh start failed for {$vzid} (exit {$return})");
		self::runBuildEbtables();
	}

	/**
	* Hard-resets the VPS (sends a reset, not a clean shutdown).
	* @param string $vzid VPS identifier
	*/
	public static function resetVps($vzid) {
		Vps::getLogger()->info('Resetting the VPS');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh reset {$vzidArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh reset failed for {$vzid} (exit {$return})");
		}
	}

	/**
	* Stops a VPS. By default attempts graceful shutdown (up to 240s) before forcing power-off.
	* @param string $vzid VPS identifier
	* @param bool $fast skip the graceful shutdown attempt and force power-off immediately
	*/
	public static function stopVps($vzid, $fast = false) {
		Vps::getLogger()->info('Stopping the VPS');
		Vps::getLogger()->indent();
		$vzidArg = escapeshellarg($vzid);
		$stopped = false;
		if ($fast === false) {
			Vps::getLogger()->info('Sending Software Power-Off');
			Vps::getLogger()->write(Vps::runCommand("virsh shutdown {$vzidArg}", $return));
			if ($return != 0) {
				Vps::getLogger()->error("virsh shutdown failed for {$vzid} (exit {$return}); will force off");
			}
			$waited = 0;
			$maxWait = 240;
			$sleepTime = 5;
			while ($waited <= $maxWait && $stopped == false) {
				if (Vps::isVpsRunning($vzid)) {
					Vps::getLogger()->info('still running, waiting (waited '.$waited.'/'.$maxWait.' seconds)');
					sleep($sleepTime);
					$waited += $sleepTime;
					if ($waited % 15 == 0)
						Vps::runCommand("virsh shutdown {$vzidArg}");
				} else {
					Vps::getLogger()->info('appears to have cleanly shutdown');
					$stopped = true;
				}
			}
		}
		if ($stopped === false) {
			Vps::getLogger()->info('Sending Hardware Power-Off');
			Vps::getLogger()->write(Vps::runCommand("virsh destroy {$vzidArg}", $return));
			if ($return != 0)
				Vps::getLogger()->error("virsh destroy failed for {$vzid} (exit {$return})");
		}
		Xinetd::remove($vzid);
		Xinetd::restart();
		Vps::getLogger()->unIndent();
	}

	/**
	* Removes a VPS definition, its storage, and its DHCP entry. Refuses if the VPS is still running.
	* If `virsh undefine` fails, aborts BEFORE removing storage/DHCP to avoid orphaning resources
	* tied to a still-defined VM.
	* @param string $vzid VPS identifier
	*/
	public static function destroyVps($vzid) {
		if (Vps::isVpsRunning($vzid)) {
			Vps::getLogger()->error("VPS '{$vzid}' is running; please stop it first.");
			return;
		}
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh managedsave-remove {$vzidArg}"));
		Vps::getLogger()->write(Vps::runCommand("virsh undefine {$vzidArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("virsh undefine failed for {$vzid} (exit {$return}); aborting destroy");
			return;
		}
		self::removeStorage($vzid);
		Dhcpd::remove($vzid);
		VpsIps::removeMainIp($vzid);
	}

	public static function installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts, $ioLimit, $iopsLimit) {
		Vps::getLogger()->info('Installing OS Template');
		$result = $pool == 'zfs'
			? self::installTemplateV2($vzid, $template, $password, $device, $hd, $kpartxOpts, $ioLimit, $iopsLimit)
			: self::installTemplateV1($vzid, $template, $password, $device, $hd, $kpartxOpts, $ioLimit, $iopsLimit);
		if ($result === false)
			Vps::getLogger()->error("Template installation failed for {$vzid} (template={$template}, pool={$pool}).");
		return $result;
	}

	/**
	 * Resolves a cloud-init: template reference to a config array.
	 *
	 * Two supported forms (the leading 'cloud-init:' prefix is mandatory):
	 *   1) cloud-init:<config>          legacy JSON config form
	 *      Bare names resolve under /vz/templates/cloudinit/<name>.json,
	 *      absolute paths are used as-is.
	 *   2) cloud-init:<image>:<yaml>                inline form (2 colons total)
	 *   3) cloud-init:<image>:<os_variant>:<yaml>   inline form with explicit variant
	 *      <image> resolves under /vz/templates/ when not absolute or a
	 *      http(s)/ftp URL; <yaml> resolves under /vz/templates/cloudinit/
	 *      when not absolute (empty -> auto-generated user-data). In form (2)
	 *      os_variant is inferred from the image filename via
	 *      self::detectOsVariant(); in form (3) the caller supplies it
	 *      directly (use form (3) when auto-detection cannot identify the
	 *      distro). An empty os_variant in form (3) is treated as form (2).
	 *
	 * Returns a normalized config array (matching the JSON schema used by
	 * installCloudInit()) on success, false on failure.
	 *
	 * @return array|false
	 */
	public static function resolveCloudInitConfig($ref) {
		if (strpos($ref, 'cloud-init:') === 0)
			$ref = substr($ref, strlen('cloud-init:'));
		if ($ref === '')
			return false;
		if (strpos($ref, ':') !== false)
			return self::resolveCloudInitInline($ref);
		return self::resolveCloudInitJson($ref);
	}

	/**
	 * Legacy JSON-config form: read & decode the file, return its contents.
	 * @return array|false
	 */
	private static function resolveCloudInitJson($ref) {
		if ($ref[0] !== '/') {
			$candidate = '/vz/templates/cloudinit/'.$ref;
			if (!file_exists($candidate) && substr($ref, -5) !== '.json')
				$candidate .= '.json';
			$ref = $candidate;
		}
		if (!file_exists($ref)) {
			Vps::getLogger()->error("Cloud-init config not found: {$ref}");
			return false;
		}
		$raw = @file_get_contents($ref);
		if ($raw === false) {
			Vps::getLogger()->error("Could not read cloud-init config {$ref}");
			return false;
		}
		$cfg = json_decode($raw, true);
		if (!is_array($cfg)) {
			Vps::getLogger()->error("Cloud-init config {$ref} is not valid JSON: ".json_last_error_msg());
			return false;
		}
		if (empty($cfg['image'])) {
			Vps::getLogger()->error("Cloud-init config {$ref} missing required 'image' field");
			return false;
		}
		if (empty($cfg['os_variant'])) {
			$detected = self::detectOsVariant($cfg['image']);
			if ($detected === false) {
				Vps::getLogger()->error("Cloud-init config {$ref} has no 'os_variant' and it could not be inferred from image filename '".basename($cfg['image'])."'. Set 'os_variant' in the JSON config explicitly.");
				return false;
			}
			$cfg['os_variant'] = $detected;
		}
		return $cfg;
	}

	/**
	 * Inline form, two shapes:
	 *   <image>:<yaml>              (2-part) os_variant is auto-detected from
	 *                               the image filename; errors out if it cannot.
	 *   <image>:<os_variant>:<yaml> (3-part) os_variant is supplied explicitly.
	 *                               Use when auto-detection fails. The middle
	 *                               part may be left empty to fall back to
	 *                               auto-detection (treats it like the 2-part
	 *                               form).
	 * yaml may be empty in either shape (auto-generate user-data).
	 * @return array|false
	 */
	private static function resolveCloudInitInline($ref) {
		$parts = explode(':', $ref, 3);
		if (count($parts) === 2) {
			$imageRef = trim($parts[0]);
			$osVariant = '';
			$yamlRef = trim($parts[1]);
		} else {
			$imageRef = trim($parts[0]);
			$osVariant = trim($parts[1]);
			$yamlRef = trim($parts[2]);
		}
		if ($imageRef === '') {
			Vps::getLogger()->error('Cloud-init inline form is missing the base image');
			return false;
		}
		if (!preg_match('#^(https?|ftp)://#i', $imageRef) && $imageRef[0] !== '/')
			$imageRef = '/vz/templates/'.$imageRef;
		if ($yamlRef !== '' && $yamlRef[0] !== '/')
			$yamlRef = '/vz/templates/cloudinit/'.$yamlRef;
		if ($yamlRef !== '' && !file_exists($yamlRef)) {
			Vps::getLogger()->error("Cloud-init user-data file not found: {$yamlRef}");
			return false;
		}
		if ($osVariant === '') {
			$osVariant = self::detectOsVariant($imageRef);
			if ($osVariant === false) {
				Vps::getLogger()->error("Could not infer os_variant from image filename '".basename($imageRef)."'. Either use the 3-part inline form (cloud-init:<image>:<os_variant>:<yaml>) or the JSON config form (cloud-init:<config>.json) and set 'os_variant' explicitly.");
				return false;
			}
		}
		$cfg = ['image' => $imageRef, 'os_variant' => $osVariant];
		if ($yamlRef !== '')
			$cfg['user_data'] = $yamlRef;
		return $cfg;
	}

	/**
	 * Best-effort os_variant detection from a cloud image / template filename.
	 * Returns a libosinfo short id understood by `virt-install --os-variant`,
	 * or false. Recognizes both cloud-image style filenames
	 * (e.g. `ubuntu-22.04-server-cloudimg-amd64.img`,
	 *       `Rocky-9-GenericCloud-Base.latest.x86_64.qcow2`)
	 * and the project's compact template tags
	 * (e.g. `ubuntu26`, `ubuntu-22.04`, `debian12`, `alma10`, `almalinux-8.3`,
	 *       `centos-7.6`, `centosstream-8`, `opensuse-tumbleweed`).
	 */
	public static function detectOsVariant($imagePath) {
		$base = strtolower(basename($imagePath));
		// Ubuntu: prefer XX.YY; fall back to bare XX (assume .04, since LTS / interim are released in April/October — .04 is the safest default for the tags shipped here).
		if (preg_match('/ubuntu[-_]?(\d{2})\.(\d{2})/', $base, $m))
			return 'ubuntu'.$m[1].'.'.$m[2];
		if (preg_match('/ubuntu[-_]?(\d{2})(?!\d)/', $base, $m))
			return 'ubuntu'.$m[1].'.04';
		// Debian: major only
		if (preg_match('/debian[-_]?(\d+)/', $base, $m))
			return 'debian'.$m[1];
		// AlmaLinux: accept both 'alma' and 'almalinux'; libosinfo ids use major only.
		if (preg_match('/alma(?:linux)?[-_]?(\d+)/', $base, $m))
			return 'almalinux'.$m[1];
		// Rocky: optional minor; libosinfo ids include minor (default .0 if omitted).
		if (preg_match('/rocky(?:linux)?[-_]?(\d+)(?:\.(\d+))?/', $base, $m))
			return 'rocky'.$m[1].'.'.(isset($m[2]) && $m[2] !== '' ? $m[2] : '0');
		// CentOS Stream: dash optional ('centosstream-8' / 'centos-stream-9').
		if (preg_match('/centos[-_]?stream[-_]?(\d+)/', $base, $m))
			return 'centos-stream'.$m[1];
		// Plain CentOS: optional minor (default .0 if omitted).
		if (preg_match('/centos[-_]?(\d+)(?:\.(\d+))?/', $base, $m))
			return 'centos'.$m[1].'.'.(isset($m[2]) && $m[2] !== '' ? $m[2] : '0');
		// Fedora: also tolerate "Fedora-Cloud-Base-NN" style filenames.
		if (preg_match('/fedora[-_]?(?:cloud[-_]?base[-_]?)?(\d+)/', $base, $m))
			return 'fedora'.$m[1];
		// openSUSE Tumbleweed is a rolling release — check before the numbered Leap match.
		if (preg_match('/opensuse[-_]?tumbleweed/', $base))
			return 'opensusetumbleweed';
		if (preg_match('/opensuse[-_]?(?:leap[-_]?)?(\d+)(?:\.(\d+))?/', $base, $m))
			return 'opensuse'.$m[1].(isset($m[2]) && $m[2] !== '' ? '.'.$m[2] : '');
		if (preg_match('/scientific(?:linux)?[-_]?(\d+)/', $base, $m))
			return 'scientificlinux'.$m[1];
		if (preg_match('/freebsd[-_]?(\d+)(?:\.(\d+))?/', $base, $m))
			return 'freebsd'.$m[1].'.'.(isset($m[2]) && $m[2] !== '' ? $m[2] : '0');
		return false;
	}

	/**
	 * Hash a plaintext password with SHA-512 ($6$), the crypt scheme every
	 * modern cloud image understands. The salt is drawn from random_bytes()
	 * and trimmed to the 16-char crypt salt window.
	 */
	private static function cryptPassword($password) {
		$salt = '$6$'.substr(str_replace('+', '.', base64_encode(random_bytes(12))), 0, 16);
		return crypt((string)$password, $salt);
	}

	/**
	 * Builds a default cloud-init user-data document. The crypt() password hash
	 * uses SHA-512 ($6$) which all modern cloud images understand.
	 */
	private static function buildUserData($hostname, $password, $sshKey) {
		$lines = [];
		$lines[] = "#cloud-config";
		$lines[] = "preserve_hostname: false";
		$lines[] = "hostname: ".self::yamlScalar($hostname);
		$lines[] = "fqdn: ".self::yamlScalar($hostname);
		$lines[] = "manage_etc_hosts: true";
		$lines[] = "ssh_pwauth: true";
		$lines[] = "disable_root: false";
		$lines[] = "chpasswd:";
		$lines[] = "  expire: false";
		if ($password !== '' && $password !== null) {
			$lines[] = "users:";
			$lines[] = "  - name: root";
			$lines[] = "    lock_passwd: false";
			$lines[] = "    hashed_passwd: ".self::yamlScalar(self::cryptPassword($password));
			if ($sshKey !== false && $sshKey !== '') {
				$lines[] = "    ssh_authorized_keys:";
				foreach (preg_split('/\r?\n/', (string)$sshKey) as $key) {
					$key = trim($key);
					if ($key !== '')
						$lines[] = "      - ".self::yamlScalar($key);
				}
			}
		}
		$lines[] = "package_update: true";
		$lines[] = "package_upgrade: false";
		return implode("\n", $lines)."\n";
	}

	/**
	 * Builds a default cloud-init v2 network-config for a single NIC with a static IPv4 (and optional IPv6).
	 * mac is matched so the cloud image binds the address to the right interface regardless of kernel name.
	 */
	private static function buildNetworkConfig($mac, $ip, array $extraIps, $ipv6Ip, $ipv6Range) {
		$lines = [];
		$lines[] = "version: 2";
		$lines[] = "ethernets:";
		$lines[] = "  primary:";
		if ($mac !== '' && $mac !== null) {
			$lines[] = "    match:";
			$lines[] = "      macaddress: ".self::yamlScalar(strtolower($mac));
			$lines[] = "    set-name: eth0";
		}
		$lines[] = "    dhcp4: true";
		$addresses = [];
		foreach ($extraIps as $extra) {
			if ($extra !== '' && $extra !== $ip)
				$addresses[] = $extra.'/32';
		}
		if ($ipv6Ip !== false && $ipv6Ip !== '' && $ipv6Range !== false && $ipv6Range !== '') {
			// --ipv6-range may arrive as a full CIDR (e.g. 2604:a00:50:11c:1::/80)
			// or as a bare prefix length (e.g. 80). netplan wants <addr>/<prefixlen>,
			// so take only the prefix length — otherwise we emit a malformed address
			// like '2604:...::1/2604:...::/80' that breaks netplan apply on boot.
			$prefix = (strpos($ipv6Range, '/') !== false)
				? substr($ipv6Range, strrpos($ipv6Range, '/') + 1)
				: $ipv6Range;
			if (trim($prefix) !== '')
				$addresses[] = $ipv6Ip.'/'.trim($prefix);
		}
		if (count($addresses) > 0) {
			$lines[] = "    addresses:";
			foreach ($addresses as $addr)
				$lines[] = "      - ".self::yamlScalar($addr);
		}
		$lines[] = "    nameservers:";
		$lines[] = "      addresses: [8.8.8.8, 1.1.1.1]";
		return implode("\n", $lines)."\n";
	}

	/**
	 * Quote a YAML scalar so embedded $, :, # or whitespace are safe.
	 */
	private static function yamlScalar($value) {
		return "'".str_replace("'", "''", (string)$value)."'";
	}

	/**
	 * Best-effort validation of an operator-supplied cloud-init user-data file.
	 *
	 * Only #cloud-config payloads are YAML, so those are syntax-checked; shell
	 * scripts (#!), cloud-boothooks, #include lists and MIME multipart are passed
	 * through untouched (they are not YAML). A #cloud-config must parse to a
	 * mapping (top-level dict) — anything else is a syntax error or wrong shape.
	 *
	 * Validator preference (php-yaml first, as it is the most reliable here):
	 *   1. the php-yaml extension in-process, else
	 *   2. try to install php-yaml (apt/dnf/yum) and validate via a fresh `php`
	 *      subprocess (the freshly installed extension is auto-enabled for CLI),
	 *      else
	 *   3. python3 + PyYAML — but ONLY when PyYAML is actually importable, so a
	 *      missing module is treated as "no validator" rather than a false
	 *      "invalid YAML" verdict, else
	 *   4. no validator -> warn and accept (never block an install just because
	 *      the host lacks a YAML parser).
	 *
	 * @return bool false only on a definitive parse failure / unreadable file.
	 */
	private static function validateUserDataFile($path) {
		$content = @file_get_contents($path);
		if ($content === false) {
			Vps::getLogger()->error("Cloud-init user-data file could not be read: {$path}");
			return false;
		}
		if (strncmp(ltrim($content), '#cloud-config', 13) !== 0) {
			Vps::getLogger()->info2("user-data {$path} is not a #cloud-config document; skipping YAML validation");
			return true;
		}
		// 1. in-process php-yaml
		if (function_exists('yaml_parse')) {
			$ok = is_array(@yaml_parse($content));
			return self::userDataYamlVerdict($ok ? 0 : 1, $path, 'php-yaml');
		}
		// 2. install php-yaml and validate through a fresh php subprocess
		Vps::getLogger()->info2('php-yaml extension not loaded; attempting to install it');
		self::installPhpYaml();
		$rc = self::phpYamlCheck($path);
		if ($rc === 0 || $rc === 1)
			return self::userDataYamlVerdict($rc, $path, 'php-yaml');
		// 3. python3 + PyYAML, only when the module is importable
		$rc = self::pythonYamlCheck($path);
		if ($rc === 0 || $rc === 1)
			return self::userDataYamlVerdict($rc, $path, 'python3');
		// 4. nothing usable
		Vps::getLogger()->warn("No YAML validator available (php-yaml or python3+PyYAML); skipping validation of {$path}");
		return true;
	}

	/** Log + map a 0(valid)/1(invalid) YAML result to a bool for validateUserDataFile(). */
	private static function userDataYamlVerdict($code, $path, $via) {
		if ($code === 0) {
			Vps::getLogger()->info2("user-data {$path} passed YAML validation ({$via})");
			return true;
		}
		Vps::getLogger()->error("Cloud-init user-data {$path} is not valid YAML (or its top level is not a mapping); aborting. [{$via}]");
		return false;
	}

	/** Best-effort install of the php-yaml extension using whichever package manager exists. */
	private static function installPhpYaml() {
		if (trim(Vps::runCommand('command -v apt-get 2>/dev/null')) !== '') {
			Vps::getLogger()->info('Installing php-yaml via apt-get');
			Vps::getLogger()->write(Vps::runCommand('DEBIAN_FRONTEND=noninteractive apt-get install -y php-yaml 2>&1', $r));
		} elseif (trim(Vps::runCommand('command -v dnf 2>/dev/null')) !== '') {
			Vps::getLogger()->info('Installing php-yaml via dnf (php-pecl-yaml)');
			Vps::getLogger()->write(Vps::runCommand('dnf install -y php-pecl-yaml 2>&1', $r));
		} elseif (trim(Vps::runCommand('command -v yum 2>/dev/null')) !== '') {
			Vps::getLogger()->info('Installing php-yaml via yum (php-pecl-yaml)');
			Vps::getLogger()->write(Vps::runCommand('yum install -y php-pecl-yaml 2>&1', $r));
		} else {
			Vps::getLogger()->info2('no apt/dnf/yum found; cannot auto-install php-yaml');
		}
	}

	/**
	 * Validate YAML through a fresh `php` CLI subprocess (picks up a php-yaml
	 * extension that was just installed and auto-enabled for CLI).
	 * @return int 0 valid, 1 invalid, -1 php/yaml-ext unavailable.
	 */
	private static function phpYamlCheck($path) {
		if (trim(Vps::runCommand('command -v php 2>/dev/null')) === '')
			return -1;
		$script = 'if(!function_exists("yaml_parse")){exit(3);} $d=@yaml_parse(file_get_contents($argv[1])); exit(is_array($d)?0:1);';
		Vps::runCommand("php -r '".$script."' ".escapeshellarg($path)." 2>/dev/null", $r);
		if ($r === 0) return 0;
		if ($r === 1) return 1;
		return -1; // 3 = no extension (or any other failure) -> unavailable
	}

	/**
	 * Validate YAML with python3 + PyYAML, distinguishing a missing module
	 * (unavailable) from an actual parse failure (invalid).
	 * @return int 0 valid, 1 invalid, -1 python3/PyYAML unavailable.
	 */
	private static function pythonYamlCheck($path) {
		if (trim(Vps::runCommand('command -v python3 2>/dev/null')) === '')
			return -1;
		Vps::runCommand('python3 -c "import yaml" 2>/dev/null', $rmod);
		if ($rmod != 0)
			return -1; // PyYAML not installed -> not a YAML verdict
		Vps::runCommand('python3 -c "import sys, yaml; yaml.safe_load(open(sys.argv[1]).read())" '.escapeshellarg($path).' 2>/dev/null', $rparse);
		return ($rparse == 0) ? 0 : 1;
	}

	/**
	 * Cloud-init driven KVM install via `virt-install --import`. Replaces the
	 * defineVps/installTemplate/virt-customize sequence for guests that ship with
	 * cloud-init pre-installed (Ubuntu/Debian/CentOS/Rocky cloud images, etc.).
	 *
	 * Config JSON schema (see /vz/templates/cloudinit/*.json):
	 *   image          (required)  absolute path or http(s)/ftp URL to qcow2
	 *   os_variant     (optional)  passed to virt-install --os-variant; if
	 *                              absent, inferred from the image filename
	 *                              via detectOsVariant() and the call aborts
	 *                              if detection fails.
	 *   user_data      (optional)  path to user-data YAML; auto-generated if absent
	 *   network_config (optional)  path to network-config YAML; auto-generated if absent
	 *   disk_format    (optional)  default 'qcow2'
	 *   graphics       (optional)  default 'vnc'  (use 'none' for headless)
	 *   bridge         (optional)  default 'br0'
	 *
	 * Inline form (cloud-init:<image>:<yaml>) is resolved by
	 * resolveCloudInitConfig() into the same schema (image + auto-detected
	 * os_variant + optional user_data path).
	 *
	 * Storage is reused from Vps::setupStorage() (same /vz/<vzid>/os.qcow2 or /dev/vz/<vzid> path
	 * as the XML install path) so snapshot/backup/resize tooling keeps working.
	 */
	public static function installCloudInit($vzid, $configRef, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $hd, $hostname, $password, $sshKey, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit) {
		Vps::getLogger()->info('Installing via cloud-init (virt-install --import)');
		Vps::getLogger()->indent();
		$cfg = self::resolveCloudInitConfig($configRef);
		if ($cfg === false) {
			Vps::getLogger()->unIndent();
			return false;
		}
		// Validate an operator-supplied user-data file up front so a YAML typo
		// fails the request immediately instead of after we have converted the
		// image and booted a guest with a broken (or ignored) seed.
		if (!empty($cfg['user_data']) && !self::validateUserDataFile($cfg['user_data'])) {
			Vps::getLogger()->unIndent();
			return false;
		}
		$image = $cfg['image'];
		$osVariant = $cfg['os_variant'];
		$diskFormat = isset($cfg['disk_format']) && $cfg['disk_format'] !== '' ? $cfg['disk_format'] : 'qcow2';
		$graphics = isset($cfg['graphics']) && $cfg['graphics'] !== '' ? $cfg['graphics'] : 'vnc';
		$bridge = isset($cfg['bridge']) && $cfg['bridge'] !== '' ? $cfg['bridge'] : 'br0';

		// fetch image to a local cache path if it's a URL
		$cleanupImage = false;
		if (preg_match('#^(https?|ftp)://#i', $image)) {
			$tmpDir = '/vz/templates';
			if (!is_dir($tmpDir))
				@mkdir($tmpDir, 0755, true);
			$cached = $tmpDir.'/cloud-init-'.$vzid.'.'.$diskFormat;
			Vps::getLogger()->info("Downloading {$image}");
			Vps::getLogger()->write(Vps::runCommand("curl -fL --connect-timeout 30 -o ".escapeshellarg($cached)." ".escapeshellarg($image), $return));
			if ($return != 0 || !file_exists($cached)) {
				Vps::getLogger()->error("Could not download image {$image} (exit {$return})");
				@unlink($cached);
				Vps::getLogger()->unIndent();
				return false;
			}
			$image = $cached;
			$cleanupImage = true;
		}
		if (!file_exists($image)) {
			Vps::getLogger()->error("Cloud-init image not found: {$image}");
			Vps::getLogger()->unIndent();
			return false;
		}

		// copy & resize image onto the storage device that Vps::setupStorage() prepared
		Vps::getLogger()->info("Copying {$image} -> {$device}");
		Vps::getLogger()->write(Vps::runCommand("qemu-img convert -O ".escapeshellarg($diskFormat)." ".escapeshellarg($image)." ".escapeshellarg($device), $return));
		if ($return != 0) {
			Vps::getLogger()->error("qemu-img convert failed (exit {$return})");
			if ($cleanupImage) @unlink($image);
			Vps::getLogger()->unIndent();
			return false;
		}
		if ($cleanupImage)
			@unlink($image);
		// resize disk to the requested capacity (hd arrives in MB; 'all' means consume the storage pool)
		if ($hd === 'all') {
			if ($pool === 'zfs') {
				$avail = (int)trim(Vps::runCommand("zfs list vz -o available -H -p"));
				$hd = (int)floor($avail / (1024 * 1024));
				if ($hd > 2000000) $hd = 2000000;
			}
		}
		if (is_numeric($hd) && (int)$hd > 0)
			Vps::getLogger()->write(Vps::runCommand("qemu-img resize ".escapeshellarg($device)." ".((int)$hd)."M"));

		// Set the root password (and ssh key) directly in the image filesystem,
		// exactly like the non-cloud-init install path does. cloud-init only
		// applies user-data on a fresh first boot and many base images ship with
		// cloud-init already run / disabled / using a non-NoCloud datasource, so
		// relying on it alone leaves the image's baked-in password in place. The
		// libvirt domain does not exist yet, so target the disk with -a (the
		// guest is not running at this point, which virt-customize requires).
		$hasPassword = ($password !== '' && $password !== null);
		$hasKey = ($sshKey !== false && $sshKey !== '' && $sshKey !== null);
		if ($hasPassword || $hasKey) {
			$cust = 'virt-customize --no-network -a '.escapeshellarg($device);
			if ($hasPassword)
				$cust .= ' --root-password password:'.escapeshellarg($password);
			if ($hasKey)
				$cust .= ' --ssh-inject root:string:'.escapeshellarg($sshKey);
			Vps::getLogger()->info('Setting root password in image (virt-customize)');
			Vps::getLogger()->write(Vps::runCommand($cust, $return));
			if ($return != 0)
				Vps::getLogger()->warn("virt-customize could not set the root password (exit {$return}); falling back to cloud-init user-data only");
		}

		// Make sure cloud-init will actually process the seed we attach below.
		// Ubuntu live/server (subiquity) images disable cloud-init after install
		// with two artifacts that BOTH have to go (confirmed by inspecting such an
		// image): the marker file /etc/cloud/cloud-init.disabled, which makes
		// ds-identify bail with "disabled by marker file", AND
		// /etc/cloud/cloud.cfg.d/99-installer.cfg, which pins
		// `datasource_list: [None]` and embeds the installer's own user-data — so
		// even with the marker gone cloud-init stays on the None datasource and
		// never reads our attached NoCloud (cidata) seed. Removing just the marker
		// is not enough. Subiquity ships /etc/cloud/clean.d/99-installer listing
		// exactly the files `cloud-init clean` deletes to re-enable; we replicate
		// that offline (verified: afterwards ds-identify reports
		// "Found single datasource: NoCloud"). Also clear stale per-instance state
		// and reset /etc/machine-id (empty, so systemd regenerates it early in
		// boot — do NOT write "uninitialized" or touch /var/lib/dbus/machine-id,
		// which breaks dbus/NetworkManager and the network with it).
		$installerArtifacts = implode(' ', [
			'/etc/cloud/cloud-init.disabled',
			'/etc/cloud/cloud.cfg.d/99-installer.cfg',
			'/etc/cloud/cloud.cfg.d/90-installer-network.cfg',
			'/etc/cloud/cloud.cfg.d/20-disable-cc-dpkg-grub.cfg',
			'/etc/cloud/ds-identify.cfg',
		]);
		$reset = 'virt-customize --no-network -a '.escapeshellarg($device)
			." --run-command ".escapeshellarg('rm -f '.$installerArtifacts.' 2>/dev/null || true')
			." --run-command ".escapeshellarg('rm -rf /var/lib/cloud/instance /var/lib/cloud/instances /var/lib/cloud/sem /var/lib/cloud/data 2>/dev/null || true')
			." --run-command ".escapeshellarg(': > /etc/machine-id 2>/dev/null || true');
		Vps::getLogger()->info('Re-enabling cloud-init (reset machine-id + clear state) so the seed runs on first boot');
		Vps::getLogger()->write(Vps::runCommand($reset, $return));
		if ($return != 0)
			Vps::getLogger()->warn("cloud-init state reset reported exit {$return}; supplied user-data may not run on first boot (is cloud-init installed in the base image?)");

		// build (or load) user-data and network-config
		$cloudDir = '/tmp/provirted-cloud-init-'.$vzid;
		if (!is_dir($cloudDir) && !@mkdir($cloudDir, 0700, true)) {
			Vps::getLogger()->error("Could not create cloud-init scratch dir {$cloudDir}");
			Vps::getLogger()->unIndent();
			return false;
		}
		if (!empty($cfg['user_data']) && file_exists($cfg['user_data'])) {
			// Operator supplied their own user-data: hand it to cloud-init exactly
			// as written. The CLI --password/--ssh-key are already applied directly
			// to the image by virt-customize above, so there is nothing to inject
			// here — and passing the file verbatim (rather than wrapping it in a
			// generated MIME-multipart with merge headers) guarantees cloud-init
			// processes the operator's config unaltered.
			$userDataFile = $cfg['user_data'];
		} else {
			$userDataFile = $cloudDir.'/user-data';
			if (@file_put_contents($userDataFile, self::buildUserData($hostname, $password, $sshKey)) === false) {
				Vps::getLogger()->error("Could not write {$userDataFile}");
				Vps::getLogger()->unIndent();
				return false;
			}
		}
		if (!empty($cfg['network_config']) && file_exists($cfg['network_config'])) {
			$networkFile = $cfg['network_config'];
		} else {
			$networkFile = $cloudDir.'/network-config';
			if (@file_put_contents($networkFile, self::buildNetworkConfig($mac, $ip, $extraIps, $ipv6Ip, $ipv6Range)) === false) {
				Vps::getLogger()->error("Could not write {$networkFile}");
				Vps::getLogger()->unIndent();
				return false;
			}
		}

		// build virt-install command (ram passed in KB; virt-install wants MB)
		$ramMb = (int)max(1, floor((int)$ram / 1024));
		$cpuN = (int)max(1, (int)$cpu);
		$parts = [];
		$parts[] = 'virt-install';
		$parts[] = '--name '.escapeshellarg($vzid);
		$parts[] = '--memory '.$ramMb;
		$parts[] = '--vcpus '.$cpuN;
		$parts[] = '--os-variant '.escapeshellarg($osVariant);
		$parts[] = '--cpu host-passthrough,cache.mode=passthrough';
		$parts[] = '--disk path='.escapeshellarg($device).',format='.escapeshellarg($diskFormat).',bus=virtio,cache=writeback,discard=unmap';
		$netSpec = 'bridge='.$bridge;
		if ($mac !== '' && $mac !== null)
			$netSpec .= ',mac='.$mac;
		$netSpec .= ',model=virtio';
		$parts[] = '--network '.escapeshellarg($netSpec);
		$parts[] = '--cloud-init user-data='.escapeshellarg($userDataFile).',network-config='.escapeshellarg($networkFile);
		if ($graphics === 'none')
			$parts[] = '--graphics none';
		else
			$parts[] = '--graphics '.escapeshellarg($graphics.',listen=127.0.0.1');
		$parts[] = '--import';
		$parts[] = '--noautoconsole';
		$parts[] = '--wait 0';
		$cmd = implode(' ', $parts);
		Vps::getLogger()->info('Running virt-install');
		Vps::getLogger()->debug($cmd);
		Vps::getLogger()->write(Vps::runCommand($cmd, $return));
		// scrub temp dir (leave operator-provided paths alone)
		if (strpos($userDataFile, $cloudDir) === 0) @unlink($userDataFile);
		if (strpos($networkFile, $cloudDir) === 0) @unlink($networkFile);
		@rmdir($cloudDir);
		if ($return != 0) {
			Vps::getLogger()->error("virt-install failed for {$vzid} (exit {$return})");
			Vps::getLogger()->unIndent();
			return false;
		}

		// apply io/iops tuning (matches installTemplateV2)
		if ($ioLimit !== false)
			Vps::getLogger()->write(Vps::runCommand("virsh blkdeviotune ".escapeshellarg($vzid)." vda --total-bytes-sec ".(int)$ioLimit." --config"));
		if ($iopsLimit !== false)
			Vps::getLogger()->write(Vps::runCommand("virsh blkdeviotune ".escapeshellarg($vzid)." vda --total-iops-sec ".(int)$iopsLimit." --config"));

		// register DHCP + IP map so DHCP, addon-ip, and rebuild commands see this VPS
		if ($ip !== 'none' && $ip !== '' && $ip !== null) {
			Dhcpd::setup($vzid, $ip, $mac);
			VpsIps::setMainIp($vzid, $ip);
			if (is_array($extraIps))
				foreach ($extraIps as $extra)
					if ($extra !== '' && $extra !== $ip)
						VpsIps::addAddonIp($ip, $extra);
		}
		if ($ipv6Ip !== false && $ipv6Ip !== '' && $ipv6Range !== false && $ipv6Range !== '')
			Dhcpd6::setup($vzid, $ipv6Ip, $ipv6Range, $mac);

		Vps::getLogger()->unIndent();
		return true;
	}

	public static function setupRouting($vzid, $ip, $pool, $useAll, $id) {
		Vps::getLogger()->info('Setting up Routing');
		if ($useAll == false) {
			self::runBuildEbtables();
		}
		if ($ip != 'none') {
			$tclimit = Vps::requireScript('tclimit');
			if ($tclimit !== false) {
				$ipArg = escapeshellarg($ip);
				Vps::getLogger()->write(Vps::runCommand(escapeshellarg($tclimit)." {$ipArg}", $return));
				if ($return != 0)
					Vps::getLogger()->error("tclimit failed for {$ip} (exit {$return})");
			}
			self::blockSmtp($vzid, $id);
		}
		if ($pool != 'zfs' && $useAll == false) {
			Vps::getLogger()->write(Vps::runCommand("/admin/kvmenable ebflush", $return));
			if ($return != 0)
				Vps::getLogger()->error("/admin/kvmenable ebflush failed (exit {$return})");
			$buildRules = Vps::requireScript('buildebtablesrules');
			if ($buildRules !== false) {
				Vps::getLogger()->write(Vps::runCommand(escapeshellarg($buildRules)." | sh", $return));
				if ($return != 0)
					Vps::getLogger()->error("buildebtablesrules pipeline failed (exit {$return})");
			}
		}
	}

	public static function blockSmtp($vzid, $id) {
		Vps::getLogger()->write(Vps::runCommand("/admin/kvmenable blocksmtp {$vzid}"));
	}

	public static function installTemplateV2($vzid, $template, $password, $device, $hd, $kpartxOpts, $ioLimit, $iopsLimit) {
		// kvmv2
		$base = Vps::$base;
		$downloadedTemplate = substr($template, 0, 7) == 'http://' || substr($template, 0, 8) == 'https://' || substr($template, 0, 6) == 'ftp://';
		if ($downloadedTemplate == true) {
			Vps::getLogger()->info("Downloading {$template} Image");
			Vps::getLogger()->write(Vps::runCommand("{$base}/vps_get_image.sh \"{$template} zfs\""));
			$template = 'image';
		}
		if (!file_exists('/vz/templates/'.$template.'.qcow2') && $template != 'empty') {
			Vps::getLogger()->info("There must have been a problem, the image does not exist");
			return false;
		} else {
			Vps::getLogger()->info("Copy {$template}.qcow2 Image");
			if ($hd == 'all') {
				$hd = floor(intval(trim(Vps::runCommand("zfs list vz -o available -H -p"))) / (1024 * 1024));
				if ($hd > 2000000)
					$hd = 2000000;
			}
			if (stripos($template, 'freebsd') !== false) {
				Vps::getLogger()->write(Vps::runCommand("cp -f /vz/templates/{$template}.qcow2 {$device};"));
				Vps::getLogger()->write(Vps::runCommand("qemu-img resize {$device} \"{$hd}\"M;"));
			} else {
				Vps::getLogger()->write(Vps::runCommand("qemu-img create -f qcow2 -o preallocation=metadata {$device} 25G;"));
				Vps::getLogger()->write(Vps::runCommand("qemu-img resize {$device} \"{$hd}\"M;"));
				if ($template != 'empty') {
					Vps::getLogger()->debug('Listing Partitions in Template');
					$part = trim(Vps::runCommand("virt-filesystems -a /vz/templates/{$template}.qcow2 --partitions 2>/dev/null | tail -n 1;"));
					$backuppart = trim(Vps::runCommand("virt-filesystems -a /vz/templates/{$template}.qcow2 --partitions 2>/dev/null | head -n 1;"));
					Vps::getLogger()->debug('List Partitions got partition '.$part.' and backup partition '.$backuppart);
					Vps::getLogger()->debug('Copying and Resizing Template');
					Vps::getLogger()->write(Vps::runCommand("virt-resize --expand {$part} /vz/templates/{$template}.qcow2 {$device} || virt-resize --expand {$backuppart} /vz/templates/{$template}.qcow2 {$device} || cp -fv /vz/templates/{$template}.qcow2 {$device}"));
				}
			}
			if ($downloadedTemplate === true) {
				Vps::getLogger()->info("Removing Downloaded Image");
				Vps::getLogger()->write(Vps::runCommand("rm -f /vz/templates/{$template}.qcow2"));
			}
			$vzidArg = escapeshellarg($vzid);
            Vps::getLogger()->write(Vps::runCommand("virsh detach-disk {$vzidArg} vda --persistent"));
            Vps::getLogger()->write(Vps::runCommand("virsh detach-disk {$vzidArg} sda --persistent"));
			Vps::getLogger()->write(Vps::runCommand("virsh attach-disk {$vzidArg} /vz/{$vzid}/os.qcow2 sda --targetbus scsi --driver qemu --subdriver qcow2 --type disk --sourcetype file --persistent"));
            $dev = 'sda';
			$xmlFile = tempnam(sys_get_temp_dir(), 'provirted-kvm-install-');
			if ($xmlFile === false) {
				Vps::getLogger()->error("Could not create temp file for {$vzid} XML; aborting installTemplateV2.");
				return false;
			}
			$xmlFileArg = escapeshellarg($xmlFile);
			Vps::getLogger()->write(Vps::runCommand("virsh dumpxml {$vzidArg} > {$xmlFileArg}", $return));
			if ($return != 0) {
				Vps::getLogger()->error("virsh dumpxml failed for {$vzid} (exit {$return})");
				@unlink($xmlFile);
				return false;
			}
            Vps::getLogger()->write(Vps::runCommand("sed s#\"type='qcow2'/\"#\"type='qcow2' cache='writeback' discard='unmap'/\"#g -i {$xmlFileArg}"));
			Vps::getLogger()->write(Vps::runCommand("virsh define {$xmlFileArg}", $return));
			@unlink($xmlFile);
			if ($return != 0) {
				Vps::getLogger()->error("virsh define failed for {$vzid} (exit {$return})");
				return false;
			}
            if ($ioLimit !== false)
                Vps::getLogger()->write(Vps::runCommand("virsh blkdeviotune {$vzidArg} {$dev} --total-bytes-sec ".intval($ioLimit)." --config"));
            if ($iopsLimit !== false)
                Vps::getLogger()->write(Vps::runCommand("virsh blkdeviotune {$vzidArg} {$dev} --total-iops-sec ".intval($iopsLimit)." --config"));
		}
		return true;
	}

	public static function installTemplateV1($vzid, $template, $password, $device, $hd, $kpartxOpts, $ioLimit, $iopsLimit) {
		$adjust_partitions = 1;
		$base = Vps::$base;
		$softraid = trim(Vps::runCommand("grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null"));
		$softraid = '' == $softraid ? [] : explode("\n", $softraid);
		if (count($softraid) > 0)
			foreach ($softraid as $softfile)
				file_put_contents($softfile, 'idle');
		if (substr($template, 0, 7) == 'http://' || substr($template, 0, 8) == 'https://' || substr($template, 0, 6) == 'ftp://') {
			// image from url
			$adjust_partitions = 0;
			Vps::getLogger()->info("Downloading {$template} Image");
			Vps::getLogger()->write(Vps::runCommand("{$base}/vps_get_image.sh \"{$template}\""));
			if (!file_exists('/image_storage/image.img')) {
				Vps::getLogger()->info("There must have been a problem, the image does not exist");
				if (count($softraid) > 0)
					foreach ($softraid as $softfile)
						file_put_contents($softfile, 'check');
				return false;
			} else {
				self::installImage('/image_storage/image.img', $device);
				Vps::getLogger()->info("Removing Downloaded Image");
			}
			Vps::getLogger()->write(Vps::runCommand("umount /image_storage;"));
			Vps::getLogger()->write(Vps::runCommand("virsh vol-delete --pool vz image_storage;"));
			Vps::getLogger()->write(Vps::runCommand("rmdir /image_storage;"));
		} elseif ($template == 'empty') {
			// kvmv1 install empty image
			$adjust_partitions = 0;
		} else {
			// kvmv1 install
			$found = 0;
			foreach (['/vz/templates/', '/templates/', '/'] as $prefix) {
				$source = $prefix.$template.'.img.gz';
				if ($found == 0 && file_exists($source)) {
					$found = 1;
					self::installGzImage($source, $device);
				}
			}
			foreach (['/vz/templates/', '/templates/', '/', '/dev/vz/'] as $prefix) {
				foreach (['.img', ''] as $suffix) {
					$source = $prefix.$template.$suffix;
					if ($found == 0 && file_exists($source)) {
						$found = 1;
						self::installImage($source, $device);
					}
				}
			}
			if ($found == 0) {
				Vps::getLogger()->info("Template does not exist");
				if (count($softraid) > 0)
					foreach ($softraid as $softfile)
						file_put_contents($softfile, 'check');
				return false;
			}
		}
		if ($adjust_partitions == 1) {
			$sects = trim(Vps::runCommand("fdisk -l -u {$device}  | grep sectors$ | sed s#\"^.* \([0-9]*\) sectors$\"#\"\\1\"#g"));
			$t = trim(Vps::runCommand("fdisk -l -u {$device} | sed s#\"\*\"#\"\"#g | grep \"^{$device}\" | tail -n 1"));
			$p = trim(Vps::runCommand("echo {$t} | awk '{ print $1 }'"));
			$fs = trim(Vps::runCommand("echo {$t} | awk '{ print $5 }'"));
			if (trim(Vps::runCommand("echo \"{$fs}\" | grep \"[A-Z]\"")) != '') {
				$fs = trim(Vps::runCommand("echo {$t} | awk '{ print $6 }'"));
			}
			$pn = trim(Vps::runCommand("echo \"{$p}\" | sed s#\"{$device}[p]*\"#\"\"#g"));
			$pt = $pn > 4 ? 'l' : 'p';
			$start = trim(Vps::runCommand("echo {$t} | awk '{ print $2 }'"));
			if ($fs == 83) {
				Vps::getLogger()->info("Resizing Last Partition To Use All Free Space (Sect {$sects} P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}");
				Vps::getLogger()->write(Vps::runCommand("echo -e \"d\n{$pn}\nn\n{$pt}\n{$pn}\n{$start}\n\n\nw\nprint\nq\n\" | fdisk -u {$device}"));
				Vps::getLogger()->write(Vps::runCommand("kpartx {$kpartxOpts} -av {$device}"));
				$pname = trim(Vps::runCommand("ls /dev/mapper/vz-\"{$vzid}\"p{$pn} /dev/mapper/vz-{$vzid}{$pn} /dev/mapper/\"{$vzid}\"p{$pn} /dev/mapper/{$vzid}{$pn} 2>/dev/null | cut -d/ -f4 | sed s#\"{$pn}$\"#\"\"#g"));
				Vps::getLogger()->write(Vps::runCommand("e2fsck -p -f /dev/mapper/{$pname}{$pn}"));
				$resizefs = trim(Vps::runCommand("which resize4fs 2>/dev/null")) != '' ? 'resize4fs' : 'resize2fs';
				Vps::getLogger()->write(Vps::runCommand("$resizefs -p /dev/mapper/{$pname}{$pn}"));
				@mkdir('/vz/mounts/'.$vzid.$pn, 0777, true);
				Vps::getLogger()->write(Vps::runCommand("mount /dev/mapper/{$pname}{$pn} /vz/mounts/{$vzid}{$pn};"));
				$password = escapeshellarg($password);
				Vps::getLogger()->write(Vps::runCommand("echo root:{$password} | chroot /vz/mounts/{$vzid}{$pn} chpasswd || php {$base}/vps_kvm_password_manual.php {$password} \"/vz/mounts/{$vzid}{$pn}\""));
				if (file_exists('/vz/mounts/'.$vzid.$pn.'/home/kvm')) {
					Vps::getLogger()->write(Vps::runCommand("echo kvm:{$password} | chroot /vz/mounts/{$vzid}{$pn} chpasswd"));
				}
				Vps::getLogger()->write(Vps::runCommand("umount /dev/mapper/{$pname}{$pn}"));
				Vps::getLogger()->write(Vps::runCommand("kpartx {$kpartxOpts} -d {$device}"));
			} else {
				Vps::getLogger()->info("Skipping Resizing Last Partition FS is not 83. Space (Sect {$sects} P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}");
			}
		}
		if (count($softraid) > 0)
			foreach ($softraid as $softfile)
				file_put_contents($softfile, 'check');
		return true;
	}

	public static function installGzImage($source, $device) {
		Vps::getLogger()->info("Copying {$source} Image");
		$tsize = trim(Vps::runCommand("stat -c%s \"{$source}\""));
		Vps::getLogger()->write(Vps::runCommand("gzip -dc \"/{$source}\"  | dd of={$device} 2>&1"));
		return true;
	}

	public static function installImage($source, $device) {
		Vps::getLogger()->info("Copying Image");
		$tsize = trim(Vps::runCommand("stat -c%s \"{$source}\""));
		Vps::getLogger()->write(Vps::runCommand("dd \"if={$source}\" \"of={$device}\" 2>&1"));
		Vps::getLogger()->write(Vps::runCommand("rm -f dd.progress;"));
		return true;
	}
}
