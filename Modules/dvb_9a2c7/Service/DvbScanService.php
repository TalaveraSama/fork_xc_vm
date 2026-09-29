<?php

namespace XcVm\Module\Dvb\Service;

/**
 * DvbScanService — tunes one transponder and returns the services on it.
 *
 * This is the only class in the module that touches hardware, and it only ever
 * runs on the node that physically holds the card (DvbCronJob calls it after
 * claiming a job addressed to its own SERVER_ID). The panel never calls it.
 *
 * ## How the scan actually works
 *
 * `dvbv5-scan` from v4l-utils takes an "initial tuning file" describing one or
 * more transponders, tunes each, reads the PSI tables (PAT, PMT, SDT) and
 * writes a channel file naming every service it found. That is precisely the
 * operation the operator wants: type a frequency, get a channel list. So the
 * work here is (1) render the stored transponder row as an initial tuning
 * file, (2) run the scanner, (3) parse its output back into rows.
 *
 * We do not reimplement tuning or section parsing in PHP. The kernel owns the
 * frontend and libdvbv5 owns the tables; both are far better at it than we
 * would be, and a TBS6909X exposes a perfectly standard frontend precisely so
 * that standard tools work.
 *
 * ## Units, because they are the usual source of "no lock"
 *
 * Satellite frequencies and symbol rates go in kHz and Sym/s respectively, and
 * the frequency is the *downlink* frequency (11778000), not the L-band IF —
 * dvbv5 does the LNB subtraction itself once told the LNB type. Terrestrial
 * and cable frequencies go in Hz. `dvb_transponders.frequency` stores whichever
 * of the two the delivery system implies, so nothing is converted here.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbScanService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** Seconds a single-transponder scan is allowed to take before we give up. */
	private const SCAN_TIMEOUT = 120;

	/** Delivery systems that are satellite, i.e. need LNB and polarization. */
	private const SATELLITE = ['DVBS', 'DVBS2', 'TURBO', 'ISDBS'];

	/**
	 * Scan one transponder.
	 *
	 * @param array $rTransponder Row from `dvb_transponders`.
	 * @param array $rAdapter     Row from `dvb_adapters` to tune with.
	 * @return array{status:bool,error:string,services:array,signal:array,log:string}
	 */
	/** Seconds to hold the frontend open while sampling the demodulator. */
	private const SIGNAL_SECONDS = 3;

	public static function scan(array $rTransponder, array $rAdapter): array {
		$rFail = function ($rError, $rLog = '') {
			return ['status' => false, 'error' => $rError, 'services' => [], 'signal' => [], 'log' => $rLog];
		};

		$rScanner = self::locateBinary('dvbv5-scan');

		if ($rScanner === null) {
			return $rFail('dvbv5-scan not found on this node. Install v4l-utils (apt-get install dvb-tools).');
		}

		$rWorkDir = self::workDir();

		if ($rWorkDir === null) {
			return $rFail('Unable to create the DVB scratch directory.');
		}

		$rStamp   = (int) $rTransponder['id'] . '_' . getmypid();
		$rInPath  = $rWorkDir . 'scan_' . $rStamp . '.init';
		$rOutPath = $rWorkDir . 'scan_' . $rStamp . '.conf';
		$rLogPath = $rWorkDir . 'scan_' . $rStamp . '.log';

		if (file_put_contents($rInPath, self::buildInitialFile($rTransponder)) === false) {
			return $rFail('Unable to write the initial tuning file to ' . $rInPath . '.');
		}

		$rCommand = self::buildCommand($rScanner, $rTransponder, $rAdapter, $rInPath, $rOutPath);

		// stderr carries the progress log (lock status, service names, provider
		// names); stdout is usually empty. Both are wanted.
		@shell_exec('timeout ' . self::SCAN_TIMEOUT . ' ' . $rCommand . ' > ' . escapeshellarg($rLogPath) . ' 2>&1');

		$rLog = is_file($rLogPath) ? (string) file_get_contents($rLogPath) : '';

		@unlink($rInPath);
		@unlink($rLogPath);

		if (!is_file($rOutPath)) {
			@unlink($rOutPath);

			return $rFail(self::explainFailure($rLog), $rLog);
		}

		$rServices = self::parseChannelFile((string) file_get_contents($rOutPath));
		@unlink($rOutPath);

		if (empty($rServices)) {
			return $rFail(self::explainFailure($rLog), $rLog);
		}

		return [
			'status'   => true,
			'error'    => '',
			'services' => self::enrichFromLog($rServices, $rLog),
			'signal'   => self::parseSignal($rLog),
			'log'      => $rLog,
		];
	}

	/**
	 * Take a live signal reading without scanning.
	 *
	 * dvbv5-scan only reports signal as a side effect of a successful scan, so
	 * when a transponder will not lock there is nothing to look at -- which is
	 * exactly the moment an operator needs a number. This tunes the carrier and
	 * reads the demodulator, then gets out of the way.
	 *
	 * Deliberately synchronous: a meter that answers once a minute through the
	 * job queue is useless for pointing a dish. The caller is responsible for
	 * only invoking this on the node that holds the card.
	 *
	 * @param array $rTransponder Row from `dvb_transponders`.
	 * @param array $rAdapter     Row from `dvb_adapters`.
	 * @return array{status:bool,error:string,signal:array,log:string}
	 */
	public static function measureSignal(array $rTransponder, array $rAdapter): array {
		$rFail = function ($rError, $rLog = '') {
			return ['status' => false, 'error' => $rError, 'signal' => [], 'log' => $rLog];
		};

		$rZap = self::locateBinary('dvbv5-zap');

		if ($rZap === null) {
			return $rFail('dvbv5-zap not found on this node. Install dvb-tools (apt-get install dvb-tools).');
		}

		$rWorkDir = self::workDir();

		if ($rWorkDir === null) {
			return $rFail('Unable to create the DVB scratch directory.');
		}

		$rStamp   = (int) $rTransponder['id'] . '_' . getmypid();
		$rInPath  = $rWorkDir . 'signal_' . $rStamp . '.conf';
		$rLogPath = $rWorkDir . 'signal_' . $rStamp . '.log';

		if (file_put_contents($rInPath, self::buildInitialFile($rTransponder)) === false) {
			return $rFail('Unable to write the tuning file to ' . $rInPath . '.');
		}

		$rCommand = self::buildZapCommand($rZap, $rTransponder, $rAdapter, $rInPath);

		// Hard-kill one second after the monitor window: dvbv5-zap holds the
		// frontend open, and a stray process would keep the tuner busy for
		// every later scan on this adapter.
		@shell_exec(
			'timeout -k 1 ' . (self::SIGNAL_SECONDS + 2) . ' ' . $rCommand
			. ' > ' . escapeshellarg($rLogPath) . ' 2>&1'
		);

		$rLog = is_file($rLogPath) ? (string) file_get_contents($rLogPath) : '';

		@unlink($rInPath);
		@unlink($rLogPath);

		$rSignal = self::parseSignal($rLog);

		if (!$rSignal['locked'] && $rSignal['strength'] === null && $rSignal['quality'] === null) {
			// Nothing at all came back: that is a tool or permission problem,
			// not a weak signal, and saying "0%" would be a lie.
			return $rFail(self::explainFailure($rLog), $rLog);
		}

		return ['status' => true, 'error' => '', 'signal' => $rSignal, 'log' => $rLog];
	}

	/**
	 * Assemble the dvbv5-zap command line for a signal reading.
	 *
	 * Monitor mode (-m) takes the section name of the tuning file rather than a
	 * service name, which is why buildInitialFile() writes a fixed [CHANNEL]
	 * header. -t bounds the run so the frontend is released promptly.
	 *
	 * @param string $rZap Absolute path to dvbv5-zap.
	 * @param array  $rT   Transponder row.
	 * @param array  $rA   Adapter row.
	 * @param string $rIn  Tuning file.
	 * @return string
	 */
	private static function buildZapCommand($rZap, array $rT, array $rA, $rIn) {
		$rArgs = [
			escapeshellarg($rZap),
			'-c ' . escapeshellarg($rIn),
			'-a ' . (int) ($rA['adapter_num'] ?? 0),
			'-f ' . (int) ($rA['frontend_num'] ?? 0),
			'-m',
			'-t ' . self::SIGNAL_SECONDS,
		];

		if (in_array(strtoupper((string) ($rT['delivery_system'] ?? '')), self::SATELLITE, true)) {
			$rArgs[] = '-l ' . escapeshellarg(self::lnbName((string) ($rT['lnb_type'] ?? 'UNIVERSAL')));

			// Same 1-based to 0-based shift as the scan path: dvbv5 counts
			// satellites from 0, the panel stores the port the way an operator
			// says it.
			if ((int) ($rT['diseqc'] ?? 0) > 0) {
				$rArgs[] = '-S ' . ((int) $rT['diseqc'] - 1);
			}
		}

		$rArgs[] = escapeshellarg('CHANNEL');

		return implode(' ', $rArgs);
	}

	/**
	 * Render a transponder row as a dvbv5 initial tuning file.
	 *
	 * Kept public so the admin UI can show the operator exactly what will be
	 * handed to the tuner — when a transponder will not lock, seeing the
	 * generated file is usually enough to spot the wrong polarization or a
	 * frequency typed in MHz.
	 *
	 * @param array $rT Row from `dvb_transponders`.
	 * @return string
	 */
	public static function buildInitialFile(array $rT): string {
		$rDelsys = strtoupper((string) ($rT['delivery_system'] ?? 'DVBS2'));
		$rLines  = ['[CHANNEL]'];

		$rLines[] = "\tDELIVERY_SYSTEM = " . $rDelsys;
		$rLines[] = "\tFREQUENCY = " . (int) $rT['frequency'];

		if (in_array($rDelsys, self::SATELLITE, true)) {
			$rLines[] = "\tPOLARIZATION = " . self::polarizationWord((string) ($rT['polarization'] ?? 'H'));
			$rLines[] = "\tSYMBOL_RATE = " . (int) $rT['symbol_rate'];
			$rLines[] = "\tINNER_FEC = " . self::enumOr($rT['inner_fec'] ?? '', 'AUTO');

			if ($rDelsys === 'DVBS2') {
				$rLines[] = "\tMODULATION = " . self::enumOr($rT['modulation'] ?? '', 'QPSK');
				$rLines[] = "\tROLLOFF = " . self::rolloffWord((string) ($rT['rolloff'] ?? 'AUTO'));
				$rLines[] = "\tPILOT = " . self::enumOr($rT['pilot'] ?? '', 'AUTO');

				// Multistream (DVB-S2X). -1 means "not a multistream carrier";
				// emitting STREAM_ID = -1 makes some drivers refuse the tune,
				// so the key is omitted entirely in that case.
				if ((int) ($rT['isi'] ?? -1) >= 0) {
					$rLines[] = "\tSTREAM_ID = " . (int) $rT['isi'];
				}
			}
		} elseif ($rDelsys === 'DVBC/ANNEX_A' || $rDelsys === 'DVBC/ANNEX_C') {
			$rLines[] = "\tSYMBOL_RATE = " . (int) $rT['symbol_rate'];
			$rLines[] = "\tMODULATION = " . self::enumOr($rT['modulation'] ?? '', 'QAM/64');
			$rLines[] = "\tINNER_FEC = " . self::enumOr($rT['inner_fec'] ?? '', 'NONE');
		} else {
			// Terrestrial and ATSC: bandwidth matters, symbol rate does not.
			if ((int) ($rT['bandwidth'] ?? 0) > 0) {
				$rLines[] = "\tBANDWIDTH_HZ = " . (int) $rT['bandwidth'];
			}

			$rLines[] = "\tMODULATION = " . self::enumOr($rT['modulation'] ?? '', 'QAM/AUTO');

			if ((int) ($rT['isi'] ?? -1) >= 0) {
				$rLines[] = "\tSTREAM_ID = " . (int) $rT['isi'];
			}
		}

		return implode("\n", $rLines) . "\n";
	}

	/**
	 * Assemble the dvbv5-scan command line.
	 *
	 * @param string $rScanner Absolute path to dvbv5-scan.
	 * @param array  $rT       Transponder row.
	 * @param array  $rA       Adapter row.
	 * @param string $rIn      Initial tuning file.
	 * @param string $rOut     Channel file to write.
	 * @return string
	 */
	private static function buildCommand($rScanner, array $rT, array $rA, $rIn, $rOut) {
		$rArgs = [
			escapeshellarg($rScanner),
			'-a ' . (int) ($rA['adapter_num'] ?? 0),
			'-f ' . (int) ($rA['frontend_num'] ?? 0),
			'-o ' . escapeshellarg($rOut),
			'-O DVBV5',
			// Read the NIT so the scan reports services the SDT alone misses,
			// but do not chase other transponders: the operator asked about
			// this one, and following the NIT across a whole satellite turns a
			// 30-second scan into a 40-minute one.
			'-t 2',
		];

		if (in_array(strtoupper((string) ($rT['delivery_system'] ?? '')), self::SATELLITE, true)) {
			$rArgs[] = '-l ' . escapeshellarg(self::lnbName((string) ($rT['lnb_type'] ?? 'UNIVERSAL')));

			// dvbv5 counts satellites from 0; the panel stores the DiSEqC port
			// the way an operator says it (0 = no switch, 1..4 = port A..D).
			if ((int) ($rT['diseqc'] ?? 0) > 0) {
				$rArgs[] = '-S ' . ((int) $rT['diseqc'] - 1);
			}
		}

		$rArgs[] = escapeshellarg($rIn);

		return implode(' ', $rArgs);
	}

	/**
	 * Parse a dvbv5 channel file into service rows.
	 *
	 * The format is INI-like: a bracketed section per service whose header is
	 * the service name, followed by tab-indented KEY = VALUE pairs. Service
	 * names legitimately contain brackets and equals signs, so the section
	 * header is matched only at the very start of a line and the value of a
	 * pair is split on the FIRST equals sign only.
	 *
	 * @param string $rText Channel file contents.
	 * @return array<int,array<string,mixed>>
	 */
	public static function parseChannelFile($rText) {
		$rServices = [];
		$rCurrent  = null;

		foreach (preg_split('/\r\n|\r|\n/', $rText) as $rLine) {
			if (preg_match('/^\[(.*)\]\s*$/', $rLine, $rMatch) === 1) {
				if ($rCurrent !== null) {
					$rServices[] = $rCurrent;
				}

				$rCurrent = ['name' => trim($rMatch[1]), 'keys' => []];
				continue;
			}

			if ($rCurrent === null || strpos($rLine, '=') === false) {
				continue;
			}

			$rParts = explode('=', $rLine, 2);
			$rKey   = strtoupper(trim($rParts[0]));
			$rValue = trim($rParts[1]);

			if ($rKey !== '') {
				$rCurrent['keys'][$rKey] = $rValue;
			}
		}

		if ($rCurrent !== null) {
			$rServices[] = $rCurrent;
		}

		$rOut = [];

		foreach ($rServices as $rService) {
			$rKeys = $rService['keys'];

			// A section without a SERVICE_ID is not a service — dvbv5 emits no
			// such thing today, but a truncated file (scan killed mid-write)
			// can leave a header with nothing under it.
			if (!isset($rKeys['SERVICE_ID'])) {
				continue;
			}

			$rAudio = isset($rKeys['AUDIO_PID']) ? preg_split('/\s+/', trim($rKeys['AUDIO_PID'])) : [];
			$rAudio = array_values(array_filter($rAudio, 'strlen'));

			$rOut[] = [
				'name'       => $rService['name'] !== '' ? $rService['name'] : ('Service ' . (int) $rKeys['SERVICE_ID']),
				'service_id' => (int) $rKeys['SERVICE_ID'],
				'pmt_pid'    => isset($rKeys['PID_02']) ? (int) $rKeys['PID_02'] : 0,
				'pcr_pid'    => isset($rKeys['PCR_PID']) ? (int) $rKeys['PCR_PID'] : 0,
				'video_pid'  => isset($rKeys['VIDEO_PID']) ? (int) $rKeys['VIDEO_PID'] : 0,
				'audio_pid'  => implode(',', $rAudio),
				// PID_09 is the CA PID list (CAT). Its presence means the
				// service carries conditional access, which is the closest
				// thing to an "encrypted" flag a channel file gives us. It is
				// a heuristic, not a promise: a free service on a scrambled
				// mux can inherit one.
				'encrypted'  => isset($rKeys['PID_09']) && trim($rKeys['PID_09']) !== '' ? 1 : 0,
				'provider'   => '',
			];
		}

		return $rOut;
	}

	/**
	 * Fill in provider names from the scanner's log.
	 *
	 * The DVBV5 channel format carries no provider field, but the scan log
	 * prints lines like `Service Foo, provider Bar` as it walks the SDT. Purely
	 * cosmetic, so a parse miss costs nothing.
	 *
	 * @param array  $rServices Parsed services.
	 * @param string $rLog      Scanner output.
	 * @return array
	 */
	private static function enrichFromLog(array $rServices, $rLog) {
		if (preg_match_all('/Service\s+(.+?),\s+provider\s+(.+?)\s*$/mi', $rLog, $rMatches, PREG_SET_ORDER) === 0) {
			return $rServices;
		}

		$rProviders = [];

		foreach ($rMatches as $rMatch) {
			$rProviders[trim($rMatch[1])] = trim(rtrim($rMatch[2], ':'));
		}

		foreach ($rServices as $rIndex => $rService) {
			if (isset($rProviders[$rService['name']])) {
				$rServices[$rIndex]['provider'] = $rProviders[$rService['name']];
			}
		}

		return $rServices;
	}

	/**
	 * Pull the last reported signal strength and quality out of the log.
	 *
	 * dvbv5 prints a status line such as
	 * `Lock   (0x1f) Signal= 75.29% C/N= 13.20dB UCB= 0`.
	 *
	 * @param string $rLog Scanner output.
	 * @return array{strength:?int,quality:?int,locked:bool}
	 */
	public static function parseSignal($rLog) {
		$rStrength = null;
		$rQuality  = null;

		$rStrengthDbm = null;
		$rCnrDb       = null;

		// Drivers report signal either as a percentage or as an absolute power
		// in dBm, and the dBm figure is negative. Match the unit explicitly so
		// "-33.40dBm" is never mistaken for 33% -- and note the leading -? on
		// every number here: without it a negative reading parses as its own
		// absolute value, which reads as a healthy signal.
		if (preg_match_all('/Signal\s*=\s*(-?[0-9.]+)\s*%/i', $rLog, $rMatches) > 0) {
			$rStrength = (int) max(0, min(100, round((float) end($rMatches[1]))));
		} elseif (preg_match_all('/Signal\s*=\s*(-?[0-9.]+)\s*dBm/i', $rLog, $rMatches) > 0) {
			$rStrengthDbm = (float) end($rMatches[1]);

			// Map dBm onto the same 0..100 bar. A Ku tuner sees roughly -75 dBm
			// at the noise floor and -25 dBm on a strong carrier, so that span
			// is stretched over the bar rather than inventing a percentage.
			$rStrength = (int) max(0, min(100, round(($rStrengthDbm + 75) * 2)));
		}

		// C/N is a dB figure, not a percentage. Clamping it to 0..100 keeps one
		// column usable for both without pretending the units are the same;
		// anything above 20 dB is excellent on satellite anyway. It can be
		// negative when the demodulator is not locked.
		if (preg_match_all('/C\/N\s*=\s*(-?[0-9.]+)\s*dB/i', $rLog, $rMatches) > 0) {
			$rCnrDb   = (float) end($rMatches[1]);
			$rQuality = (int) max(0, min(100, round($rCnrDb * 5)));
		}

		// "Lock" also appears inside the word "Unlock"/"unlocked", and dvbv5
		// prints the status flags as (0x1f) when locked. Require the flag word
		// at a boundary and not preceded by "un".
		$rLocked = preg_match('/(?<!un)\bLock/i', $rLog) === 1;

		$rBer = null;

		if (preg_match_all('/postBER\s*=\s*([0-9.]+(?:x10\^-?[0-9]+)?)/i', $rLog, $rMatches) > 0) {
			$rBer = (string) end($rMatches[1]);
		}

		$rUcb = null;

		if (preg_match_all('/UCB\s*=\s*([0-9]+)/i', $rLog, $rMatches) > 0) {
			$rUcb = (int) end($rMatches[1]);
		}

		return [
			'strength'     => $rStrength,
			'quality'      => $rQuality,
			'locked'       => $rLocked,
			'strength_dbm' => $rStrengthDbm,
			'cnr_db'       => $rCnrDb,
			'ber'          => $rBer,
			'ucb'          => $rUcb,
		];
	}

	/**
	 * Turn a failed scan into something an operator can act on.
	 *
	 * @param string $rLog Scanner output.
	 * @return string
	 */
	private static function explainFailure($rLog) {
		$rLog = trim($rLog);

		if ($rLog === '') {
			return 'The scanner produced no output at all — it was most likely killed by the ' . self::SCAN_TIMEOUT . 's timeout.';
		}

		if (stripos($rLog, 'No such file or directory') !== false && stripos($rLog, 'dvb') !== false) {
			return 'The tuner device does not exist. Check that the TBS driver is loaded (ls /dev/dvb) and that the adapter number is right.';
		}

		if (stripos($rLog, 'Device or resource busy') !== false) {
			return 'That frontend is busy — something else is already tuned to it (a running dvblast, or another scan). Free it and retry.';
		}

		if (stripos($rLog, 'Permission denied') !== false) {
			return 'Permission denied on the tuner device. The xc_vm user needs to be in the `video` group.';
		}

		if (stripos($rLog, 'Lock') === false) {
			return 'The tuner never locked. Verify frequency, polarization, symbol rate, the LNB type, and that the DiSEqC port points at the right satellite.';
		}

		return 'Locked, but no services were found. The transponder may be empty, or the FEC/modulation may be wrong for this carrier.';
	}

	/**
	 * Locate a helper binary without trusting $PATH.
	 *
	 * Cron runs with a minimal environment, so `which` alone is not enough; the
	 * usual install locations are checked first and $PATH is the fallback.
	 *
	 * @param string $rName Binary name.
	 * @return string|null Absolute path, or null when absent.
	 */
	public static function locateBinary($rName) {
		$rName = basename($rName);

		foreach (['/usr/bin/', '/usr/local/bin/', '/bin/', '/usr/sbin/'] as $rDir) {
			if (is_executable($rDir . $rName)) {
				return $rDir . $rName;
			}
		}

		$rFound = trim((string) @shell_exec('command -v ' . escapeshellarg($rName) . ' 2>/dev/null'));

		return ($rFound !== '' && is_executable($rFound)) ? $rFound : null;
	}

	/**
	 * Scratch directory for tuning and channel files.
	 *
	 * @return string|null Path with trailing slash, or null when unusable.
	 */
	private static function workDir() {
		$rBase = defined('CACHE_TMP_PATH') ? CACHE_TMP_PATH : sys_get_temp_dir() . '/';
		$rPath = rtrim($rBase, '/') . '/dvb/';

		if (!is_dir($rPath) && !@mkdir($rPath, 0755, true) && !is_dir($rPath)) {
			return null;
		}

		return is_writable($rPath) ? $rPath : null;
	}

	/**
	 * Map the stored single-letter polarization onto the dvbv5 keyword.
	 *
	 * @param string $rValue H, V, L or R.
	 * @return string
	 */
	private static function polarizationWord($rValue) {
		switch (strtoupper(substr(trim($rValue) . 'H', 0, 1))) {
			case 'V':
				return 'VERTICAL';

			case 'L':
				return 'LEFT';

			case 'R':
				return 'RIGHT';

			default:
				return 'HORIZONTAL';
		}
	}

	/**
	 * Map a roll-off label onto the dvbv5 keyword.
	 *
	 * @param string $rValue 35, 25, 20 or AUTO.
	 * @return string
	 */
	private static function rolloffWord($rValue) {
		switch (preg_replace('/[^0-9]/', '', $rValue)) {
			case '20':
				return '0.20';

			case '25':
				return '0.25';

			case '35':
				return '0.35';

			default:
				return 'AUTO';
		}
	}

	/**
	 * Accept only characters that are legal in a dvbv5 enum value.
	 *
	 * These reach a config file rather than a shell, so injection is not the
	 * risk; a stray character silently aborting the tune is.
	 *
	 * @param string $rValue    Candidate.
	 * @param string $rFallback Value to use when the candidate is empty.
	 * @return string
	 */
	private static function enumOr($rValue, $rFallback) {
		$rClean = strtoupper(trim((string) $rValue));
		$rClean = preg_replace('#[^A-Z0-9/._-]#', '', $rClean);

		return ($rClean === '') ? $rFallback : $rClean;
	}

	/**
	 * Constrain the LNB name to one dvbv5 knows.
	 *
	 * `dvb-fe-tool --list-lnb` prints the full catalogue; these are the ones an
	 * operator realistically uses. Anything unrecognised falls back to
	 * UNIVERSAL rather than failing the scan.
	 *
	 * @param string $rValue Stored LNB type.
	 * @return string
	 */
	private static function lnbName($rValue) {
		$rKnown = [
			'UNIVERSAL', 'EXTENDED', 'STANDARD', 'L10700', 'L10750', 'L11300',
			'ENHANCED', 'QPH031', 'C-BAND', 'C-MULT', 'DISHPRO', 'BIG_DISH_MONOPOINT',
			'BRASILSAT', 'GVT-BRASILSAT_OI', 'AMAZONAS', 'ASTRA', 'THOR',
		];

		$rClean = strtoupper(trim((string) $rValue));

		return in_array($rClean, $rKnown, true) ? $rClean : 'UNIVERSAL';
	}
}
