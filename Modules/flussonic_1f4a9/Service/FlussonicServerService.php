<?php

namespace XcVm\Module\Flussonic\Service;

use XcVm\Module\Flussonic\Contract\HttpTransportInterface;
use XcVm\Module\Flussonic\Exception\FlussonicApiException;
use XcVm\Module\Flussonic\Http\CurlTransport;

/**
 * FlussonicServerService — CRUD for the `flussonic_servers` table plus the
 * factory that turns a stored row into a live {@see FlussonicApiClient}.
 *
 * A "Flussonic server" here is an *external* origin the panel pulls from; it is
 * deliberately not a row in the core `servers` table (those are XC_VM streaming
 * nodes managed by the panel itself). The link between the two lives in
 * `flussonic_servers.target_server_id`, which decides where imported streams
 * are started.
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FlussonicServerService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** @var HttpTransportInterface|null Injected transport (null = real cURL). */
	private static ?HttpTransportInterface $transport = null;

	/** Columns accepted from the admin form, with their casting rule. */
	private const FIELDS = [
		'name'             => 'string',
		'api_scheme'       => 'scheme',
		'api_host'         => 'host',
		'api_port'         => 'port',
		'api_username'     => 'string',
		'api_password'     => 'string',
		'bearer_token'     => 'string',
		'verify_tls'       => 'bool',
		'play_scheme'      => 'scheme_optional',
		'play_host'        => 'host',
		'play_port'        => 'port_optional',
		'rtmp_port'        => 'port_optional',
		'rtsp_port'        => 'port_optional',
		'protocol'         => 'protocol',
		'play_token'       => 'string',
		'enabled'          => 'bool',
		'auto_import'      => 'bool',
		'auto_remove'      => 'bool',
		'direct_source'    => 'bool',
		'sync_interval'    => 'int',
		'target_server_id' => 'int',
		'category_id'      => 'int',
		'bouquets'         => 'json_ids',
		'stream_prefix'    => 'string',
	];

	/**
	 * All configured Flussonic servers, newest sync first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function getAll(): array {
		$db = self::db();
		$db->query('SELECT * FROM `flussonic_servers` ORDER BY `name` ASC, `id` ASC;');

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * All servers keyed by id (handy for view lookups).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function getAllKeyed(): array {
		$rReturn = [];

		foreach (self::getAll() as $rRow) {
			$rReturn[(int) $rRow['id']] = $rRow;
		}

		return $rReturn;
	}

	/**
	 * Servers eligible for an automatic sync run.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function getEnabled(): array {
		$db = self::db();
		$db->query('SELECT * FROM `flussonic_servers` WHERE `enabled` = 1 ORDER BY `id` ASC;');

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * @param int $rID Server id.
	 * @return array<string,mixed>|null
	 */
	public static function getById($rID): ?array {
		$db = self::db();
		$db->query('SELECT * FROM `flussonic_servers` WHERE `id` = ?;', (int) $rID);

		return $db->num_rows() === 1 ? $db->get_row() : null;
	}

	/**
	 * Create or update a server from submitted admin-form data.
	 *
	 * @param array<string,mixed> $rData Raw POST payload (`edit` = id when updating).
	 * @return array{status:bool,id:int,error:string}
	 */
	public static function save(array $rData): array {
		$db = self::db();
		$rEditID = isset($rData['edit']) ? (int) $rData['edit'] : 0;
		$rExisting = $rEditID > 0 ? self::getById($rEditID) : null;

		if ($rEditID > 0 && $rExisting === null) {
			return ['status' => false, 'id' => 0, 'error' => 'Server not found.'];
		}

		$rRow = [];

		foreach (self::FIELDS as $rColumn => $rCast) {
			$rRow[$rColumn] = self::castValue($rCast, $rData[$rColumn] ?? null, $rExisting[$rColumn] ?? null, array_key_exists($rColumn, $rData));
		}

		if ($rRow['api_host'] === '') {
			return ['status' => false, 'id' => 0, 'error' => 'The API host is required.'];
		}

		if ($rRow['name'] === '') {
			$rRow['name'] = $rRow['api_host'];
		}

		// Keep the stored password when the form posts an empty field (the edit
		// view never echoes secrets back into the HTML).
		if ($rExisting !== null && $rRow['api_password'] === '') {
			$rRow['api_password'] = (string) $rExisting['api_password'];
		}
		if ($rExisting !== null && $rRow['bearer_token'] === '') {
			$rRow['bearer_token'] = (string) $rExisting['bearer_token'];
		}

		if ($rRow['sync_interval'] < 60) {
			$rRow['sync_interval'] = 60;
		}

		// Reject a duplicate endpoint — two rows pointing at the same origin
		// would import every stream twice.
		if ($rEditID > 0) {
			$db->query('SELECT `id` FROM `flussonic_servers` WHERE `api_host` = ? AND `api_port` = ? AND `id` <> ? LIMIT 1;', $rRow['api_host'], $rRow['api_port'], $rEditID);
		} else {
			$db->query('SELECT `id` FROM `flussonic_servers` WHERE `api_host` = ? AND `api_port` = ? LIMIT 1;', $rRow['api_host'], $rRow['api_port']);
		}

		if ($db->num_rows() > 0) {
			return ['status' => false, 'id' => 0, 'error' => 'Another Flussonic server already uses this host and port.'];
		}

		if ($rEditID > 0) {
			$rSet = [];
			$rValues = [];

			foreach ($rRow as $rColumn => $rValue) {
				$rSet[] = '`' . $rColumn . '` = ?';
				$rValues[] = $rValue;
			}

			$rValues[] = $rEditID;

			if (!$db->query('UPDATE `flussonic_servers` SET ' . implode(', ', $rSet) . ' WHERE `id` = ?;', ...$rValues)) {
				return ['status' => false, 'id' => 0, 'error' => 'Could not update the server.'];
			}

			return ['status' => true, 'id' => $rEditID, 'error' => ''];
		}

		$rRow['added'] = time();
		$rColumns = array_keys($rRow);
		$rPlaceholders = implode(', ', array_fill(0, count($rColumns), '?'));
		$rQuery = 'INSERT INTO `flussonic_servers`(`' . implode('`, `', $rColumns) . '`) VALUES(' . $rPlaceholders . ');';

		if (!$db->query($rQuery, ...array_values($rRow))) {
			return ['status' => false, 'id' => 0, 'error' => 'Could not save the server.'];
		}

		return ['status' => true, 'id' => (int) $db->last_insert_id(), 'error' => ''];
	}

	/**
	 * Delete a server and forget the streams discovered through it.
	 *
	 * Streams already imported into the panel are left untouched on purpose —
	 * deleting an origin definition must never wipe live channels.
	 *
	 * @param int $rID Server id.
	 * @return bool
	 */
	public static function deleteById($rID): bool {
		$db = self::db();
		$rID = (int) $rID;

		if (self::getById($rID) === null) {
			return false;
		}

		$db->query('DELETE FROM `flussonic_streams` WHERE `server_id` = ?;', $rID);
		$db->query('DELETE FROM `flussonic_servers` WHERE `id` = ?;', $rID);

		return true;
	}

	/**
	 * Flip the enabled flag.
	 *
	 * @param int  $rID      Server id.
	 * @param bool $rEnabled Desired state.
	 * @return bool
	 */
	public static function setEnabled($rID, bool $rEnabled): bool {
		$db = self::db();

		return (bool) $db->query('UPDATE `flussonic_servers` SET `enabled` = ? WHERE `id` = ?;', $rEnabled ? 1 : 0, (int) $rID);
	}

	/**
	 * Persist the outcome of a sync/probe attempt.
	 *
	 * @param int    $rID      Server id.
	 * @param bool   $rOnline  Whether the API answered.
	 * @param string $rError   Error text (empty when healthy).
	 * @param int    $rStreams Number of streams seen.
	 * @param array  $rInfo    Server info payload to cache.
	 * @return void
	 */
	public static function recordStatus($rID, bool $rOnline, string $rError = '', int $rStreams = 0, array $rInfo = []): void {
		$db = self::db();
		$db->query(
			'UPDATE `flussonic_servers` SET `status` = ?, `last_error` = ?, `last_sync` = ?, `streams_found` = ?, `server_info` = ? WHERE `id` = ?;',
			$rOnline ? 1 : 0,
			mb_substr($rError, 0, 500),
			time(),
			$rStreams,
			$rInfo === [] ? null : json_encode($rInfo),
			(int) $rID
		);
	}

	/**
	 * Override the HTTP transport used by makeClient().
	 *
	 * Seam for tests and for the dev sandbox, which serves recorded Flussonic
	 * payloads instead of reaching the network. Pass null to restore cURL.
	 *
	 * @param HttpTransportInterface|null $rTransport Transport instance.
	 * @return void
	 */
	public static function setTransport(?HttpTransportInterface $rTransport): void {
		self::$transport = $rTransport;
	}

	/**
	 * Build an API client for a stored server row (or an unsaved form payload).
	 *
	 * @param array<string,mixed> $rServer Server row / form values.
	 * @return FlussonicApiClient
	 * @throws FlussonicApiException When the row has no usable base URL.
	 */
	public static function makeClient(array $rServer): FlussonicApiClient {
		return new FlussonicApiClient(
			self::$transport ?? new CurlTransport(),
			FlussonicUrlBuilder::apiBase($rServer),
			(string) ($rServer['api_username'] ?? ''),
			(string) ($rServer['api_password'] ?? ''),
			!empty($rServer['verify_tls']),
			5,
			20,
			(string) ($rServer['bearer_token'] ?? '')
		);
	}

	/**
	 * Probe a server (saved row or ad-hoc credentials from the add/edit form).
	 *
	 * @param array<string,mixed> $rServer Server row / form values.
	 * @return array{ok:bool,error:string,streams:int,version:string}
	 */
	public static function probe(array $rServer): array {
		try {
			$rClient = self::makeClient($rServer);
		} catch (FlussonicApiException $rException) {
			return ['ok' => false, 'error' => $rException->getMessage(), 'streams' => 0, 'version' => ''];
		}

		$rPing = $rClient->ping();

		if (!$rPing['ok']) {
			return ['ok' => false, 'error' => $rPing['error'], 'streams' => 0, 'version' => ''];
		}

		$rInfo = $rClient->getServerInfo();
		$rVersion = (string) ($rInfo['version'] ?? ($rInfo['streamer_version'] ?? ''));

		return ['ok' => true, 'error' => '', 'streams' => $rPing['streams'], 'version' => $rVersion];
	}

	/**
	 * Cast one submitted form value to its storage representation.
	 *
	 * @param string $rCast     Casting rule from self::FIELDS.
	 * @param mixed  $rValue    Submitted value.
	 * @param mixed  $rCurrent  Currently stored value (edit mode).
	 * @param bool   $rProvided Whether the key was present in the payload.
	 * @return mixed
	 */
	private static function castValue(string $rCast, $rValue, $rCurrent, bool $rProvided) {
		switch ($rCast) {
			case 'bool':
				// Checkboxes are absent from the POST body when unticked.
				return !empty($rValue) ? 1 : 0;

			case 'int':
				return $rProvided ? max(0, (int) $rValue) : (int) ($rCurrent ?? 0);

			case 'port':
				$rPort = $rProvided ? (int) $rValue : (int) ($rCurrent ?? 0);

				return ($rPort > 0 && $rPort <= 65535) ? $rPort : 80;

			case 'port_optional':
				$rPort = $rProvided ? (int) $rValue : (int) ($rCurrent ?? 0);

				return ($rPort > 0 && $rPort <= 65535) ? $rPort : 0;

			case 'scheme':
				$rScheme = strtolower(trim((string) ($rProvided ? $rValue : ($rCurrent ?? 'http'))));

				return in_array($rScheme, ['http', 'https'], true) ? $rScheme : 'http';

			case 'scheme_optional':
				$rScheme = strtolower(trim((string) ($rProvided ? $rValue : ($rCurrent ?? ''))));

				return in_array($rScheme, ['http', 'https'], true) ? $rScheme : '';

			case 'protocol':
				return FlussonicUrlBuilder::protocol($rProvided ? $rValue : ($rCurrent ?? 'hls'));

			case 'host':
				$rHost = trim((string) ($rProvided ? $rValue : ($rCurrent ?? '')));
				// Tolerate a pasted URL: keep only the host part.
				if (strpos($rHost, '://') !== false) {
					$rHost = (string) (parse_url($rHost, PHP_URL_HOST) ?: '');
				}

				return preg_replace('/[^A-Za-z0-9\.\-\:\[\]_]/', '', $rHost) ?? '';

			case 'json_ids':
				$rIDs = $rProvided ? $rValue : json_decode((string) ($rCurrent ?? '[]'), true);
				if (!is_array($rIDs)) {
					$rIDs = [];
				}
				$rIDs = array_values(array_unique(array_filter(array_map('intval', $rIDs), static fn($rItem) => $rItem > 0)));

				return json_encode($rIDs);

			default:
				return trim((string) ($rProvided ? $rValue : ($rCurrent ?? '')));
		}
	}
}
