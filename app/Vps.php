<?php
namespace App;

use App\XmlToArray;
use App\Os\Os;
use App\Os\Xinetd;
use App\Vps\Docker;
use App\Vps\Kvm;
use App\Vps\Lxc;
use App\Vps\Virtuozzo;
use App\Vps\OpenVz;

/**
* Provides OOP interface to virtualization technologies
*/
class Vps
{
	public static $base = '/root/cpaneldirect';
	public static $virtBins = [
		'virtuozzo' => '/usr/bin/prlctl',
		'openvz' => '/usr/sbin/vzctl',
		'kvm' => '/usr/bin/virsh',
		'lxc' => '/usr/bin/lxc',
		'docker' => '/usr/bin/docker',
	];
	public static $virtInstalled = false;
	public static $virtType = false;
	public static $virtValidations = [
		'kvm-ok',
		'lscpu',
		'/proc/cpuinfo' => 'egrep "svm|vmx" /proc/cpuinfo',
		'virt-host-validate'
	];
	/** @var \App\Logger */
	protected static $logger;
	/** @var array */
	protected static $args;
	/** @var \GetOptionKit\OptionCollection */
	protected static $opts;

	/**
	* @param \App\Logger $logger
	*/
	public static function setLogger($logger) {
		self::$logger = $logger;
	}

	/**
	* @return \App\Logger
	*/
	public static function getLogger() {
		return self::$logger;
	}

	/**
	* Verifies an external helper script exists and is executable before invoking it.
	* Logs an error if missing or non-executable; callers should bail out on false.
	*
	* @param string $relPath path under self::$base (e.g. 'tclimit', 'vps_kvm_lvmcreate.sh')
	*   OR an absolute path starting with '/'
	* @return string|false the absolute path on success, false if the script can't be invoked
	*/
	public static function requireScript($relPath) {
		$path = substr($relPath, 0, 1) === '/' ? $relPath : rtrim(self::$base, '/').'/'.$relPath;
		if (!file_exists($path)) {
			self::getLogger()->error("Required helper script not found: {$path}");
			return false;
		}
		if (!is_executable($path)) {
			// .sh / interpreted scripts may still run via `bash <path>`; only block hard
			// failures (e.g. directory or unreadable file). Log a warning either way.
			if (is_dir($path) || !is_readable($path)) {
				self::getLogger()->error("Required helper script not readable: {$path}");
				return false;
			}
			self::getLogger()->info2("Helper script {$path} is not marked executable; will rely on interpreter invocation.");
		}
		return $path;
	}

	/**
	* @param \GetOptionKit\OptionCollection $opts
	* @param array $args
	*/
	public static function init($opts, array $args) {
		self::$opts = $opts;
		self::$args = $args;
		self::setVirtType(array_key_exists('virt', self::$opts->keys) && self::$opts->keys['virt']->value != 'auto' ? self::$opts->keys['virt']->value : false);
		if (array_key_exists('verbose', self::$opts->keys)) {
			self::getLogger()->info("verbosity increased by ".self::$opts->keys['verbose']->value." levels");
			self::getLogger()->setLevel(self::getLogger()->getLevel() + self::$opts->keys['verbose']->value);
		}
		// Session-wide history suppression: --no-log on the command, or env var PROVIRTED_NO_LOG=1
		if ((array_key_exists('no-log', self::$opts->keys) && self::$opts->keys['no-log']->value)
		    || getenv('PROVIRTED_NO_LOG') == '1') {
			self::getLogger()->disableHistory();
		}
	}

	/**
	* returns an array of installed virtualization types
	*
	* @return array
	*/
	public static function getInstalledVirts() {
		if (self::$virtInstalled === false) {
			self::getLogger()->info2('detecting installed virtualization types.');
			self::getLogger()->indent();
			$found = [];
			foreach (self::$virtBins as $virt => $virtBin) {
				if (file_exists($virtBin)) {
					self::getLogger()->info2('found '.$virt.' virtualization installed');
					$found[] = $virt;
				}
			}
			self::getLogger()->unIndent();
			self::$virtInstalled = $found;
		}
		return self::$virtInstalled;
	}

	/**
	* determines if the host is setup for virtualization or not
	*
	* @return bool
	*/
	public static function isVirtualHost() {
		$virt = self::getVirtType();
		if ($virt !== false)
			self::getLogger()->info2('using '.$virt.' virtualization.');
		return $virt !== false;
	}

	/**
	* gets the type of virtualization we'll be using
	*
	* @return string
	*/
	public static function getVirtType() {
		$virts = self::getInstalledVirts();
		foreach ($virts as $idx => $virt)
			if (self::$virtType == false || self::$virtType == $virt)
				return self::$virtType = $virt;
			return false;
	}

	public static function setVirtType($virt) {
		if ($virt !== false)
			self::getLogger()->info2('trying to force '.$virt.' virtualization.');
		self::$virtType = $virt;
	}

    /**
    * locks a vps for backgrounded actions
    *
    * @param int|string $vzid
    * @param bool $useAll
    * @return bool indicates success
    */
    public static function lock($vzid, $useAll) {
        $module = $useAll === true ? 'quickservers' : 'vps';
        $url = self::getUrl().'?action=lock&id='.urlencode($vzid).'&module='.$module;
        self::runCommand('curl -sS --max-time 30 '.escapeshellarg($url).' >/dev/null 2>&1', $return);
        if ($return != 0) {
            self::getLogger()->error("Failed to lock VPS {$vzid} (curl exit {$return})");
            return false;
        }
        return true;
    }

    /**
    * unlocks a vps
    *
    * @param int|string $vzid
    * @param bool $useAll
    * @return bool indicates success
    */
    public static function unlock($vzid, $useAll) {
        $module = $useAll === true ? 'quickservers' : 'vps';
        $url = self::getUrl().'?action=unlock&id='.urlencode($vzid).'&module='.$module;
        self::runCommand('curl -sS --max-time 30 '.escapeshellarg($url).' >/dev/null 2>&1', $return);
        if ($return != 0) {
            self::getLogger()->error("Failed to unlock VPS {$vzid} (curl exit {$return})");
            return false;
        }
        return true;
    }

	/**
	* returns an array containing information about the host server, vlans, and vps's
	*
	* @return array|false the host info on success, false on network or parse failure
	*/
	public static function getHostInfo() {
		$url = self::getUrl().'?action=get_info';
		$response = trim(self::runCommand('curl -sS --max-time 30 '.escapeshellarg($url).' 2>&1', $return));
		if ($return != 0) {
			self::getLogger()->error("Failed to fetch host info from {$url} (curl exit {$return}); response: {$response}");
			// fall back to cached copy if available
			$cacheFile = $_SERVER['HOME'].'/.provirted/host.json';
			if (file_exists($cacheFile)) {
				$age = time() - filemtime($cacheFile);
				$ageReadable = $age < 3600 ? round($age / 60).' min' : ($age < 86400 ? round($age / 3600, 1).' hr' : round($age / 86400, 1).' day');
				self::getLogger()->error("Using cached host info from {$cacheFile} (age: {$ageReadable})");
				$cached = @file_get_contents($cacheFile);
				if ($cached !== false) {
					$host = json_decode($cached, true);
					if (is_array($host) && isset($host['vlans'])) {
						return $host;
					}
					self::getLogger()->error("Cached host info at {$cacheFile} is unparseable; aborting.");
				} else {
					self::getLogger()->error("Could not read cached host info from {$cacheFile}");
				}
			}
			return false;
		}
		$host = json_decode($response, true);
		if (!is_array($host) || !isset($host['vlans'])) {
			self::getLogger()->error("Invalid response getting host info: {$response}");
			return false;
		}
		/* $vps = {
			"id": "2324459",
			"hostname": "vps2324459",
			"vzid": "vps2324459",
			"mac": "00:16:3e:23:77:eb",
			"ip": "208.73.202.209",
			"status": "active",
			"server_status": "running",
			"vnc": "79.156.208.231"
		} */

		@mkdir($_SERVER['HOME'].'/.provirted', 0750, true);
		if (@file_put_contents($_SERVER['HOME'].'/.provirted/host.json', $response) === false) {
			self::getLogger()->error("Could not cache host info to ~/.provirted/host.json (check permissions)");
		}
		return $host;
	}

	/**
	* Fetches a cloud-init template from the control panel and caches it locally.
	*
	* Used when a cloud-init install references a user-data file that does not yet
	* exist under /vz/templates/cloudinit/ (e.g. the "openclaw.yaml" in a template
	* like "cloud-init:ubuntu24.qcow2:openclaw.yaml"). The full cloud-init: reference
	* is passed verbatim as the "template" GET var to action=get_template, which
	* returns the matching vps_templates row as JSON; the user-data lives in the
	* "template_config" field. The contents are written to
	* /vz/templates/cloudinit/<yaml-basename> and that path is returned.
	*
	* @param string $template the full cloud-init: reference (e.g. cloud-init:ubuntu24.qcow2:openclaw.yaml)
	* @return string|false the local path to the cached template on success, false on failure
	*/
	public static function getTemplate($template) {
		$url = self::getUrl().'?action=get_template&template='.urlencode($template);
		$response = trim(self::runCommand('curl -sS --max-time 30 '.escapeshellarg($url).' 2>&1', $return));
		if ($return != 0) {
			self::getLogger()->error("Failed to fetch template '{$template}' from {$url} (curl exit {$return}); response: {$response}");
			return false;
		}
		$data = json_decode($response, true);
		if (!is_array($data)) {
			self::getLogger()->error("Invalid response getting template '{$template}': {$response}");
			return false;
		}
		if (isset($data['error'])) {
			self::getLogger()->error("Server error fetching template '{$template}': {$data['error']}");
			return false;
		}
		if (!isset($data['template_config']) || trim((string) $data['template_config']) === '') {
			self::getLogger()->error("Template '{$template}' response has no template_config content: {$response}");
			return false;
		}
		// The on-disk filename is the last colon-delimited segment of the reference
		// (e.g. "openclaw.yaml" from "cloud-init:ubuntu24.qcow2:openclaw.yaml").
		$ref = $template;
		if (strpos($ref, 'cloud-init:') === 0)
			$ref = substr($ref, strlen('cloud-init:'));
		$parts = explode(':', $ref);
		$yamlName = basename(trim((string) end($parts)));
		if ($yamlName === '') {
			self::getLogger()->error("Could not determine target filename for template '{$template}'");
			return false;
		}
		$dir = '/vz/templates/cloudinit';
		@mkdir($dir, 0755, true);
		$dest = $dir.'/'.$yamlName;
		if (@file_put_contents($dest, $data['template_config']) === false) {
			self::getLogger()->error("Could not write cloud-init template to {$dest} (check permissions)");
			return false;
		}
		self::getLogger()->info("Downloaded cloud-init template '{$template}' to {$dest}");
		return $dest;
	}

	/**
	* return a list of the running vps
	*
	* @return array
	*/
	public static function getRunningVps() {
		if (self::getVirtType() == 'kvm')
			return Kvm::getRunningVps();
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getRunningVps();
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::getRunningVps();
		elseif (self::getVirtType() == 'docker')
			return Docker::getRunningVps();
		elseif (self::getVirtType() == 'lxc')
			return Lxc::getRunningVps();
	}

	/**
	* return a list of all the vps
	*
	* @return array
	*/
	public static function getAllVps() {
		if (self::getVirtType() == 'kvm')
			return Kvm::getAllVps();
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getAllVps();
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::getAllVps();
		elseif (self::getVirtType() == 'docker')
			return Docker::getAllVps();
		elseif (self::getVirtType() == 'lxc')
			return Lxc::getAllVps();
	}

	/**
	* return a list of all the vps on all installed virtualization types
	*
	* @return array
	*/
	public static function getAllVpsAllVirts() {
		$virts = self::getInstalledVirts();
		$vpsList = [];
		if (in_array('virtuozzo', $virts))
			$vpsList = array_merge($vpsList, Virtuozzo::getAllVps());
		if (in_array('openvz', $virts))
			$vpsList = array_merge($vpsList, OpenVz::getAllVps());
		if (in_array('kvm', $virts))
			$vpsList = array_merge($vpsList, Kvm::getAllVps());
		if (in_array('docker', $virts))
			$vpsList = array_merge($vpsList, Docker::getAllVps());
		if (in_array('lxc', $virts))
			$vpsList = array_merge($vpsList, Lxc::getAllVps());
		return $vpsList;
	}

	/**
	* determines if a vps is running or not
	*
	* @param int|string $vzid
	* @return bool
	*/
	public static function isVpsRunning($vzid) {
		return in_array($vzid, self::getRunningVps());
	}

	/**
	* determines if a vps exists or not
	*
	* @param string $vzid
	* @return bool
	*/
	public static function vpsExists($vzid) {
		if (self::getVirtType() == 'kvm')
			return Kvm::vpsExists($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::vpsExists($vzid);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::vpsExists($vzid);
		elseif (self::getVirtType() == 'docker')
			return Docker::vpsExists($vzid);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::vpsExists($vzid);
	}

	public static function getUrl() {
		return 'https://myvps.interserver.net/queue.php';
	}

	/**
	* gets the type of storage pool
	*
	* @return string
	*/
	public static function getPoolType() {
		$pool = '';
		if (self::getVirtType() == 'kvm')
			$pool = Kvm::getPoolType();
		elseif (self::getVirtType() == 'docker')
			$pool = 'docker';
		elseif (self::getVirtType() == 'lxc')
			$pool = 'lxc';
		else
			self::getLogger()->error("dont know how to handle virt type:".self::getVirtType());
		return $pool;
	}

	/**
	* gets the mac address of a vps
	*
	* @param int|string $vzid
	* @return string
	*/
	public static function getVpsMac($vzid) {
		if (self::getVirtType() == 'docker')
			return Docker::getVpsMac($vzid);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::getVpsMac($vzid);
		return Kvm::getVpsMac($vzid);
	}

	/**
	* gets the ips configured on a vps
	*
	* @param int|string $vzid
	* @return array
	*/
	public static function getVpsIps($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getVpsIps($vzid);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::getVpsIps($vzid);
		elseif (self::getVirtType() == 'kvm')
			return Kvm::getVpsIps($vzid);
		elseif (self::getVirtType() == 'docker')
			return Docker::getVpsIps($vzid);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::getVpsIps($vzid);
	}

	/**
	* converts an order id into a mac address
	*
	* @param int $id
	* @param bool $useAll
	* @return string
	*/
	public static function convertIdToMac($id, $useAll) {
		$prefix = $useAll == true ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}

	/**
	* gets the vnc/spice ports for a vps
	*
	* @param int|string $vzid
	* @return array
	*/
	public static function getVpsRemotes($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getVpsRemotes($vzid);
		elseif (self::getVirtType() == 'docker' || self::getVirtType() == 'lxc')
			return [];
		else
			return Kvm::getVpsRemotes($vzid);
	}

	/**
	* gets the vnc port for a vps
	*
	* @param int|string $vzid
	* @return int|string
	*/
	public static function getVncPort($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getVncPort($vzid);
		elseif (self::getVirtType() == 'docker' || self::getVirtType() == 'lxc')
			return '';
		else
			return Kvm::getVncPort($vzid);
	}

	public static function setupVnc($vzid, $clientIp = '') {
		Xinetd::lock();
		$remotes = self::getVpsRemotes($vzid);
		if (self::getVirtType() == 'virtuozzo') {
			$vps = Virtuozzo::getVps($vzid);
			if ($vps === false || !isset($vps['EnvID'])) {
				self::getLogger()->error("Could not resolve EnvID for Virtuozzo VPS '{$vzid}'; aborting VNC setup.");
				Xinetd::unlock();
				return;
			}
			$vzid = $vps['EnvID'];
		}
		self::getLogger()->write('Parsing Services...');
		$services = Xinetd::parseEntries();
		self::getLogger()->write('done'.PHP_EOL);
		foreach ($services as $serviceName => $serviceData) {
			if (in_array($serviceName, [$vzid, $vzid.'-spice'])
				|| (isset($serviceData['port']) && in_array(intval($serviceData['port']), array_values($remotes)))) {
				self::getLogger()->write("removing {$serviceData['filename']}\n");
				unlink($serviceData['filename']);
			}
		}
		foreach ($remotes as $type => $port) {
			self::getLogger()->write("setting up {$type} on {$vzid} port {$port}".(trim($clientIp) != '' ? " ip {$clientIp}" : "")."\n");
			Xinetd::setup($type == 'vnc' ? $vzid : $vzid.'-'.$type, $port, trim($clientIp) != '' ? $clientIp : false);
		}
		Xinetd::unlock();
		Xinetd::restart();
	}

	public static function enableAutostart($vzid) {
		if (self::getVirtType() == 'kvm')
			Kvm::enableAutostart($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::enableAutostart($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::enableAutostart($vzid);
		elseif (self::getVirtType() == 'docker')
			Docker::enableAutostart($vzid);
		elseif (self::getVirtType() == 'lxc')
			Lxc::enableAutostart($vzid);
	}

	public static function disableAutostart($vzid) {
		if (self::getVirtType() == 'kvm')
			Kvm::disableAutostart($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::disableAutostart($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::disableAutostart($vzid);
		elseif (self::getVirtType() == 'docker')
			Docker::disableAutostart($vzid);
		elseif (self::getVirtType() == 'lxc')
			Lxc::disableAutostart($vzid);
	}

	public static function startVps($vzid) {
		self::getLogger()->info('Starting the VPS');
		if (self::getVirtType() == 'kvm')
			Kvm::startVps($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::startVps($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::startVps($vzid);
		elseif (self::getVirtType() == 'docker')
			Docker::startVps($vzid);
		elseif (self::getVirtType() == 'lxc')
			Lxc::startVps($vzid);
		if (!self::isVpsRunning($vzid))
			return 1;
	}

	public static function stopVps($vzid, $fast = false) {
		if (self::getVirtType() == 'kvm')
			Kvm::stopVps($vzid, $fast);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::stopVps($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::stopVps($vzid);
		elseif (self::getVirtType() == 'docker')
			Docker::stopVps($vzid, $fast);
		elseif (self::getVirtType() == 'lxc')
			Lxc::stopVps($vzid, $fast);
	}

	public static function resetVps($vzid) {
		if (self::getVirtType() == 'kvm')
			Kvm::resetVps($vzid);
		elseif (self::getVirtType() == 'docker')
			Docker::resetVps($vzid);
		elseif (self::getVirtType() == 'lxc')
			Lxc::resetVps($vzid);
	}

	public static function restartVps($vzid) {
		self::stopVps($vzid);
		self::startVps($vzid);
	}

	public static function deleteVps($vzid) {
		self::stopVps($vzid);
		self::disableAutostart($vzid);
	}

	public static function destroyVps($vzid) {
		//self::deleteVps($vzid);
		if (self::getVirtType() == 'kvm')
			Kvm::destroyVps($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::destroyVps($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::destroyVps($vzid);
		elseif (self::getVirtType() == 'docker')
			Docker::destroyVps($vzid);
		elseif (self::getVirtType() == 'lxc')
			Lxc::destroyVps($vzid);
	}

	public static function addIp($vzid, $ip) {
		if (self::getVirtType() == 'kvm')
			return Kvm::addIp($vzid, $ip);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::addIp($vzid, $ip);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::addIp($vzid, $ip);
		elseif (self::getVirtType() == 'docker')
			return Docker::addIp($vzid, $ip);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::addIp($vzid, $ip);
		self::getLogger()->error('addIp is not supported on this platform: '.self::getVirtType());
		return false;
	}

	public static function removeIp($vzid, $ip) {
		if (self::getVirtType() == 'kvm')
			return Kvm::removeIp($vzid, $ip);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::removeIp($vzid, $ip);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::removeIp($vzid, $ip);
		elseif (self::getVirtType() == 'docker')
			return Docker::removeIp($vzid, $ip);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::removeIp($vzid, $ip);
		self::getLogger()->error('removeIp is not supported on this platform: '.self::getVirtType());
		return false;
	}

	public static function changeIp($vzid, $ipOld, $ipNew) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::changeIp($vzid, $ipOld, $ipNew);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::changeIp($vzid, $ipOld, $ipNew);
		elseif (self::getVirtType() == 'kvm')
			return Kvm::changeIp($vzid, $ipOld, $ipNew);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::changeIp($vzid, $ipOld, $ipNew);
		elseif (self::getVirtType() == 'docker')
			return Docker::changeIp($vzid, $ipOld, $ipNew);
		self::getLogger()->error('Changing an IP is not supported on this platform: '.self::getVirtType());
		return false;
	}

	public static function setupRouting($vzid, $ip, $pool, $useAll, $id) {
		if (self::getVirtType() == 'kvm')
			Kvm::setupRouting($vzid, $ip, $pool, $useAll, $id);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupRouting($vzid, $id);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::setupRouting($vzid, $id);
		elseif (self::getVirtType() == 'docker')
			Docker::setupRouting($vzid, $ip, $pool, $useAll, $id);
		elseif (self::getVirtType() == 'lxc')
			Lxc::setupRouting($vzid, $ip, $pool, $useAll, $id);
	}

	public static function blockSmtp($vzid, $id) {
		if (self::getVirtType() == 'kvm')
			Kvm::blockSmtp($vzid, $id);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::blockSmtp($vzid, $id);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::blockSmtp($vzid, $id);
		elseif (self::getVirtType() == 'docker')
			Docker::blockSmtp($vzid, $id);
		elseif (self::getVirtType() == 'lxc')
			Lxc::blockSmtp($vzid, $id);
	}

	public static function setupStorage($vzid, $device, $pool, $hd) {
		if (self::getVirtType() == 'kvm')
			Kvm::setupStorage($vzid, $device, $pool, $hd);
		elseif (self::getVirtType() == 'docker')
			Docker::setupStorage($vzid, $device, $pool, $hd);
		elseif (self::getVirtType() == 'lxc')
			Lxc::setupStorage($vzid, $device, $pool, $hd);
	}

	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $hd, $maxRam, $maxCpu, $useAll, $password, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit) {
		if (self::getVirtType() == 'kvm')
			return Kvm::defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $maxRam, $maxCpu, $useAll, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit);
		elseif (self::getVirtType() == 'docker')
			return Docker::defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $maxRam, $maxCpu, $useAll, $password, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit);
		return true;
	}

	public static function setupCgroups($vzid, $useAll, $cpu) {
		$slices = $cpu;
		if ($useAll == false) {
			if (self::getVirtType() == 'kvm')
				Kvm::setupCgroups($vzid, $slices);
			elseif (self::getVirtType() == 'docker')
				Docker::setupCgroups($vzid, $slices);
			elseif (self::getVirtType() == 'lxc')
				Lxc::setupCgroups($vzid, $slices);
		}
	}

	public static function installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts, $ioLimit, $iopsLimit) {
		if (self::getVirtType() == 'kvm')
			return Kvm::installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts, $ioLimit, $iopsLimit);
		elseif (self::getVirtType() == 'docker')
			return Docker::installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts, $ioLimit, $iopsLimit);
		elseif (self::getVirtType() == 'lxc')
			return Lxc::installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts, $ioLimit, $iopsLimit);
		return true;
	}

	/**
	 * True when the template reference selects the cloud-init / virt-install path
	 * instead of the legacy XML + qcow2 copy flow. Only meaningful for KVM.
	 */
	public static function isCloudInitTemplate($template) {
		return is_string($template) && strpos($template, 'cloud-init:') === 0;
	}

	/**
	 * Cloud-init driven KVM install — currently KVM-only. Other backends would need
	 * their own cloud-init-ish flow if/when added; for now fall through to false.
	 */
	public static function installCloudInit($vzid, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $hd, $hostname, $password, $sshKey, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit, $clientEmail = '') {
		if (self::getVirtType() == 'kvm')
			return Kvm::installCloudInit($vzid, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $hd, $hostname, $password, $sshKey, $ipv6Ip, $ipv6Range, $ioLimit, $iopsLimit, $clientEmail);
		self::getLogger()->error('cloud-init templates are only supported for the kvm backend');
		return false;
	}

	public static function setupWebuzo($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupWebuzo($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::setupWebuzo($vzid);
	}

	public static function setupCpanel($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupCpanel($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::setupCpanel($vzid);
	}

	public static function getHistoryChoices() {
		$return = self::getLogger()->getHistory();
		array_unshift($return, 'last');
	}

	/**
	* runs a commnand
	*
	* @param string $cmd command to run
	* @param int $return store the return value
	* @param false|int $timeout false or timeout in seconds
	* @return string stdout.stderr text
	*/
	public static function runCommand($cmd, &$return = 0, $timeout = false) {
		$descs = [
			0 => ['pipe','r'],
			1 => ['pipe','w'],
			2 => ['pipe','w']
		];
		$stdout = '';
		$stderr = '';
		$proc = proc_open($cmd, $descs, $pipes);
		if (is_resource($proc)) {
			if ($timeout !== false) {
				stream_set_timeout($pipes[1], $timeout);
				stream_set_timeout($pipes[2], $timeout);
			}
			while (!feof($pipes[1])) {
				$stdout .= fgets($pipes[1]);
				$info = stream_get_meta_data($pipes[1]);
				if ($info['timed_out'] == true) {
					echo 'Connection timed out!';
					break;
				}
			}
			while (!feof($pipes[2])) {
				$stderr .= fgets($pipes[2]);
				$info = stream_get_meta_data($pipes[2]);
				if ($info['timed_out'] == true) {
					echo 'Connection timed out!';
					break;
				}
			}
			fclose($pipes[0]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_get_status($proc);
			$retVal = proc_close($proc);
			$return = $status['running'] ? $retVal : $status['exitcode'];
		} else {
			$stderr = 'couldnt run';
			$return = 1;
		}
		self::getLogger()->info2('cmd:'.$cmd);
		self::getLogger()->debug('out:'.$stdout);
		$history = [
			'type' => 'command',
			'command' => $cmd,
			'output' => $stdout,
			'return' => $return
		];
		if ($stderr != '') {
			$history['error'] = $stderr;
			self::getLogger()->debug('error:'.$stderr);
		}
		/*
		$output = [];
		exec($cmd, $output, $return);
		self::getLogger()->indent();
		foreach ($output as $line)
			self::getLogger()->debug('out:'.$line);
		self::getLogger()->unIndent();
		self::getLogger()->debug('exit:'.$return);
		$response = implode("\n", $output);
		*/
		self::getLogger()->addHistory($history);
		return $stdout.$stderr;
	}
}
