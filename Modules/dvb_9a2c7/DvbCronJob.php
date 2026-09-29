<?php

namespace XcVm\Module\Dvb;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Process\ProcessManager;
use XcVm\Module\Dvb\Service\DvbAdapterService;
use XcVm\Module\Dvb\Service\DvbDecryptRunner;
use XcVm\Module\Dvb\Service\DvbJobService;
use XcVm\Module\Dvb\Service\DvbScanService;
use XcVm\Module\Dvb\Service\DvbServiceCatalog;
use XcVm\Module\Dvb\Service\DvbStreamRunner;
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

		// Supervision runs on every tick, job or no job: a DVBlast that died
		// overnight has to come back without anyone pressing anything.
		$this->supervise($rServerID);

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

				case DvbJobService::TYPE_RESTREAM:
					$rResult = $this->restream($rJob);
					break;

				case DvbJobService::TYPE_STOP:
					$rResult = $this->stopStream($rJob);
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

		// Never interrupt a live stream to run a scan. A pinned tuner that is
		// busy feeding another carrier would otherwise be taken away, dropping
		// every channel on it — and the operator would see only "device busy"
		// on the scan, with no hint that they had just knocked a transponder
		// off the air.
		// Any claim at all means the frontend is taken: only a live DVBlast or
		// a scan holds one, and the per-node lock rules out a concurrent scan.
		// Scanning the very transponder that is streaming is refused too —
		// dvblast owns the device, so dvbv5-scan would just report "busy".
		$rBusyWith = (int) ($rAdapter['in_use_by'] ?? 0);

		if ($rBusyWith > 0) {
			$rMessage = $rBusyWith === (int) $rTransponder['id']
				? 'This transponder is streaming, so its tuner is busy. Stop streaming before rescanning it.'
				: 'Tuner adapter' . (int) $rAdapter['adapter_num']
					. ' is streaming transponder #' . $rBusyWith . '. Stop that transponder, or pin this one to a free tuner.';

			DvbTransponderService::recordScan((int) $rTransponder['id'], 'error', $rMessage);

			return ['status' => false, 'message' => $rMessage];
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
	 * Rebuild and restart the DVBlast feeding one transponder.
	 *
	 * @param array $rJob Claimed job row.
	 * @return array{status:bool,message:string}
	 */
	private function restream(array $rJob) {
		$rTransponder = DvbTransponderService::find((int) $rJob['ref_id']);

		if ($rTransponder === null) {
			return ['status' => false, 'message' => 'Transponder ' . (int) $rJob['ref_id'] . ' no longer exists.'];
		}

		$rResult = DvbStreamRunner::start($rTransponder);
		DvbStreamRunner::record((int) $rTransponder['id'], $rResult['status'] ? 'running' : 'error', $rResult['message']);

		return $rResult;
	}

	/**
	 * Stop the DVBlast feeding one transponder.
	 *
	 * @param array $rJob Claimed job row.
	 * @return array{status:bool,message:string}
	 */
	private function stopStream(array $rJob) {
		$rID          = (int) $rJob['ref_id'];
		$rTransponder = DvbTransponderService::find($rID);

		// A stop queued by delete() outlives the row it refers to, on purpose:
		// the process has to be reaped even though the transponder is gone.
		// Stopping needs only the id (to find the PID file) and the adapter
		// binding (to release it), so a synthetic row is enough — treating a
		// missing transponder as an error here would strand DVBlast holding a
		// tuner and a frequency with nothing left to reconcile it against.
		if ($rTransponder === null) {
			$rTransponder = ['id' => $rID, 'adapter_id' => null];
		}

		// Decryptors first: they read from DVBlast, so tearing down the source
		// before its consumers just leaves them spinning on a dead socket.
		DvbDecryptRunner::stopForTransponder($rID);

		$rResult = DvbStreamRunner::stop($rTransponder);
		DvbStreamRunner::record($rID, 'stopped', 'Stopped by request.');

		return $rResult;
	}

	/**
	 * Restart anything that should be streaming on this node and is not.
	 *
	 * Deliberately silent unless it had to act: this runs sixty times an hour
	 * and a log line per tick would bury everything else.
	 *
	 * @param int $rServerID This node.
	 * @return void
	 */
	private function supervise($rServerID) {
		$rCounts = DvbStreamRunner::supervise($rServerID);

		if ($rCounts['started'] || $rCounts['stopped'] || $rCounts['failed']) {
			echo sprintf(
				"[dvb] supervisor: %d started, %d stopped, %d failed\n",
				$rCounts['started'],
				$rCounts['stopped'],
				$rCounts['failed']
			);
		}

		foreach ($rCounts['messages'] ?? [] as $rWhy) {
			echo '[dvb]   ' . $rWhy . "\n";
		}

		// Decryptors are reconciled after the tuners, not before: tsdecrypt
		// reads what DVBlast produces, so starting one for a transponder that
		// is still coming up just burns a CAMD session on a dead input.
		$rCrypt = DvbDecryptRunner::supervise($rServerID);

		if ($rCrypt['started'] || $rCrypt['stopped'] || $rCrypt['failed']) {
			echo sprintf(
				"[dvb] decryptors: %d started, %d stopped, %d failed\n",
				$rCrypt['started'],
				$rCrypt['stopped'],
				$rCrypt['failed']
			);
		}

		foreach ($rCrypt['messages'] ?? [] as $rWhy) {
			echo '[dvb]   ' . $rWhy . "\n";
		}
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
