<?php

namespace XcVm\Module\Dvb\Service;

/**
 * DvbCamdService — CRUD for the `dvb_camd` table.
 *
 * A CAMD profile is a set of client credentials for someone else's card
 * server. The panel never connects to it: the credentials travel to the tuner
 * node through the shared database, and tsdecrypt there opens the session.
 *
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbCamdService {

	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** Columns accepted from the admin form. */
	private const FIELDS = [
		'name', 'protocol', 'host', 'port', 'username', 'password', 'des_key',
		'ca_system', 'caid', 'emm', 'input_buffer', 'max_connections', 'mute_on_error', 'enabled', 'notes',
	];

	/**
	 * Every CAMD profile, with a count of the services using it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$db = self::db();

		$db->query(
			// running_count is the number that actually hold a session right
			// now. Assigned and running are very different numbers when a
			// card line runs out of sessions, and only seeing the first one
			// leaves an operator guessing why most channels are black.
			'SELECT c.*,
			        (SELECT COUNT(*) FROM `dvb_services` s WHERE s.`camd_id` = c.`id`) AS `service_count`,
			        (SELECT COUNT(*) FROM `dvb_services` s WHERE s.`camd_id` = c.`id` AND s.`decrypt_status` = \'running\') AS `running_count`
			 FROM `dvb_camd` c ORDER BY c.`name` ASC, c.`id` ASC;'
		);

		return $db->num_rows() > 0 ? $db->get_rows() : [];
	}

	/**
	 * Profiles that may be offered when importing, keyed by id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function enabled() {
		$db = self::db();

		$db->query('SELECT * FROM `dvb_camd` WHERE `enabled` = 1 ORDER BY `name` ASC, `id` ASC;');

		$rOut = [];

		foreach ($db->num_rows() > 0 ? $db->get_rows() : [] as $rRow) {
			$rOut[(int) $rRow['id']] = $rRow;
		}

		return $rOut;
	}

	/**
	 * Fetch one profile.
	 *
	 * @param int $rID Profile id.
	 * @return array|null
	 */
	public static function find($rID) {
		$db = self::db();

		$db->query('SELECT * FROM `dvb_camd` WHERE `id` = ?;', (int) $rID);

		return $db->num_rows() === 1 ? $db->get_row() : null;
	}

	/**
	 * Create or update a profile from posted form data.
	 *
	 * @param array    $rInput  Raw form values.
	 * @param int|null $rEditID Row to update, or null to insert.
	 * @return array{status:bool,id:int,error:string}
	 */
	public static function save(array $rInput, $rEditID = null) {
		$rRow = [];

		foreach (self::FIELDS as $rField) {
			if (array_key_exists($rField, $rInput)) {
				$rRow[$rField] = $rInput[$rField];
			}
		}

		$rRow['protocol'] = DvbDecryptRunner::protocol((string) ($rRow['protocol'] ?? ''));
		$rRow['host']     = trim((string) ($rRow['host'] ?? ''));

		if ($rRow['host'] === '') {
			return self::fail('The CAMD server host is required.');
		}

		$rRow['port'] = (int) ($rRow['port'] ?? 0);

		if ($rRow['port'] < 1 || $rRow['port'] > 65535) {
			return self::fail('Port must be between 1 and 65535.');
		}

		$rRow['username'] = trim((string) ($rRow['username'] ?? ''));

		if ($rRow['username'] === '') {
			return self::fail('The CAMD username is required.');
		}

		// NEWCAMD will not authenticate without the shared DES key, and the
		// server's rejection is indistinguishable from a wrong password — so
		// the key is validated here, where the operator can still see why.
		if ($rRow['protocol'] === 'NEWCAMD') {
			$rKey = DvbDecryptRunner::desKey((string) ($rRow['des_key'] ?? ''));

			if ($rKey === '') {
				return self::fail('NEWCAMD needs a DES key of exactly 28 hex characters (14 bytes). Spaces and a 0x prefix are fine, anything else is not.');
			}

			$rRow['des_key'] = $rKey;
		}

		$rRow['ca_system'] = DvbDecryptRunner::caSystem((string) ($rRow['ca_system'] ?? ''));

		$rCaid = DvbDecryptRunner::caid((string) ($rRow['caid'] ?? ''));
		$rRow['caid'] = ($rCaid === null) ? null : substr($rCaid, 2);

		$rRow['emm']           = !empty($rRow['emm']) ? 1 : 0;
		$rRow['mute_on_error'] = !empty($rRow['mute_on_error']) ? 1 : 0;
		$rRow['enabled']       = !empty($rRow['enabled']) ? 1 : 0;
		$rRow['input_buffer']  = max(0, min(10000, (int) ($rRow['input_buffer'] ?? 0)));
		// 0 means no cap, which is how every profile behaved before the
		// column existed.
		$rRow['max_connections'] = max(0, min(1000, (int) ($rRow['max_connections'] ?? 0)));

		if (trim((string) ($rRow['name'] ?? '')) === '') {
			$rRow['name'] = $rRow['host'] . ':' . $rRow['port'];
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

			if (!$db->query('UPDATE `dvb_camd` SET ' . implode(', ', $rSet) . ' WHERE `id` = ?;', ...$rValues)) {
				return self::fail('Could not update the CAMD server.');
			}

			return ['status' => true, 'id' => (int) $rEditID, 'error' => ''];
		}

		$rColumns = array_map(function ($rColumn) {
			return '`' . $rColumn . '`';
		}, array_keys($rRow));

		$rQuery = 'INSERT INTO `dvb_camd`(' . implode(', ', $rColumns) . ') VALUES('
			. implode(', ', array_fill(0, count($rRow), '?')) . ');';

		if (!$db->query($rQuery, ...array_values($rRow))) {
			return self::fail('Could not create the CAMD server.');
		}

		return ['status' => true, 'id' => (int) $db->last_insert_id(), 'error' => ''];
	}

	/**
	 * Flip the enabled flag.
	 *
	 * A single-column update rather than a save(): save() validates the whole
	 * row and would refuse to touch an incomplete profile, which is exactly
	 * when disabling it is most useful.
	 *
	 * @param int  $rID      Profile id.
	 * @param bool $rEnabled Desired state.
	 * @return bool
	 */
	public static function setEnabled($rID, $rEnabled) {
		return (bool) self::db()->query(
			'UPDATE `dvb_camd` SET `enabled` = ? WHERE `id` = ?;',
			$rEnabled ? 1 : 0,
			(int) $rID
		);
	}

	/**
	 * Delete a profile and detach the services using it.
	 *
	 * The services keep their channels; they simply stop being decrypted. The
	 * supervisor notices `camd_id` is gone and stops their tsdecrypt on the
	 * next tick.
	 *
	 * @param int $rID Profile id.
	 * @return bool
	 */
	public static function delete($rID) {
		$db = self::db();

		$db->query('UPDATE `dvb_services` SET `camd_id` = NULL WHERE `camd_id` = ?;', (int) $rID);

		return (bool) $db->query('DELETE FROM `dvb_camd` WHERE `id` = ?;', (int) $rID);
	}

	/**
	 * Is the CAMD server reachable from the panel?
	 *
	 * A plain TCP connect, nothing more. It cannot verify the credentials —
	 * only tsdecrypt on the tuner node can do that, and only against a real
	 * ECM. It still catches the two most common mistakes, a typo in the host
	 * and a port the provider has not opened.
	 *
	 * Note the panel and the tuner node may reach the internet differently, so
	 * a pass here is not a promise for the node.
	 *
	 * @param array $rCamd CAMD row or unsaved form values.
	 * @return array{status:bool,message:string}
	 */
	public static function probe(array $rCamd) {
		$rHost = trim((string) ($rCamd['host'] ?? ''));
		$rPort = (int) ($rCamd['port'] ?? 0);

		if ($rHost === '' || $rPort <= 0) {
			return ['status' => false, 'message' => 'Set a host and port first.'];
		}

		$rErrNo  = 0;
		$rErrStr = '';
		$rStart  = microtime(true);
		$rSocket = @fsockopen($rHost, $rPort, $rErrNo, $rErrStr, 5);

		if ($rSocket === false) {
			return ['status' => false, 'message' => 'Cannot reach ' . $rHost . ':' . $rPort . ' — ' . ($rErrStr !== '' ? $rErrStr : 'connection failed') . '.'];
		}

		fclose($rSocket);

		return [
			'status'  => true,
			'message' => sprintf(
				'TCP reachable in %d ms. Credentials are only proven once a channel is actually decrypted.',
				(int) round((microtime(true) - $rStart) * 1000)
			),
		];
	}

	/**
	 * Shorthand for a validation failure.
	 *
	 * @param string $rError Message.
	 * @return array{status:bool,id:int,error:string}
	 */
	private static function fail($rError) {
		return ['status' => false, 'id' => 0, 'error' => $rError];
	}
}
