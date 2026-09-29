<?php

namespace XcVm\Module\Dvb\Service;

/**
 * DvbJobService — the queue between the panel and the tuner node.
 *
 * The panel enqueues ("scan transponder 7"), the node that owns the card
 * dequeues and executes. Both halves talk to the same MySQL because streaming
 * nodes are installed pointing at the main server's database, so no new
 * network path, port or credential is introduced.
 *
 * Claiming is done with a token rather than the usual
 * "UPDATE ... LIMIT 1; SELECT the row I just touched" pair, which cannot tell
 * two runners apart inside the same second. DvbCronJob already holds a per-node
 * PID lock, so this is belt and braces — but a queue that hands the same job to
 * two workers tunes the same frontend twice, and that failure looks like
 * flaky hardware rather than a software bug, so it is worth closing properly.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbJobService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** Job types understood by DvbCronJob. */
	public const TYPE_DISCOVER = 'discover';
	public const TYPE_SCAN     = 'scan';

	/** Finished jobs are kept this long so the UI can still show the outcome. */
	private const KEEP_SECONDS = 86400;

	/** A running job older than this is assumed dead and is failed. */
	private const STALE_SECONDS = 600;

	/**
	 * Queue a job for a node.
	 *
	 * An identical pending job is reused rather than duplicated: an operator
	 * who clicks Scan four times because nothing seems to happen should get one
	 * scan, not four fighting over the same frontend.
	 *
	 * @param int        $rServerID Node that must run it.
	 * @param string     $rType     One of the TYPE_* constants.
	 * @param int|null   $rRefID    Transponder id for scans, null otherwise.
	 * @param array      $rPayload  Extra parameters.
	 * @return int Job id.
	 */
	public static function enqueue($rServerID, $rType, $rRefID = null, array $rPayload = []) {
		$db = self::db();

		$db->query(
			'SELECT `id` FROM `dvb_jobs`
			 WHERE `server_id` = ? AND `type` = ? AND `status` = \'pending\'
			   AND (`ref_id` <=> ?) LIMIT 1;',
			(int) $rServerID,
			$rType,
			$rRefID === null ? null : (int) $rRefID
		);

		if ($db->num_rows() === 1) {
			$rExisting = $db->get_row();

			return (int) $rExisting['id'];
		}

		$db->query(
			'INSERT INTO `dvb_jobs`(`server_id`, `type`, `ref_id`, `payload`, `status`, `created_at`)
			 VALUES(?, ?, ?, ?, \'pending\', ?);',
			(int) $rServerID,
			$rType,
			$rRefID === null ? null : (int) $rRefID,
			json_encode($rPayload),
			time()
		);

		return (int) $db->last_insert_id();
	}

	/**
	 * Claim the oldest pending job for a node.
	 *
	 * @param int $rServerID Node asking for work.
	 * @return array|null Job row, or null when the queue is empty.
	 */
	public static function claim($rServerID) {
		$db     = self::db();
		$rToken = 'claim:' . bin2hex(random_bytes(16));

		$db->query(
			'UPDATE `dvb_jobs`
			 SET `status` = \'running\', `result` = ?, `started_at` = ?, `attempts` = `attempts` + 1
			 WHERE `server_id` = ? AND `status` = \'pending\'
			 ORDER BY `id` ASC LIMIT 1;',
			$rToken,
			time(),
			(int) $rServerID
		);

		$db->query('SELECT * FROM `dvb_jobs` WHERE `result` = ? LIMIT 1;', $rToken);

		return $db->num_rows() === 1 ? $db->get_row() : null;
	}

	/**
	 * Record the outcome of a job.
	 *
	 * @param int    $rJobID   Job id.
	 * @param bool   $rSuccess Whether it worked.
	 * @param string $rMessage Human-readable outcome.
	 * @return void
	 */
	public static function finish($rJobID, $rSuccess, $rMessage) {
		self::db()->query(
			'UPDATE `dvb_jobs` SET `status` = ?, `result` = ?, `finished_at` = ? WHERE `id` = ?;',
			$rSuccess ? 'done' : 'error',
			substr((string) $rMessage, 0, 4000),
			time(),
			(int) $rJobID
		);
	}

	/**
	 * Fetch one job, for the UI's polling endpoint.
	 *
	 * @param int $rID Job id.
	 * @return array|null
	 */
	public static function find($rID) {
		$db = self::db();

		$db->query('SELECT * FROM `dvb_jobs` WHERE `id` = ?;', (int) $rID);

		return $db->num_rows() === 1 ? $db->get_row() : null;
	}

	/**
	 * Fail jobs whose worker died, and drop old finished ones.
	 *
	 * Without this a node that is rebooted mid-scan leaves a job stuck in
	 * `running` for ever, and the transponder it belongs to stays stuck in
	 * `scanning` in the UI with no way back.
	 *
	 * @return void
	 */
	public static function housekeep() {
		$db = self::db();

		$db->query(
			'UPDATE `dvb_jobs` SET `status` = \'error\', `result` = ?, `finished_at` = ?
			 WHERE `status` = \'running\' AND `started_at` < ?;',
			'Worker stopped before reporting back (node restarted, or the scan was killed).',
			time(),
			time() - self::STALE_SECONDS
		);

		$db->query(
			'UPDATE `dvb_transponders` SET `scan_status` = \'error\', `scan_message` = ?
			 WHERE `scan_status` = \'scanning\'
			   AND (`last_scan` IS NULL OR `last_scan` < ?);',
			'Scan did not report back; the node may have restarted. Try again.',
			time() - self::STALE_SECONDS
		);

		$db->query(
			'DELETE FROM `dvb_jobs` WHERE `status` IN(\'done\', \'error\') AND `finished_at` < ?;',
			time() - self::KEEP_SECONDS
		);
	}
}
