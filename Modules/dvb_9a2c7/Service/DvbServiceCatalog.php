<?php

namespace XcVm\Module\Dvb\Service;

/**
 * DvbServiceCatalog — the services found on each transponder.
 *
 * Scan results are merged, never replaced. That matters because a service row
 * may already be linked to a live panel stream through `stream_id`: deleting
 * and reinserting on every rescan would break that link and, with it, whatever
 * the subscriber is watching. So a rescan updates what it finds, adds what is
 * new, and simply stops touching `last_seen` on what has gone away — which is
 * how the UI tells "disappeared from the transponder" from "never existed".
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbServiceCatalog {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Merge a scan result into `dvb_services`.
	 *
	 * @param int   $rTransponderID Transponder the services were found on.
	 * @param array $rServices      Rows from {@see DvbScanService::scan()}.
	 * @return array{added:int,updated:int,total:int}
	 */
	public static function merge($rTransponderID, array $rServices) {
		$db      = self::db();
		$rNow    = time();
		$rAdded  = 0;
		$rSeen   = 0;

		foreach ($rServices as $rService) {
			$rSid = (int) $rService['service_id'];

			$db->query(
				'SELECT `id` FROM `dvb_services` WHERE `transponder_id` = ? AND `service_id` = ? LIMIT 1;',
				(int) $rTransponderID,
				$rSid
			);

			if ($db->num_rows() === 1) {
				$rExisting = $db->get_row();

				$db->query(
					'UPDATE `dvb_services`
					 SET `name` = ?, `provider` = ?, `pmt_pid` = ?, `pcr_pid` = ?, `video_pid` = ?,
					     `audio_pid` = ?, `encrypted` = ?, `last_seen` = ?
					 WHERE `id` = ?;',
					self::clip($rService['name'], 190),
					self::clip($rService['provider'], 190),
					(int) $rService['pmt_pid'],
					(int) $rService['pcr_pid'],
					(int) $rService['video_pid'],
					self::clip($rService['audio_pid'], 190),
					(int) $rService['encrypted'],
					$rNow,
					(int) $rExisting['id']
				);

				$rSeen++;
				continue;
			}

			$db->query(
				'INSERT INTO `dvb_services`
				 (`transponder_id`, `service_id`, `name`, `provider`, `pmt_pid`, `pcr_pid`, `video_pid`, `audio_pid`, `encrypted`, `first_seen`, `last_seen`)
				 VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
				(int) $rTransponderID,
				$rSid,
				self::clip($rService['name'], 190),
				self::clip($rService['provider'], 190),
				(int) $rService['pmt_pid'],
				(int) $rService['pcr_pid'],
				(int) $rService['video_pid'],
				self::clip($rService['audio_pid'], 190),
				(int) $rService['encrypted'],
				$rNow,
				$rNow
			);

			$rAdded++;
		}

		return ['added' => $rAdded, 'updated' => $rSeen, 'total' => $rAdded + $rSeen];
	}

	/**
	 * Services on one transponder.
	 *
	 * @param int $rTransponderID Transponder id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function forTransponder($rTransponderID) {
		$db = self::db();

		$db->query(
			'SELECT * FROM `dvb_services` WHERE `transponder_id` = ? ORDER BY `name` ASC, `service_id` ASC;',
			(int) $rTransponderID
		);

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * Fetch one service row.
	 *
	 * @param int $rID Service id.
	 * @return array|null
	 */
	public static function find($rID) {
		$db = self::db();

		$db->query('SELECT * FROM `dvb_services` WHERE `id` = ?;', (int) $rID);

		return $db->num_rows() === 1 ? $db->get_row() : null;
	}

	/**
	 * Truncate a value to the column width, respecting UTF-8 boundaries.
	 *
	 * Service names arrive from the broadcaster and regularly contain accented
	 * characters; a byte-wise cut can split one in half and MySQL then rejects
	 * or mangles the whole row.
	 *
	 * @param mixed $rValue  Raw value.
	 * @param int   $rLength Maximum length in characters.
	 * @return string
	 */
	private static function clip($rValue, $rLength) {
		$rValue = trim((string) $rValue);

		if (function_exists('mb_substr')) {
			return mb_substr($rValue, 0, $rLength, 'UTF-8');
		}

		return substr($rValue, 0, $rLength);
	}
}
