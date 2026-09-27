<?php

namespace XcVm\Module\Flussonic;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Module\Flussonic\Service\FlussonicServerService;
use XcVm\Module\Flussonic\Service\FlussonicSyncService;

/**
 * FlussonicCronJob — periodic discovery of the streams published by the
 * configured Flussonic origins.
 *
 * Usage:
 *   php console.php cron:flussonic              # honour each server's interval
 *   php console.php cron:flussonic --force      # sync every enabled server now
 *   php console.php cron:flussonic 3            # sync only server id 3
 *   php console.php cron:flussonic 3 --import   # …and import its new streams
 *
 * Servers with `auto_import` enabled import their new streams as part of the
 * normal run, so the unattended path needs no extra flags.
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once MAIN_HOME . 'Cli/CronTrait.php';

class FlussonicCronJob implements CommandInterface {

	use CronTrait;

	public function getName(): string {
		return 'cron:flussonic';
	}

	public function getDescription(): string {
		return 'Cron: Flussonic — discover and import streams from Flussonic Media Servers';
	}

	/**
	 * @param array $rArgs CLI arguments.
	 * @return int Exit code.
	 */
	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Flussonic]');

		$rTimeout = 300;
		set_time_limit($rTimeout);
		ini_set('max_execution_time', (string) $rTimeout);

		$rForce = in_array('--force', $rArgs, true);
		$rImport = in_array('--import', $rArgs, true);
		$rServerID = 0;

		foreach ($rArgs as $rArg) {
			if (is_numeric($rArg)) {
				$rServerID = (int) $rArg;
				break;
			}
		}

		if ($rServerID > 0) {
			$rServer = FlussonicServerService::getById($rServerID);

			if ($rServer === null) {
				echo 'Flussonic server ' . $rServerID . ' not found.' . "\n";

				return 1;
			}

			$rResult = FlussonicSyncService::syncServer($rServer);
			$this->report($rResult);

			if ($rImport && $rResult['ok']) {
				$rImported = FlussonicSyncService::importStreams($rServer, [], true);
				echo '  imported ' . $rImported['imported'] . ', skipped ' . $rImported['skipped'] . "\n";
			}

			return $rResult['ok'] ? 0 : 1;
		}

		$rResults = FlussonicSyncService::syncAll($rForce);

		if ($rResults === []) {
			echo "Nothing to do — no enabled Flussonic server is due for a sync.\n";

			return 0;
		}

		$rFailed = 0;

		foreach ($rResults as $rResult) {
			$this->report($rResult);

			if (!$rResult['ok']) {
				$rFailed++;
			}
		}

		return $rFailed > 0 ? 1 : 0;
	}

	/**
	 * Print a one-line summary for a server sync.
	 *
	 * @param array<string,mixed> $rResult Result from FlussonicSyncService.
	 * @return void
	 */
	private function report(array $rResult): void {
		if (!$rResult['ok']) {
			echo '[FAIL] ' . $rResult['server'] . ': ' . $rResult['error'] . "\n";

			return;
		}

		echo '[ OK ] ' . $rResult['server'] . ': ' . $rResult['found'] . ' stream(s), '
			. $rResult['new'] . ' new, ' . $rResult['removed'] . ' gone, '
			. $rResult['imported'] . ' imported' . "\n";
	}
}
