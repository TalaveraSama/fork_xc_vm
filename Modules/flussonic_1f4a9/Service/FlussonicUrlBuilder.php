<?php

namespace XcVm\Module\Flussonic\Service;

/**
 * FlussonicUrlBuilder — turns a `flussonic_servers` row plus a stream name into
 * the playback URL the panel will restream from.
 *
 * Flussonic publishes the same stream over several protocols at well-known
 * paths (see the "Playback" chapter of the Flussonic manual):
 *
 *   HLS       http://HOST:PORT/NAME/index.m3u8
 *   LL-HLS    http://HOST:PORT/NAME/index.ll.m3u8
 *   MPEG-TS   http://HOST:PORT/NAME/mpegts
 *   DASH      http://HOST:PORT/NAME/index.mpd
 *   RTMP      rtmp://HOST:1935/static/NAME
 *   RTSP      rtsp://HOST:554/NAME
 *
 * The playback host may differ from the API host (API on an internal address,
 * delivery through a CDN/edge name), so every playback field falls back to its
 * API counterpart when left empty.
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class FlussonicUrlBuilder {

	/** Protocol key => human label, used by the UI dropdowns. */
	public const PROTOCOLS = [
		'hls'    => 'HLS  —  /NAME/index.m3u8',
		'hlsll'  => 'LL-HLS  —  /NAME/index.ll.m3u8',
		'mpegts' => 'MPEG-TS  —  /NAME/mpegts',
		'dash'   => 'MPEG-DASH  —  /NAME/index.mpd',
		'rtmp'   => 'RTMP  —  rtmp://HOST/static/NAME',
		'rtsp'   => 'RTSP  —  rtsp://HOST/NAME',
	];

	/**
	 * Absolute API base URL (scheme://host[:port]) for a server row.
	 *
	 * @param array<string,mixed> $rServer Row from `flussonic_servers`.
	 * @return string
	 */
	public static function apiBase(array $rServer): string {
		$rScheme = self::scheme($rServer['api_scheme'] ?? 'http');
		$rHost = trim((string) ($rServer['api_host'] ?? ''));
		$rPort = (int) ($rServer['api_port'] ?? 0);

		return $rScheme . '://' . $rHost . self::portSuffix($rScheme, $rPort);
	}

	/**
	 * Absolute playback base URL (scheme://host[:port]) for a server row.
	 *
	 * @param array<string,mixed> $rServer Row from `flussonic_servers`.
	 * @return string
	 */
	public static function playbackBase(array $rServer): string {
		$rScheme = self::scheme($rServer['play_scheme'] ?? '') ?: self::scheme($rServer['api_scheme'] ?? 'http');
		$rHost = trim((string) ($rServer['play_host'] ?? ''));

		if ($rHost === '') {
			$rHost = trim((string) ($rServer['api_host'] ?? ''));
		}

		$rPort = (int) ($rServer['play_port'] ?? 0);
		if ($rPort <= 0) {
			$rPort = (int) ($rServer['api_port'] ?? 0);
		}

		return $rScheme . '://' . $rHost . self::portSuffix($rScheme, $rPort);
	}

	/**
	 * Build the playback URL for one stream.
	 *
	 * @param array<string,mixed> $rServer   Row from `flussonic_servers`.
	 * @param string              $rName     Flussonic stream name.
	 * @param string|null         $rProtocol Override the server's default protocol.
	 * @return string Empty string when the server row has no usable host.
	 */
	public static function build(array $rServer, string $rName, ?string $rProtocol = null): string {
		$rName = trim($rName, '/');

		if ($rName === '') {
			return '';
		}

		$rProtocol = self::protocol($rProtocol ?? ($rServer['protocol'] ?? 'hls'));
		$rPath = self::encodeName($rName);
		$rToken = trim((string) ($rServer['play_token'] ?? ''));

		if ($rProtocol === 'rtmp' || $rProtocol === 'rtsp') {
			$rHost = trim((string) ($rServer['play_host'] ?? '')) ?: trim((string) ($rServer['api_host'] ?? ''));

			if ($rHost === '') {
				return '';
			}

			if ($rProtocol === 'rtmp') {
				$rPort = (int) ($rServer['rtmp_port'] ?? 1935);
				$rUrl = 'rtmp://' . $rHost . ($rPort > 0 && $rPort !== 1935 ? ':' . $rPort : '') . '/static/' . $rPath;
			} else {
				$rPort = (int) ($rServer['rtsp_port'] ?? 554);
				$rUrl = 'rtsp://' . $rHost . ($rPort > 0 && $rPort !== 554 ? ':' . $rPort : '') . '/' . $rPath;
			}

			return self::withToken($rUrl, $rToken);
		}

		$rBase = self::playbackBase($rServer);

		if ($rBase === '' || strpos($rBase, '://') === false || substr($rBase, -3) === '://') {
			return '';
		}

		$rSuffix = [
			'hls'    => '/index.m3u8',
			'hlsll'  => '/index.ll.m3u8',
			'mpegts' => '/mpegts',
			'dash'   => '/index.mpd',
		][$rProtocol] ?? '/index.m3u8';

		return self::withToken($rBase . '/' . $rPath . $rSuffix, $rToken);
	}

	/**
	 * Direct link to Flussonic's own preview page for a stream.
	 *
	 * @param array<string,mixed> $rServer Row from `flussonic_servers`.
	 * @param string              $rName   Flussonic stream name.
	 * @return string
	 */
	public static function embedUrl(array $rServer, string $rName): string {
		$rBase = self::playbackBase($rServer);
		$rName = trim($rName, '/');

		if ($rBase === '' || $rName === '') {
			return '';
		}

		return self::withToken($rBase . '/' . self::encodeName($rName) . '/embed.html', trim((string) ($rServer['play_token'] ?? '')));
	}

	/**
	 * Normalise a protocol key to one the builder understands.
	 *
	 * @param mixed $rProtocol Raw value.
	 * @return string
	 */
	public static function protocol($rProtocol): string {
		$rProtocol = strtolower(trim((string) $rProtocol));

		return isset(self::PROTOCOLS[$rProtocol]) ? $rProtocol : 'hls';
	}

	/**
	 * Append the static playback token, if the server defines one.
	 *
	 * @param string $rUrl   URL without token.
	 * @param string $rToken Token value.
	 * @return string
	 */
	private static function withToken(string $rUrl, string $rToken): string {
		if ($rToken === '') {
			return $rUrl;
		}

		return $rUrl . (strpos($rUrl, '?') === false ? '?' : '&') . 'token=' . rawurlencode($rToken);
	}

	/**
	 * Percent-encode a stream name while keeping its path separators intact
	 * (Flussonic allows names such as `sports/football`).
	 *
	 * @param string $rName Stream name.
	 * @return string
	 */
	private static function encodeName(string $rName): string {
		$rParts = array_map('rawurlencode', explode('/', $rName));

		return implode('/', $rParts);
	}

	/**
	 * @param mixed $rScheme Raw scheme value.
	 * @return string 'http', 'https' or '' when unset.
	 */
	private static function scheme($rScheme): string {
		$rScheme = strtolower(trim((string) $rScheme));

		return in_array($rScheme, ['http', 'https'], true) ? $rScheme : '';
	}

	/**
	 * @param string $rScheme Normalised scheme.
	 * @param int    $rPort   Port number (0 = default).
	 * @return string ':port' or '' when the port is the scheme default.
	 */
	private static function portSuffix(string $rScheme, int $rPort): string {
		if ($rPort <= 0) {
			return '';
		}
		if (($rScheme === 'http' && $rPort === 80) || ($rScheme === 'https' && $rPort === 443)) {
			return '';
		}

		return ':' . $rPort;
	}
}
