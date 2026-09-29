<?php

namespace XcVm\Module\Dvb\Service;

/**
 * DvbTransponderService — CRUD and validation for `dvb_transponders`.
 *
 * Validation here is not about security (these values reach a config file, not
 * a shell) but about catching the handful of mistakes that produce an
 * unhelpful "no lock" half a minute later. The expensive ones are:
 *
 *   - a satellite frequency typed in MHz (11778) instead of kHz (11778000);
 *   - a symbol rate typed in kSym/s (27500) instead of Sym/s (27500000);
 *   - a transponder pointed at a node that has no tuner at all.
 *
 * The first two are unambiguous — no real satellite carrier sits at 11.778 kHz
 * and no real one runs at 27.5 kSym/s — so they are corrected rather than
 * rejected, and the operator is told what was assumed.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbTransponderService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** Delivery systems the module offers, mapped to their frequency unit. */
	public const DELIVERY_SYSTEMS = [
		'DVBS'         => 'kHz',
		'DVBS2'        => 'kHz',
		'DVBC/ANNEX_A' => 'Hz',
		'DVBC/ANNEX_C' => 'Hz',
		'DVBT'         => 'Hz',
		'DVBT2'        => 'Hz',
		'ATSC'         => 'Hz',
		'ISDBT'        => 'Hz',
	];

	/** Columns accepted from the admin form. */
	private const FIELDS = [
		'server_id', 'adapter_id', 'name', 'satellite', 'delivery_system',
		'frequency', 'polarization', 'symbol_rate', 'modulation', 'inner_fec',
		'rolloff', 'pilot', 'bandwidth', 'isi', 'pls_mode', 'pls_code',
		'lnb_type', 'lnb_low', 'lnb_high', 'lnb_switch', 'diseqc', 'output_host',
		'enabled',
	];

	/**
	 * Every transponder, with its node and a count of what was found on it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$db = self::db();

		$db->query(
			'SELECT t.*, (SELECT COUNT(*) FROM `dvb_services` s WHERE s.`transponder_id` = t.`id`) AS `service_count`
			 FROM `dvb_transponders` t
			 ORDER BY t.`satellite` ASC, t.`frequency` ASC, t.`id` ASC;'
		);

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * Fetch one transponder.
	 *
	 * @param int $rID Transponder id.
	 * @return array|null
	 */
	public static function find($rID) {
		$db = self::db();

		$db->query('SELECT * FROM `dvb_transponders` WHERE `id` = ?;', (int) $rID);

		return $db->num_rows() === 1 ? $db->get_row() : null;
	}

	/**
	 * Create or update a transponder from posted form data.
	 *
	 * @param array    $rInput  Raw form values.
	 * @param int|null $rEditID Row to update, or null to insert.
	 * @return array{status:bool,id:int,error:string,notes:array<int,string>}
	 */
	public static function save(array $rInput, $rEditID = null) {
		$rRow   = [];
		$rNotes = [];

		foreach (self::FIELDS as $rField) {
			if (array_key_exists($rField, $rInput)) {
				$rRow[$rField] = $rInput[$rField];
			}
		}

		$rDelsys = strtoupper(trim((string) ($rRow['delivery_system'] ?? 'DVBS2')));

		if (!isset(self::DELIVERY_SYSTEMS[$rDelsys])) {
			return self::fail('Unknown delivery system "' . $rDelsys . '".');
		}

		$rRow['delivery_system'] = $rDelsys;
		$rRow['server_id']       = (int) ($rRow['server_id'] ?? 0);

		if ($rRow['server_id'] <= 0) {
			return self::fail('Pick the server that holds the tuner card.');
		}

		$rRow['adapter_id'] = empty($rRow['adapter_id']) ? null : (int) $rRow['adapter_id'];
		$rRow['frequency']  = (int) preg_replace('/[^0-9]/', '', (string) ($rRow['frequency'] ?? '0'));

		if ($rRow['frequency'] <= 0) {
			return self::fail('Frequency is required.');
		}

		$rSatellite = in_array($rDelsys, ['DVBS', 'DVBS2'], true);

		if ($rSatellite) {
			// A satellite downlink is 3 400 000-12 750 000 kHz. Anything in the
			// low thousands was typed in MHz.
			if ($rRow['frequency'] < 100000) {
				$rRow['frequency'] *= 1000;
				$rNotes[] = 'Frequency read as MHz and converted to ' . $rRow['frequency'] . ' kHz.';
			}

			$rRow['symbol_rate'] = (int) preg_replace('/[^0-9]/', '', (string) ($rRow['symbol_rate'] ?? '0'));

			if ($rRow['symbol_rate'] <= 0) {
				return self::fail('Symbol rate is required for satellite.');
			}

			// Real carriers run from about 1 to 45 MSym/s.
			if ($rRow['symbol_rate'] < 100000) {
				$rRow['symbol_rate'] *= 1000;
				$rNotes[] = 'Symbol rate read as kSym/s and converted to ' . $rRow['symbol_rate'] . ' Sym/s.';
			}

			$rPol = strtoupper(substr(trim((string) ($rRow['polarization'] ?? 'H')) . 'H', 0, 1));
			$rRow['polarization'] = in_array($rPol, ['H', 'V', 'L', 'R'], true) ? $rPol : 'H';

			$rRow['diseqc'] = max(0, min(4, (int) ($rRow['diseqc'] ?? 0)));
		} else {
			$rRow['polarization'] = null;
			$rRow['diseqc']       = 0;
		}

		$rRow['isi'] = isset($rRow['isi']) && $rRow['isi'] !== '' ? (int) $rRow['isi'] : -1;

		if ($rRow['isi'] < -1 || $rRow['isi'] > 255) {
			return self::fail('Input Stream Id must be between 0 and 255, or empty for a normal carrier.');
		}

		foreach (['lnb_low', 'lnb_high', 'lnb_switch', 'bandwidth', 'pls_code'] as $rNumeric) {
			if (isset($rRow[$rNumeric])) {
				$rRow[$rNumeric] = (int) preg_replace('/[^0-9]/', '', (string) $rRow[$rNumeric]);
			}
		}

		$rRow['enabled'] = !empty($rRow['enabled']) ? 1 : 0;

		// Where DVBlast fans the services out to. Loopback is the default and
		// the right answer whenever the channels run on the tuner node itself.
		$rHost = trim((string) ($rRow['output_host'] ?? ''));

		if ($rHost === '') {
			$rHost = '127.0.0.1';
		}

		if (filter_var($rHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			return self::fail('Output host must be an IPv4 address, for example 127.0.0.1 or 239.10.0.1.');
		}

		$rRow['output_host'] = $rHost;

		if (empty($rRow['name'])) {
			$rRow['name'] = self::autoName($rRow, $rSatellite);
		}

		$db = self::db();

		if ($rEditID !== null) {
			$rSet    = [];
			$rValues = [];

			foreach ($rRow as $rColumn => $rValue) {
				$rSet[]    = '`' . $rColumn . '` = ?';
				$rValues[] = $rValue;
			}

			$rValues[] = (int) $rEditID;

			if (!$db->query('UPDATE `dvb_transponders` SET ' . implode(', ', $rSet) . ' WHERE `id` = ?;', ...$rValues)) {
				return self::fail('Could not update the transponder.');
			}

			return ['status' => true, 'id' => (int) $rEditID, 'error' => '', 'notes' => $rNotes];
		}

		$rColumns = array_map(function ($rColumn) {
			return '`' . $rColumn . '`';
		}, array_keys($rRow));

		$rQuery = 'INSERT INTO `dvb_transponders`(' . implode(', ', $rColumns) . ') VALUES('
			. implode(', ', array_fill(0, count($rRow), '?')) . ');';

		if (!$db->query($rQuery, ...array_values($rRow))) {
			return self::fail('Could not create the transponder.');
		}

		return ['status' => true, 'id' => (int) $db->last_insert_id(), 'error' => '', 'notes' => $rNotes];
	}

	/**
	 * Flip the enabled flag.
	 *
	 * Deliberately not routed through {@see save()}: that method validates a
	 * whole form and would reject a payload holding nothing but `enabled`.
	 *
	 * @param int  $rID      Transponder id.
	 * @param bool $rEnabled Desired state.
	 * @return bool
	 */
	public static function setEnabled($rID, $rEnabled) {
		return (bool) self::db()->query(
			'UPDATE `dvb_transponders` SET `enabled` = ? WHERE `id` = ?;',
			$rEnabled ? 1 : 0,
			(int) $rID
		);
	}

	/**
	 * Mark whether this transponder should be feeding DVBlast.
	 *
	 * Intent only. The tuner node reconciles reality with it on its next tick,
	 * which is also what brings a stream back after the node reboots.
	 *
	 * @param int  $rID        Transponder id.
	 * @param bool $rStreaming Desired state.
	 * @return bool
	 */
	public static function setStreaming($rID, $rStreaming) {
		return (bool) self::db()->query(
			'UPDATE `dvb_transponders` SET `streaming` = ? WHERE `id` = ?;',
			$rStreaming ? 1 : 0,
			(int) $rID
		);
	}

	/**
	 * Delete a transponder and everything found on it.
	 *
	 * Services already imported as panel streams are unlinked rather than
	 * deleted — the stream is a real channel by then and removing it here would
	 * silently take a line-up off the air.
	 *
	 * @param int $rID Transponder id.
	 * @return bool
	 */
	public static function delete($rID) {
		$db = self::db();

		// Ask the node to stop DVBlast before the row vanishes: once the
		// transponder is gone the supervisor has nothing to reconcile against
		// and the process would keep a tuner and a frequency for ever.
		$rRow = self::find($rID);

		if ($rRow !== null && (!empty($rRow['streaming']) || $rRow['stream_status'] === 'running')) {
			DvbJobService::enqueue((int) $rRow['server_id'], DvbJobService::TYPE_STOP, $rID);
		}

		$db->query('DELETE FROM `dvb_services` WHERE `transponder_id` = ?;', (int) $rID);
		$db->query('UPDATE `dvb_adapters` SET `in_use_by` = NULL WHERE `in_use_by` = ?;', (int) $rID);
		$db->query('DELETE FROM `dvb_jobs` WHERE `ref_id` = ? AND `type` = ?;', (int) $rID, DvbJobService::TYPE_SCAN);

		return (bool) $db->query('DELETE FROM `dvb_transponders` WHERE `id` = ?;', (int) $rID);
	}

	/**
	 * Record the outcome of a scan against the transponder row.
	 *
	 * @param int    $rID      Transponder id.
	 * @param string $rStatus  never | scanning | ok | error.
	 * @param string $rMessage Human-readable detail.
	 * @param array  $rSignal  Result of DvbScanService::parseSignal().
	 * @return void
	 */
	/**
	 * Store a live meter reading.
	 *
	 * Deliberately narrower than recordScan(): the meter must not overwrite
	 * scan_status, scan_message or last_scan, or pointing a dish would erase
	 * the reason the last scan failed, which is exactly what you are trying
	 * to fix at that moment.
	 *
	 * @param int   $rID     Transponder id.
	 * @param array $rSignal Result of DvbScanService::parseSignal().
	 * @return void
	 */
	public static function recordSignal($rID, array $rSignal) {
		self::db()->query(
			'UPDATE `dvb_transponders` SET `signal_strength` = ?, `signal_quality` = ? WHERE `id` = ?;',
			isset($rSignal['strength']) ? $rSignal['strength'] : null,
			isset($rSignal['quality']) ? $rSignal['quality'] : null,
			(int) $rID
		);
	}

	public static function recordScan($rID, $rStatus, $rMessage, array $rSignal = []) {
		self::db()->query(
			'UPDATE `dvb_transponders`
			 SET `scan_status` = ?, `scan_message` = ?, `last_scan` = ?, `signal_strength` = ?, `signal_quality` = ?
			 WHERE `id` = ?;',
			$rStatus,
			substr((string) $rMessage, 0, 4000),
			time(),
			isset($rSignal['strength']) ? $rSignal['strength'] : null,
			isset($rSignal['quality']) ? $rSignal['quality'] : null,
			(int) $rID
		);
	}

	/**
	 * Build a readable default name, e.g. "11778 H 27500".
	 *
	 * @param array $rRow       Sanitised row.
	 * @param bool  $rSatellite Whether this is a satellite carrier.
	 * @return string
	 */
	private static function autoName(array $rRow, $rSatellite) {
		if ($rSatellite) {
			return sprintf(
				'%d %s %d',
				(int) round($rRow['frequency'] / 1000),
				$rRow['polarization'],
				(int) round($rRow['symbol_rate'] / 1000)
			);
		}

		return sprintf('%.3f MHz', $rRow['frequency'] / 1000000);
	}

	/**
	 * Shorthand for a validation failure.
	 *
	 * @param string $rError Message.
	 * @return array{status:bool,id:int,error:string,notes:array}
	 */
	private static function fail($rError) {
		return ['status' => false, 'id' => 0, 'error' => $rError, 'notes' => []];
	}
}
