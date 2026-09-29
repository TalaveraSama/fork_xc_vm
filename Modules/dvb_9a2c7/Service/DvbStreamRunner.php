<?php

namespace XcVm\Module\Dvb\Service;

use XcVm\Core\Process\ProcessManager;

/**
 * DvbStreamRunner — keeps one DVBlast per transponder alive on the tuner node.
 *
 * DVBlast is the right shape for this job: it tunes a frontend once and
 * demultiplexes every service on that carrier to its own UDP address. Eight
 * tuners therefore become eight processes, not one per channel, and a
 * transponder carrying twenty services costs exactly one tuner.
 *
 * Like DvbScanService this only ever runs on the machine holding the card.
 *
 * ## Two dialects, one card
 *
 * Scanning uses dvbv5 and streaming uses DVBlast, and their command-line
 * conventions disagree in ways that fail silently rather than loudly:
 *
 *   - DiSEqC: dvbv5 counts satellites from 0, DVBlast from 1. Feeding one
 *     tool the other tool's number tunes a different satellite and reports a
 *     perfectly healthy lock on the wrong sky.
 *   - FEC: dvbv5 says `3/4`, DVBlast says `34`, and "auto" is `999`.
 *   - Modulation: dvbv5 says `PSK/8`, DVBlast says `psk_8`.
 *   - Roll-off: DVBlast spells auto `0`, not `AUTO`.
 *
 * All four are translated in this class, and nowhere else.
 *
 * ## Two things DVBlast cannot do
 *
 * It has no multistream (ISI/PLS) option, and it assumes a universal LNB
 * (9750/10600 with the switch at 11700) with no way to state another local
 * oscillator. Both are reported as an explicit error instead of being tuned
 * wrong, because a mis-tuned carrier looks exactly like bad weather.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbStreamRunner {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** Milliseconds DVBlast waits for a lock before complaining. */
	private const LOCK_TIMEOUT = 8000;

	/**
	 * Start (or restart) the DVBlast feeding one transponder.
	 *
	 * @param array $rTransponder Row from `dvb_transponders`.
	 * @return array{status:bool,message:string}
	 */
	public static function start(array $rTransponder) {
		$rID       = (int) $rTransponder['id'];
		$rServices = self::outputs($rID);

		if (empty($rServices)) {
			self::stop($rTransponder);

			return ['status' => true, 'message' => 'No services imported from this transponder; nothing to stream.'];
		}

		$rBlocker = self::unsupported($rTransponder);

		if ($rBlocker !== null) {
			return ['status' => false, 'message' => $rBlocker];
		}

		$rBinary = DvbScanService::locateBinary('dvblast');

		if ($rBinary === null) {
			return ['status' => false, 'message' => 'dvblast not found on this node. Install it (apt-get install dvblast).'];
		}

		$rAdapter = DvbAdapterService::pick($rTransponder);

		if ($rAdapter === null) {
			if (!empty($rTransponder['adapter_id'])) {
				$rPinned = DvbAdapterService::find((int) $rTransponder['adapter_id']);

				if ($rPinned !== null && !empty($rPinned['in_use_by'])) {
					return [
						'status'  => false,
						'message' => 'This transponder is pinned to adapter ' . (int) $rPinned['adapter_num']
							. ', which transponder ' . (int) $rPinned['in_use_by'] . ' is already using.'
							. ' Pin it to a different tuner, or set it to "Any free tuner".',
					];
				}
			}

			return ['status' => false, 'message' => 'No tuner available on this node. Run Discover adapters first.'];
		}

		// A live stream must not be torn down and rebuilt when nothing about it
		// changed: that is a visible glitch for every subscriber watching a
		// channel on this carrier. Only restart when the output set actually
		// differs from what the running process was given.
		$rConfig = self::renderConfig($rServices);
		$rPath   = self::configPath($rID);
		$rSame   = is_file($rPath) && file_get_contents($rPath) === $rConfig;

		if ($rSame && self::isRunning($rID)) {
			return ['status' => true, 'message' => 'Already streaming ' . count($rServices) . ' service(s), unchanged.'];
		}

		self::stop($rTransponder);

		$rWhy = self::ensureWorkDir();

		if ($rWhy !== null) {
			return ['status' => false, 'message' => 'Could not write the DVBlast config to ' . $rPath . '. ' . $rWhy];
		}

		if (file_put_contents($rPath, $rConfig) === false) {
			return [
				'status'  => false,
				'message' => 'Could not write the DVBlast config to ' . $rPath . '. '
					. DvbScanService::describePath(dirname($rPath)),
			];
		}

		$rCommand = self::buildCommand($rBinary, $rTransponder, $rAdapter, $rPath, $rServices);
		$rLog     = self::logPath($rID);

		// setsid detaches the process group so it survives this cron run.
		@shell_exec('setsid ' . $rCommand . ' >> ' . escapeshellarg($rLog) . ' 2>&1 & echo $! > ' . escapeshellarg(self::pidPath($rID)));

		// DVBlast needs a moment to open the frontend; checking instantly would
		// report success for a process that is about to die on "device busy".
		sleep(2);

		if (!self::isRunning($rID)) {
			return ['status' => false, 'message' => self::explainFailure($rLog)];
		}

		DvbAdapterService::claim((int) $rAdapter['id'], $rID);

		return [
			'status'  => true,
			'message' => sprintf(
				'Streaming %d service(s) from adapter %d.',
				count($rServices),
				(int) $rAdapter['adapter_num']
			),
		];
	}

	/**
	 * Stop the DVBlast feeding one transponder.
	 *
	 * @param array $rTransponder Row from `dvb_transponders`.
	 * @return array{status:bool,message:string}
	 */
	public static function stop(array $rTransponder) {
		$rID  = (int) $rTransponder['id'];
		$rPid = self::readPid($rID);

		if ($rPid > 0 && ProcessManager::isRunning($rPid, 'dvblast')) {
			// TERM first: DVBlast closes the frontend cleanly, which matters
			// because a frontend left open by a killed process stays busy until
			// the driver notices.
			//
			// Signals are numeric on purpose. SIGTERM and SIGKILL are defined by
			// pcntl, not by posix, so naming them would make this line a fatal
			// error on a build without that extension — while killing a tuner
			// process is exactly the code path that must never fail.
			ProcessManager::kill($rPid, 15);

			for ($rWait = 0; $rWait < 10; $rWait++) {
				usleep(200000);

				if (!ProcessManager::isRunning($rPid, 'dvblast')) {
					break;
				}
			}

			if (ProcessManager::isRunning($rPid, 'dvblast')) {
				ProcessManager::kill($rPid, 9);
			}
		}

		@unlink(self::pidPath($rID));

		if (!empty($rTransponder['adapter_id'])) {
			DvbAdapterService::claim((int) $rTransponder['adapter_id'], null);
		}

		self::db()->query('UPDATE `dvb_adapters` SET `in_use_by` = NULL WHERE `in_use_by` = ?;', $rID);

		return ['status' => true, 'message' => 'Stopped.'];
	}

	/**
	 * Bring reality in line with intent for every transponder on this node.
	 *
	 * Called on every cron tick, so it has to stay cheap: one indexed SELECT,
	 * then a PID check per transponder that is supposed to be streaming.
	 *
	 * @param int $rServerID This node.
	 * @return array{started:int,stopped:int,failed:int,messages:string[]}
	 */
	public static function supervise($rServerID) {
		$db = self::db();

		$db->query(
			'SELECT * FROM `dvb_transponders` WHERE `server_id` = ? AND (`streaming` = 1 OR `stream_status` = \'running\');',
			(int) $rServerID
		);

		$rRows    = $db->num_rows() > 0 ? $db->get_rows() : [];
		// Carrying the reasons out of here matters: record() files them in
		// stream_message, but an operator running cron:dvb by hand sees only
		// the counters, and "1 failed" is not a diagnosis.
		$rCounts  = ['started' => 0, 'stopped' => 0, 'failed' => 0, 'messages' => []];

		foreach ($rRows as $rTransponder) {
			$rID = (int) $rTransponder['id'];

			self::trimLog($rID);

			if (empty($rTransponder['streaming'])) {
				self::stop($rTransponder);
				self::record($rID, 'stopped', 'Stopped by request.');
				$rCounts['stopped']++;
				continue;
			}

			if (self::isRunning($rID)) {
				// Touch the heartbeat so the UI can tell "running" from
				// "nobody has looked at this since the node rebooted".
				$db->query('UPDATE `dvb_transponders` SET `stream_checked` = ? WHERE `id` = ?;', time(), $rID);
				continue;
			}

			$rResult = self::start($rTransponder);
			self::record($rID, $rResult['status'] ? 'running' : 'error', $rResult['message']);

			if ($rResult['status']) {
				$rCounts['started']++;
			} else {
				$rCounts['failed']++;
				$rCounts['messages'][] = 'transponder ' . $rID
					. ' (' . trim((string) ($rTransponder['name'] ?? '')) . '): '
					. $rResult['message'];
			}
		}

		return $rCounts;
	}

	/**
	 * Write the streaming state back to the transponder row.
	 *
	 * @param int    $rID      Transponder id.
	 * @param string $rStatus  stopped | running | error.
	 * @param string $rMessage Detail.
	 * @return void
	 */
	public static function record($rID, $rStatus, $rMessage) {
		self::db()->query(
			'UPDATE `dvb_transponders` SET `stream_status` = ?, `stream_message` = ?, `stream_checked` = ? WHERE `id` = ?;',
			$rStatus,
			substr((string) $rMessage, 0, 4000),
			time(),
			(int) $rID
		);
	}

	/**
	 * Is the DVBlast for this transponder alive?
	 *
	 * @param int $rID Transponder id.
	 * @return bool
	 */
	public static function isRunning($rID) {
		$rPid = self::readPid($rID);

		return $rPid > 0 && ProcessManager::isRunning($rPid, 'dvblast');
	}

	/**
	 * Services on this transponder that have been imported as panel streams.
	 *
	 * Only imported services are streamed. Fanning out everything a scan found
	 * would waste bandwidth on channels nobody asked for, and on a busy
	 * transponder that is most of them.
	 *
	 * @param int $rTransponderID Transponder id.
	 * @return array<int,array<string,mixed>>
	 */
	private static function outputs($rTransponderID) {
		$db = self::db();

		$db->query(
			'SELECT * FROM `dvb_services`
			 WHERE `transponder_id` = ? AND `stream_id` IS NOT NULL
			   AND `output_port` IS NOT NULL AND `output_port` > 0
			 ORDER BY `output_port` ASC;',
			(int) $rTransponderID
		);

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * Render the DVBlast config file.
	 *
	 * One line per service: `<ip>:<port> <always_on> <service_id>`.
	 *
	 * @param array $rServices Imported services.
	 * @return string
	 */
	public static function targetPort(array $rService) {
		return self::decrypting($rService)
			? (int) $rService['enc_port']
			: (int) $rService['output_port'];
	}

	/**
	 * Does this service go through tsdecrypt?
	 *
	 * Both halves are required. A CAMD with no enc_port allocated cannot be
	 * routed anywhere, and silently sending it to output_port would put a
	 * scrambled stream on the channel with nothing to explain it.
	 *
	 * @param array $rService Service row.
	 * @return bool
	 */
	public static function decrypting(array $rService) {
		return !empty($rService['camd_id']) && !empty($rService['enc_port']);
	}

	/**
	 * Render the DVBlast channel config.
	 *
	 * @param array $rServices Service rows.
	 * @return string
	 */
	public static function renderConfig(array $rServices) {
		$rLines = [
			'# Generated by the XC_VM dvb module. Edits here are overwritten.',
			'# <output address> <always on> <service id>',
		];

		foreach ($rServices as $rService) {
			// An encrypted service with a CAMD assigned is handed to tsdecrypt
			// instead of straight to the panel: DVBlast writes it to enc_port,
			// tsdecrypt reads that and writes the clear stream to output_port.
			// output_port is therefore always what the channel reads, whether
			// or not decryption is in play, so assigning a CAMD never has to
			// rewrite streams.stream_source.
			$rPort = self::targetPort($rService);

			$rLines[] = sprintf(
				'%s:%d 1 %d # %s%s',
				$rService['output_ip'],
				$rPort,
				(int) $rService['service_id'],
				str_replace(["\n", "\r"], ' ', (string) $rService['name']),
				($rPort === (int) $rService['output_port']) ? '' : ' -> tsdecrypt'
			);
		}

		return implode("\n", $rLines) . "\n";
	}

	/**
	 * Assemble the DVBlast command line.
	 *
	 * @param string $rBinary Absolute path to dvblast.
	 * @param array  $rT      Transponder row.
	 * @param array  $rA      Adapter row.
	 * @param string $rConfig Config file path.
	 * @return string
	 */
	public static function buildCommand($rBinary, array $rT, array $rA, $rConfig, array $rServices = []) {
		$rDelsys = strtoupper((string) $rT['delivery_system']);
		$rArgs   = [
			escapeshellarg($rBinary),
			'-q',
			// Raw UDP rather than RTP: ffmpeg reads udp:// without extra
			// demuxer hints, and some set-top boxes cannot cope with RTP here.
			'-U',
			// Build the mandatory DVB tables so each output is a valid TS on
			// its own rather than a PID soup that only ffmpeg can guess at.
			'-C',
			'-e',
			'-a ' . (int) $rA['adapter_num'],
			'-n ' . (int) $rA['frontend_num'],
			'-f ' . (int) $rT['frequency'],
			'-O ' . self::LOCK_TIMEOUT,
			'-c ' . escapeshellarg($rConfig),
		];

		// DVBlast strips the conditional access tables by default, which is
		// right up until something downstream has to decrypt. tsdecrypt gets
		// its control words by sending the ECMs to the card server, so if any
		// service on this carrier is being decrypted the ECM and EMM streams
		// have to survive the remux. Without these two flags tsdecrypt starts
		// cleanly, connects to the CAMD, and then never receives a single ECM.
		$rAnyDecrypt = false;
		$rAnyEmm     = false;

		foreach ($rServices as $rCheck) {
			if (!self::decrypting($rCheck)) {
				continue;
			}

			$rAnyDecrypt = true;
			$rCheckCamd  = DvbCamdService::find((int) $rCheck['camd_id']);

			if ($rCheckCamd !== null && !empty($rCheckCamd['emm'])) {
				$rAnyEmm = true;
				break;
			}
		}

		if ($rAnyDecrypt) {
			$rArgs[] = '-Y';
		}

		// EMM passthrough only when a CAMD profile actually asked for it.
		// Passing it unconditionally pushes every entitlement message on the
		// mux into every tsdecrypt: one carrier measured 76,603 EMMs in sixty
		// seconds, which overflowed tsdecrypt's queue, drew "EMM rejected by
		// card" from the server, and delayed control words by up to eighteen
		// seconds. The picture stutters and the cause looks like a weak
		// signal. A card with no AU rights cannot use them at all.
		if ($rAnyEmm) {
			$rArgs[] = '-W';
		}

		if (in_array($rDelsys, ['DVBS', 'DVBS2'], true)) {
			$rArgs[] = '-s ' . (int) $rT['symbol_rate'];
			$rArgs[] = '-v ' . self::voltage((string) $rT['polarization']);
			$rArgs[] = '-F ' . self::fec((string) $rT['inner_fec']);

			// DiSEqC is 1-based here and 0-based in dvbv5. Passing the stored
			// value straight through is correct for DVBlast precisely because
			// DvbScanService subtracts one for the scanner.
			if ((int) $rT['diseqc'] > 0) {
				$rArgs[] = '-S ' . (int) $rT['diseqc'];
			}

			if ($rDelsys === 'DVBS2') {
				$rArgs[] = '-m ' . self::modulation((string) $rT['modulation']);
				$rArgs[] = '-R ' . self::rolloff((string) $rT['rolloff']);
				$rArgs[] = '-P ' . self::pilot((string) $rT['pilot']);
			}
		} else {
			if ((int) $rT['bandwidth'] > 0) {
				$rArgs[] = '-b ' . (int) round($rT['bandwidth'] / 1000000);
			}

			$rArgs[] = '-m ' . self::modulation((string) $rT['modulation']);

			if ((int) $rT['symbol_rate'] > 0 && strpos($rDelsys, 'DVBC') === 0) {
				$rArgs[] = '-s ' . (int) $rT['symbol_rate'];
			}
		}

		return implode(' ', $rArgs);
	}

	/**
	 * Refuse configurations DVBlast cannot honour.
	 *
	 * @param array $rT Transponder row.
	 * @return string|null Reason, or null when it is streamable.
	 */
	private static function unsupported(array $rT) {
		if ((int) $rT['isi'] >= 0) {
			return 'This is a multistream carrier (ISI ' . (int) $rT['isi'] . '). DVBlast has no multistream option, so it can scan but not stream. Use a non-multistream carrier for these channels.';
		}

		$rLnb = strtoupper((string) $rT['lnb_type']);

		if (in_array(strtoupper((string) $rT['delivery_system']), ['DVBS', 'DVBS2'], true) && $rLnb !== 'UNIVERSAL') {
			return 'DVBlast assumes a universal LNB (9750/10600, switch at 11700) and cannot be told another local oscillator, but this transponder is set to ' . $rLnb . '. Scanning works; streaming would tune the wrong frequency.';
		}

		return null;
	}

	/**
	 * LNB voltage for a polarization.
	 *
	 * 18 V selects horizontal and left-hand circular, 13 V vertical and
	 * right-hand circular.
	 *
	 * @param string $rPolarization H, V, L or R.
	 * @return int
	 */
	private static function voltage($rPolarization) {
		return in_array(strtoupper(substr($rPolarization . 'H', 0, 1)), ['V', 'R'], true) ? 13 : 18;
	}

	/**
	 * Translate a dvbv5 FEC into DVBlast's spelling.
	 *
	 * @param string $rFec e.g. "3/4".
	 * @return int
	 */
	private static function fec($rFec) {
		$rDigits = preg_replace('#[^0-9]#', '', (string) $rFec);

		return ($rDigits === '') ? 999 : (int) $rDigits;
	}

	/**
	 * Translate a dvbv5 modulation into DVBlast's spelling.
	 *
	 * @param string $rModulation e.g. "PSK/8".
	 * @return string
	 */
	private static function modulation($rModulation) {
		$rMap = [
			'QPSK'     => 'qpsk',
			'PSK/8'    => 'psk_8',
			'QAM/16'   => 'qam_16',
			'QAM/32'   => 'qam_32',
			'QAM/64'   => 'qam_64',
			'QAM/128'  => 'qam_128',
			'QAM/256'  => 'qam_256',
			'QAM/AUTO' => 'qam_auto',
		];

		$rKey = strtoupper(trim((string) $rModulation));

		return $rMap[$rKey] ?? 'qpsk';
	}

	/**
	 * Translate a roll-off label into DVBlast's spelling, where 0 means auto.
	 *
	 * @param string $rRolloff AUTO, 35, 25 or 20.
	 * @return int
	 */
	private static function rolloff($rRolloff) {
		$rDigits = preg_replace('#[^0-9]#', '', (string) $rRolloff);

		return in_array($rDigits, ['35', '25', '20'], true) ? (int) $rDigits : 0;
	}

	/**
	 * Translate a pilot setting, where -1 means auto.
	 *
	 * @param string $rPilot AUTO, ON or OFF.
	 * @return int
	 */
	private static function pilot($rPilot) {
		switch (strtoupper(trim((string) $rPilot))) {
			case 'ON':
				return 1;

			case 'OFF':
				return 0;

			default:
				return -1;
		}
	}

	/**
	 * Turn a dead DVBlast into something an operator can act on.
	 *
	 * @param string $rLogPath Log file.
	 * @return string
	 */
	private static function explainFailure($rLogPath) {
		$rLog = is_file($rLogPath) ? (string) @shell_exec('tail -n 20 ' . escapeshellarg($rLogPath)) : '';

		if (stripos($rLog, 'Device or resource busy') !== false) {
			return 'DVBlast could not open the frontend: it is busy. Another transponder or a scan is using that tuner.';
		}

		if (stripos($rLog, 'Permission denied') !== false) {
			return 'Permission denied on the tuner device. Add the xc_vm user to the `video` group.';
		}

		if (stripos($rLog, 'unable to tune') !== false || stripos($rLog, 'frontend has no lock') !== false) {
			return 'DVBlast tuned but never locked. The settings that scan fine may still be wrong for streaming if the LNB is not universal.';
		}

		return 'DVBlast exited immediately. Last log lines: ' . trim(substr($rLog, -400));
	}

	/**
	 * PID recorded for a transponder's DVBlast.
	 *
	 * @param int $rID Transponder id.
	 * @return int
	 */
	private static function readPid($rID) {
		$rPath = self::pidPath($rID);

		return is_file($rPath) ? (int) trim((string) file_get_contents($rPath)) : 0;
	}

	/**
	 * Make sure the scratch directory exists.
	 *
	 * @return string|null Null when usable, otherwise why not.
	 */
	/** Bytes of DVBlast log to keep before trimming. */
	private const LOG_CAP = 4194304;

	/**
	 * Stop a DVBlast log from filling the disk.
	 *
	 * DVBlast writes one "couldn't writev ... Connection refused" per packet
	 * per port with no reader, and a transponder with 21 services and nothing
	 * consuming them produced 156 MB in about five minutes. That is a disk
	 * outage waiting to happen on a box that also stores recordings, so the
	 * supervisor trims on every pass.
	 *
	 * Safe against the running process: dvblast holds the file O_APPEND, so
	 * it keeps writing at the new end rather than at a stale offset.
	 *
	 * @param int $rID Transponder id.
	 * @return void
	 */
	private static function trimLog($rID) {
		$rPath = self::logPath($rID);

		if (!is_file($rPath) || (int) @filesize($rPath) < self::LOG_CAP) {
			return;
		}

		$rTail = (string) @shell_exec('tail -c 65536 ' . escapeshellarg($rPath));

		@file_put_contents($rPath, $rTail);
	}

	private static function ensureWorkDir() {
		$rPath   = self::workDir();
		$rParent = dirname(rtrim($rPath, '/'));

		if (!is_dir($rPath) && !@mkdir($rPath, 0755, true) && !is_dir($rPath)) {
			return DvbScanService::describePath($rParent);
		}

		if (!is_writable($rPath)) {
			return DvbScanService::describePath($rPath);
		}

		return null;
	}

	/**
	 * Scratch directory shared with DvbScanService.
	 *
	 * @return string Path with trailing slash.
	 */
	private static function workDir() {
		$rBase = defined('CACHE_TMP_PATH') ? CACHE_TMP_PATH : sys_get_temp_dir() . '/';

		return rtrim($rBase, '/') . '/dvb/';
	}

	/**
	 * Path of the generated DVBlast config.
	 *
	 * @param int $rID Transponder id.
	 * @return string
	 */
	public static function configPath($rID) {
		return self::workDir() . 'tp' . (int) $rID . '.conf';
	}

	/**
	 * Path of the PID file.
	 *
	 * @param int $rID Transponder id.
	 * @return string
	 */
	private static function pidPath($rID) {
		return self::workDir() . 'tp' . (int) $rID . '.pid';
	}

	/**
	 * Path of the DVBlast log.
	 *
	 * @param int $rID Transponder id.
	 * @return string
	 */
	private static function logPath($rID) {
		return self::workDir() . 'tp' . (int) $rID . '.log';
	}
}
