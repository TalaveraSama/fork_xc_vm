<?php

namespace XcVm\Module\Flussonic\Service;

/**
 * FlussonicStreamService — persistence for the streams discovered on the
 * remote Flussonic servers (`flussonic_streams`).
 *
 * The table is a *catalogue*, not a copy of the panel's `streams` table: one
 * row per (server, Flussonic stream name) with the last known live telemetry
 * and, once imported, a back-reference to the panel stream it produced
 * (`stream_id`). That back-reference is what lets the UI show "Imported" and
 * lets a re-sync update an existing channel instead of duplicating it.
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FlussonicStreamService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Discovered streams, optionally filtered.
	 *
	 * @param array{server_id?:int,status?:string,search?:string} $rFilters Filters.
	 * @return array<int,array<string,mixed>>
	 */
	public static function getAll(array $rFilters = []): array {
		$db = self::db();
		$rWhere = [];
		$rValues = [];

		if (!empty($rFilters['server_id'])) {
			$rWhere[] = '`flussonic_streams`.`server_id` = ?';
			$rValues[] = (int) $rFilters['server_id'];
		}

		if (($rFilters['status'] ?? '') === 'imported') {
			$rWhere[] = '`flussonic_streams`.`stream_id` > 0';
		} elseif (($rFilters['status'] ?? '') === 'new') {
			$rWhere[] = '`flussonic_streams`.`stream_id` = 0';
		} elseif (($rFilters['status'] ?? '') === 'alive') {
			$rWhere[] = '`flussonic_streams`.`alive` = 1';
		} elseif (($rFilters['status'] ?? '') === 'offline') {
			$rWhere[] = '`flussonic_streams`.`alive` = 0';
		}

		if (!empty($rFilters['search'])) {
			$rWhere[] = '(`flussonic_streams`.`name` LIKE ? OR `flussonic_streams`.`title` LIKE ?)';
			$rValues[] = '%' . $rFilters['search'] . '%';
			$rValues[] = '%' . $rFilters['search'] . '%';
		}

		$rWhereSql = $rWhere === [] ? '' : ' WHERE ' . implode(' AND ', $rWhere);
		$db->query('SELECT * FROM `flussonic_streams`' . $rWhereSql . ' ORDER BY `server_id` ASC, `name` ASC;', ...$rValues);

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * @param int[] $rIDs Row ids from `flussonic_streams`.
	 * @return array<int,array<string,mixed>>
	 */
	public static function getByIds(array $rIDs): array {
		$rIDs = array_values(array_filter(array_map('intval', $rIDs), static fn($rItem) => $rItem > 0));

		if ($rIDs === []) {
			return [];
		}

		$db = self::db();
		$db->query('SELECT * FROM `flussonic_streams` WHERE `id` IN (' . implode(',', $rIDs) . ');');

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * Every discovered stream of a server, keyed by Flussonic stream name.
	 *
	 * @param int $rServerID Flussonic server id.
	 * @return array<string,array<string,mixed>>
	 */
	public static function getByServerKeyed($rServerID): array {
		$rReturn = [];

		foreach (self::getAll(['server_id' => (int) $rServerID]) as $rRow) {
			$rReturn[(string) $rRow['name']] = $rRow;
		}

		return $rReturn;
	}

	/**
	 * Insert or refresh one discovered stream.
	 *
	 * @param int                 $rServerID Flussonic server id.
	 * @param array<string,mixed> $rStream   Normalised stream from the API client.
	 * @param string              $rPlayUrl  Playback URL built for the server's protocol.
	 * @return bool True when the row was newly created.
	 */
	public static function upsert($rServerID, array $rStream, string $rPlayUrl): bool {
		$db = self::db();
		$rServerID = (int) $rServerID;
		$rName = (string) $rStream['name'];
		$rNow = time();

		$db->query('SELECT `id` FROM `flussonic_streams` WHERE `server_id` = ? AND `name` = ? LIMIT 1;', $rServerID, $rName);
		$rExistingID = $db->num_rows() > 0 ? (int) $db->get_row()['id'] : 0;

		if ($rExistingID > 0) {
			$db->query(
				'UPDATE `flussonic_streams` SET `title` = ?, `alive` = ?, `bitrate` = ?, `clients` = ?, `input_url` = ?, `video_codec` = ?, `audio_codec` = ?, `resolution` = ?, `dvr_depth` = ?, `logo` = ?, `play_url` = ?, `last_seen` = ? WHERE `id` = ?;',
				(string) $rStream['title'],
				!empty($rStream['alive']) ? 1 : 0,
				(int) $rStream['bitrate'],
				(int) $rStream['clients'],
				mb_substr((string) $rStream['input_url'], 0, 1000),
				(string) $rStream['video_codec'],
				(string) $rStream['audio_codec'],
				(string) $rStream['resolution'],
				(int) $rStream['dvr_depth'],
				mb_substr((string) $rStream['logo'], 0, 1000),
				mb_substr($rPlayUrl, 0, 1000),
				$rNow,
				$rExistingID
			);

			return false;
		}

		$db->query(
			'INSERT INTO `flussonic_streams`(`server_id`, `name`, `title`, `alive`, `bitrate`, `clients`, `input_url`, `video_codec`, `audio_codec`, `resolution`, `dvr_depth`, `logo`, `play_url`, `stream_id`, `first_seen`, `last_seen`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?);',
			$rServerID,
			$rName,
			(string) $rStream['title'],
			!empty($rStream['alive']) ? 1 : 0,
			(int) $rStream['bitrate'],
			(int) $rStream['clients'],
			mb_substr((string) $rStream['input_url'], 0, 1000),
			(string) $rStream['video_codec'],
			(string) $rStream['audio_codec'],
			(string) $rStream['resolution'],
			(int) $rStream['dvr_depth'],
			mb_substr((string) $rStream['logo'], 0, 1000),
			mb_substr($rPlayUrl, 0, 1000),
			$rNow,
			$rNow
		);

		return true;
	}

	/**
	 * Drop catalogue rows for streams the origin no longer advertises.
	 *
	 * Only rows that were never imported are removed; an imported stream keeps
	 * its catalogue entry so the operator can still see where the now-missing
	 * channel came from.
	 *
	 * @param int      $rServerID  Flussonic server id.
	 * @param string[] $rSeenNames Stream names returned by the last sync.
	 * @return int Number of catalogue rows removed.
	 */
	public static function pruneMissing($rServerID, array $rSeenNames): int {
		$db = self::db();
		$rServerID = (int) $rServerID;
		$rRemoved = 0;

		foreach (self::getAll(['server_id' => $rServerID]) as $rRow) {
			if (in_array((string) $rRow['name'], $rSeenNames, true)) {
				continue;
			}

			if ((int) $rRow['stream_id'] > 0) {
				$db->query('UPDATE `flussonic_streams` SET `alive` = 0 WHERE `id` = ?;', (int) $rRow['id']);
				continue;
			}

			$db->query('DELETE FROM `flussonic_streams` WHERE `id` = ?;', (int) $rRow['id']);
			$rRemoved++;
		}

		return $rRemoved;
	}

	/**
	 * Attach a catalogue row to the panel stream it produced.
	 *
	 * @param int $rID       `flussonic_streams` id.
	 * @param int $rStreamID `streams` id (0 unlinks).
	 * @return void
	 */
	public static function linkPanelStream($rID, $rStreamID): void {
		$db = self::db();
		$db->query('UPDATE `flussonic_streams` SET `stream_id` = ? WHERE `id` = ?;', (int) $rStreamID, (int) $rID);
	}

	/**
	 * Clear links to panel streams that no longer exist (deleted by the admin).
	 *
	 * @return int Number of stale links cleared.
	 */
	public static function clearOrphanLinks(): int {
		$db = self::db();
		$db->query('UPDATE `flussonic_streams` SET `stream_id` = 0 WHERE `stream_id` > 0 AND `stream_id` NOT IN (SELECT `id` FROM `streams`);');

		return (int) $db->num_rows();
	}

	/**
	 * Per-server totals for the servers list.
	 *
	 * @return array<int,array{total:int,alive:int,imported:int}>
	 */
	public static function statsByServer(): array {
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT `server_id`, COUNT(*) AS `total`, SUM(`alive` = 1) AS `alive`, SUM(`stream_id` > 0) AS `imported` FROM `flussonic_streams` GROUP BY `server_id`;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[(int) $rRow['server_id']] = [
					'total'    => (int) $rRow['total'],
					'alive'    => (int) $rRow['alive'],
					'imported' => (int) $rRow['imported'],
				];
			}
		}

		return $rReturn;
	}

	/**
	 * Remove every catalogue row of a server (used by "Forget discovered").
	 *
	 * @param int $rServerID Flussonic server id.
	 * @return void
	 */
	public static function deleteByServer($rServerID): void {
		$db = self::db();
		$db->query('DELETE FROM `flussonic_streams` WHERE `server_id` = ?;', (int) $rServerID);
	}
}
