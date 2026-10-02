<?php

namespace XcVm\Core\GeoIP;

use XcVm\Core\Util\GeoIP;

/**
 * GeoIPService — GeoIP/ISP lookup и CIDR matching.
 *
 * Использует MaxMind GeoLite2 и GeoISP базы с файловым кэшированием.
 *
 * @package XC_VM_Core_GeoIP
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class GeoIPService {

	/**
	 * Получить GeoIP-информацию по IP-адресу (GeoLite2).
	 *
	 * Результат кэшируется в файл CONS_TMP_PATH/md5(ip)_geo2.
	 *
	 * @param string $rIP
	 * @return array|false
	 */
	public static function getIPInfo($rIP) {
		if (!empty($rIP)) {
			if (!file_exists(CONS_TMP_PATH . md5($rIP) . '_geo2')) {
				$rResponse = self::read(GEOLITE2_BIN, $rIP);
				if ($rResponse) {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_geo2', json_encode($rResponse));
				}
				return $rResponse;
			}
			return json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_geo2'), true);
		}
		return false;
	}

	/**
	 * Получить ISP-информацию по IP-адресу (GeoISP).
	 *
	 * Результат кэшируется в файл CONS_TMP_PATH/md5(ip)_isp.
	 *
	 * @param string $rIP
	 * @return array|false
	 */
	public static function getISP($rIP) {
		if (!empty($rIP)) {
			$rResponse = (file_exists(CONS_TMP_PATH . md5($rIP) . '_isp') ? json_decode(file_get_contents(CONS_TMP_PATH . md5($rIP) . '_isp'), true) : null);
			if (!is_array($rResponse)) {
				$rResponse = self::read(GEOISP_BIN, $rIP);
				if (is_array($rResponse)) {
					file_put_contents(CONS_TMP_PATH . md5($rIP) . '_isp', json_encode($rResponse));
				}
			}
			return $rResponse;
		}
		return false;
	}

	/**
	 * Read one IP out of a MaxMind database, tolerating an absent one.
	 *
	 * GeoIP is enrichment, not a dependency: the country code decorates logs
	 * and only gates playback for a line that pins a country. But
	 * `new Reader($path)` throws when the file is not there, and
	 * Public/stream/auth.php calls this on every playback request without a
	 * try/catch and with display_errors off -- so an absent database does not
	 * degrade playback, it silently kills it, while playlist downloads keep
	 * working because they never look up an IP. That is the shape of the
	 * outage seen in the field: the line authenticates, pulls its M3U, and
	 * nothing plays.
	 *
	 * The databases are downloaded post-install by `cron:maxmind`, which the
	 * installer itself calls and explicitly treats as non-fatal, so a fresh
	 * server can legitimately reach first playback with an empty bin/maxmind.
	 * GeoIP2-ISP is a paid database most installs never have at all, and
	 * `show_isps` reaches it on the same path.
	 *
	 * @param string $rPath Absolute path to the .mmdb.
	 * @param string $rIP   Address to look up.
	 * @return array|false Decoded record, or false when unavailable.
	 */
	private static function read($rPath, $rIP) {
		static $rWarned = [];

		if (!is_file($rPath)) {
			if (!isset($rWarned[$rPath])) {
				$rWarned[$rPath] = true;
				error_log('GeoIP database missing: ' . $rPath . ' -- run `console.php cron:maxmind --force`');
			}
			return false;
		}

		try {
			$rGeoIP = new \MaxMind\Db\Reader($rPath);
			$rResponse = $rGeoIP->get($rIP);
			$rGeoIP->close();
			return $rResponse;
		} catch (\Throwable $e) {
			if (!isset($rWarned[$rPath])) {
				$rWarned[$rPath] = true;
				error_log('GeoIP lookup failed on ' . $rPath . ': ' . $e->getMessage());
			}
			return false;
		}
	}

	/**
	 * Проверить IP на соответствие CIDR-блокам для ASN.
	 *
	 * @param string $rASN ASN identifier
	 * @param string $rIP IP address
	 * @return array|null Matching CIDR data or null
	 */
	public static function matchCIDR($rASN, $rIP) {
		if (file_exists(CIDR_TMP_PATH . $rASN)) {
			$rCIDRs = json_decode(file_get_contents(CIDR_TMP_PATH . $rASN), true);
			foreach ($rCIDRs as $rCIDR => $rData) {
				if (ip2long($rData[1]) <= ip2long($rIP) && ip2long($rIP) <= ip2long($rData[2])) {
					return $rData;
				}
			}
		}
		return null;
	}
}
