<?php
namespace App\Vps;

use App\Vps;

class Docker
{
	/** @var string default network mode: 'macvlan' or 'bridge' */
	protected static $networkMode = null;
	/** @var array docker config cache */
	protected static $config = null;

	/**
	* loads docker configuration from ~/.provirted/docker.json
	*
	* @return array
	*/
	public static function getConfig() {
		if (self::$config !== null)
			return self::$config;
		$defaults = [
            //'network_mode' => 'macvlan',
			'network_mode' => 'bridge',
			'macvlan_interface' => 'br0',
			'bridge_network' => 'provirted-bridge',
			'restart_policy' => 'on-failure:5',
			'container_command' => 'sleep infinity',
		];
		$configFile = $_SERVER['HOME'].'/.provirted/docker.json';
		if (file_exists($configFile)) {
			$loaded = json_decode(file_get_contents($configFile), true);
			if (is_array($loaded))
				$defaults = array_merge($defaults, $loaded);
		}
		self::$config = $defaults;
		return self::$config;
	}

	/**
	* gets the configured network mode
	*
	* @return string 'macvlan' or 'bridge'
	*/
	public static function getNetworkMode() {
		if (self::$networkMode !== null)
			return self::$networkMode;
		$config = self::getConfig();
		self::$networkMode = $config['network_mode'];
		return self::$networkMode;
	}

	/**
	* gets docker restart policy for containers
	*
	* @return string
	*/
	protected static function getRestartPolicy() {
		$config = self::getConfig();
		$policy = isset($config['restart_policy']) ? trim($config['restart_policy']) : '';
		return $policy != '' ? $policy : 'on-failure:5';
	}

	/**
	* gets command to run as container pid 1
	*
	* @return string
	*/
	protected static function getContainerCommand() {
		$config = self::getConfig();
		return isset($config['container_command']) ? trim($config['container_command']) : 'sleep infinity';
	}

	/**
	* ensures the docker network exists, creating it if needed
	*
	* @return string the network name
	*/
	public static function ensureNetwork($requiredIp = '') {
		$config = self::getConfig();
		$mode = self::getNetworkMode();
		$vlans = self::getHostVlanNetworks();
		self::ensureAllVlanNetworks($vlans, $mode);
		$matchedVlan = self::findVlanForIp($requiredIp, $vlans);
		if ($matchedVlan === false) {
			if ($requiredIp != '' && $requiredIp != 'none')
				Vps::getLogger()->error("Could not find VLAN for {$requiredIp}; falling back to default docker network.");
			$defaultNetwork = $mode == 'macvlan' ? 'provirted-macvlan' : $config['bridge_network'];
			$exists = trim(Vps::runCommand("docker network ls --filter name={$defaultNetwork} --format '{{.Name}}'"));
			if ($exists == '') {
				if ($mode == 'macvlan') {
					Vps::getLogger()->info('Creating fallback macvlan network on '.$config['macvlan_interface']);
					Vps::getLogger()->write(Vps::runCommand("docker network create -d macvlan --attachable -o parent={$config['macvlan_interface']} {$defaultNetwork}"));
				} else {
					Vps::getLogger()->info('Creating fallback bridge network '.$defaultNetwork);
					Vps::getLogger()->write(Vps::runCommand("docker network create {$defaultNetwork}"));
				}
			}
			return $defaultNetwork;
		}
		return self::getNetworkNameForVlan($matchedVlan['cidr'], $mode);
	}

	/**
	* get host VLAN networks normalized for docker usage
	*
	* @return array
	*/
	protected static function getHostVlanNetworks() {
		$host = Vps::getHostInfo();
		if (!is_array($host) || !isset($host['vlans']) || !is_array($host['vlans']))
			return [];
		$vlans = [];
		foreach ($host['vlans'] as $vlan) {
			if (!isset($vlan['network_ip']) || !isset($vlan['netmask']) || !isset($vlan['hostmin']))
				continue;
			$cidrBits = self::netmaskToCidr($vlan['netmask']);
			if ($cidrBits === false)
				continue;
			$cidr = $vlan['network_ip'].'/'.$cidrBits;
			$vlans[] = ['subnet' => $vlan['network_ip'], 'cidr' => $cidr, 'gateway' => $vlan['hostmin']];
		}
		return $vlans;
	}

	/**
	* find matching VLAN for a given IP
	*
	* @param string $requiredIp
	* @param array $vlans
	* @return array
	*/
	protected static function findVlanForIp($requiredIp, $vlans) {
		if ($requiredIp == '' || $requiredIp == 'none')
			return false;
		foreach ($vlans as $vlan) {
			if (self::ipInCidr($requiredIp, $vlan['cidr']))
				return $vlan;
		}
		return false;
	}

	/**
	* ensures every host vlan exists as a docker network
	*
	* @param array $vlans
	* @param string $mode
	*/
	protected static function ensureAllVlanNetworks($vlans, $mode) {
		$config = self::getConfig();
		foreach ($vlans as $vlan) {
			$networkName = self::getNetworkNameForVlan($vlan['cidr'], $mode);
			$exists = trim(Vps::runCommand("docker network ls --filter name={$networkName} --format '{{.Name}}'"));
			if ($exists != '')
				continue;
			if ($mode == 'macvlan') {
				Vps::getLogger()->info('Creating macvlan network '.$networkName.' on '.$config['macvlan_interface']);
				$cmd = "docker network create -d macvlan --attachable --subnet={$vlan['cidr']} --gateway={$vlan['gateway']} -o parent={$config['macvlan_interface']} {$networkName}";
			} else {
				Vps::getLogger()->info('Creating bridge network '.$networkName);
				$cmd = "docker network create --subnet={$vlan['cidr']} --gateway={$vlan['gateway']} {$networkName}";
			}
			Vps::getLogger()->write(Vps::runCommand($cmd));
		}
	}

	/**
	* get docker network name for a given vlan cidr
	*
	* @param string $cidr
	* @param string $mode
	* @return string
	*/
	protected static function getNetworkNameForVlan($cidr, $mode) {
		$config = self::getConfig();
		$prefix = $mode == 'macvlan' ? 'provirted-macvlan' : $config['bridge_network'];
		$suffix = str_replace(['.', '/'], ['-', '-'], $cidr);
		return $prefix.'-'.$suffix;
	}

	/**
	* convert IPv4 netmask to CIDR bits
	*
	* @param string $netmask
	* @return int|false
	*/
	protected static function netmaskToCidr($netmask) {
		$long = ip2long($netmask);
		if ($long === false)
			return false;
		$binary = decbin($long & 0xFFFFFFFF);
		$bits = substr_count($binary, '1');
		if (str_repeat('1', $bits).str_repeat('0', 32 - $bits) !== $binary)
			return false;
		return $bits;
	}

	/**
	* returns configured subnets for a docker network
	*
	* @param string $networkName
	* @return array
	*/
	protected static function getNetworkSubnets($networkName) {
		$networkName = escapeshellarg($networkName);
		$json = trim(Vps::runCommand("docker network inspect {$networkName} 2>/dev/null"));
		$data = json_decode($json, true);
		if (!is_array($data) || !isset($data[0]['IPAM']['Config']) || !is_array($data[0]['IPAM']['Config']))
			return [];
		$subnets = [];
		foreach ($data[0]['IPAM']['Config'] as $entry) {
			if (isset($entry['Subnet']) && $entry['Subnet'] != '')
				$subnets[] = $entry['Subnet'];
		}
		return $subnets;
	}

	/**
	* checks if an IP belongs to a CIDR subnet
	*
	* @param string $ip
	* @param string $cidr
	* @return bool
	*/
	protected static function ipInCidr($ip, $cidr) {
		if (strpos($cidr, '/') === false)
			return false;
		list($subnet, $bits) = explode('/', $cidr, 2);
		$ipLong = ip2long($ip);
		$subnetLong = ip2long($subnet);
		$bits = intval($bits);
		if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32)
			return false;
		if ($bits == 0)
			return true;
		$mask = -1 << (32 - $bits);
		$mask = $mask & 0xFFFFFFFF;
		return ($ipLong & $mask) == ($subnetLong & $mask);
	}

	/**
	* return a list of the running containers
	*
	* @return array
	*/
	public static function getRunningVps() {
		$output = trim(Vps::runCommand("docker ps --format '{{.Names}}'"));
		return $output == '' ? [] : explode("\n", $output);
	}

	/**
	* return a list of all containers
	*
	* @return array
	*/
	public static function getAllVps() {
		$output = trim(Vps::runCommand("docker ps -a --format '{{.Names}}'"));
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
		Vps::runCommand("docker inspect {$vzid} >/dev/null 2>&1", $return);
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
		$json = trim(Vps::runCommand("docker inspect {$vzid} 2>/dev/null"));
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
		$networks = $vps['NetworkSettings']['Networks'];
		foreach ($networks as $netName => $netInfo) {
			if (isset($netInfo['MacAddress']) && $netInfo['MacAddress'] != '')
				return $netInfo['MacAddress'];
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
		$networks = $vps['NetworkSettings']['Networks'];
		foreach ($networks as $netName => $netInfo) {
			if (isset($netInfo['IPAddress']) && $netInfo['IPAddress'] != '')
				$ips[] = $netInfo['IPAddress'];
		}
		return $ips;
	}

	/**
	* adds an IP to a container by connecting it to a network with a specific IP
	*
	* @param string $vzid container name
	* @param string $ip IP address
	* @return bool
	*/
	public static function addIp($vzid, $ip) {
		$ips = self::getVpsIps($vzid);
		if (in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping adding IP '.$ip.' to '.$vzid.', it already exists in the container.');
			return false;
		}
		Vps::getLogger()->info('Adding IP '.$ip.' to '.$vzid);
		$network = self::ensureNetwork($ip);
		$vzid = escapeshellarg($vzid);
		$ip = escapeshellarg($ip);
		Vps::getLogger()->write(Vps::runCommand("docker network connect --ip {$ip} {$network} {$vzid}"));
		return true;
	}

	/**
	* removes an IP from a container by disconnecting it from a network
	*
	* @param string $vzid container name
	* @param string $ip IP address
	* @return bool
	*/
	public static function removeIp($vzid, $ip) {
		$ips = self::getVpsIps($vzid);
		if (!in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping removing IP '.$ip.' from '.$vzid.', it does not appear to exist in the container.');
			return false;
		}
		Vps::getLogger()->info('Removing IP '.$ip.' from '.$vzid);
		$vps = self::getVps($vzid);
		$networks = $vps['NetworkSettings']['Networks'];
		foreach ($networks as $netName => $netInfo) {
			if (isset($netInfo['IPAddress']) && $netInfo['IPAddress'] == $ip) {
				$vzid = escapeshellarg($vzid);
				Vps::getLogger()->write(Vps::runCommand("docker network disconnect {$netName} {$vzid}"));
				return true;
			}
		}
		return false;
	}

	/**
	* creates and defines a docker container
	*
	* @param string $vzid container name
	* @param string $hostname hostname
	* @param string $template image name or local template
	* @param string $ip primary IP address
	* @param array $extraIps additional IPs
	* @param string $mac MAC address
	* @param string $device unused for docker
	* @param string $pool unused for docker
	* @param int $ram memory in KiB
	* @param int $cpu number of CPUs
	* @param int $maxRam unused for docker
	* @param int $maxCpu unused for docker
	* @param bool $useAll use all resources
	* @param string $password root password (passed as ROOT_PASSWORD env var)
	* @param string|false $ipv6Ip IPv6 address
	* @param string|false $ipv6Range IPv6 range
	* @param int|false $ioLimit IO limit in bytes/s
	* @param int|false $iopsLimit IOPS limit
	* @return bool
	*/
	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $maxRam, $maxCpu, $useAll, $password, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit) {
		Vps::getLogger()->info('Creating Docker Container Definition');
		Vps::getLogger()->indent();
		if (self::vpsExists($vzid)) {
			Vps::getLogger()->info('Container already exists, removing it');
			Vps::runCommand("docker stop ".escapeshellarg($vzid)." 2>/dev/null");
			Vps::runCommand("docker rm ".escapeshellarg($vzid)." 2>/dev/null");
		}
		$image = self::resolveTemplate($template);
		if ($image === false) {
			Vps::getLogger()->error("Could not resolve template: {$template}");
			Vps::getLogger()->unIndent();
			return false;
		}
		$network = self::ensureNetwork($ip);
		$ramMb = intval($ram / 1024);
		$cmd = "docker create";
		$cmd .= " --name ".escapeshellarg($vzid);
		$cmd .= " --hostname ".escapeshellarg($hostname);
		$cmd .= " --network {$network}";
		if ($ip != '' && $ip != 'none') {
			$subnets = self::getNetworkSubnets($network);
			$canUseIp = false;
			foreach ($subnets as $subnet) {
				if (self::ipInCidr($ip, $subnet)) {
					$canUseIp = true;
					break;
				}
			}
			if ($canUseIp)
				$cmd .= " --ip ".escapeshellarg($ip);
			else
				Vps::getLogger()->error("Skipping --ip {$ip} because it is outside {$network} subnets. Check host VLAN data for this IP.");
		}
		if ($mac != '')
			$cmd .= " --mac-address ".escapeshellarg($mac);
		if ($ramMb > 0)
			$cmd .= " --memory {$ramMb}m";
		if ($cpu > 0)
			$cmd .= " --cpus {$cpu}";
		if ($ioLimit !== false)
			$cmd .= " --device-write-bps /dev/sda:{$ioLimit}";
		if ($iopsLimit !== false)
			$cmd .= " --device-write-iops /dev/sda:{$iopsLimit}";
		if ($password != '')
			$cmd .= " -e ROOT_PASSWORD=".escapeshellarg($password);
		$cmd .= " --restart ".escapeshellarg(self::getRestartPolicy());
		$cmd .= " --privileged";
		$cmd .= " --tmpfs /run --tmpfs /run/lock";
		$cmd .= " -v /sys/fs/cgroup:/sys/fs/cgroup:ro";
		$cmd .= " {$image}";
		$containerCommand = self::getContainerCommand();
		if ($containerCommand != '')
			$cmd .= " /bin/sh -lc ".escapeshellarg($containerCommand);
		Vps::getLogger()->debug('Running: '.$cmd);
		Vps::getLogger()->write(Vps::runCommand($cmd, $return));
		if ($return != 0) {
			Vps::getLogger()->error('Failed to create container');
			Vps::getLogger()->unIndent();
			return false;
		}
		if (count($extraIps) > 0) {
			foreach ($extraIps as $extraIp) {
				self::addIp($vzid, $extraIp);
			}
		}
		Vps::getLogger()->unIndent();
		return true;
	}

	/**
	* resolves a template name to a docker image
	* supports: docker hub images, URLs, local Dockerfiles in /vz/templates/
	*
	* @param string $template template name or URL
	* @return string|false the docker image name or false on failure
	*/
	public static function resolveTemplate($template) {
		$downloadedTemplate = substr($template, 0, 7) == 'http://' || substr($template, 0, 8) == 'https://' || substr($template, 0, 6) == 'ftp://';
		if ($downloadedTemplate) {
			Vps::getLogger()->info("Downloading template from URL: {$template}");
			Vps::getLogger()->write(Vps::runCommand("docker pull ".escapeshellarg($template), $return));
			return $return == 0 ? $template : false;
		}
		if (file_exists('/vz/templates/'.$template.'/Dockerfile')) {
			Vps::getLogger()->info("Building image from local Dockerfile: {$template}");
			$tag = 'provirted/'.$template;
			Vps::getLogger()->write(Vps::runCommand("docker build -t {$tag} /vz/templates/{$template}", $return));
			return $return == 0 ? $tag : false;
		}
		$exists = trim(Vps::runCommand("docker image inspect ".escapeshellarg($template)." >/dev/null 2>&1", $return));
		if ($return == 0)
			return $template;
		Vps::getLogger()->info("Pulling image: {$template}");
		Vps::getLogger()->write(Vps::runCommand("docker pull ".escapeshellarg($template), $return));
		return $return == 0 ? $template : false;
	}

	/**
	* starts a container
	*
	* @param string $vzid container name
	*/
	public static function startVps($vzid) {
		Vps::getLogger()->info('Starting the Container');
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("docker start {$vzid}"));
	}

	/**
	* resets/restarts a container
	*
	* @param string $vzid container name
	*/
	public static function resetVps($vzid) {
		Vps::getLogger()->info('Resetting the Container');
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("docker restart {$vzid}"));
	}

	/**
	* stops a container
	*
	* @param string $vzid container name
	* @param bool $fast if true, kill immediately instead of graceful stop
	*/
	public static function stopVps($vzid, $fast = false) {
		Vps::getLogger()->info('Stopping the Container');
		Vps::getLogger()->indent();
		$escaped = escapeshellarg($vzid);
		if ($fast === false) {
			Vps::getLogger()->info('Sending graceful stop (30s timeout)');
			Vps::getLogger()->write(Vps::runCommand("docker stop -t 30 {$escaped}"));
		} else {
			Vps::getLogger()->info('Sending immediate kill');
			Vps::getLogger()->write(Vps::runCommand("docker kill {$escaped}"));
		}
		Vps::getLogger()->unIndent();
	}

	/**
	* destroys a container and its volumes
	*
	* @param string $vzid container name
	*/
	public static function destroyVps($vzid) {
		if (Vps::isVpsRunning($vzid)) {
			Vps::getLogger()->write("Container is running, please stop first.\n");
			return;
		}
		$escaped = escapeshellarg($vzid);
		Vps::getLogger()->info('Removing container and volumes');
		Vps::getLogger()->write(Vps::runCommand("docker rm -v {$escaped}"));
	}

	/**
	* enables autostart (restart policy) for a container
	*
	* @param string $vzid container name
	*/
	public static function enableAutostart($vzid) {
		Vps::getLogger()->info('Enabling Automatic Restart of the Container');
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("docker update --restart ".escapeshellarg(self::getRestartPolicy())." {$vzid}"));
	}

	/**
	* disables autostart (restart policy) for a container
	*
	* @param string $vzid container name
	*/
	public static function disableAutostart($vzid) {
		Vps::getLogger()->info('Disabling Automatic Restart of the Container');
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("docker update --restart no {$vzid}"));
	}

	/**
	* sets up cgroups resource limits on a container
	*
	* @param string $vzid container name
	* @param int $slices CPU slices
	*/
	public static function setupCgroups($vzid, $slices) {
		Vps::getLogger()->info('Setting up Container Resource Limits');
		$cpushares = $slices * 512;
		$escaped = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("docker update --cpu-shares {$cpushares} {$escaped}"));
	}

	/**
	* sets up routing/network rules for a container
	*
	* @param string $vzid container name
	* @param string $ip primary IP
	* @param string $pool unused for docker
	* @param bool $useAll use all resources
	* @param int $id order id
	*/
	public static function setupRouting($vzid, $ip, $pool, $useAll, $id) {
		Vps::getLogger()->info('Setting up Routing');
		$base = Vps::$base;
		if ($ip != 'none') {
			Vps::getLogger()->write(Vps::runCommand("{$base}/tclimit {$ip};"));
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
	* installs a template (pulls or builds docker image)
	* this is handled during defineVps via resolveTemplate, so this is mostly a no-op
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
		// Password is set via ROOT_PASSWORD env var in defineVps
		return true;
	}

	/**
	* sets up storage - no-op for docker
	*
	* @param string $vzid container name
	* @param string $device unused
	* @param string $pool unused
	* @param int $hd unused
	*/
	public static function setupStorage($vzid, $device, $pool, $hd) {
		// Docker manages its own storage layer
	}
}
