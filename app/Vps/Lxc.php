<?php
namespace App\Vps;

use App\Vps;
use App\Os\VpsIps;

class Lxc
{
	/**
	* return a list of the running containers
	*
	* @return array
	*/
	public static function getRunningVps() {
		$output = trim(Vps::runCommand("lxc list status=running -c n --format csv"));
		return $output == '' ? [] : explode("\n", $output);
	}

	/**
	* return a list of all containers
	*
	* @return array
	*/
	public static function getAllVps() {
		$output = trim(Vps::runCommand("lxc list -c n --format csv"));
		return $output == '' ? [] : explode("\n", $output);
	}

	/**
	* determines if a container exists
	*
	* @param string $vzid container name
	* @return bool
	*/
	public static function vpsExists($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::runCommand("lxc info {$vzid} >/dev/null 2>&1", $return);
		return $return == 0;
	}

	/**
	* gets container details as an array
	*
	* @param string $vzid container name
	* @return array|false
	*/
	public static function getVps($vzid) {
		$vzid = escapeshellarg($vzid);
		$json = trim(Vps::runCommand("lxc list {$vzid} --format json 2>/dev/null"));
		$data = json_decode($json, true);
		if (!is_array($data) || count($data) == 0)
			return false;
		return $data[0];
	}

	/**
	* gets the mac address of a container
	*
	* @param string $vzid container name
	* @return string
	*/
	public static function getVpsMac($vzid) {
		$vps = self::getVps($vzid);
		if ($vps === false)
			return '';
		if (isset($vps['state']['network'])) {
			foreach ($vps['state']['network'] as $iface => $info) {
				if ($iface == 'lo')
					continue;
				if (isset($info['hwaddr']) && $info['hwaddr'] != '')
					return $info['hwaddr'];
			}
		}
		// fallback to volatile config
		if (isset($vps['config'])) {
			foreach ($vps['config'] as $key => $value) {
				if (preg_match('/volatile\..*\.hwaddr/', $key) && $value != '')
					return $value;
			}
		}
		return '';
	}

	/**
	* gets the ips configured on a container
	*
	* @param string $vzid container name
	* @return array
	*/
	public static function getVpsIps($vzid) {
		$vps = self::getVps($vzid);
		if ($vps === false)
			return [];
		$ips = [];
		if (isset($vps['state']['network'])) {
			foreach ($vps['state']['network'] as $iface => $info) {
				if ($iface == 'lo')
					continue;
				if (isset($info['addresses'])) {
					foreach ($info['addresses'] as $addr) {
						if ($addr['family'] == 'inet' && $addr['address'] != '')
							$ips[] = $addr['address'];
					}
				}
			}
		}
		return $ips;
	}

	/**
	* adds an IP to a container via a static IP device config
	*
	* @param string $vzid container name
	* @param string $ip IP address
	* @return bool
	*/
	public static function addIp($vzid, $ip) {
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			Vps::getLogger()->error("Invalid IPv4 address '{$ip}'; refusing to modify container.");
			return false;
		}
		$ips = self::getVpsIps($vzid);
		if (in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping adding IP '.$ip.' to '.$vzid.', it already exists in the container.');
			return false;
		}
		Vps::getLogger()->info('Adding IP '.$ip.' to '.$vzid);
		// find the next available device index
		$escaped = escapeshellarg($vzid);
		$ipArg = escapeshellarg($ip);
		$existing = trim(Vps::runCommand("lxc config device list {$escaped} 2>/dev/null"));
		$devices = $existing == '' ? [] : explode("\n", $existing);
		$idx = count($devices);
		$devName = 'eth'.$idx;
		Vps::getLogger()->write(Vps::runCommand("lxc config device add {$escaped} {$devName} nic nictype=bridged parent=br0 ipv4.address={$ipArg}", $return));
		if ($return != 0) {
			Vps::getLogger()->error("lxc config device add failed for {$vzid} (exit {$return})");
			return false;
		}
		$mainIp = VpsIps::getMainIp($vzid);
		if ($mainIp === null && empty($ips)) {
			VpsIps::setMainIp($vzid, $ip);
		} elseif ($mainIp !== null) {
			VpsIps::addAddonIp($mainIp, $ip);
		} else {
			VpsIps::setMainIp($vzid, $ips[0]);
			VpsIps::addAddonIp($ips[0], $ip);
		}
		return true;
	}

	/**
	* removes an IP from a container
	*
	* @param string $vzid container name
	* @param string $ip IP address
	* @return bool
	*/
	/**
	* Changes one of a container's IP addresses by removing the old and adding the new.
	*
	* @param string $vzid container name
	* @param string $ipOld existing IP to drop
	* @param string $ipNew replacement IP
	* @return bool true on success, false on failure
	*/
	public static function changeIp($vzid, $ipOld, $ipNew) {
		if ($ipOld === $ipNew) {
			Vps::getLogger()->error("changeIp called with identical old and new IP '{$ipOld}'; nothing to do.");
			return false;
		}
		Vps::getLogger()->info("Changing IP on {$vzid} from {$ipOld} to {$ipNew}");
		if (!self::removeIp($vzid, $ipOld)) {
			Vps::getLogger()->error("removeIp({$ipOld}) failed; aborting changeIp.");
			return false;
		}
		if (!self::addIp($vzid, $ipNew)) {
			Vps::getLogger()->error("addIp({$ipNew}) failed after removing {$ipOld}; container may now have fewer IPs than before.");
			return false;
		}
		return true;
	}

	public static function removeIp($vzid, $ip) {
		if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			Vps::getLogger()->error("Invalid IPv4 address '{$ip}'; refusing to modify container.");
			return false;
		}
		$ips = self::getVpsIps($vzid);
		if (!in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping removing IP '.$ip.' from '.$vzid.', it does not appear to exist in the container.');
			return false;
		}
		Vps::getLogger()->info('Removing IP '.$ip.' from '.$vzid);
		// find the device with this IP
		$escaped = escapeshellarg($vzid);
		// parse devices from lxc config to find matching device
		$devices = trim(Vps::runCommand("lxc config device list {$escaped} 2>/dev/null"));
		if ($devices != '') {
			foreach (explode("\n", $devices) as $dev) {
				$dev = trim($dev);
				if ($dev == '') continue;
				$devArg = escapeshellarg($dev);
				$devIp = trim(Vps::runCommand("lxc config device get {$escaped} {$devArg} ipv4.address 2>/dev/null"));
				if ($devIp == $ip) {
					Vps::getLogger()->write(Vps::runCommand("lxc config device remove {$escaped} {$devArg}", $return));
					if ($return != 0) {
						Vps::getLogger()->error("lxc config device remove failed for {$vzid}/{$dev} (exit {$return})");
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
			}
		}
		Vps::getLogger()->error('Could not find device with IP '.$ip);
		return false;
	}

	/**
	* creates and defines an LXC container
	*
	* @param string $vzid container name
	* @param string $hostname hostname
	* @param string $template image name
	* @param string $ip primary IP address
	* @param array $extraIps additional IPs
	* @param string $mac MAC address
	* @param string $device unused for lxc
	* @param string $pool unused for lxc
	* @param int $ram memory in KiB
	* @param int $cpu number of CPUs
	* @param int $hd disk size in MB
	* @param string $password root password
	* @param string|false $ipv6Ip IPv6 address
	* @param string|false $ipv6Range IPv6 range
	* @param int|false $ioLimit IO limit in bytes/s
	* @param int|false $iopsLimit IOPS limit
	* @return bool
	*/
	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit) {
		Vps::getLogger()->info('Creating LXC Container');
		Vps::getLogger()->indent();
		if (self::vpsExists($vzid)) {
			Vps::getLogger()->info('Container already exists, removing it');
			Vps::runCommand("lxc stop ".escapeshellarg($vzid)." --force 2>/dev/null");
			Vps::runCommand("lxc delete ".escapeshellarg($vzid)." 2>/dev/null");
		}
		$image = self::resolveTemplate($template);
		if ($image === false) {
			Vps::getLogger()->error("Could not resolve template: {$template}");
			Vps::getLogger()->unIndent();
			return false;
		}
		$escaped = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("lxc init {$image} {$escaped}", $return));
		if ($return != 0) {
			Vps::getLogger()->error('Failed to create container');
			Vps::getLogger()->unIndent();
			return false;
		}
		// set hostname
		$hostnameEscaped = escapeshellarg($hostname);
		// configure network - bridged on br0
		Vps::getLogger()->debug('Configuring network');
		Vps::getLogger()->write(Vps::runCommand("lxc config device add {$escaped} eth0 nic nictype=bridged parent=br0"));
		if ($ip != '' && $ip != 'none')
			Vps::getLogger()->write(Vps::runCommand("lxc config device set {$escaped} eth0 ipv4.address {$ip}"));
		if ($mac != '')
			Vps::getLogger()->write(Vps::runCommand("lxc config set {$escaped} volatile.eth0.hwaddr {$mac}"));
		// set resource limits
		$ramMb = intval($ram / 1024);
		if ($ramMb > 0) {
			Vps::getLogger()->debug('Setting memory limit to '.$ramMb.'MB');
			Vps::getLogger()->write(Vps::runCommand("lxc config set {$escaped} limits.memory {$ramMb}MB"));
		}
		if ($cpu > 0) {
			Vps::getLogger()->debug('Setting CPU limit to '.$cpu);
			Vps::getLogger()->write(Vps::runCommand("lxc config set {$escaped} limits.cpu {$cpu}"));
		}
		// set disk limits
		if ($hd != '' && $hd != 'all' && is_numeric($hd)) {
			$hdMb = intval($hd);
			Vps::getLogger()->debug('Setting disk limit to '.$hdMb.'MB');
			Vps::getLogger()->write(Vps::runCommand("lxc config device set {$escaped} root size={$hdMb}MB 2>/dev/null"));
		}
		// set IO limits
		if ($ioLimit !== false)
			Vps::getLogger()->write(Vps::runCommand("lxc config set {$escaped} limits.disk.priority 5"));
		// set boot to auto
		Vps::getLogger()->write(Vps::runCommand("lxc config set {$escaped} boot.autostart true"));
		// add extra IPs
		foreach ($extraIps as $extraIp) {
			self::addIp($vzid, $extraIp);
		}
		// set password if provided
		if ($password != '') {
			Vps::getLogger()->debug('Starting container to set password');
			Vps::getLogger()->write(Vps::runCommand("lxc start {$escaped}"));
			sleep(3); // wait for container to boot
			$password = escapeshellarg($password);
			Vps::getLogger()->write(Vps::runCommand("lxc exec {$escaped} -- bash -c \"echo root:{$password} | chpasswd\" 2>/dev/null"));
			// set hostname inside container
			Vps::getLogger()->write(Vps::runCommand("lxc exec {$escaped} -- bash -c \"hostnamectl set-hostname {$hostnameEscaped} 2>/dev/null || hostname {$hostnameEscaped} 2>/dev/null || echo {$hostnameEscaped} > /etc/hostname\" 2>/dev/null"));
			Vps::getLogger()->write(Vps::runCommand("lxc stop {$escaped} --force"));
		}
		Vps::getLogger()->unIndent();
		return true;
	}

	/**
	* resolves a template name to an LXC image
	* supports: lxc image remotes, local images, URLs
	*
	* @param string $template template name or URL
	* @return string|false the image identifier or false on failure
	*/
	public static function resolveTemplate($template) {
		$downloadedTemplate = substr($template, 0, 7) == 'http://' || substr($template, 0, 8) == 'https://' || substr($template, 0, 6) == 'ftp://';
		if ($downloadedTemplate) {
			Vps::getLogger()->info("Downloading template from URL: {$template}");
			$template = escapeshellarg($template);
			Vps::getLogger()->write(Vps::runCommand("lxc image import {$template} --alias provirted-imported 2>/dev/null", $return));
			return $return == 0 ? 'provirted-imported' : false;
		}
		// check if it's a local image alias
		$escaped = escapeshellarg($template);
		Vps::runCommand("lxc image info {$escaped} >/dev/null 2>&1", $return);
		if ($return == 0)
			return $template;
		// try as remote image (e.g., ubuntu:22.04, images:centos/8)
		// if template contains a colon, use it directly
		if (strpos($template, ':') !== false)
			return $template;
		// try common remotes
		foreach (['images:', 'ubuntu:'] as $remote) {
			Vps::getLogger()->info("Trying {$remote}{$template}");
			Vps::runCommand("lxc image info {$remote}{$escaped} >/dev/null 2>&1", $return);
			if ($return == 0)
				return $remote.$template;
		}
		// as last resort, try it as-is (lxc init will pull from default remote)
		return $template;
	}

	/**
	* starts a container
	*
	* @param string $vzid container name
	*/
	public static function startVps($vzid) {
		Vps::getLogger()->info('Starting the Container');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("lxc start {$vzidArg}", $return));
		if ($return != 0)
			Vps::getLogger()->error("lxc start failed for {$vzid} (exit {$return})");
	}

	/**
	* resets/restarts a container
	*
	* @param string $vzid container name
	*/
	public static function resetVps($vzid) {
		Vps::getLogger()->info('Resetting the Container');
		$vzidArg = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("lxc restart {$vzidArg}", $return));
		if ($return != 0)
			Vps::getLogger()->error("lxc restart failed for {$vzid} (exit {$return})");
	}

	/**
	* stops a container
	*
	* @param string $vzid container name
	* @param bool $fast if true, force stop immediately
	*/
	public static function stopVps($vzid, $fast = false) {
		Vps::getLogger()->info('Stopping the Container');
		Vps::getLogger()->indent();
		$escaped = escapeshellarg($vzid);
		if ($fast === false) {
			Vps::getLogger()->info('Sending graceful stop (30s timeout)');
			Vps::getLogger()->write(Vps::runCommand("lxc stop {$escaped} --timeout 30", $return));
			if ($return != 0) {
				Vps::getLogger()->info('Graceful stop failed, forcing');
				Vps::getLogger()->write(Vps::runCommand("lxc stop {$escaped} --force"));
			}
		} else {
			Vps::getLogger()->info('Sending force stop');
			Vps::getLogger()->write(Vps::runCommand("lxc stop {$escaped} --force"));
		}
		Vps::getLogger()->unIndent();
	}

	/**
	* destroys a container
	*
	* @param string $vzid container name
	*/
	public static function destroyVps($vzid) {
		if (Vps::isVpsRunning($vzid)) {
			Vps::getLogger()->error("Container '{$vzid}' is running, please stop it first.");
			return;
		}
		$escaped = escapeshellarg($vzid);
		Vps::getLogger()->info('Deleting container');
		Vps::getLogger()->write(Vps::runCommand("lxc delete {$escaped}", $return));
		if ($return != 0)
			Vps::getLogger()->error("lxc delete failed for {$vzid} (exit {$return})");
	}

	/**
	* enables autostart for a container
	*
	* @param string $vzid container name
	*/
	public static function enableAutostart($vzid) {
		Vps::getLogger()->info('Enabling Automatic Startup of the Container');
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("lxc config set {$vzid} boot.autostart true"));
	}

	/**
	* disables autostart for a container
	*
	* @param string $vzid container name
	*/
	public static function disableAutostart($vzid) {
		Vps::getLogger()->info('Disabling Automatic Startup of the Container');
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("lxc config set {$vzid} boot.autostart false"));
	}

	/**
	* sets up cgroups resource limits on a container
	*
	* @param string $vzid container name
	* @param int $slices CPU slices
	*/
	public static function setupCgroups($vzid, $slices) {
		Vps::getLogger()->info('Setting up Container Resource Limits');
		$escaped = escapeshellarg($vzid);
		$cpuAllowance = ($slices * 100).'ms/100ms';
		Vps::getLogger()->write(Vps::runCommand("lxc config set {$escaped} limits.cpu.allowance {$cpuAllowance}"));
	}

	/**
	* sets up routing/network rules for a container
	*
	* @param string $vzid container name
	* @param string $ip primary IP
	* @param string $pool unused for lxc
	* @param bool $useAll use all resources
	* @param int $id order id
	*/
	public static function setupRouting($vzid, $ip, $pool, $useAll, $id) {
		Vps::getLogger()->info('Setting up Routing');
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
	}

	/**
	* blocks SMTP on a container
	*
	* @param string $vzid container name
	* @param int $id order id
	*/
	public static function blockSmtp($vzid, $id) {
		$ip = self::getVpsIps($vzid);
		if (count($ip) > 0) {
			$ip = $ip[0];
			Vps::getLogger()->write(Vps::runCommand("iptables -I FORWARD -s {$ip} -p tcp --dport 25 -j DROP 2>/dev/null"));
		}
	}

	/**
	* installs a template - handled during defineVps, so this sets password if needed
	*
	* @param string $vzid container name
	* @param string $template template/image name
	* @param string $password root password
	* @param string $device unused
	* @param string $pool unused
	* @param int $hd unused
	* @param string $kpartxOpts unused
	* @param int|false $ioLimit unused
	* @param int|false $iopsLimit unused
	* @return bool
	*/
	public static function installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts, $ioLimit, $iopsLimit) {
		// Template resolution happens in defineVps via resolveTemplate
		// If a password was specified and container is running, try to set it
		if ($password != '' && Vps::isVpsRunning($vzid)) {
			$escaped = escapeshellarg($vzid);
			$password = escapeshellarg($password);
			Vps::getLogger()->info('Setting root password in container');
			Vps::getLogger()->write(Vps::runCommand("lxc exec {$escaped} -- bash -c \"echo root:{$password} | chpasswd\" 2>/dev/null"));
		}
		return true;
	}

	/**
	* sets up storage - no-op for lxc (uses storage pools managed by lxd)
	*
	* @param string $vzid container name
	* @param string $device unused
	* @param string $pool unused
	* @param int $hd unused
	*/
	public static function setupStorage($vzid, $device, $pool, $hd) {
		// LXD manages its own storage pools
	}
}
