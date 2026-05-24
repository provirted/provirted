<?php
namespace App\Os;

use App\Vps;

/**
* Host-level IP registry for VPS hosts.
*
* Manages two flat files under Vps::$base:
*   vps.mainips  -- one "{vzid}:{mainIp}" entry per line; the canonical record of
*                   each VPS's primary IP. Cron jobs (VpsInfoCommand) read this
*                   as a fallback when dhcpd.vps is not present.
*   vps.ipmap    -- one "{mainIp}:{addonIp}" entry per line; maps each
*                   additional IP back to its VPS via that VPS's main IP.
*                   BwInfoCommand uses it to attribute bandwidth on addon IPs.
*
* These files are intentionally simple text so external shell tooling can grep
* them; we re-read on every call rather than caching, to stay correct when
* other processes (e.g. cron jobs) rewrite them.
*/
class VpsIps
{
	/**
	* @return string absolute path to the vps.mainips file
	*/
	public static function getMainIpsFile() {
		return rtrim(Vps::$base, '/').'/vps.mainips';
	}

	/**
	* @return string absolute path to the vps.ipmap file
	*/
	public static function getIpMapFile() {
		return rtrim(Vps::$base, '/').'/vps.ipmap';
	}

	/**
	* @return array assoc array of vzid => mainIp
	*/
	public static function getMainIps() {
		$file = self::getMainIpsFile();
		if (!file_exists($file)) {
			return [];
		}
		$out = [];
		$data = @file_get_contents($file);
		if ($data === false) {
			Vps::getLogger()->error("Could not read {$file} (check permissions)");
			return [];
		}
		foreach (explode("\n", $data) as $line) {
			$line = trim($line);
			if ($line === '' || strpos($line, ':') === false) {
				continue;
			}
			list($vzid, $ip) = explode(':', $line, 2);
			$vzid = trim($vzid);
			$ip = trim($ip);
			if ($vzid !== '' && $ip !== '') {
				$out[$vzid] = $ip;
			}
		}
		return $out;
	}

	/**
	* @param string $vzid
	* @return string|null main IP for the given vzid, or null if not recorded
	*/
	public static function getMainIp($vzid) {
		$all = self::getMainIps();
		return isset($all[$vzid]) ? $all[$vzid] : null;
	}

	/**
	* Sets (or replaces) the main IP recorded for a vzid. If the vzid already
	* has a recorded main IP and it differs from $ip, any addon ipmap entries
	* keyed off the old main IP are re-keyed to the new main IP so we don't
	* orphan them.
	*
	* @param string $vzid
	* @param string $ip new main IP
	* @return bool true on success
	*/
	public static function setMainIp($vzid, $ip) {
		if (!preg_match('/^[A-Za-z0-9._-]+$/', $vzid)) {
			Vps::getLogger()->error("Invalid vzid '{$vzid}' for vps.mainips; refusing.");
			return false;
		}
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			Vps::getLogger()->error("Invalid IP '{$ip}' for vps.mainips; refusing.");
			return false;
		}
		$existing = self::getMainIps();
		$old = isset($existing[$vzid]) ? $existing[$vzid] : null;
		if ($old === $ip) {
			return true;
		}
		$existing[$vzid] = $ip;
		if (!self::writeMainIps($existing)) {
			return false;
		}
		if ($old !== null && $old !== $ip) {
			self::rekeyMainIp($old, $ip);
		}
		return true;
	}

	/**
	* Removes the vps.mainips entry for a vzid and clears any vps.ipmap entries
	* keyed off that VPS's main IP.
	*
	* @param string $vzid
	* @return bool true on success
	*/
	public static function removeMainIp($vzid) {
		$all = self::getMainIps();
		if (!isset($all[$vzid])) {
			return true;
		}
		$oldMain = $all[$vzid];
		unset($all[$vzid]);
		if (!self::writeMainIps($all)) {
			return false;
		}
		self::removeAllAddonsFor($oldMain);
		return true;
	}

	/**
	* @return array assoc array of mainIp => [addonIp, addonIp, ...]
	*/
	public static function getIpMap() {
		$file = self::getIpMapFile();
		if (!file_exists($file)) {
			return [];
		}
		$data = @file_get_contents($file);
		if ($data === false) {
			Vps::getLogger()->error("Could not read {$file} (check permissions)");
			return [];
		}
		$out = [];
		foreach (explode("\n", $data) as $line) {
			$line = trim($line);
			if ($line === '' || strpos($line, ':') === false) {
				continue;
			}
			list($mainIp, $addonIp) = explode(':', $line, 2);
			$mainIp = trim($mainIp);
			$addonIp = trim($addonIp);
			if ($mainIp === '' || $addonIp === '') {
				continue;
			}
			if (!isset($out[$mainIp])) {
				$out[$mainIp] = [];
			}
			if (!in_array($addonIp, $out[$mainIp], true)) {
				$out[$mainIp][] = $addonIp;
			}
		}
		return $out;
	}

	/**
	* @param string $mainIp
	* @return array list of addon IPs registered against this main IP
	*/
	public static function getAddonIps($mainIp) {
		$map = self::getIpMap();
		return isset($map[$mainIp]) ? $map[$mainIp] : [];
	}

	/**
	* Records an addon IP against a main IP. Idempotent.
	*
	* @param string $mainIp
	* @param string $addonIp
	* @return bool true on success
	*/
	public static function addAddonIp($mainIp, $addonIp) {
		if (!filter_var($mainIp, FILTER_VALIDATE_IP) || !filter_var($addonIp, FILTER_VALIDATE_IP)) {
			Vps::getLogger()->error("Invalid IP for vps.ipmap entry (main='{$mainIp}', addon='{$addonIp}'); refusing.");
			return false;
		}
		if ($mainIp === $addonIp) {
			return true;
		}
		$map = self::getIpMap();
		if (isset($map[$mainIp]) && in_array($addonIp, $map[$mainIp], true)) {
			return true;
		}
		if (!isset($map[$mainIp])) {
			$map[$mainIp] = [];
		}
		$map[$mainIp][] = $addonIp;
		return self::writeIpMap($map);
	}

	/**
	* Removes a specific addon entry. If $mainIp is null, removes the addon
	* regardless of which main IP it was keyed under.
	*
	* @param string|null $mainIp
	* @param string $addonIp
	* @return bool true on success
	*/
	public static function removeAddonIp($mainIp, $addonIp) {
		$map = self::getIpMap();
		$changed = false;
		foreach ($map as $key => $addons) {
			if ($mainIp !== null && $mainIp !== $key) {
				continue;
			}
			$filtered = array_values(array_filter($addons, function ($v) use ($addonIp) {
				return $v !== $addonIp;
			}));
			if (count($filtered) !== count($addons)) {
				$map[$key] = $filtered;
				$changed = true;
			}
		}
		if (!$changed) {
			return true;
		}
		return self::writeIpMap($map);
	}

	/**
	* Removes all addon entries keyed off the given main IP (used when the
	* VPS's main IP is being removed or replaced).
	*
	* @param string $mainIp
	* @return bool true on success
	*/
	public static function removeAllAddonsFor($mainIp) {
		$map = self::getIpMap();
		if (!isset($map[$mainIp])) {
			return true;
		}
		unset($map[$mainIp]);
		return self::writeIpMap($map);
	}

	/**
	* Re-keys all addon entries from $oldMainIp to $newMainIp without otherwise
	* touching them. Used when a VPS's main IP changes but its addon IPs stay.
	*
	* @param string $oldMainIp
	* @param string $newMainIp
	* @return bool true on success
	*/
	public static function rekeyMainIp($oldMainIp, $newMainIp) {
		if ($oldMainIp === $newMainIp) {
			return true;
		}
		$map = self::getIpMap();
		if (!isset($map[$oldMainIp])) {
			return true;
		}
		$addons = $map[$oldMainIp];
		unset($map[$oldMainIp]);
		if (!isset($map[$newMainIp])) {
			$map[$newMainIp] = [];
		}
		foreach ($addons as $addon) {
			if ($addon !== $newMainIp && !in_array($addon, $map[$newMainIp], true)) {
				$map[$newMainIp][] = $addon;
			}
		}
		return self::writeIpMap($map);
	}

	/**
	* @param array $mainIps assoc array vzid => ip
	* @return bool true on success
	*/
	private static function writeMainIps(array $mainIps) {
		ksort($mainIps);
		$lines = [];
		foreach ($mainIps as $vzid => $ip) {
			$lines[] = $vzid.':'.$ip;
		}
		return self::atomicWrite(self::getMainIpsFile(), implode("\n", $lines).(empty($lines) ? '' : "\n"));
	}

	/**
	* @param array $map assoc array mainIp => [addonIp, ...]
	* @return bool true on success
	*/
	private static function writeIpMap(array $map) {
		ksort($map);
		$lines = [];
		foreach ($map as $mainIp => $addons) {
			foreach ($addons as $addonIp) {
				$lines[] = $mainIp.':'.$addonIp;
			}
		}
		return self::atomicWrite(self::getIpMapFile(), implode("\n", $lines).(empty($lines) ? '' : "\n"));
	}

	/**
	* Writes $contents to $file via a tempfile + rename so concurrent readers
	* never see a half-written file.
	*
	* @param string $file
	* @param string $contents
	* @return bool true on success
	*/
	private static function atomicWrite($file, $contents) {
		$dir = dirname($file);
		if (!is_dir($dir)) {
			Vps::getLogger()->error("Directory does not exist: {$dir}");
			return false;
		}
		if (!is_writable($dir) && !(file_exists($file) && is_writable($file))) {
			Vps::getLogger()->error("Cannot write to {$file} (permissions)");
			return false;
		}
		$tmp = @tempnam($dir, '.provirted-ips-');
		if ($tmp === false) {
			Vps::getLogger()->error("Could not create temp file in {$dir}");
			return false;
		}
		if (@file_put_contents($tmp, $contents) === false) {
			Vps::getLogger()->error("Could not write temp file {$tmp}");
			@unlink($tmp);
			return false;
		}
		@chmod($tmp, 0644);
		if (!@rename($tmp, $file)) {
			Vps::getLogger()->error("Could not move {$tmp} into place at {$file}");
			@unlink($tmp);
			return false;
		}
		return true;
	}
}
