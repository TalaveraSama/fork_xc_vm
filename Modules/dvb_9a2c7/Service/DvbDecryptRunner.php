<?php

namespace XcVm\Module\Dvb\Service;

use XcVm\Core\Process\ProcessManager;

/**
 * DvbDecryptRunner — one tsdecrypt per encrypted service.
 *
 * tsdecrypt reads an MPEG transport stream over UDP, pulls the ECMs out of it,
 * asks a CAMD server for the control words over NEWCAMD (or CS378X), and writes
 * the descrambled stream back out over UDP. That makes it a drop-in stage in a
 * pipeline this module already has:
 *
 *   DVBlast  --udp:enc_port-->  tsdecrypt  --udp:output_port-->  ffmpeg
 *                                    |
 *                                    +-- newcamd TCP --> card server
 *
 * ## Why the panel channel always reads `output_port`
 *
 * The obvious design is one port per service, with the channel pointing at
 * DVBlast for free-to-air and at tsdecrypt for encrypted. It is wrong: the
 * moment an operator assigns or removes a CAMD, every affected channel's
 * `stream_source` has to be rewritten, and any that is missed plays silence
 * with no visible cause. Here `output_port` is *always* the address the channel
 * reads. Adding decryption inserts a stage behind it; removing decryption
 * points DVBlast back at the same port. `streams` is never touched.
 *
 * ## One process per encrypted channel
 *
 * Unavoidable, and worth stating plainly: DVBlast is one process per
 * transponder, but descrambling is per service, so twenty encrypted channels
 * means twenty tsdecrypt processes. They are small, but a CAMD line with a
 * session limit will notice.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbDecryptRunner {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Start the tsdecrypt for one service.
	 *
	 * @param array $rService Row from `dvb_services`.
	 * @param array $rCamd    Row from `dvb_camd`.
	 * @return array{status:bool,message:string}
	 */
	public static function start(array $rService, array $rCamd) {
		$rID = (int) $rService['id'];

		if (empty($rService['enc_port']) || empty($rService['output_port'])) {
			return ['status' => false, 'message' => 'This service has no port pair allocated. Re-import it, or assign the CAMD again.'];
		}

		$rBinary = DvbScanService::locateBinary('tsdecrypt');

		if ($rBinary === null) {
			return ['status' => false, 'message' => 'tsdecrypt not found on this node. Build it from georgi.unixsol.org/programs/tsdecrypt or install the package.'];
		}

		$rMissing = self::incomplete($rCamd);

		if ($rMissing !== null) {
			return ['status' => false, 'message' => $rMissing];
		}

		if (self::isRunning($rID)) {
			return ['status' => true, 'message' => 'Already decrypting.'];
		}

		$rWhy = self::ensureWorkDir();

		if ($rWhy !== null) {
			return ['status' => false, 'message' => 'Could not use the DVB scratch directory. ' . $rWhy];
		}

		// tsdecrypt daemonises itself and writes the pid file, so there is no
		// setsid and no shell backgrounding to get wrong here.
		$rCommand = self::buildCommand($rBinary, $rService, $rCamd);
		@shell_exec($rCommand . ' >> ' . escapeshellarg(self::logPath($rID)) . ' 2>&1');

		// Give it a moment to fail: a refused CAMD login or a port already in
		// use kills it immediately, and reporting success for a process that is
		// already gone is worse than reporting nothing.
		sleep(2);

		if (!self::isRunning($rID)) {
			return ['status' => false, 'message' => self::explainFailure($rID)];
		}

		return ['status' => true, 'message' => 'Decrypting via ' . $rCamd['name'] . ' (' . $rCamd['protocol'] . ').'];
	}

	/**
	 * Stop the tsdecrypt for one service.
	 *
	 * Takes an id rather than a row so it still works for a service that has
	 * just been deleted — the process has to be reaped either way.
	 *
	 * @param int $rServiceID `dvb_services` id.
	 * @return array{status:bool,message:string}
	 */
	public static function stop($rServiceID) {
		$rID  = (int) $rServiceID;
		$rPid = self::readPid($rID);

		if ($rPid > 0 && ProcessManager::isRunning($rPid, 'tsdecrypt')) {
			// Numeric signals: SIGTERM and SIGKILL come from pcntl, not posix,
			// so naming them would make this fatal on a build without it.
			ProcessManager::kill($rPid, 15);

			for ($rWait = 0; $rWait < 10; $rWait++) {
				usleep(200000);

				if (!ProcessManager::isRunning($rPid, 'tsdecrypt')) {
					break;
				}
			}

			if (ProcessManager::isRunning($rPid, 'tsdecrypt')) {
				ProcessManager::kill($rPid, 9);
			}
		}

		@unlink(self::pidPath($rID));

		return ['status' => true, 'message' => 'Stopped.'];
	}

	/**
	 * Reconcile every decryptor on this node.
	 *
	 * A service wants decrypting when it has a CAMD, has been imported, and its
	 * transponder is actually streaming — there is no point descrambling a feed
	 * that DVBlast is not producing.
	 *
	 * @param int $rServerID This node.
	 * @return array{started:int,stopped:int,failed:int}
	 */
	public static function supervise($rServerID) {
		$db = self::db();

		$db->query(
			'SELECT s.*, t.`streaming` AS `tp_streaming`, t.`stream_status` AS `tp_status`
			 FROM `dvb_services` s
			 INNER JOIN `dvb_transponders` t ON t.`id` = s.`transponder_id`
			 WHERE t.`server_id` = ? AND (s.`camd_id` IS NOT NULL OR s.`decrypt_status` = \'running\');',
			(int) $rServerID
		);

		$rRows   = $db->num_rows() > 0 ? $db->get_rows() : [];
		$rCounts = ['started' => 0, 'stopped' => 0, 'failed' => 0, 'messages' => []];
		$rKnown  = [];

		// Count live sessions per CAMD before starting anything. NEWCAMD lines
		// are sold with a session limit and descrambling is per service, so a
		// transponder carrying more encrypted channels than the account allows
		// gets the surplus rejected -- and a rejected tsdecrypt reconnects for
		// ever, which the server sees as a flood and may ban. Counting in a
		// separate pass matters: a row further down the list is already
		// holding a session when an earlier row asks for one.
		$rLive = [];

		foreach ($rRows as $rService) {
			if (!empty($rService['camd_id']) && self::isRunning((int) $rService['id'])) {
				$rKey         = (int) $rService['camd_id'];
				$rLive[$rKey] = ($rLive[$rKey] ?? 0) + 1;
			}
		}

		foreach ($rRows as $rService) {
			$rID          = (int) $rService['id'];
			$rKnown[$rID] = true;
			$rWants = !empty($rService['camd_id'])
				&& !empty($rService['stream_id'])
				&& !empty($rService['tp_streaming']);

			if (!$rWants) {
				if (self::isRunning($rID) || $rService['decrypt_status'] === 'running') {
					self::stop($rID);
					self::record($rID, 'off', 'Not decrypting.');
					$rCounts['stopped']++;
				}

				continue;
			}

			if (self::isRunning($rID)) {
				// Alive is not the same as working. A rejected NEWCAMD login
				// leaves tsdecrypt reconnecting for ever, and calling that
				// "running" is the same lie as a signal bar reading 0% when
				// nothing could be measured at all.
				$rTrouble = self::liveTrouble($rID);

				if ($rTrouble !== null) {
					self::record($rID, 'error', $rTrouble);
					$rCounts['failed']++;
				}

				continue;
			}

			$rCamd = DvbCamdService::find((int) $rService['camd_id']);

			if ($rCamd === null || empty($rCamd['enabled'])) {
				self::record($rID, 'error', 'Its CAMD server is missing or disabled.');
				$rCounts['failed']++;
				$rCounts['messages'][] = 'service ' . $rID . ': its CAMD server is missing or disabled.';
				continue;
			}

			$rCamdKey = (int) $rService['camd_id'];
			$rCap     = (int) ($rCamd['max_connections'] ?? 0);

			if ($rCap > 0 && ($rLive[$rCamdKey] ?? 0) >= $rCap) {
				self::record(
					$rID,
					'error',
					'Not started: ' . $rCamd['name'] . ' is capped at ' . $rCap
					. ' simultaneous connection(s) and they are all in use. Every encrypted'
					. ' channel needs its own CAMD session, so either raise the cap, raise the'
					. ' limit on the account, or decrypt fewer channels at once.'
				);
				$rCounts['failed']++;
				continue;
			}

			$rResult = self::start($rService, $rCamd);
			self::record($rID, $rResult['status'] ? 'running' : 'error', $rResult['message']);

			if ($rResult['status']) {
				$rLive[$rCamdKey] = ($rLive[$rCamdKey] ?? 0) + 1;
			} else {
				$rCounts['messages'][] = 'service ' . $rID . ': ' . $rResult['message'];
			}

			if ($rResult['status']) {
				$rCounts['started']++;
			} else {
				$rCounts['failed']++;
			}
		}

		$rCounts['stopped'] += self::reapOrphans($rKnown);

		return $rCounts;
	}

	/**
	 * Stop every decryptor belonging to one transponder, now.
	 *
	 * The supervisor would catch these on its next tick anyway, but a tick is
	 * up to a minute, and for that minute each tsdecrypt sits on a CAMD
	 * session decoding an input that no longer produces packets. Card server
	 * lines usually cap concurrent sessions, so that is a real cost.
	 *
	 * @param int $rTransponderID Transponder id.
	 * @return int How many were stopped.
	 */
	public static function stopForTransponder($rTransponderID) {
		$db = self::db();

		$db->query('SELECT `id` FROM `dvb_services` WHERE `transponder_id` = ?;', (int) $rTransponderID);

		$rStopped = 0;

		foreach ($db->num_rows() > 0 ? $db->get_rows() : [] as $rRow) {
			if (self::isRunning((int) $rRow['id'])) {
				self::stop((int) $rRow['id']);
				self::record((int) $rRow['id'], 'off', 'Its transponder stopped streaming.');
				$rStopped++;
			}
		}

		return $rStopped;
	}

	/**
	 * Reap decryptors whose service row no longer exists.
	 *
	 * Deleting a transponder cascades its services away, and a decryptor for a
	 * deleted service is unreachable through any query — the row that named it
	 * is gone. The PID files are the only remaining record, so they are the
	 * authority here.
	 *
	 * @param array<int,bool> $rKnown Service ids that legitimately have one.
	 * @return int How many were reaped.
	 */
	private static function reapOrphans(array $rKnown) {
		$rFiles = @glob(self::workDir() . 'cw*.pid');

		if (empty($rFiles)) {
			return 0;
		}

		$rReaped = 0;

		foreach ($rFiles as $rFile) {
			if (!preg_match('#/cw(\d+)\.pid$#', $rFile, $rMatch)) {
				continue;
			}

			$rID = (int) $rMatch[1];

			if (isset($rKnown[$rID])) {
				continue;
			}

			self::stop($rID);
			$rReaped++;
		}

		return $rReaped;
	}

	/**
	 * Write the decryption state back to the service row.
	 *
	 * @param int    $rID      Service id.
	 * @param string $rStatus  off | running | error.
	 * @param string $rMessage Detail.
	 * @return void
	 */
	public static function record($rID, $rStatus, $rMessage) {
		self::db()->query(
			'UPDATE `dvb_services` SET `decrypt_status` = ?, `decrypt_message` = ? WHERE `id` = ?;',
			$rStatus,
			substr((string) $rMessage, 0, 4000),
			(int) $rID
		);
	}

	/**
	 * Is the tsdecrypt for this service alive?
	 *
	 * @param int $rServiceID Service id.
	 * @return bool
	 */
	public static function isRunning($rServiceID) {
		$rPid = self::readPid($rServiceID);

		return $rPid > 0 && ProcessManager::isRunning($rPid, 'tsdecrypt');
	}

	/**
	 * Assemble the tsdecrypt command line.
	 *
	 * @param string $rBinary  Absolute path to tsdecrypt.
	 * @param array  $rService Service row.
	 * @param array  $rCamd    CAMD row.
	 * @return string
	 */
	public static function buildCommand($rBinary, array $rService, array $rCamd) {
		$rHost = trim((string) $rService['output_ip']) !== '' ? (string) $rService['output_ip'] : '127.0.0.1';
		$rID   = (int) $rService['id'];

		$rArgs = [
			escapeshellarg($rBinary),
			'-d ' . escapeshellarg(self::pidPath($rID)),
			'-F ' . escapeshellarg(self::logPath($rID)),
			// Ident shows up in the log lines, which is the difference between
			// a readable log and twenty interleaved ones.
			'-i ' . escapeshellarg(self::ident($rService)),
			'-I ' . escapeshellarg($rHost . ':' . (int) $rService['enc_port']),
			'-O ' . escapeshellarg($rHost . ':' . (int) $rService['output_port']),
			'-A ' . escapeshellarg(self::protocol((string) $rCamd['protocol'])),
			'-s ' . escapeshellarg(trim((string) $rCamd['host']) . ':' . (int) $rCamd['port']),
			'-U ' . escapeshellarg((string) $rCamd['username']),
			'-P ' . escapeshellarg((string) $rCamd['password']),
			// DVBlast already emits one service per port, so the input is SPTS.
			// Naming the service anyway is free insurance: without it tsdecrypt
			// falls back to "the last service listed in PAT".
			'-M ' . (int) $rService['service_id'],
		];

		if (self::protocol((string) $rCamd['protocol']) === 'NEWCAMD') {
			$rArgs[] = '-B ' . escapeshellarg(self::desKey((string) $rCamd['des_key']));
		}

		$rCaid = self::caid((string) $rCamd['caid']);

		if ($rCaid !== null) {
			$rArgs[] = '-C ' . escapeshellarg($rCaid);
		} else {
			$rArgs[] = '-c ' . escapeshellarg(self::caSystem((string) $rCamd['ca_system']));
		}

		if (!empty($rCamd['emm'])) {
			$rArgs[] = '-e';
		}

		if ((int) $rCamd['input_buffer'] > 0) {
			$rArgs[] = '-T ' . (int) $rCamd['input_buffer'];
		}

		if (!empty($rCamd['mute_on_error'])) {
			// Emit nothing rather than scrambled noise when there is no valid
			// code word. A channel that goes black is diagnosable; one that
			// shows digital mush gets blamed on the encoder.
			$rArgs[] = '-u';
		}

		return implode(' ', $rArgs);
	}

	/**
	 * Refuse a CAMD profile that cannot possibly connect.
	 *
	 * @param array $rCamd CAMD row.
	 * @return string|null Reason, or null when usable.
	 */
	private static function incomplete(array $rCamd) {
		if (trim((string) $rCamd['host']) === '') {
			return 'That CAMD server has no host set.';
		}

		if ((int) $rCamd['port'] <= 0) {
			return 'That CAMD server has no port set.';
		}

		if (trim((string) $rCamd['username']) === '') {
			return 'That CAMD server has no username set.';
		}

		if (self::protocol((string) $rCamd['protocol']) === 'NEWCAMD' && self::desKey((string) $rCamd['des_key']) === '') {
			return 'NEWCAMD needs a 28 hex character DES key, and this profile has none.';
		}

		return null;
	}

	/**
	 * Constrain the protocol to one tsdecrypt knows.
	 *
	 * @param string $rProtocol Stored value.
	 * @return string
	 */
	public static function protocol($rProtocol) {
		return (strtoupper(trim($rProtocol)) === 'CS378X') ? 'CS378X' : 'NEWCAMD';
	}

	/**
	 * Normalise the NEWCAMD DES key.
	 *
	 * Operators paste these with spaces or a 0x prefix; both are stripped. The
	 * key is 14 bytes, so 28 hex characters — anything else is rejected rather
	 * than padded, because a silently wrong key is indistinguishable from a
	 * wrong password in the server's reply.
	 *
	 * @param string $rKey Stored value.
	 * @return string Normalised key, or '' when unusable.
	 */
	public static function desKey($rKey) {
		$rClean = strtolower(preg_replace('#[^0-9a-fA-F]#', '', (string) $rKey));

		return (strlen($rClean) === 28) ? $rClean : '';
	}

	/**
	 * Normalise an explicit CAID.
	 *
	 * @param string $rCaid Stored value, e.g. "0963" or "0x0963".
	 * @return string|null Prefixed hex CAID, or null when not set.
	 */
	public static function caid($rCaid) {
		$rRaw = strtolower(trim((string) $rCaid));

		// Strip a 0x the operator typed, or its 0 survives the filter below
		// and "0x1802" becomes "0x01802". tsdecrypt parses that with
		// strtoul(base 0) and gets the right number anyway, but only by luck.
		if (strpos($rRaw, '0x') === 0) {
			$rRaw = substr($rRaw, 2);
		}

		$rClean = preg_replace('#[^0-9a-f]#', '', $rRaw);

		// The prefix is not decoration: strtoul with base 0 reads a bare 1802
		// as decimal, which is CAID 0x070a and matches nothing.
		return ($rClean === '') ? null : '0x' . str_pad($rClean, 4, '0', STR_PAD_LEFT);
	}

	/**
	 * Constrain the CA system to one tsdecrypt accepts.
	 *
	 * @param string $rSystem Stored value.
	 * @return string
	 */
	public static function caSystem($rSystem) {
		$rKnown = [
			'CONAX', 'CRYPTOWORKS', 'IRDETO', 'VIACCESS', 'MEDIAGUARD', 'SECA',
			'VIDEOGUARD', 'NDS', 'NAGRA', 'BULCRYPT', 'GRIFFIN', 'DGCRYPT', 'DRECRYPT',
		];

		$rClean = strtoupper(trim((string) $rSystem));

		return in_array($rClean, $rKnown, true) ? $rClean : 'CONAX';
	}

	/**
	 * Log ident, in tsdecrypt's preferred PROVIDER/CHANNEL shape.
	 *
	 * @param array $rService Service row.
	 * @return string
	 */
	private static function ident(array $rService) {
		$rProvider = trim((string) $rService['provider']);
		$rName     = trim((string) $rService['name']);

		$rClean = function ($rValue) {
			$rValue = preg_replace('#[^A-Za-z0-9 ._-]#', '', $rValue);

			return trim(preg_replace('#\s+#', ' ', (string) $rValue));
		};

		$rProvider = $rClean($rProvider) !== '' ? $rClean($rProvider) : 'dvb';
		$rName     = $rClean($rName) !== '' ? $rClean($rName) : ('service' . (int) $rService['service_id']);

		return $rProvider . '/' . $rName;
	}

	/**
	 * Turn a dead tsdecrypt into something an operator can act on.
	 *
	 * @param int $rServiceID Service id.
	 * @return string
	 */
	/**
	 * Diagnose a tsdecrypt that is running but not actually decrypting.
	 *
	 * explainFailure() only ever sees a process that died. The more common
	 * case is worse: the process lives, the CAMD server rejects every login,
	 * and the panel cheerfully reports "running" while the channel stays
	 * black. The raw log tail is always appended, because a guess about which
	 * signature matched is worth less than what the tool actually printed.
	 *
	 * @param int $rServiceID Service id.
	 * @return string|null Null when nothing looks wrong.
	 */
	private static function liveTrouble($rServiceID) {
		$rPath = self::logPath($rServiceID);

		if (!is_file($rPath)) {
			return null;
		}

		$rLog = (string) @shell_exec('tail -n 40 ' . escapeshellarg($rPath));

		if (trim($rLog) === '') {
			return null;
		}

		$rTail = ' Last log lines: ' . trim(substr($rLog, -400));

		// tsdecrypt prints both halves of the commonest misconfiguration:
		//   CAM | [newcamd] Card info: CAID 0x1802
		//   --- | ECM CAID: 0x187a (NAGRA)
		// A carrier can advertise several CA systems of the same family, and
		// naming the family with -c makes tsdecrypt take the last descriptor
		// in the PMT, which need not be the one the card holds. The server
		// then answers "Card was not able to decode the channel", which reads
		// like an entitlement problem on the provider's side and is not one.
		if (preg_match('/Card info:\s*CAID\s*(0x[0-9a-f]{1,4})/i', $rLog, $rCard) === 1
			&& preg_match('/ECM CAID:\s*(0x[0-9a-f]{1,4})/i', $rLog, $rEcm) === 1
			&& strcasecmp(trim($rCard[1]), trim($rEcm[1])) !== 0) {
			return 'CAID mismatch: the card server holds ' . $rCard[1]
				. ' but tsdecrypt is sending it ECMs for ' . $rEcm[1]
				. '. This carrier advertises more than one CA system, so choosing the CA system by'
				. ' name is not enough. Set the CAID field on this CAMD profile to ' . $rCard[1]
				. ' and the right ECM PID will be used.' . $rTail;
		}

		$rSignatures = [
			'no such user'  => 'The CAMD server has no such username.',
			'doesnt exist'  => 'The CAMD server has no such username.',
			'does not exist' => 'The CAMD server has no such username.',
			'access denied' => 'The CAMD server denied access to this user.',
			'login fail'    => 'The CAMD server rejected the login. For NEWCAMD a wrong DES key looks exactly like a wrong password, so check both.',
			'bad password'  => 'The CAMD server rejected the password.',
			'rejected'      => 'The CAMD server rejected this client.',
			'not able to decode' => 'The card server received the ECMs but could not decode them.'
				. ' Either the CAID being sent is not the one the card holds, or the account has no'
				. ' entitlement for this provider.',
		];

		foreach ($rSignatures as $rNeedle => $rWhy) {
			if (stripos($rLog, $rNeedle) !== false) {
				return $rWhy . $rTail;
			}
		}

		// A healthy session connects once and then talks ECM. A tail full of
		// connection attempts means it is being dropped as fast as it is made.
		if (preg_match_all('/connect/i', $rLog) >= 8) {
			return 'tsdecrypt keeps reconnecting to the CAMD server, so the session is being dropped as fast as it is opened.' . $rTail;
		}

		return null;
	}

	private static function explainFailure($rServiceID) {
		$rPath = self::logPath($rServiceID);
		$rLog  = is_file($rPath) ? (string) @shell_exec('tail -n 20 ' . escapeshellarg($rPath)) : '';

		if (stripos($rLog, 'Address already in use') !== false) {
			return 'That output port is already taken on this node. Another decryptor or stream is using it.';
		}

		if (stripos($rLog, 'Connection refused') !== false) {
			return 'The CAMD server refused the connection. Check the host and port.';
		}

		if (stripos($rLog, 'login fail') !== false || stripos($rLog, 'Login failed') !== false || stripos($rLog, 'bad password') !== false) {
			return 'The CAMD server rejected the login. For NEWCAMD a wrong DES key looks exactly like a wrong password, so check both.';
		}

		if (stripos($rLog, 'not found') !== false || stripos($rLog, 'resolve') !== false) {
			return 'The CAMD hostname did not resolve from this node.';
		}

		return 'tsdecrypt exited immediately. Last log lines: ' . trim(substr($rLog, -400));
	}

	/**
	 * PID recorded for a service's tsdecrypt.
	 *
	 * @param int $rServiceID Service id.
	 * @return int
	 */
	private static function readPid($rServiceID) {
		$rPath = self::pidPath($rServiceID);

		return is_file($rPath) ? (int) trim((string) file_get_contents($rPath)) : 0;
	}

	/**
	 * Make sure the scratch directory exists.
	 *
	 * @return string|null Null when usable, otherwise why not.
	 */
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
	 * Scratch directory shared with the other DVB runners.
	 *
	 * @return string Path with trailing slash.
	 */
	private static function workDir() {
		$rBase = defined('CACHE_TMP_PATH') ? CACHE_TMP_PATH : sys_get_temp_dir() . '/';

		return rtrim($rBase, '/') . '/dvb/';
	}

	/**
	 * Path of the PID file.
	 *
	 * @param int $rServiceID Service id.
	 * @return string
	 */
	private static function pidPath($rServiceID) {
		return self::workDir() . 'cw' . (int) $rServiceID . '.pid';
	}

	/**
	 * Path of the tsdecrypt log.
	 *
	 * @param int $rServiceID Service id.
	 * @return string
	 */
	public static function logPath($rServiceID) {
		return self::workDir() . 'cw' . (int) $rServiceID . '.log';
	}
}
