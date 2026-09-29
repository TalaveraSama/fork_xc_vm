<?php

namespace XcVm\Module\Dvb;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Process\ProcessManager;
use XcVm\Module\Dvb\Service\DvbAdapterService;
use XcVm\Module\Dvb\Service\DvbJobService;
use XcVm\Module\Dvb\Service\DvbScanService;
use XcVm\Module\Dvb\Service\DvbServiceCatalog;
use XcVm\Module\Dvb\Service\DvbTransponderService;

/**
 * cron:dvb — the worker that runs on whichever node holds the tuner card.
 *
 * Installed on every node (the whole tree is), but each instance only ever
 * claims jobs addressed to its own SERVER_ID, so on a node with no card it
 * does nothing but a cheap indexed SELECT once a minute.
 *
 * One job per run. A transponder scan occupies a frontend for up to two
 * minutes and the queue is almost always one item long; draining it in a loop
 * would only make the lock harder to reason about, and the next tick is a
 * minute away.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbCronJob implements CommandInterface {

	/** Lock file, mirroring the convention the Watch Folder job uses. */
	private const LOCK = 'dvb_pid';

	public function getName(): string {
		return 'cron:dvb';
	}

	public function getDescription(): string {
		return 'Cron: DVB — run queued tuner work (adapter discovery, transponder scans)';
	}

	/**
	 * Claim and run one job.
	 *
	 * @param array $rArgs Unused.
	 * @return int Shell exit status.
	 */
	public function execute(array $rArgs): int {
		if (!$this->lock()) {
			return 0;
		}

		$rServerID = defined('SERVER_ID') ? (int) SERVER_ID : 1;

		// Only the main server prunes, so the housekeeping UPDATEs are not run
		// by every node in the cluster once a minute for no reason.
		if ($rServerID === 1) {
			DvbJobService::housekeep();
		}

		$rJob = DvbJobService::claim($rServerID);

		if ($rJob === null) {
			$this->unlock();

			return 0;
		}

		try {
			switch ($rJob['type']) {
				case DvbJobService::TYPE_DISCOVER:
					$rResult = $this->discover($rServerID);
					break;

				case DvbJobService::TYPE_SCAN:
					$rResult = $this->scan($rJob);
					break;

				default:
					$rResult = ['status' => false, 'message' => 'Unknown job type "' . $rJob['type'] . '".'];
			}
		} catch (\Throwable $rException) {
			// A job must never leave the queue wedged in `running`: that pins
			// the transponder in `scanning` in the UI with no way out until
			// housekeeping notices ten minutes later.
			$rResult = ['status' => false, 'message' => 'Unhandled error: ' . $rException->getMessage()];
		}

		DvbJobService::finish((int) $rJob['id'], $rResult['status'], $rResult['message']);
		$this->report($rJob, $rResult);
		$this->unlock();

		return $rResult['status'] ? 0 : 1;
	}

	/**
	 * Enumerate this node's frontends and record them.
	 *
	 * @param int $rServerID This node.
	 * @return array{status:bool,message:string}
	 */
	private function discover($rServerID) {
		$rProbed = DvbAdapterService::probeLocal();

		if (empty($rProbed)) {
			return [
				'status'  => false,
				'message' => 'No DVB frontends under /dev/dvb on this node. If a card is fitted, the driver is not loaded — check `lspci | grep -i tbs` and `dmesg | grep -i frontend`.',
			];
		}

		DvbAdapterService::sync($rServerID, $rProbed);

		$rNames = [];

		foreach ($rProbed as $rAdapter) {
			if ($rAdapter['name'] !== '') {
				$rNames[$rAdapter['name']] = true;
			}
		}

		return [
			'status'  => true,
			'message' => sprintf(
				'Found %d frontend%s%s.',
				count($rProbed),
				count($rProbed) === 1 ? '' : 's',
				empty($rNames) ? '' : ' (' . implode(', ', array_keys($rNames)) . ')'
			),
		];
	}

	/**
	 * Tune and scan one transponder, then merge the result.
	 *
	 * @param array $rJob Claimed job row.
	 * @return array{status:bool,message:string}
	 */
	private function scan(array $rJob) {
		$rTransponder = DvbTransponderService::find((int) $rJob['ref_id']);

		if ($rTransponder === null) {
			return ['status' => false, 'message' => 'Transponder ' . (int) $rJob['ref_id'] . ' no longer exists.'];
		}

		$rAdapter = DvbAdapterService::pick($rTransponder);

		if ($rAdapter === null) {
			DvbTransponderService::recordScan(
				(int) $rTransponder['id'],
				'error',
				'No tuner available on this node. Run Discover adapters first.'
			);

			return ['status' => false, 'message' => 'No tuner available on server ' . (int) $rTransponder['server_id'] . '.'];
		}

		DvbTransponderService::recordScan((int) $rTransponder['id'], 'scanning', 'Tuning...');
		DvbAdapterService::claim((int) $rAdapter['id'], (int) $rTransponder['id']);

		$rScan = DvbScanService::scan($rTransponder, $rAdapter);

		// Released whatever happened — a frontend left marked busy because a
		// scan failed is a tuner lost until someone notices.
		DvbAdapterService::claim((int) $rAdapter['id'], null);

		if (!$rScan['status']) {
			DvbTransponderService::recordScan(
				(int) $rTransponder['id'],
				'error',
				$rScan['error'],
				$rScan['signal']
			);

			return ['status' => false, 'message' => $rScan['error']];
		}

		$rMerged = DvbServiceCatalog::merge((int) $rTransponder['id'], $rScan['services']);

		$rMessage = sprintf(
			'%d service%s on adapter %d (%d new, %d already known).',
			$rMerged['total'],
			$rMerged['total'] === 1 ? '' : 's',
			(int) $rAdapter['adapter_num'],
			$rMerged['added'],
			$rMerged['updated']
		);

		DvbTransponderService::recordScan((int) $rTransponder['id'], 'ok', $rMessage, $rScan['signal']);

		return ['status' => true, 'message' => $rMessage];
	}

	/**
	 * Echo the outcome so `console.php cron:dvb` is usable by hand.
	 *
	 * @param array $rJob    Job row.
	 * @param array $rResult Outcome.
	 * @return void
	 */
	private function report(array $rJob, array $rResult) {
		echo sprintf(
			"[dvb] job #%d (%s): %s %s\n",
			(int) $rJob['id'],
			$rJob['type'],
			$rResult['status'] ? 'OK' : 'FAILED',
			$rResult['message']
		);
	}

	/**
	 * Take the run lock.
	 *
	 * @return bool False when another run is still going.
	 */
	private function lock() {
		$rPath = $this->lockPath();

		if (is_file($rPath)) {
			$rPrevPID = (int) file_get_contents($rPath);

			if ($rPrevPID > 0 && ProcessManager::isRunning($rPrevPID, 'php')) {
				return false;
			}
		}

		file_put_contents($rPath, getmypid());

		return true;
	}

	/**
	 * Release the run lock.
	 *
	 * @return void
	 */
	private function unlock() {
		@unlink($this->lockPath());
	}

	/**
	 * Absolute path of the lock file.
	 *
	 * @return string
	 */
	private function lockPath() {
		$rBase = defined('CACHE_TMP_PATH') ? CACHE_TMP_PATH : sys_get_temp_dir() . '/';

		return rtrim($rBase, '/') . '/' . self::LOCK;
	}
}
