<?php

namespace XcVm\Module\Flussonic\Dev\Support;

use XcVm\Module\Flussonic\Contract\HttpTransportInterface;
use XcVm\Module\Flussonic\Exception\FlussonicApiException;

/**
 * DevTransport — an in-memory Flussonic Media Server for the preview.
 *
 * It implements the module's real HTTP transport contract and answers the
 * exact v3 endpoints FlussonicApiClient calls, with payloads shaped like the
 * ones a live streamer returns (`/streamer/api/v3/streams` with cursor
 * pagination, `stats`, `inputs`, `dvr_info`, …). Nothing else about the module
 * is stubbed: the API client parses these responses for real.
 *
 * Two demo origins are provided; anything else answers 401/connection refused
 * so the error paths can be exercised too.
 *
 * @package XC_VM_Module_Flussonic_Dev
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DevTransport implements HttpTransportInterface {

	/** Demo credentials accepted by both sample origins. */
	public const DEMO_USER = 'admin';
	public const DEMO_PASSWORD = 'flussonic';

	/**
	 * Perform a fake HTTP request.
	 *
	 * @param string                $rMethod  HTTP verb.
	 * @param string                $rUrl     Absolute URL.
	 * @param array<string,string>  $rHeaders Request headers.
	 * @param string|null           $rBody    Request body.
	 * @param array<string,mixed>   $rOptions Transport options.
	 * @return array{status:int,body:string,headers:array<string,string>}
	 */
	public function request(string $rMethod, string $rUrl, array $rHeaders = [], ?string $rBody = null, array $rOptions = []): array {
		$rParts = parse_url($rUrl);
		$rHost = (string) ($rParts['host'] ?? '');
		$rPath = (string) ($rParts['path'] ?? '/');
		parse_str((string) ($rParts['query'] ?? ''), $rQuery);

		$rCatalogue = self::catalogue();

		if (!isset($rCatalogue[$rHost])) {
			// Mirrors CurlTransport, which throws when the connection itself
			// fails rather than returning a status code.
			throw new FlussonicApiException('Flussonic request failed: Could not resolve host: ' . $rHost);
		}

		if (!self::authorised($rHeaders)) {
			return self::json(401, ['error' => 'authorization_required']);
		}

		if (preg_match('#/(streamer|flussonic)/api/v3/streams/([^/]+)$#', $rPath, $rMatch)) {
			$rName = urldecode($rMatch[2]);

			foreach ($rCatalogue[$rHost]['streams'] as $rStream) {
				if ($rStream['name'] === $rName) {
					return self::json(200, $rStream);
				}
			}

			return self::json(404, ['error' => 'stream_not_found']);
		}

		if (preg_match('#/(streamer|flussonic)/api/v3/streams$#', $rPath)) {
			return self::json(200, self::page($rCatalogue[$rHost], $rQuery));
		}

		if (preg_match('#/(streamer|flussonic)/api/v3/sessions$#', $rPath)) {
			return self::json(200, ['sessions' => [], 'estimated_count' => 0]);
		}

		if (preg_match('#/(streamer|flussonic)/api/(v3/)?server$#', $rPath)) {
			return self::json(200, $rCatalogue[$rHost]['server']);
		}

		return self::json(404, ['error' => 'not_found']);
	}

	/**
	 * The demo origins, keyed by host.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function catalogue(): array {
		return [
			'demo.flussonic.local' => [
				'server'  => [
					'version'   => '24.10.1',
					'hostname'  => 'demo.flussonic.local',
					'uptime'    => 942130,
					'total_clients' => 1284,
				],
				'streams' => self::mainStreams(),
			],
			'edge.flussonic.local' => [
				'server'  => [
					'version'   => '23.11.4',
					'hostname'  => 'edge.flussonic.local',
					'uptime'    => 120400,
					'total_clients' => 311,
				],
				'streams' => self::edgeStreams(),
			],
		];
	}

	/**
	 * Build a cursor-paginated `/streams` page.
	 *
	 * @param array<string,mixed> $rServer Catalogue entry.
	 * @param array<string,mixed> $rQuery  Parsed query string.
	 * @return array<string,mixed>
	 */
	private static function page(array $rServer, array $rQuery): array {
		$rStreams = $rServer['streams'];
		$rSearch = trim((string) ($rQuery['q'] ?? ''));

		if ($rSearch !== '') {
			$rStreams = array_values(array_filter($rStreams, static function (array $rStream) use ($rSearch): bool {
				return stripos($rStream['name'], $rSearch) !== false
					|| stripos((string) ($rStream['title'] ?? ''), $rSearch) !== false;
			}));
		}

		$rTotal = count($rStreams);
		$rLimit = max(1, (int) ($rQuery['limit'] ?? 100));
		$rOffset = 0;

		if (!empty($rQuery['cursor'])) {
			$rOffset = max(0, (int) base64_decode((string) $rQuery['cursor'], true));
		}

		$rSlice = array_slice($rStreams, $rOffset, $rLimit);
		$rNext = ($rOffset + $rLimit) < $rTotal ? base64_encode((string) ($rOffset + $rLimit)) : null;

		return [
			'server_id'       => $rServer['server']['hostname'],
			'estimated_count' => $rTotal,
			'next'            => $rNext,
			'prev'            => $rOffset > 0 ? base64_encode((string) max(0, $rOffset - $rLimit)) : null,
			'streams'         => $rSlice,
		];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function mainStreams(): array {
		return [
			self::stream('cnn_hd', 'CNN HD', true, 4820, 312, '1920x1080', 'h264', 'aac', 604800, 'udp://239.10.0.11:5000'),
			self::stream('bbc_world_hd', 'BBC World News HD', true, 5120, 274, '1920x1080', 'h264', 'aac', 604800, 'udp://239.10.0.12:5000'),
			self::stream('discovery_uhd', 'Discovery Channel UHD', true, 18400, 96, '3840x2160', 'hevc', 'aac', 259200, 'udp://239.10.0.13:5000'),
			self::stream('eurosport_1', 'Eurosport 1', true, 6100, 501, '1920x1080', 'h264', 'ac3', 86400, 'udp://239.10.0.14:5000'),
			self::stream('natgeo_hd', 'National Geographic HD', true, 4400, 143, '1280x720', 'h264', 'aac', 0, 'udp://239.10.0.15:5000'),
			self::stream('mtv_live', 'MTV Live HD', false, 0, 0, '1920x1080', 'h264', 'aac', 0, 'udp://239.10.0.16:5000'),
			self::stream('history_hd', 'History HD', true, 3900, 88, '1280x720', 'h264', 'aac', 172800, 'udp://239.10.0.17:5000'),
			self::stream('cartoon_network', 'Cartoon Network', true, 2600, 219, '1024x576', 'h264', 'aac', 0, 'udp://239.10.0.18:5000'),
			self::stream('sky_news', 'Sky News', true, 3500, 167, '1280x720', 'h264', 'aac', 604800, 'udp://239.10.0.19:5000'),
			self::stream('animal_planet', 'Animal Planet HD', false, 0, 0, '1280x720', 'h264', 'aac', 0, 'udp://239.10.0.20:5000'),
			self::stream('espn_hd', 'ESPN HD', true, 7200, 640, '1920x1080', 'h264', 'ac3', 43200, 'udp://239.10.0.21:5000'),
			self::stream('tve_int', 'TVE Internacional', true, 2900, 74, '1024x576', 'h264', 'aac', 0, 'udp://239.10.0.22:5000'),
		];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function edgeStreams(): array {
		return [
			self::stream('local_news_1', 'Canal 2 Nicaragua', true, 3100, 58, '1280x720', 'h264', 'aac', 86400, 'rtmp://10.20.0.4/live/canal2'),
			self::stream('local_news_2', 'Canal 10 HD', true, 3600, 41, '1280x720', 'h264', 'aac', 86400, 'rtmp://10.20.0.4/live/canal10'),
			self::stream('radio_ya', 'Radio Ya (audio)', true, 128, 19, '', '', 'aac', 0, 'icecast://10.20.0.9:8000/radioya'),
			self::stream('cam_plaza', 'Plaza Cam 24/7', true, 1800, 7, '1920x1080', 'h264', '', 0, 'rtsp://10.20.0.30:554/cam1'),
			self::stream('event_backup', 'Event Backup Feed', false, 0, 0, '', '', '', 0, 'srt://10.20.0.44:9000'),
		];
	}

	/**
	 * Shape one stream the way the v3 API returns it.
	 *
	 * @param string $rName       Stream name.
	 * @param string $rTitle      Human title.
	 * @param bool   $rAlive      Whether it is publishing.
	 * @param int    $rBitrate    Bitrate in kbit/s.
	 * @param int    $rClients    Online clients.
	 * @param string $rResolution WxH, '' for audio-only.
	 * @param string $rVideo      Video codec.
	 * @param string $rAudio      Audio codec.
	 * @param int    $rDvrDepth   DVR depth in seconds (0 = no DVR).
	 * @param string $rInput      Input URL.
	 * @return array<string,mixed>
	 */
	private static function stream(string $rName, string $rTitle, bool $rAlive, int $rBitrate, int $rClients, string $rResolution, string $rVideo, string $rAudio, int $rDvrDepth, string $rInput): array {
		$rTracks = [];

		if ($rResolution !== '' && $rVideo !== '') {
			[$rWidth, $rHeight] = array_map('intval', explode('x', $rResolution));
			$rTracks[] = [
				'content' => 'video',
				'codec'   => $rVideo,
				'width'   => $rWidth,
				'height'  => $rHeight,
				'bitrate' => (int) round($rBitrate * 0.9),
			];
		}

		if ($rAudio !== '') {
			$rTracks[] = [
				'content'  => 'audio',
				'codec'    => $rAudio,
				'bitrate'  => min(320, max(64, (int) round($rBitrate * 0.1))),
				'language' => 'eng',
			];
		}

		return [
			'name'     => $rName,
			'title'    => $rTitle,
			'comment'  => '',
			'position' => 0,
			'stats'    => [
				'alive'            => $rAlive,
				'bitrate'          => $rBitrate,
				'online_clients'   => $rClients,
				'bytes_in'         => $rAlive ? $rBitrate * 125 * 3600 : 0,
				'bytes_out'        => $rAlive ? $rBitrate * 125 * 3600 * max(1, $rClients) : 0,
				'ts_delay'         => $rAlive ? 40 : 0,
				'input_error_rate' => 0,
				'retry_count'      => $rAlive ? 0 : 27,
				'dvr_enabled'      => $rDvrDepth > 0,
				'dvr_info'         => $rDvrDepth > 0 ? [
					'from'  => time() - $rDvrDepth,
					'depth' => $rDvrDepth,
				] : null,
				'media_info'       => ['tracks' => $rTracks],
			],
			'inputs'   => [
				['url' => $rInput, 'alive' => $rAlive],
			],
		];
	}

	/**
	 * Validate the Authorization header.
	 *
	 * Headers arrive the way cURL wants them — a list of "Name: value" strings
	 * — so they are parsed rather than looked up by key.
	 *
	 * @param array<int|string,string> $rHeaders Request headers.
	 * @return bool
	 */
	private static function authorised(array $rHeaders): bool {
		foreach ($rHeaders as $rKey => $rValue) {
			$rLine = is_int($rKey) ? (string) $rValue : $rKey . ': ' . $rValue;

			if (stripos($rLine, 'authorization:') !== 0) {
				continue;
			}

			$rCredentials = trim(substr($rLine, strlen('authorization:')));

			if (stripos($rCredentials, 'Bearer ') === 0) {
				return trim(substr($rCredentials, 7)) !== '';
			}

			if (stripos($rCredentials, 'Basic ') === 0) {
				$rDecoded = base64_decode(trim(substr($rCredentials, 6)), true);

				return $rDecoded === self::DEMO_USER . ':' . self::DEMO_PASSWORD;
			}
		}

		return false;
	}

	/**
	 * @param int                 $rStatus HTTP status.
	 * @param array<string,mixed> $rData   Payload.
	 * @return array{status:int,body:string,headers:array<string,string>}
	 */
	private static function json(int $rStatus, array $rData): array {
		return [
			'status'  => $rStatus,
			'body'    => (string) json_encode($rData),
			'headers' => ['Content-Type' => 'application/json'],
		];
	}

}
