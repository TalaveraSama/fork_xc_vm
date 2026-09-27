<?php

namespace XcVm\Module\Flussonic\Service;

use JsonException;
use XcVm\Module\Flussonic\Contract\HttpTransportInterface;
use XcVm\Module\Flussonic\Exception\FlussonicApiException;

/**
 * FlussonicApiClient — thin, dependency-free client for the Flussonic Media
 * Server HTTP API (v3, `/streamer/api/v3/...`).
 *
 * Only the read endpoints the panel needs are implemented:
 *   - streams_list   GET /streamer/api/v3/streams
 *   - stream_get     GET /streamer/api/v3/streams/{name}
 *   - sessions_list  GET /streamer/api/v3/sessions
 *   - server info    GET /streamer/api/v3/server (falls back to the legacy
 *                    /flussonic/api/server endpoint on older builds)
 *
 * Collections in v3 are cursor-paginated: the response carries the collection
 * under its own key (`streams`, `sessions`, …) plus `next` / `prev` cursors and
 * an `estimated_count`. listAllStreams() walks the cursor until the server stops
 * handing out a `next`, so panels with thousands of streams import completely.
 *
 * Authentication is HTTP Basic by default (the scheme Flussonic documents);
 * pass a bearer token instead when the server is behind an API gateway.
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class FlussonicApiClient {

	/** Default v3 API prefix exposed by Flussonic Media Server. */
	public const API_PREFIX = '/streamer/api/v3';

	/** Legacy (pre-v3) prefix, still present on long-lived installations. */
	public const LEGACY_PREFIX = '/flussonic/api';

	/** Hard ceiling Flussonic accepts for `limit`. */
	public const MAX_PAGE_SIZE = 500;

	private HttpTransportInterface $transport;
	private string $baseUrl;
	private string $username;
	private string $password;
	private string $bearer;
	private bool $verifyTls;
	private int $connectTimeout;
	private int $requestTimeout;

	/**
	 * @param HttpTransportInterface $transport      HTTP transport (cURL by default).
	 * @param string                 $baseUrl        Absolute API base, e.g. http://10.0.0.5:8080
	 * @param string                 $username       API user (Basic auth).
	 * @param string                 $password       API password (Basic auth).
	 * @param bool                   $verifyTls      Verify the TLS chain for https endpoints.
	 * @param int                    $connectTimeout Connect timeout, seconds.
	 * @param int                    $requestTimeout Total request timeout, seconds.
	 * @param string                 $bearer         Optional bearer token; wins over Basic auth.
	 */
	public function __construct(
		HttpTransportInterface $transport,
		string $baseUrl,
		string $username = '',
		string $password = '',
		bool $verifyTls = true,
		int $connectTimeout = 5,
		int $requestTimeout = 15,
		string $bearer = ''
	) {
		$this->transport = $transport;
		$this->baseUrl = self::normaliseBaseUrl($baseUrl);
		$this->username = $username;
		$this->password = $password;
		$this->bearer = trim($bearer);
		$this->verifyTls = $verifyTls;
		$this->connectTimeout = max(1, $connectTimeout);
		$this->requestTimeout = max(1, $requestTimeout);
	}

	/**
	 * Cheapest possible reachability + credentials probe.
	 *
	 * Asks for a single stream name; every Flussonic build that speaks v3
	 * answers it, so a 200 proves both connectivity and authentication.
	 *
	 * @return array{ok:bool,error:string,streams:int}
	 */
	public function ping(): array {
		try {
			$rResponse = $this->request('GET', self::API_PREFIX . '/streams', ['limit' => 1, 'select' => 'name']);
		} catch (FlussonicApiException $rException) {
			return ['ok' => false, 'error' => $rException->getMessage(), 'streams' => 0];
		}

		$rCount = $rResponse['estimated_count'] ?? null;
		if (!is_int($rCount)) {
			$rCount = count($rResponse['streams'] ?? []);
		}

		return ['ok' => true, 'error' => '', 'streams' => (int) $rCount];
	}

	/**
	 * Server identity/version. Never throws — an empty array simply means the
	 * endpoint is unavailable on this build, which must not break a sync.
	 *
	 * @return array<string,mixed>
	 */
	public function getServerInfo(): array {
		foreach ([self::API_PREFIX . '/server', self::LEGACY_PREFIX . '/server'] as $rPath) {
			try {
				$rData = $this->request('GET', $rPath);
				if ($rData !== []) {
					return $rData;
				}
			} catch (FlussonicApiException $rException) {
				continue;
			}
		}

		return [];
	}

	/**
	 * One page of the streams collection (raw API payload).
	 *
	 * @param int         $rLimit  Page size (1..500).
	 * @param string|null $rCursor Opaque cursor returned as `next` by the previous page.
	 * @param string      $rSelect Comma-separated field projection; empty = full objects.
	 * @param string      $rSearch Free-text search pattern (`q` parameter).
	 * @return array<string,mixed>
	 */
	public function listStreams(int $rLimit = 100, ?string $rCursor = null, string $rSelect = '', string $rSearch = ''): array {
		$rQuery = ['limit' => max(1, min(self::MAX_PAGE_SIZE, $rLimit))];

		if ($rCursor !== null && $rCursor !== '') {
			$rQuery['cursor'] = $rCursor;
		}
		if ($rSelect !== '') {
			$rQuery['select'] = $rSelect;
		}
		if ($rSearch !== '') {
			$rQuery['q'] = $rSearch;
		}

		return $this->request('GET', self::API_PREFIX . '/streams', $rQuery);
	}

	/**
	 * Every stream the server exposes, normalised for the panel.
	 *
	 * Walks the v3 cursor until exhaustion. `$rHardLimit` is a safety valve so a
	 * misbehaving server can never spin the sync forever.
	 *
	 * @param int $rHardLimit Maximum number of streams to collect.
	 * @param int $rPageSize  Rows per API call.
	 * @return array<int,array<string,mixed>> Normalised stream rows.
	 */
	public function listAllStreams(int $rHardLimit = 5000, int $rPageSize = 200): array {
		$rStreams = [];
		$rCursor = null;
		$rSeenCursors = [];

		do {
			$rPage = $this->listStreams($rPageSize, $rCursor);
			$rRows = $rPage['streams'] ?? [];

			if (!is_array($rRows)) {
				break;
			}

			foreach ($rRows as $rRow) {
				if (!is_array($rRow)) {
					continue;
				}
				$rStream = self::normaliseStream($rRow);
				if ($rStream['name'] === '') {
					continue;
				}
				$rStreams[$rStream['name']] = $rStream;
				if (count($rStreams) >= $rHardLimit) {
					return array_values($rStreams);
				}
			}

			$rCursor = $rPage['next'] ?? null;
			if (!is_string($rCursor) || $rCursor === '' || isset($rSeenCursors[$rCursor])) {
				break;
			}
			$rSeenCursors[$rCursor] = true;
		} while (count($rRows) > 0);

		return array_values($rStreams);
	}

	/**
	 * A single stream, normalised.
	 *
	 * @param string $rStreamName Flussonic stream name (may contain slashes).
	 * @return array<string,mixed>
	 */
	public function getStream(string $rStreamName): array {
		$rStreamName = trim($rStreamName);

		if ($rStreamName === '') {
			throw new FlussonicApiException('Stream name must not be empty.');
		}

		return self::normaliseStream($this->request('GET', self::API_PREFIX . '/streams/' . rawurlencode($rStreamName)));
	}

	/**
	 * Currently open playback sessions (viewers).
	 *
	 * @param int $rLimit Page size.
	 * @return array<int,array<string,mixed>>
	 */
	public function listSessions(int $rLimit = 100): array {
		$rResponse = $this->request('GET', self::API_PREFIX . '/sessions', ['limit' => max(1, min(self::MAX_PAGE_SIZE, $rLimit))]);
		$rSessions = $rResponse['sessions'] ?? [];

		return is_array($rSessions) ? $rSessions : [];
	}

	/**
	 * Flatten a raw v3 stream object into the shape the panel stores.
	 *
	 * Flussonic moved configuration under an `effective` sub-object in newer
	 * releases while older ones keep it at the top level — both layouts are
	 * accepted so the module works across versions without branching.
	 *
	 * @param array<string,mixed> $rRaw Raw API object.
	 * @return array<string,mixed>
	 */
	public static function normaliseStream(array $rRaw): array {
		$rEffective = is_array($rRaw['effective'] ?? null) ? $rRaw['effective'] : [];
		$rStats = is_array($rRaw['stats'] ?? null) ? $rRaw['stats'] : [];
		$rMediaInfo = is_array($rStats['media_info'] ?? null) ? $rStats['media_info'] : [];
		$rTracks = is_array($rMediaInfo['tracks'] ?? null) ? $rMediaInfo['tracks'] : [];

		$rVideoCodec = '';
		$rAudioCodec = '';
		$rWidth = 0;
		$rHeight = 0;

		foreach ($rTracks as $rTrack) {
			if (!is_array($rTrack)) {
				continue;
			}
			$rContent = (string) ($rTrack['content'] ?? '');
			if ($rContent === 'video' && $rVideoCodec === '') {
				$rVideoCodec = (string) ($rTrack['codec'] ?? '');
				$rWidth = (int) ($rTrack['width'] ?? 0);
				$rHeight = (int) ($rTrack['height'] ?? 0);
			} elseif ($rContent === 'audio' && $rAudioCodec === '') {
				$rAudioCodec = (string) ($rTrack['codec'] ?? '');
			}
		}

		$rInputs = $rRaw['inputs'] ?? ($rEffective['inputs'] ?? []);
		$rInputUrl = '';
		if (is_array($rInputs)) {
			foreach ($rInputs as $rInput) {
				if (is_array($rInput) && !empty($rInput['url'])) {
					$rInputUrl = (string) $rInput['url'];
					break;
				}
				if (is_string($rInput) && $rInput !== '') {
					$rInputUrl = $rInput;
					break;
				}
			}
		}

		$rDvrInfo = is_array($rStats['dvr_info'] ?? null) ? $rStats['dvr_info'] : [];
		$rDvrConfig = is_array($rRaw['dvr'] ?? null) ? $rRaw['dvr'] : (is_array($rEffective['dvr'] ?? null) ? $rEffective['dvr'] : []);
		$rDvrDepth = (int) ($rDvrInfo['depth'] ?? ($rDvrConfig['expiration'] ?? 0));

		$rTitle = (string) ($rRaw['title'] ?? ($rEffective['title'] ?? ''));
		$rName = (string) ($rRaw['name'] ?? ($rEffective['name'] ?? ''));

		return [
			'name'        => $rName,
			'title'       => $rTitle !== '' ? $rTitle : $rName,
			'comment'     => (string) ($rRaw['comment'] ?? ($rEffective['comment'] ?? '')),
			'alive'       => !empty($rStats['alive']),
			'bitrate'     => (int) ($rStats['bitrate'] ?? 0),
			'clients'     => (int) ($rStats['online_clients'] ?? 0),
			'input_url'   => $rInputUrl,
			'video_codec' => $rVideoCodec,
			'audio_codec' => $rAudioCodec,
			'width'       => $rWidth,
			'height'      => $rHeight,
			'resolution'  => ($rWidth > 0 && $rHeight > 0) ? ($rWidth . 'x' . $rHeight) : '',
			'dvr_depth'   => $rDvrDepth,
			'logo'        => (string) ($rRaw['logo'] ?? ($rEffective['logo']['path'] ?? '')),
			'static'      => !empty($rRaw['static'] ?? ($rEffective['static'] ?? false)),
			'position'    => (int) ($rRaw['position'] ?? ($rEffective['position'] ?? 0)),
		];
	}

	/**
	 * Perform a JSON request and decode the response.
	 *
	 * @param string               $rMethod HTTP verb.
	 * @param string               $rPath   Absolute path on the Flussonic host.
	 * @param array<string,mixed>  $rQuery  Query-string parameters.
	 * @return array<string,mixed>
	 * @throws FlussonicApiException On transport, HTTP or decoding failure.
	 */
	private function request(string $rMethod, string $rPath, array $rQuery = []): array {
		$rUrl = $this->baseUrl . $rPath;

		if ($rQuery !== []) {
			$rUrl .= '?' . http_build_query($rQuery, '', '&', PHP_QUERY_RFC3986);
		}

		$rHeaders = ['Accept: application/json'];

		if ($this->bearer !== '') {
			$rHeaders[] = 'Authorization: Bearer ' . $this->bearer;
		} elseif ($this->username !== '' || $this->password !== '') {
			$rHeaders[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password);
		}

		$rResponse = $this->transport->request($rMethod, $rUrl, $rHeaders, null, [
			'verify_tls'      => $this->verifyTls,
			'connect_timeout' => $this->connectTimeout,
			'timeout'         => $this->requestTimeout,
		]);

		if ($rResponse['status'] === 401 || $rResponse['status'] === 403) {
			throw new FlussonicApiException('Flussonic rejected the credentials (HTTP ' . $rResponse['status'] . ').', $rResponse['status']);
		}

		if ($rResponse['status'] === 404) {
			throw new FlussonicApiException('Flussonic endpoint not found (HTTP 404) — check the API path and server version.', 404);
		}

		if ($rResponse['status'] < 200 || $rResponse['status'] >= 300) {
			throw new FlussonicApiException('Flussonic API returned HTTP ' . $rResponse['status'] . '.', $rResponse['status']);
		}

		$rBody = trim((string) $rResponse['body']);

		if ($rBody === '') {
			return [];
		}

		try {
			$rData = json_decode($rBody, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $rException) {
			throw new FlussonicApiException('Flussonic API returned invalid JSON.');
		}

		if (!is_array($rData)) {
			throw new FlussonicApiException('Flussonic API returned an unexpected response.');
		}

		return $rData;
	}

	/**
	 * Validate and canonicalise the API base URL.
	 *
	 * Credentials/query/fragment are rejected on purpose: they would leak into
	 * logs and break the Basic-auth header the client builds itself.
	 *
	 * @param string $rBaseUrl Raw base URL.
	 * @return string Canonical scheme://host[:port] form.
	 * @throws FlussonicApiException When the URL is not a usable HTTP(S) base.
	 */
	private static function normaliseBaseUrl(string $rBaseUrl): string {
		$rBaseUrl = rtrim(trim($rBaseUrl), '/');
		$rParts = parse_url($rBaseUrl);

		if ($rBaseUrl === '' || !is_array($rParts) || !isset($rParts['scheme'], $rParts['host']) || !in_array(strtolower($rParts['scheme']), ['http', 'https'], true)) {
			throw new FlussonicApiException('Flussonic base URL must be an absolute HTTP or HTTPS URL.');
		}

		if (isset($rParts['user']) || isset($rParts['pass']) || isset($rParts['query']) || isset($rParts['fragment'])) {
			throw new FlussonicApiException('Flussonic base URL must not contain credentials, query parameters, or fragments.');
		}

		return $rBaseUrl;
	}
}
