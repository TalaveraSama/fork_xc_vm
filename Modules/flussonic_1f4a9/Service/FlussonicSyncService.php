<?php

namespace XcVm\Module\Flussonic\Service;

use XcVm\Core\Http\ApiClient;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Module\Flussonic\Exception\FlussonicApiException;

/**
 * FlussonicSyncService — discovery + import pipeline.
 *
 * Two distinct operations, deliberately kept apart so an operator can look
 * before they leap:
 *
 *   1. sync()   — ask each enabled Flussonic server for its stream list and
 *                 refresh the `flussonic_streams` catalogue. Read-only as far
 *                 as the panel's own content is concerned.
 *   2. import() — turn selected catalogue rows into real panel channels
 *                 (`streams` + `streams_servers`), optionally filing them into
 *                 a category and bouquets.
 *
 * Servers flagged `auto_import` run step 2 automatically at the end of step 1,
 * which is what makes "every stream that appears on the origin shows up in the
 * panel" work unattended.
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FlussonicSyncService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Sync every enabled server whose interval has elapsed.
	 *
	 * @param bool $rForce Ignore the per-server interval.
	 * @return array<int,array<string,mixed>> Per-server results keyed by server id.
	 */
	public static function syncAll(bool $rForce = false): array {
		$rResults = [];
		$rNow = time();

		foreach (FlussonicServerService::getEnabled() as $rServer) {
			$rInterval = max(60, (int) $rServer['sync_interval']);

			if (!$rForce && (int) $rServer['last_sync'] > 0 && ($rNow - (int) $rServer['last_sync']) < $rInterval) {
				continue;
			}

			$rResults[(int) $rServer['id']] = self::syncServer($rServer);
		}

		FlussonicStreamService::clearOrphanLinks();

		return $rResults;
	}

	/**
	 * Refresh the catalogue for a single server.
	 *
	 * @param array<string,mixed>|int $rServer Server row or id.
	 * @return array{ok:bool,error:string,found:int,new:int,removed:int,imported:int,server:string}
	 */
	public static function syncServer($rServer): array {
		if (!is_array($rServer)) {
			$rServer = FlussonicServerService::getById($rServer);
		}

		$rResult = ['ok' => false, 'error' => '', 'found' => 0, 'new' => 0, 'removed' => 0, 'imported' => 0, 'server' => ''];

		if (!is_array($rServer)) {
			$rResult['error'] = 'Flussonic server not found.';

			return $rResult;
		}

		$rResult['server'] = (string) $rServer['name'];

		try {
			$rClient = FlussonicServerService::makeClient($rServer);
			$rStreams = $rClient->listAllStreams();
		} catch (FlussonicApiException $rException) {
			FlussonicServerService::recordStatus((int) $rServer['id'], false, $rException->getMessage());
			$rResult['error'] = $rException->getMessage();

			return $rResult;
		} catch (\Throwable $rThrowable) {
			FlussonicServerService::recordStatus((int) $rServer['id'], false, $rThrowable->getMessage());
			$rResult['error'] = $rThrowable->getMessage();

			return $rResult;
		}

		$rSeen = [];

		foreach ($rStreams as $rStream) {
			$rPlayUrl = FlussonicUrlBuilder::build($rServer, (string) $rStream['name']);

			if (FlussonicStreamService::upsert((int) $rServer['id'], $rStream, $rPlayUrl)) {
				$rResult['new']++;
			}

			$rSeen[] = (string) $rStream['name'];
		}

		if (!empty($rServer['auto_remove'])) {
			$rResult['removed'] = FlussonicStreamService::pruneMissing((int) $rServer['id'], $rSeen);
		}

		$rResult['found'] = count($rSeen);
		$rResult['ok'] = true;

		FlussonicServerService::recordStatus((int) $rServer['id'], true, '', $rResult['found'], $rClient->getServerInfo());

		if (!empty($rServer['auto_import'])) {
			$rImport = self::importStreams($rServer, [], true);
			$rResult['imported'] = $rImport['imported'];
		}

		return $rResult;
	}

	/**
	 * Import catalogue rows into the panel as live channels.
	 *
	 * @param array<string,mixed>|int $rServer       Server row or id.
	 * @param int[]                   $rCatalogueIDs `flussonic_streams` ids to import.
	 * @param bool                    $rAllPending   Import every not-yet-imported row instead.
	 * @return array{imported:int,skipped:int,errors:string[],stream_ids:int[]}
	 */
	public static function importStreams($rServer, array $rCatalogueIDs = [], bool $rAllPending = false): array {
		if (!is_array($rServer)) {
			$rServer = FlussonicServerService::getById($rServer);
		}

		$rResult = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'stream_ids' => []];

		if (!is_array($rServer)) {
			$rResult['errors'][] = 'Flussonic server not found.';

			return $rResult;
		}

		if ($rAllPending) {
			$rRows = FlussonicStreamService::getAll(['server_id' => (int) $rServer['id'], 'status' => 'new']);
		} else {
			$rRows = FlussonicStreamService::getByIds($rCatalogueIDs);
		}

		if ($rRows === []) {
			return $rResult;
		}

		$rCategories = self::categoryList($rServer);
		$rBouquets = self::bouquetList($rServer);
		$rTargetServers = self::targetServerList($rServer);
		$rOrder = StreamRepository::getNextOrder();

		foreach ($rRows as $rRow) {
			if ((int) $rRow['server_id'] !== (int) $rServer['id']) {
				$rResult['skipped']++;
				continue;
			}

			if ((int) $rRow['stream_id'] > 0 && self::panelStreamExists((int) $rRow['stream_id'])) {
				$rResult['skipped']++;
				continue;
			}

			$rSource = (string) $rRow['play_url'];

			if ($rSource === '') {
				$rSource = FlussonicUrlBuilder::build($rServer, (string) $rRow['name']);
			}

			if ($rSource === '') {
				$rResult['errors'][] = 'No playback URL could be built for "' . $rRow['name'] . '".';
				$rResult['skipped']++;
				continue;
			}

			$rExistingID = self::findStreamBySource($rSource);

			if ($rExistingID > 0) {
				// The channel already lives in the panel (imported manually or
				// by an earlier run that lost its link) — adopt it rather than
				// creating a duplicate.
				FlussonicStreamService::linkPanelStream((int) $rRow['id'], $rExistingID);
				$rResult['skipped']++;
				continue;
			}

			$rStreamID = self::createPanelStream($rServer, $rRow, $rSource, $rCategories, $rOrder);

			if ($rStreamID <= 0) {
				$rResult['errors'][] = 'Could not create a panel stream for "' . $rRow['name'] . '".';
				continue;
			}

			$rOrder++;
			self::attachServers($rStreamID, $rTargetServers);
			FlussonicStreamService::linkPanelStream((int) $rRow['id'], $rStreamID);

			$rResult['stream_ids'][] = $rStreamID;
			$rResult['imported']++;
		}

		if ($rResult['stream_ids'] !== []) {
			foreach ($rBouquets as $rBouquetID) {
				BouquetService::addItems('stream', $rBouquetID, $rResult['stream_ids']);
			}

			StreamProcess::updateStreams($rResult['stream_ids']);
			self::startPanelStreams($rResult['stream_ids'], !empty($rServer['direct_source']));
		}

		return $rResult;
	}

	/**
	 * Refresh the source URL of already-imported channels after the server's
	 * playback settings (host/protocol/token) changed.
	 *
	 * @param array<string,mixed>|int $rServer Server row or id.
	 * @return int Number of panel streams rewritten.
	 */
	public static function refreshImportedSources($rServer): int {
		if (!is_array($rServer)) {
			$rServer = FlussonicServerService::getById($rServer);
		}

		if (!is_array($rServer)) {
			return 0;
		}

		$db = self::db();
		$rUpdated = [];

		foreach (FlussonicStreamService::getAll(['server_id' => (int) $rServer['id'], 'status' => 'imported']) as $rRow) {
			$rSource = FlussonicUrlBuilder::build($rServer, (string) $rRow['name']);

			if ($rSource === '' || (int) $rRow['stream_id'] <= 0) {
				continue;
			}

			$db->query('UPDATE `streams` SET `stream_source` = ? WHERE `id` = ?;', json_encode([$rSource]), (int) $rRow['stream_id']);
			$db->query('UPDATE `flussonic_streams` SET `play_url` = ? WHERE `id` = ?;', mb_substr($rSource, 0, 1000), (int) $rRow['id']);
			$rUpdated[] = (int) $rRow['stream_id'];
		}

		if ($rUpdated !== []) {
			StreamProcess::updateStreams($rUpdated);
			// The source URL changed underneath a process that is already
			// running: it keeps pulling the old URL until it is restarted, so
			// "refresh sources" would appear to do nothing.
			self::startPanelStreams($rUpdated, !empty($rServer['direct_source']));
		}

		return count($rUpdated);
	}

	/**
	 * Start the panel channels this module just created or repointed.
	 *
	 * The import used to end at StreamProcess::updateStreams(), which only
	 * pushes the new configuration into the cache: it refreshes streams that
	 * are *already* running, and returns immediately without doing anything
	 * when `enable_cache` is off. Nothing in the import path ever started the
	 * process, so a freshly imported channel sat idle in the list until an
	 * operator opened it and pressed Restart by hand.
	 *
	 * This issues the same call the panel's own Start/Restart button makes
	 * (see the mass-action handler in Public/Views/admin/api.php): one request
	 * per server, which Public/admin/api.php fans out to that server's internal
	 * API as `function=start`, landing in StreamProcess::startMonitor().
	 * Grouping by server matters -- omitting `servers` makes the panel
	 * broadcast the start to every server it knows, including ones that were
	 * never given a `streams_servers` row for these channels.
	 *
	 * @param int[] $rStreamIDs    Panel stream ids to start.
	 * @param bool  $rDirectSource Server hands the origin URL straight to clients.
	 * @return void
	 */
	private static function startPanelStreams(array $rStreamIDs, bool $rDirectSource): void {
		// A direct_source channel is served to the client as the Flussonic URL
		// itself, so there is no local ffmpeg to start. StreamProcess::startStream()
		// selects `WHERE direct_source = 0` and would silently match nothing.
		if ($rDirectSource || $rStreamIDs === []) {
			return;
		}

		// Group by the servers actually recorded in `streams_servers` rather
		// than by the server list the import was told to use. They usually
		// agree, but not when attachServers() skipped a row, when the operator
		// moved the channel afterwards, or when `target_server_id` still names
		// a server that has since been deleted -- and Public/admin/api.php
		// indexes $rAllServers by that id without checking it exists.
		$rMap = [];
		$db = self::db();
		$db->query(
			'SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' .
				implode(',', array_map('intval', $rStreamIDs)) . ');'
		);

		foreach ($db->get_rows() as $rRow) {
			$rMap[(int) $rRow['server_id']][] = (int) $rRow['stream_id'];
		}

		foreach ($rMap as $rServerID => $rIDs) {
			// The receiving end sleeps 50 ms between streams, so a bulk import
			// comfortably outruns ApiClient's 5 s default and the batch would be
			// cut off part way through.
			ApiClient::request([
				'action'     => 'stream',
				'sub'        => 'start',
				'stream_ids' => $rIDs,
				'servers'    => [$rServerID],
			], min(120, max(15, (int) ceil(count($rIDs) * 0.25))));
		}
	}

	/**
	 * Insert the `streams` row for one discovered Flussonic stream.
	 *
	 * @param array<string,mixed> $rServer     Server row.
	 * @param array<string,mixed> $rRow        Catalogue row.
	 * @param string              $rSource     Playback URL to restream.
	 * @param int[]               $rCategories Category ids.
	 * @param int                 $rOrder      Channel order value.
	 * @return int New stream id, or 0 on failure.
	 */
	private static function createPanelStream(array $rServer, array $rRow, string $rSource, array $rCategories, int $rOrder): int {
		$db = self::db();
		$rPrefix = trim((string) ($rServer['stream_prefix'] ?? ''));
		$rTitle = trim((string) $rRow['title']) !== '' ? (string) $rRow['title'] : (string) $rRow['name'];
		$rDisplayName = $rPrefix !== '' ? $rPrefix . ' ' . $rTitle : $rTitle;

		$rOk = $db->query(
			'INSERT INTO `streams`(`type`, `category_id`, `stream_display_name`, `stream_source`, `stream_icon`, `notes`, `added`, `order`, `direct_source`, `read_native`, `gen_timestamps`, `transcode_profile_id`, `tv_archive_duration`) VALUES(1, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 0, ?);',
			'[' . implode(',', $rCategories) . ']',
			mb_substr($rDisplayName, 0, 250),
			json_encode([$rSource]),
			mb_substr((string) $rRow['logo'], 0, 1000),
			'Flussonic: ' . $rServer['name'] . ' / ' . $rRow['name'],
			time(),
			$rOrder,
			!empty($rServer['direct_source']) ? 1 : 0,
			self::archiveDays($rRow)
		);

		if (!$rOk) {
			return 0;
		}

		return (int) $db->last_insert_id();
	}

	/**
	 * Bind a freshly created stream to the XC_VM streaming servers that should
	 * run it.
	 *
	 * @param int   $rStreamID  Panel stream id.
	 * @param int[] $rServerIDs XC_VM `servers` ids.
	 * @return void
	 */
	private static function attachServers(int $rStreamID, array $rServerIDs): void {
		$db = self::db();

		foreach ($rServerIDs as $rServerID) {
			$db->query('INSERT IGNORE INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES(?, ?, 0, 0);', $rStreamID, (int) $rServerID);
		}
	}

	/**
	 * Locate an existing panel stream that already restreams this URL.
	 *
	 * @param string $rSource Playback URL.
	 * @return int Stream id or 0.
	 */
	private static function findStreamBySource(string $rSource): int {
		$db = self::db();

		// `streams`.`stream_source` holds a JSON array written with json_encode(),
		// which escapes forward slashes, so the stored row reads
		// ["http:\/\/host\/name\/index.m3u8"]. Matching the raw URL against that
		// could never hit -- every slash differed -- so this returned 0 for
		// everything, and the "adopt the channel already in the panel" branch in
		// importStreams() was unreachable: a stream the operator had created by
		// hand, or one whose link had been lost, got imported again as a
		// duplicate. Encode the needle the same way the value was written.
		//
		// The surrounding quotes matter for a second reason. The RTMP and RTSP
		// forms carry no suffix after the stream name, so
		// rtmp://h/static/bandamax is a prefix of rtmp://h/static/bandamax_hd;
		// anchoring on the closing quote keeps one channel from adopting
		// another channel's panel stream.
		$rEncoded = json_encode($rSource);

		if (!is_string($rEncoded)) {
			return 0;
		}

		$rNeedle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $rEncoded) . '%';
		$db->query('SELECT `id` FROM `streams` WHERE `type` = 1 AND `stream_source` LIKE ? LIMIT 1;', $rNeedle);

		return $db->num_rows() > 0 ? (int) $db->get_row()['id'] : 0;
	}

	/**
	 * @param int $rStreamID Panel stream id.
	 * @return bool
	 */
	private static function panelStreamExists(int $rStreamID): bool {
		$db = self::db();
		$db->query('SELECT `id` FROM `streams` WHERE `id` = ? LIMIT 1;', $rStreamID);

		return $db->num_rows() > 0;
	}

	/**
	 * DVR depth (seconds) reported by Flussonic → whole days of panel archive.
	 *
	 * @param array<string,mixed> $rRow Catalogue row.
	 * @return int
	 */
	private static function archiveDays(array $rRow): int {
		$rDepth = (int) ($rRow['dvr_depth'] ?? 0);

		return $rDepth > 0 ? max(1, (int) floor($rDepth / 86400)) : 0;
	}

	/**
	 * @param array<string,mixed> $rServer Server row.
	 * @return int[] Category ids to file imported channels under.
	 */
	private static function categoryList(array $rServer): array {
		$rCategoryID = (int) ($rServer['category_id'] ?? 0);

		return $rCategoryID > 0 ? [$rCategoryID] : [];
	}

	/**
	 * @param array<string,mixed> $rServer Server row.
	 * @return int[] Bouquet ids.
	 */
	private static function bouquetList(array $rServer): array {
		$rBouquets = json_decode((string) ($rServer['bouquets'] ?? '[]'), true);

		if (!is_array($rBouquets)) {
			return [];
		}

		return array_values(array_filter(array_map('intval', $rBouquets), static fn($rItem) => $rItem > 0));
	}

	/**
	 * XC_VM streaming servers that should carry the imported channels.
	 *
	 * Falls back to the MAIN server when the Flussonic row has no explicit
	 * target, so an import is never orphaned without a runner.
	 *
	 * @param array<string,mixed> $rServer Server row.
	 * @return int[]
	 */
	private static function targetServerList(array $rServer): array {
		$rTarget = (int) ($rServer['target_server_id'] ?? 0);

		if ($rTarget > 0) {
			return [$rTarget];
		}

		$db = self::db();
		$db->query('SELECT `id` FROM `servers` WHERE `is_main` = 1 LIMIT 1;');

		if ($db->num_rows() > 0) {
			return [(int) $db->get_row()['id']];
		}

		return [];
	}
}
