<?php

namespace XcVm\Module\Dvb\Service;

/**
 * DvbAdapterService — what tuners does this node have?
 *
 * Discovery runs on the tuner node (from DvbCronJob, via a `discover` job) and
 * writes what it finds into `dvb_adapters`; every other method here is a read
 * the panel performs against that table.
 *
 * A TBS6909X produces eight rows: one frontend per tuner. Which physical LNB
 * input each of those eight reaches depends on the card's mode — the 6909X can
 * be switched between a multiswitch layout and a quattro layout — so the module
 * deliberately does not try to infer a mapping. The operator pins a transponder
 * to an adapter, or leaves it unpinned and takes whatever is free.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbAdapterService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Enumerate the frontends physically present on this machine.
	 *
	 * Reads /dev/dvb directly rather than asking a tool, so it still returns
	 * something useful when v4l-utils is missing — the operator then sees
	 * "8 adapters, names unknown" instead of an empty page, which is a far
	 * better diagnostic.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function probeLocal() {
		$rFound = [];

		foreach ((array) glob('/dev/dvb/adapter*/frontend*') as $rNode) {
			if (preg_match('#/adapter(\d+)/frontend(\d+)$#', $rNode, $rMatch) !== 1) {
				continue;
			}

			$rAdapter  = (int) $rMatch[1];
			$rFrontend = (int) $rMatch[2];
			$rDetail   = self::describeFrontend($rAdapter, $rFrontend);

			$rFound[] = [
				'adapter_num'      => $rAdapter,
				'frontend_num'     => $rFrontend,
				'name'             => $rDetail['name'],
				'delivery_systems' => $rDetail['delivery_systems'],
				'bus_info'         => $rDetail['bus_info'],
			];
		}

		usort($rFound, function ($rA, $rB) {
			return ($rA['adapter_num'] <=> $rB['adapter_num']) ?: ($rA['frontend_num'] <=> $rB['frontend_num']);
		});

		return $rFound;
	}

	/**
	 * Ask dvb-fe-tool what a frontend is and what it can demodulate.
	 *
	 * @param int $rAdapter  Adapter number.
	 * @param int $rFrontend Frontend number.
	 * @return array{name:string,delivery_systems:string,bus_info:string}
	 */
	private static function describeFrontend($rAdapter, $rFrontend) {
		$rBlank = ['name' => '', 'delivery_systems' => '', 'bus_info' => ''];
		$rTool  = DvbScanService::locateBinary('dvb-fe-tool');

		if ($rTool === null) {
			return $rBlank;
		}

		$rOutput = (string) @shell_exec(
			'timeout 10 ' . escapeshellarg($rTool) . ' -a ' . (int) $rAdapter . ' -f ' . (int) $rFrontend . ' 2>&1'
		);

		if (trim($rOutput) === '') {
			return $rBlank;
		}

		$rName = '';

		// "Device TurboSight TBS 6909x (/dev/dvb/adapter0/frontend0) capabilities:"
		if (preg_match('/^Device\s+(.+?)\s+\(/mi', $rOutput, $rMatch) === 1) {
			$rName = trim($rMatch[1]);
		}

		// The supported systems are listed one per line in brackets after a
		// "Supported delivery systems:" heading, with the active one starred.
		$rSystems = [];

		if (preg_match_all('/^\s*\[?([A-Z0-9][A-Z0-9\/_.-]*)\]?\s*$/m', $rOutput, $rMatches) > 0) {
			foreach ($rMatches[1] as $rCandidate) {
				if (preg_match('/^(DVB|ATSC|ISDB|DSS|TURBO|DTMB|CMMB)/', $rCandidate) === 1) {
					$rSystems[$rCandidate] = true;
				}
			}
		}

		$rBus = '';

		if (preg_match('/^\s*Bus info\s*:\s*(.+?)\s*$/mi', $rOutput, $rMatch) === 1) {
			$rBus = trim($rMatch[1]);
		}

		return [
			'name'             => substr($rName, 0, 190),
			'delivery_systems' => substr(implode(',', array_keys($rSystems)), 0, 190),
			'bus_info'         => substr($rBus, 0, 190),
		];
	}

	/**
	 * Write a probe result into `dvb_adapters` for one node.
	 *
	 * Rows are upserted, never deleted: a transponder pinned to adapter 3 must
	 * keep that binding across a reboot during which the card was, say, briefly
	 * pulled. Absence shows up as a stale `last_seen`, which the UI can grey
	 * out, instead of as a dangling foreign key.
	 *
	 * @param int   $rServerID Node the adapters belong to.
	 * @param array $rProbed   Result of {@see probeLocal()}.
	 * @return int Number of frontends recorded.
	 */
	public static function sync($rServerID, array $rProbed) {
		$db   = self::db();
		$rNow = time();

		foreach ($rProbed as $rAdapter) {
			$db->query(
				'INSERT INTO `dvb_adapters`(`server_id`, `adapter_num`, `frontend_num`, `name`, `delivery_systems`, `bus_info`, `last_seen`)
				 VALUES(?, ?, ?, ?, ?, ?, ?)
				 ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `delivery_systems` = VALUES(`delivery_systems`), `bus_info` = VALUES(`bus_info`), `last_seen` = VALUES(`last_seen`);',
				(int) $rServerID,
				(int) $rAdapter['adapter_num'],
				(int) $rAdapter['frontend_num'],
				$rAdapter['name'],
				$rAdapter['delivery_systems'],
				$rAdapter['bus_info'],
				$rNow
			);
		}

		return count($rProbed);
	}

	/**
	 * All adapters known on a node, newest probe first.
	 *
	 * @param int $rServerID Node id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function forServer($rServerID) {
		$db = self::db();

		$db->query(
			'SELECT * FROM `dvb_adapters` WHERE `server_id` = ? ORDER BY `adapter_num` ASC, `frontend_num` ASC;',
			(int) $rServerID
		);

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * Fetch one adapter row.
	 *
	 * @param int $rID Adapter id.
	 * @return array|null
	 */
	public static function find($rID) {
		$db = self::db();

		$db->query('SELECT * FROM `dvb_adapters` WHERE `id` = ?;', (int) $rID);

		return $db->num_rows() === 1 ? $db->get_row() : null;
	}

	/**
	 * Choose the adapter a transponder should be tuned on.
	 *
	 * A pinned adapter wins outright, even if it looks busy: the operator
	 * pinned it for a reason (it is the one cabled to the right satellite), and
	 * silently tuning a different one would scan the wrong sky and report the
	 * wrong channels — a far worse failure than "device busy".
	 *
	 * Otherwise the lowest-numbered enabled adapter on the node that is not
	 * currently claimed is used.
	 *
	 * @param array $rTransponder Transponder row.
	 * @return array|null Adapter row, or null when the node has none free.
	 */
	public static function pick(array $rTransponder) {
		if (!empty($rTransponder['adapter_id'])) {
			$rPinned = self::find((int) $rTransponder['adapter_id']);

			if ($rPinned !== null) {
				return $rPinned;
			}
		}

		$db = self::db();

		$db->query(
			'SELECT * FROM `dvb_adapters`
			 WHERE `server_id` = ? AND `enabled` = 1 AND (`in_use_by` IS NULL OR `in_use_by` = ?)
			 ORDER BY `adapter_num` ASC, `frontend_num` ASC LIMIT 1;',
			(int) $rTransponder['server_id'],
			(int) $rTransponder['id']
		);

		return $db->num_rows() === 1 ? $db->get_row() : null;
	}

	/**
	 * Mark an adapter as claimed by a transponder, or release it.
	 *
	 * @param int      $rAdapterID     Adapter id.
	 * @param int|null $rTransponderID Claimant, or null to release.
	 * @return void
	 */
	public static function claim($rAdapterID, $rTransponderID) {
		self::db()->query(
			'UPDATE `dvb_adapters` SET `in_use_by` = ? WHERE `id` = ?;',
			$rTransponderID === null ? null : (int) $rTransponderID,
			(int) $rAdapterID
		);
	}
}
