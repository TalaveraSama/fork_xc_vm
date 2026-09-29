<?php

namespace XcVm\Module\Dvb\Service;

use XcVm\Core\Http\ApiClient;
use XcVm\Domain\Bouquet\BouquetService;

/**
 * DvbImportService — turn scanned services into panel channels.
 *
 * The chain a service travels:
 *
 *   dvb_services row  →  a UDP address on the tuner node
 *                     →  a `streams` row whose source is that address
 *                     →  a `streams_servers` row pinning it to that node
 *                     →  DVBlast restarted so the address actually carries it
 *                     →  the panel's own ffmpeg started on it
 *
 * ## Why the output address is loopback
 *
 * The channel is pinned to the node that holds the card, so the ffmpeg that
 * reads the UDP runs on that same machine. Loopback therefore costs nothing,
 * needs no multicast routing, cannot be disrupted by a switch that mishandles
 * IGMP, and does not put a single byte on the wire. Operators who genuinely
 * need to feed a second machine can set the transponder's `output_host` to a
 * multicast group and get the old behaviour.
 *
 * ## Ports are allocated monotonically and never reused
 *
 * Reclaiming the port of a deleted channel invites the worst kind of bug: the
 * dying DVBlast is still writing to it while a new, unrelated channel starts
 * reading, so the new channel briefly shows the old one. There are 55 000
 * ports above the base; spending them is cheaper than debugging that once.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbImportService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** First UDP port handed out on any node. */
	private const BASE_PORT = 10000;

	/** Highest port this module will allocate. */
	private const MAX_PORT = 64000;

	/**
	 * Import selected services as panel channels.
	 *
	 * @param int[] $rServiceIDs `dvb_services` ids.
	 * @param array $rOptions    category_id, bouquets[], prefix, skip_encrypted, start.
	 * @return array{status:bool,imported:int,skipped:int,errors:array,stream_ids:array}
	 */
	public static function import(array $rServiceIDs, array $rOptions = []) {
		$rCategoryID = (int) ($rOptions['category_id'] ?? 0);
		$rBouquets   = array_map('intval', (array) ($rOptions['bouquets'] ?? []));
		$rPrefix     = trim((string) ($rOptions['prefix'] ?? ''));
		$rSkipCrypt  = !empty($rOptions['skip_encrypted']);

		$rImported    = 0;
		$rSkipped     = 0;
		$rErrors      = [];
		$rStreamIDs   = [];
		$rTouchedTPs  = [];

		foreach ($rServiceIDs as $rServiceID) {
			$rService = DvbServiceCatalog::find((int) $rServiceID);

			if ($rService === null) {
				$rSkipped++;
				continue;
			}

			if (!empty($rService['stream_id']) && self::streamExists((int) $rService['stream_id'])) {
				$rSkipped++;
				continue;
			}

			if ($rSkipCrypt && !empty($rService['encrypted'])) {
				$rSkipped++;
				continue;
			}

			$rTransponder = DvbTransponderService::find((int) $rService['transponder_id']);

			if ($rTransponder === null) {
				$rErrors[] = 'Service ' . (int) $rServiceID . ': its transponder is gone.';
				$rSkipped++;
				continue;
			}

			$rPort = self::allocatePort((int) $rTransponder['server_id']);

			if ($rPort === 0) {
				$rErrors[] = 'Ran out of UDP ports on server ' . (int) $rTransponder['server_id'] . '.';
				break;
			}

			$rHost   = trim((string) ($rTransponder['output_host'] ?? '')) !== '' ? (string) $rTransponder['output_host'] : '127.0.0.1';
			$rSource = self::sourceUrl($rHost, $rPort);
			$rName   = $rPrefix !== '' ? ($rPrefix . ' ' . $rService['name']) : (string) $rService['name'];

			$rStreamID = self::createPanelStream($rName, $rSource, $rCategoryID, $rTransponder, $rService);

			if ($rStreamID === 0) {
				$rErrors[] = 'Could not create a channel for "' . $rService['name'] . '".';
				$rSkipped++;
				continue;
			}

			self::attachServer($rStreamID, (int) $rTransponder['server_id']);

			self::db()->query(
				'UPDATE `dvb_services` SET `stream_id` = ?, `output_ip` = ?, `output_port` = ? WHERE `id` = ?;',
				$rStreamID,
				$rHost,
				$rPort,
				(int) $rService['id']
			);

			$rStreamIDs[] = $rStreamID;
			$rTouchedTPs[(int) $rTransponder['id']] = $rTransponder;
			$rImported++;
		}

		foreach ($rBouquets as $rBouquetID) {
			if ($rBouquetID > 0 && !empty($rStreamIDs)) {
				BouquetService::addItems('stream', $rBouquetID, $rStreamIDs);
			}
		}

		// Ask each affected tuner node to rebuild its DVBlast. Until that job
		// runs the channels exist but carry nothing, which is why the caller is
		// told to expect a minute of silence.
		foreach ($rTouchedTPs as $rTransponderID => $rTransponder) {
			self::db()->query('UPDATE `dvb_transponders` SET `streaming` = 1 WHERE `id` = ?;', (int) $rTransponderID);

			DvbJobService::enqueue(
				(int) $rTransponder['server_id'],
				DvbJobService::TYPE_RESTREAM,
				(int) $rTransponderID
			);
		}

		if (!empty($rOptions['start']) && !empty($rStreamIDs)) {
			self::startPanelStreams($rStreamIDs);
		}

		return [
			'status'     => $rImported > 0,
			'imported'   => $rImported,
			'skipped'    => $rSkipped,
			'errors'     => $rErrors,
			'stream_ids' => $rStreamIDs,
		];
	}

	/**
	 * Unlink a service from its panel channel.
	 *
	 * The `streams` row is deliberately left in place. By the time anyone
	 * presses this the channel may be in bouquets, in subscriber line-ups and
	 * on screens; deleting it from a tuner page would be a surprising amount of
	 * destruction for a button labelled "unlink".
	 *
	 * @param int $rServiceID `dvb_services` id.
	 * @return array{status:bool,message:string}
	 */
	public static function unlink($rServiceID) {
		$rService = DvbServiceCatalog::find((int) $rServiceID);

		if ($rService === null) {
			return ['status' => false, 'message' => 'Service not found.'];
		}

		self::db()->query(
			'UPDATE `dvb_services` SET `stream_id` = NULL, `output_ip` = NULL, `output_port` = NULL WHERE `id` = ?;',
			(int) $rServiceID
		);

		$rTransponder = DvbTransponderService::find((int) $rService['transponder_id']);

		if ($rTransponder !== null) {
			DvbJobService::enqueue(
				(int) $rTransponder['server_id'],
				DvbJobService::TYPE_RESTREAM,
				(int) $rTransponder['id']
			);
		}

		return [
			'status'  => true,
			'message' => 'Unlinked. The channel itself was kept — delete it from the Streams page if you no longer want it.',
		];
	}

	/**
	 * The ffmpeg-facing URL for an output address.
	 *
	 * Multicast groups need the `@` form so ffmpeg joins the group; a unicast
	 * or loopback address must not have it, or ffmpeg tries to join 127.0.0.1
	 * as a group and reads nothing.
	 *
	 * @param string $rHost Output host.
	 * @param int    $rPort Output port.
	 * @return string
	 */
	public static function sourceUrl($rHost, $rPort) {
		$rFirst = (int) explode('.', $rHost)[0];
		$rIsMulticast = ($rFirst >= 224 && $rFirst <= 239);

		return 'udp://' . ($rIsMulticast ? '@' : '') . $rHost . ':' . (int) $rPort;
	}

	/**
	 * Next free UDP port on a node.
	 *
	 * @param int $rServerID Node id.
	 * @return int Port, or 0 when exhausted.
	 */
	private static function allocatePort($rServerID) {
		$db = self::db();

		$db->query(
			'SELECT MAX(s.`output_port`) AS `top`
			 FROM `dvb_services` s
			 INNER JOIN `dvb_transponders` t ON t.`id` = s.`transponder_id`
			 WHERE t.`server_id` = ?;',
			(int) $rServerID
		);

		$rTop = 0;

		if ($db->num_rows() === 1) {
			$rRow = $db->get_row();
			$rTop = (int) ($rRow['top'] ?? 0);
		}

		$rNext = ($rTop > 0 ? $rTop : (self::BASE_PORT - 1)) + 1;

		return ($rNext > self::MAX_PORT) ? 0 : $rNext;
	}

	/**
	 * Insert the `streams` row for one service.
	 *
	 * @param string $rName        Channel name.
	 * @param string $rSource      UDP URL.
	 * @param int    $rCategoryID  Category, or 0 for none.
	 * @param array  $rTransponder Transponder row.
	 * @param array  $rService     Service row.
	 * @return int New stream id, or 0 on failure.
	 */
	private static function createPanelStream($rName, $rSource, $rCategoryID, array $rTransponder, array $rService) {
		$db = self::db();

		$rCategories = $rCategoryID > 0 ? [$rCategoryID] : [];

		$rNotes = sprintf(
			'DVB: %s / %s (SID %d)',
			trim((string) $rTransponder['satellite']) !== '' ? $rTransponder['satellite'] : 'transponder',
			$rTransponder['name'],
			(int) $rService['service_id']
		);

		$rOk = $db->query(
			'INSERT INTO `streams`(`type`, `category_id`, `stream_display_name`, `stream_source`, `stream_icon`, `notes`, `added`, `order`, `direct_source`, `read_native`, `gen_timestamps`, `transcode_profile_id`, `tv_archive_duration`) VALUES(1, ?, ?, ?, ?, ?, ?, ?, 0, 1, 1, 0, 0);',
			'[' . implode(',', $rCategories) . ']',
			mb_substr($rName, 0, 250),
			json_encode([$rSource]),
			'',
			$rNotes,
			time(),
			0
		);

		if (!$rOk) {
			return 0;
		}

		return (int) $db->last_insert_id();
	}

	/**
	 * Pin a stream to the node that holds the tuner.
	 *
	 * This is not optional: the UDP source only exists on that machine, so a
	 * channel bound anywhere else reads from an address nothing is sending to.
	 *
	 * @param int $rStreamID Panel stream id.
	 * @param int $rServerID Node id.
	 * @return void
	 */
	private static function attachServer($rStreamID, $rServerID) {
		self::db()->query(
			'INSERT IGNORE INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES(?, ?, 0, 0);',
			(int) $rStreamID,
			(int) $rServerID
		);
	}

	/**
	 * Does this panel stream still exist?
	 *
	 * @param int $rStreamID Stream id.
	 * @return bool
	 */
	private static function streamExists($rStreamID) {
		$db = self::db();

		$db->query('SELECT `id` FROM `streams` WHERE `id` = ? LIMIT 1;', (int) $rStreamID);

		return $db->num_rows() === 1;
	}

	/**
	 * Start the freshly created channels on their node.
	 *
	 * Grouped by what `streams_servers` actually records rather than by the
	 * node we meant to use, for the same reason the Flussonic importer does:
	 * the two disagree whenever a row was skipped or the channel was moved.
	 *
	 * @param int[] $rStreamIDs Panel stream ids.
	 * @return void
	 */
	private static function startPanelStreams(array $rStreamIDs) {
		if ($rStreamIDs === []) {
			return;
		}

		$db = self::db();

		$db->query(
			'SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN ('
				. implode(',', array_map('intval', $rStreamIDs)) . ');'
		);

		$rMap = [];

		foreach ($db->get_rows() as $rRow) {
			$rMap[(int) $rRow['server_id']][] = (int) $rRow['stream_id'];
		}

		foreach ($rMap as $rServerID => $rIDs) {
			// The receiving end sleeps 50 ms between streams, so a bulk import
			// outruns ApiClient's 5 s default and the batch gets cut off part
			// way through.
			ApiClient::request([
				'action'     => 'stream',
				'sub'        => 'start',
				'stream_ids' => $rIDs,
				'servers'    => [$rServerID],
			], min(120, max(15, (int) ceil(count($rIDs) * 0.25))));
		}
	}
}
