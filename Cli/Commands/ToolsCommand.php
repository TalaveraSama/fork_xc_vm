<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\Encryption;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Server\ServerRepository;

/**
 * ToolsCommand — tools command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ToolsCommand implements CommandInterface {

	public function getName(): string {
		return 'tools';
	}

	public function getDescription(): string {
		return 'Utilities: images, duplicates, bouquets, rescue, recaptcha, access, ports, migration, user, mysql, database, flush';
	}

	public function execute(array $rArgs): int {
		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		global $db;

		$rMethod = (!empty($rArgs[0]) ? $rArgs[0] : null);
		$rUser = posix_getpwuid(posix_geteuid())['name'];

		$rRootMethods = array('rescue', 'recaptcha', 'access', 'ports', 'migration', 'user', 'mysql', 'database', 'flush', 'import');
		$rUserMethods = array('images', 'duplicates', 'bouquets');

		// No or unknown subcommand → show the full help to any user (root or xc_vm)
		if ($rMethod === null || (!in_array($rMethod, $rRootMethods, true) && !in_array($rMethod, $rUserMethods, true))) {
			$this->printUsage();
			return 1;
		}

		// root-only subcommands
		if (in_array($rMethod, $rRootMethods, true)) {
			if ($rUser !== 'root') {
				echo "Please run as root!\n";
				return 1;
			}

			$rServers = ServerRepository::getAll();

			switch ($rMethod) {
				case 'rescue':
					return $this->processRescue($db, $rServers);
				case 'recaptcha':
					return $this->processRecaptcha($db);
				case 'access':
					return $this->processAccess($db, $rServers);
				case 'ports':
					return $this->processPorts($db, $rServers);
				case 'migration':
					return $this->processMigration($db, $rArgs, $rServers);
				case 'user':
					return $this->processUser($db);
				case 'mysql':
					return $this->processMysql($db, $rServers);
				case 'database':
					return $this->processDatabase($db, $rArgs);
				case 'import':
					return $this->processImport($db, $rArgs);
				case 'flush':
					return $this->processFlush($db);
			}
		}

		// xc_vm subcommands
		if ($rUser !== 'xc_vm') {
			echo "Please run as \XC_VM!\n";
			return 1;
		}

		switch ($rMethod) {
			case 'images':
				$this->processImages($db);
				break;
			case 'duplicates':
				$this->processDuplicates($db);
				break;
			case 'bouquets':
				$this->processBouquets($db);
				break;
			default:
				$this->printUsage();
				return 1;
		}

		return 0;
	}

	private function processMigration($db, array $rArgs, array $rServers): int {
		// Re-join the argument tail so an unquoted path with spaces still resolves
		$database = (count($rArgs) > 1 ? implode(' ', array_slice($rArgs, 1)) : null);

		// Validate the backup file BEFORE wiping the migration database
		if ($database !== null) {
			if (!is_file($database)) {
				echo "Error: File not found: {$database}\n";
				echo "If the path contains spaces, wrap it in quotes.\n";
				return 1;
			}
			if (strtolower(pathinfo($database, PATHINFO_EXTENSION)) !== 'sql') {
				echo "Error: File must have .sql extension\n";
				return 1;
			}
		}

		$db->query('DROP DATABASE IF EXISTS `xc_vm_migrate`;');
		$db->query('CREATE DATABASE IF NOT EXISTS `xc_vm_migrate`;');
		echo "Migration database has been cleared.\n";

		foreach ($rServers as $rServer) {
			BackupService::grantPrivileges($rServer['server_ip']);
		}

		if ($database !== null) {
			echo 'Restoring: ' . $database . "\n";
			if (!\XC_VM::db_restore($database, 'xc_vm_migrate')) {
				echo "Error: Restore failed. Check the SQL file and database credentials.\n";
				return 1;
			}
			echo "Restore completed. You can now run: console.php migrate\n\n";
		} else {
			echo "You can restore a database to it using:\n";
			echo "  mariadb -h 127.0.0.1 -P <port> -u <username> -p'<password>' xc_vm_migrate < backup.sql\n";
		}

		return 0;
	}

	private function processUser($db): int {
		$rUsername = 'admin_' . bin2hex(random_bytes(4));
		$rPassword = bin2hex(random_bytes(8));
		$rHash = crypt($rPassword, sprintf('$6$rounds=%d$%s$', 20000, 'xc_vm'));
		$db->query(
			"INSERT INTO `users`(`username`, `password`, `email`, `ip`, `date_registered`, `last_login`, `member_group_id`, `status`) VALUES(?, ?, '', '', ?, ?, 1, 1);",
			$rUsername, $rHash, time(), time()
		);
		echo "Rescue admin user created:\n";
		echo "  Username: {$rUsername}\n";
		echo "  Password: {$rPassword}\n\n";
		echo "Please change the password and delete this user when done.\n";
		return 0;
	}

	private function processMysql($db, array $rServers): int {
		foreach ($rServers as $rServerID => $rServer) {
			echo 'Granting privileges to: ' . $rServer['server_ip'] . " (ID: {$rServerID})\n";
			BackupService::grantPrivileges($rServer['server_ip']);
		}
		echo "\nMySQL privileges have been reauthorised for all servers.\n";
		return 0;
	}

	private function processDatabase($db, array $rArgs): int {
		if (empty($rArgs[1]) || $rArgs[1] !== '--confirm') {
			echo "WARNING: This will erase ALL data and restore a blank database!\n";
			echo "To confirm, run: sudo console.php tools database --confirm\n";
			return 1;
		}
		$rDatabaseFile = MAIN_HOME . 'bin/install/database.sql';
		if (!file_exists($rDatabaseFile)) {
			echo "Error: Database file not found: {$rDatabaseFile}\n";
			return 1;
		}
		echo "Restoring blank database...\n";
		shell_exec('sudo mariadb -u root xc_vm < ' . escapeshellarg($rDatabaseFile));
		echo "Blank database has been restored.\n";
		return 0;
	}

	private function processFlush($db): int {
		echo "Flushing iptables rules...\n";
		exec('sudo iptables -F && sudo ip6tables -F');
		shell_exec('sudo rm -f ' . escapeshellarg(FLOOD_TMP_PATH) . 'block_*');
		exec('sudo iptables-save && sudo ip6tables-save');
		$db->query('TRUNCATE `blocked_ips`;');
		echo "All blocked IPs have been flushed (iptables + database).\n";
		return 0;
	}

	/**
	 * Migrate what fits from another Xtream-derived panel's dump.
	 *
	 * XUI.ONE, Xtream UI and this panel share an ancestor, so the table names
	 * line up and the columns mostly do not: each fork grew its own over the
	 * years. Restoring such a dump straight over the live database is what
	 * makes people say "the backup does not work". This stages it in a
	 * separate database, intersects the columns table by table, and copies
	 * only what both sides agree on.
	 *
	 * Reports and changes nothing unless --apply is given. `servers` is never
	 * touched: those rows describe the machines of the installation the dump
	 * came from. `lines` needs --lines on top of --apply, because a password
	 * format that does not match locks every customer out.
	 *
	 * Into a table that already has rows, the copy comes in under fresh ids
	 * -- the dump's own would collide -- and every number that points at a
	 * copied row is then rewritten from the map built while inserting: the
	 * `category_id` list of streams, the four id lists of bouquets, the
	 * `bouquet` list of lines, and the `parent_id`, `member_id` and `pair_id`
	 * scalars. An id whose row did not import is dropped rather than left to
	 * name some unrelated row of this panel. `bouquet_series` is emptied
	 * either way: its numbers belong to the dump's `streams_series`, which
	 * this tool never imports, so nothing here can resolve them. Into an
	 * empty table the dump keeps its own ids, which need no rewriting.
	 *
	 * @param object $db    Database handle.
	 * @param array  $rArgs Raw argv for this subcommand.
	 * @return int
	 */
	private function processImport($db, array $rArgs): int {
		$rFile  = isset($rArgs[1]) ? (string) $rArgs[1] : '';
		$rApply = in_array('--apply', $rArgs, true);
		$rLines = in_array('--lines', $rArgs, true);
		$rStage = 'xc_vm_import';

		if ($rFile === '' || !is_file($rFile)) {
			echo "Usage: console.php tools import <dump.sql> [--apply] [--lines]\n";
			echo "The file must exist and be readable.\n";
			return 1;
		}

		echo "Staging " . $rFile . " into `" . $rStage . "`...\n";
		$db->query('DROP DATABASE IF EXISTS `' . $rStage . '`;');
		$db->query('CREATE DATABASE `' . $rStage . '`;');

		// Shelling out to the client is deliberate: a dump is a stream of
		// statements, not something to re-implement a parser for.
		$rCmd = 'mysql ' . escapeshellarg($rStage) . ' < ' . escapeshellarg($rFile) . ' 2>&1';
		$rOut = (string) shell_exec($rCmd);

		if (trim($rOut) !== '') {
			echo "mysql said:\n" . substr($rOut, 0, 600) . "\n";
		}

		// Order matters: categories before the streams that reference them,
		// streams before the bouquets that list them, bouquets before the
		// lines that attach them.
		$rTables = array('streams_categories', 'streams', 'bouquets');

		if ($rLines) {
			$rTables[] = 'lines';
		}

		// Columns holding a JSON list of ids from another table, mapped to
		// the table those ids are rows of. A null target means the numbers
		// belong to a table no import can bring over, so the list cannot be
		// resolved -- only emptied.
		$rLists = array(
			'streams'  => array('category_id' => 'streams_categories'),
			'bouquets' => array(
				'bouquet_channels' => 'streams',
				'bouquet_movies'   => 'streams',
				'bouquet_radios'   => 'streams',
				'bouquet_series'   => null,
			),
			'lines'    => array('bouquet' => 'bouquets'),
		);

		// Scalar columns that hold one id from another table. Self-references
		// included: a category's parent or a reseller's customer may sit at
		// either side of the row, so every one of these is settled in a
		// second pass over the staged ids.
		$rScalars = array(
			'streams_categories' => array('parent_id' => 'streams_categories'),
			'lines'              => array('member_id' => 'lines', 'pair_id' => 'lines'),
		);

		$rMaps       = array(); // table => dump id => live id (identity where kept)
		$rRenumbered = array(); // table => true when the live ids are new ones
		$rCommons    = array(); // table => columns both sides agreed on
		$rDead       = array(); // table => the whole copy of it was refused
		$rTotal      = 0;
		$rFailures   = 0;

		if ($rApply) {
			// One transaction for the whole import: a crash rolls back, and
			// the reference pass never sees rows from half a table.
			$db->query('START TRANSACTION;');
		}

		foreach ($rTables as $rTable) {
			$rCommon = $this->commonColumns($db, $rStage, $rTable);

			if (empty($rCommon)) {
				echo "\n" . $rTable . ": not present on both sides, skipped\n";
				continue;
			}

			$rCommons[$rTable] = $rCommon;

			$rRows = 0;

			if ($db->query('SELECT COUNT(*) AS `n` FROM `' . $rStage . '`.`' . $rTable . '`;')
				&& $db->num_rows() > 0) {
				$rRows = (int) $db->get_row()['n'];
			}

			$rLive = 0;

			if ($db->query('SELECT COUNT(*) AS `n` FROM `' . $rTable . '`;')
				&& $db->num_rows() > 0) {
				$rLive = (int) $db->get_row()['n'];
			}

			$rDropped = $this->foreignOnlyColumns($db, $rStage, $rTable);

			echo "\n" . $rTable . ": " . $rRows . " row(s), "
				. count($rCommon) . " column(s) in common";
			echo empty($rDropped) ? "\n" : (", dropping " . count($rDropped) . ": "
				. implode(', ', array_slice($rDropped, 0, 8))
				. (count($rDropped) > 8 ? ' …' : '') . "\n");

			if ($rRows === 0) {
				echo "  nothing staged, nothing to copy\n";
				continue;
			}

			$rHasID = in_array('id', $rCommon, true);

			// The map machinery needs ids that survive a trip through PHP as
			// plain integers; a dump keyed on uuids gets bulk-copied instead.
			if ($rHasID) {
				$rProbe = null;

				if ($db->query('SELECT `id` FROM `' . $rStage . '`.`' . $rTable . '` ORDER BY `id` ASC LIMIT 1;')
					&& $db->num_rows() > 0) {
					$rProbe = $db->get_row()['id'];
				}

				if ((string) (int) $rProbe !== (string) $rProbe) {
					$rHasID = false;
					echo "  the id column is not numeric, so references cannot be remapped\n";
				}
			}

			$rKeep  = $rLive === 0 && $rHasID;

			if (!$rApply) {
				echo $rKeep ? "  report only: rows would keep the ids the dump uses\n" : ($rHasID
					? "  report only: rows would be renumbered, and ids pointing into this table rewritten\n"
					: "  report only: no id column, so rows would be copied as they stand\n");
				continue;
			}

			if ($rKeep || !$rHasID) {
				$rCols = '`' . implode('`, `', $rCommon) . '`';

				if (!$db->query('INSERT INTO `' . $rTable . '` (' . $rCols . ') '
					. 'SELECT ' . $rCols . ' FROM `' . $rStage . '`.`' . $rTable . '`;')) {
					echo "  ! the copy was refused: " . $db->error() . "\n";
					$rDead[$rTable] = true;
					$rFailures += $rRows;
					continue;
				}

				$rTotal += $rRows;

				if ($rKeep) {
					$rMaps[$rTable] = $this->identityMap($db, $rStage, $rTable);
					echo "  imported " . $rRows . " row(s) with the dump's own ids\n";
				} else {
					echo "  copied " . $rRows . " row(s); without an id column there is no map to"
						. " build, so references to and from this table keep the dump's numbering\n";
				}

				continue;
			}

			// Every common column but the id itself: the id is what the panel
			// assigns, and the reference columns go in with the dump's values
			// and get corrected in the pass below -- one insert per row, and
			// the values never round-trip through PHP.
			$rInsert = array();

			foreach ($rCommon as $rCol) {
				if ($rCol !== 'id') {
					$rInsert[] = '`' . $rCol . '`';
				}
			}

			$rInsert = implode(', ', $rInsert);
			$rCopied = 0;
			$rFailed = 0;
			$rLastID = PHP_INT_MIN; // a dump may key rows on 0, or below it
			$rMaps[$rTable] = array();
			$rRenumbered[$rTable] = true;

			while (true) {
				if (!$db->query('SELECT `id` FROM `' . $rStage . '`.`' . $rTable . '`'
					. ' WHERE `id` > ' . $rLastID . ' ORDER BY `id` ASC LIMIT 1000;')) {
					echo "  ! the staged rows could not be read: " . $db->error() . "\n";
					break;
				}

				$rPage = $db->get_rows();

				if (empty($rPage)) {
					break;
				}

				foreach ($rPage as $rRow) {
					$rLastID = (int) $rRow['id'];

					if (!$db->query('INSERT INTO `' . $rTable . '` (' . $rInsert . ') '
						. 'SELECT ' . $rInsert . ' FROM `' . $rStage . '`.`' . $rTable . '`'
						. ' WHERE `id` = ?;', $rLastID)) {
						if ($rFailed === 0) {
							echo "  ! first row refused: " . $db->error() . "\n";
						}
						$rFailed++;
						continue;
					}

					$rMaps[$rTable][$rLastID] = (int) $db->last_insert_id();
					$rCopied++;
				}

				if (count($rPage) < 1000) {
					break;
				}
			}

			$rTotal += $rCopied;

			echo "  imported " . $rCopied . " row(s) under new ids\n";

			if ($rFailed > 0) {
				echo "  ! " . $rFailed . " row(s) were refused -- duplicate names are the usual cause\n";
				$rFailures += $rFailed;
			}

			// Nothing at all coming in is a whole-table failure whatever the
			// route: references into it are then left alone and named, exactly
			// as a refused bulk copy leaves them.
			if ($rCopied === 0) {
				$rDead[$rTable] = true;
			}
		}

		if ($rApply && $rTotal > 0) {
			$rRepair = $this->repairImportReferences($db, $rStage, $rTables, $rMaps,
				$rRenumbered, $rDead, $rCommons, $rLists, $rScalars);

			if ($rRepair[0] > 0 || $rRepair[1] > 0) {
				echo "\nReferences: " . $rRepair[0] . " id(s) rewritten to the numbering assigned"
					. " above, " . $rRepair[1] . " dropped for pointing at nothing imported\n";
			}

			$rFailures += $rRepair[2];
		}

		if ($rApply) {
			// Refused rows are named and counted rather than aborting the
			// import: the rest came in fine, and a whole panel rejected over
			// two duplicate bouquet names is the worse outcome. Nothing at all
			// coming in is the one case worth unwinding.
			$db->query($rFailures > 0 && $rTotal === 0 ? 'ROLLBACK;' : 'COMMIT;');
		}

		echo "\n";

		if (!$rApply) {
			echo "Nothing was written. Re-run with --apply to import.\n";
			echo "`servers` is never imported; `lines` needs --lines as well.\n";
			return 0;
		}

		echo "Imported roughly " . $rTotal . " row(s). Staging database `"
			. $rStage . "` kept for inspection; drop it when satisfied.\n";

		if ($rFailures > 0) {
			echo $rFailures . " row(s) were refused; the reasons are printed above and in the panel log.\n";
			echo "This import is one-shot: re-running --apply would duplicate what came in, not\n";
			echo "top it up. Clear what arrived, or point the import at a fresh panel.\n";
			return 1;
		}

		return 0;
	}

	/**
	 * Rewrite the id references a renumbered import leaves pointing at the
	 * wrong rows.
	 *
	 * Every id whose row did not import is dropped rather than kept: a
	 * bouquet naming the wrong channel is the exact failure this pass exists
	 * to prevent, while an id naming no channel at all is merely missing.
	 * References into a table whose rows were refused wholesale are left
	 * alone, the refusal said out loud -- zeroing them would add a second
	 * silent loss on top of one already reported.
	 *
	 * Rows are updated only where the rewrite actually changes something, so
	 * a panel that happened to assign the same numbers costs no queries.
	 *
	 * @return array{int, int, int} [ids rewritten, ids dropped, updates refused]
	 */
	private function repairImportReferences($db, string $rStage, array $rTables, array $rMaps,
		array $rRenumbered, array $rDead, array $rCommons, array $rLists, array $rScalars): array {

		$rFixed  = 0;
		$rGone   = 0;
		$rFailed = 0;

		foreach ($rTables as $rTable) {
			if (empty($rMaps[$rTable])) {
				continue;
			}

			$rRefs = array();

			// A reference column only matters where both sides have it: the
			// copy above wrote from the same intersection, and asking for a
			// column the panel lacks would fail the read of every page.
			foreach ($rLists[$rTable] ?? array() as $rCol => $rRef) {
				if (in_array($rCol, $rCommons[$rTable] ?? array(), true)) {
					$rRefs[$rCol] = array($rRef, true);
				}
			}

			foreach ($rScalars[$rTable] ?? array() as $rCol => $rRef) {
				if (in_array($rCol, $rCommons[$rTable] ?? array(), true)) {
					$rRefs[$rCol] = array($rRef, false);
				}
			}

			$rActive = array();

			foreach ($rRefs as $rCol => $rDef) {
				$rRef    = $rDef[0];
				$rIsList = $rDef[1];

				if ($rRef === null) {
					// A table no import can bring over: the empty map drops
					// every id in the list, which is the whole point.
					$rActive[$rCol] = array(array(), $rIsList);
					continue;
				}

				if ($rRef === $rTable) {
					if (empty($rRenumbered[$rTable])) {
						continue; // own ids were kept, so they are already right
					}
					$rActive[$rCol] = array($rMaps[$rTable], $rIsList);
					continue;
				}

				if (!empty($rDead[$rRef])) {
					echo "  " . $rCol . ": left as it is -- the copy of `" . $rRef . "` failed\n";
					continue;
				}

				if (!empty($rMaps[$rRef]) && empty($rRenumbered[$rRef])) {
					continue; // the reference table kept the dump's ids
				}

				// Renumbered: remap through its map. Not imported at all: the
				// empty map drops every id, because the numbers can only
				// point at rows of this panel the dump never knew.
				$rActive[$rCol] = array($rMaps[$rRef] ?? array(), $rIsList);
			}

			if (empty($rActive)) {
				continue;
			}

			$rSelect = '`id`, `' . implode('`, `', array_keys($rActive)) . '`';
			$rLastID = PHP_INT_MIN;

			while (true) {
				if (!$db->query('SELECT ' . $rSelect . ' FROM `' . $rStage . '`.`' . $rTable . '`'
					. ' WHERE `id` > ' . $rLastID . ' ORDER BY `id` ASC LIMIT 1000;')) {
					echo "  ! the staged rows could not be read: " . $db->error() . "\n";
					break;
				}

				$rPage = $db->get_rows();

				if (empty($rPage)) {
					break;
				}

				foreach ($rPage as $rRow) {
					$rLastID = (int) $rRow['id'];
					$rLiveID = (int) ($rMaps[$rTable][$rLastID] ?? 0);

					if ($rLiveID === 0) {
						continue; // that row was refused; there is nothing to fix
					}

					$rSets  = array();
					$rBinds = array();

					foreach ($rActive as $rCol => $rDef) {
						$rMap    = $rDef[0];
						$rIsList = $rDef[1];
						$rRaw    = $rRow[$rCol] ?? '';

						if ($rIsList) {
							$rMapped = 0;
							$rLost   = 0;
							$rNew    = $this->remapIDList((string) $rRaw, $rMap, $rMapped, $rLost);

							if ($rNew !== '[' . implode(',', $this->idList($rRaw)) . ']') {
								$rSets[] = '`' . $rCol . '` = ?';
								$rBinds[] = $rNew;
							}

							$rFixed += $rMapped;
							$rGone  += $rLost;
							continue;
						}

						$rOld = (int) $rRaw;

						if ($rOld <= 0) {
							continue;
						}

						if (isset($rMap[$rOld])) {
							if ((int) $rMap[$rOld] === $rOld) {
								continue; // the new id happens to be the old one
							}
							$rSets[] = '`' . $rCol . '` = ?';
							$rBinds[] = (int) $rMap[$rOld];
							$rFixed++;
						} else {
							$rSets[] = '`' . $rCol . '` = 0';
							$rGone++;
						}
					}

					if (empty($rSets)) {
						continue;
					}

					$rArgs = array_merge(
						array('UPDATE `' . $rTable . '` SET ' . implode(', ', $rSets) . ' WHERE `id` = ?;'),
						$rBinds,
						array($rLiveID)
					);

					if (!call_user_func_array(array($db, 'query'), $rArgs)) {
						if ($rFailed === 0) {
							echo "  ! a reference update was refused: " . $db->error() . "\n";
						}
						$rFailed++;
					}
				}

				if (count($rPage) < 1000) {
					break;
				}
			}
		}

		return array($rFixed, $rGone, $rFailed);
	}

	/**
	 * dump id => the same id, for a table copied with the numbering it came
	 * with. The live row of a dump id is then the id itself, which is all the
	 * reference pass needs; building it costs one read per 5000 rows.
	 *
	 * @param object $db     Database handle.
	 * @param string $rStage Staging database name.
	 * @param string $rTable Table name.
	 * @return array<int, int>
	 */
	private function identityMap($db, string $rStage, string $rTable): array {
		$rMap    = array();
		$rLastID = PHP_INT_MIN;

		while (true) {
			if (!$db->query('SELECT `id` FROM `' . $rStage . '`.`' . $rTable . '`'
				. ' WHERE `id` > ' . $rLastID . ' ORDER BY `id` ASC LIMIT 5000;')) {
				return $rMap;
			}

			$rPage = $db->get_rows();

			if (empty($rPage)) {
				return $rMap;
			}

			foreach ($rPage as $rRow) {
				$rLastID = (int) $rRow['id'];
				$rMap[$rLastID] = $rLastID;
			}

			if (count($rPage) < 5000) {
				return $rMap;
			}
		}
	}

	/**
	 * The positive integers of a stored id list, tolerating the shapes real
	 * dumps carry: '[]', '', NULL, and text that never was JSON.
	 *
	 * @param mixed $rRaw The column value as fetched.
	 * @return int[]
	 */
	private function idList($rRaw): array {
		$rDecoded = json_decode((string) $rRaw, true);

		if (!is_array($rDecoded)) {
			return array();
		}

		$rOut = array();

		foreach ($rDecoded as $rID) {
			$rID = (int) $rID;

			if ($rID > 0) {
				$rOut[] = $rID;
			}
		}

		return $rOut;
	}

	/**
	 * Re-number one stored id list through a dump id => live id map.
	 *
	 * @param string $rRaw   The column value as fetched.
	 * @param array  $rMap     dump id => live id.
	 * @param int    $rFixed  Count of ids that changed number, by reference.
	 * @param int    $rGone   Count of ids no imported row stands behind, by reference.
	 * @return string JSON in the exact shape the panel's own writers emit.
	 */
	private function remapIDList(string $rRaw, array $rMap, int &$rFixed, int &$rGone): string {
		$rOut = array();

		foreach ($this->idList($rRaw) as $rID) {
			if (!isset($rMap[$rID])) {
				$rGone++;
				continue;
			}

			$rNew = (int) $rMap[$rID];

			if ($rNew !== $rID) {
				$rFixed++;
			}

			$rOut[] = $rNew;
		}

		return '[' . implode(',', $rOut) . ']';
	}

	/**
	 * Columns a table has in both the staged dump and the live database.
	 *
	 * @param object $db     Database handle.
	 * @param string $rStage Staging database name.
	 * @param string $rTable Table name.
	 * @return string[]
	 */
	private function commonColumns($db, $rStage, $rTable): array {
		$db->query(
			'SELECT a.`COLUMN_NAME` AS `c`
			 FROM `information_schema`.`COLUMNS` a
			 INNER JOIN `information_schema`.`COLUMNS` b
			     ON b.`TABLE_SCHEMA` = ? AND b.`TABLE_NAME` = a.`TABLE_NAME`
			    AND b.`COLUMN_NAME` = a.`COLUMN_NAME`
			 WHERE a.`TABLE_SCHEMA` = DATABASE() AND a.`TABLE_NAME` = ?
			 ORDER BY a.`ORDINAL_POSITION`;',
			$rStage,
			$rTable
		);

		$rOut = array();

		foreach (($db->num_rows() > 0 ? $db->get_rows() : array()) as $rRow) {
			$rOut[] = (string) $rRow['c'];
		}

		return $rOut;
	}

	/**
	 * Columns the dump has that this panel does not, so they can be named.
	 *
	 * @param object $db     Database handle.
	 * @param string $rStage Staging database name.
	 * @param string $rTable Table name.
	 * @return string[]
	 */
	private function foreignOnlyColumns($db, $rStage, $rTable): array {
		$db->query(
			'SELECT `COLUMN_NAME` AS `c` FROM `information_schema`.`COLUMNS`
			 WHERE `TABLE_SCHEMA` = ? AND `TABLE_NAME` = ?
			   AND `COLUMN_NAME` NOT IN (
			       SELECT `COLUMN_NAME` FROM `information_schema`.`COLUMNS`
			       WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ?
			   )
			 ORDER BY `ORDINAL_POSITION`;',
			$rStage,
			$rTable,
			$rTable
		);

		$rOut = array();

		foreach (($db->num_rows() > 0 ? $db->get_rows() : array()) as $rRow) {
			$rOut[] = (string) $rRow['c'];
		}

		return $rOut;
	}

	private function printUsage(): void {
		echo "Usage: console.php tools <subcommand>\n\n";
		echo "Subcommands (run as xc_vm):\n";
		echo "  images      Download missing stream/movie/series images from TMDB\n";
		echo "  duplicates  Find and remove duplicate VOD streams\n";
		echo "  bouquets    Clean stale references from bouquets\n\n";
		echo "Subcommands (run as root):\n";
		echo "  rescue      Create temporary rescue access code\n";
		echo "  recaptcha   Disable reCAPTCHA to restore admin panel login\n";
		echo "  access      Regenerate nginx access code configs and reload\n";
		echo "  ports       Regenerate nginx port configs and reload\n";
		echo "  migration   Clear migration database and optionally restore .sql backup\n";
		echo "  user        Create a rescue admin user for the admin panel\n";
		echo "  mysql       Reauthorise load balancers on MySQL\n";
		echo "  database    Restore blank \XC_VM database (requires --confirm)\n";
		echo "  flush       Flush all blocked IPs (iptables + database)\n";
		echo "  import      Analyse a foreign panel dump, and migrate what fits\n";
		echo "              console.php tools import /root/xui.sql           (report only)\n";
		echo "              console.php tools import /root/xui.sql --apply   (write)\n";
		echo "              Against a table that already has rows, copied ids are renumbered\n";
		echo "              and every id list pointing at them is rewritten to match.\n";
	}

	private function processRecaptcha($db): int {
		$db->query('UPDATE `settings` SET `recaptcha_enable` = 0;');
		// SettingsManager is always autoloadable (Composer PSR-4).
		SettingsManager::clearCache();
		echo "reCAPTCHA has been disabled. You can now log in to the admin panel.\n";
		echo "Re-enable it in Settings once outbound access to google.com is restored.\n";
		return 0;
	}

	private function processRescue($db, array $rServers): int {
		$db->query("DELETE FROM `access_codes` WHERE `code` = 'rescue';");
		$db->query("INSERT INTO `access_codes`(`code`, `type`, `enabled`, `groups`) VALUES('rescue', 0, 1, '[1]');");
		echo "A rescue access code has been created.\nPlease ensure you delete this after you're done with it.\n";
		echo 'Access: http://' . $rServers[SERVER_ID]['server_ip'] . ':' . $rServers[SERVER_ID]['http_broadcast_port'] . "/rescue/\n\n";
		AuthRepository::updateCodes();
		shell_exec('sudo ' . MAIN_HOME . 'service reload 2>/dev/null');
		return 0;
	}

	private function processAccess($db, array $rServers): int {
		echo "Generating access code configuration...\n\n";
		AuthRepository::updateCodes();
		shell_exec('sudo ' . MAIN_HOME . 'service reload 2>/dev/null');

		foreach (AuthRepository::getAllCodes(0) as $rCode) {
			echo 'http://' . $rServers[SERVER_ID]['server_ip'] . ':' . $rServers[SERVER_ID]['http_broadcast_port'] . '/' . $rCode['code'] . "/\n";
		}
		echo "\n";
		return 0;
	}

	private function processPorts($db, array $rServers): int {
		echo "Generating port configuration...\n\n";
		$rConfig = array(
			'http' => array_unique(array_merge(
				array($rServers[SERVER_ID]['http_broadcast_port']),
				(explode(',', $rServers[SERVER_ID]['http_ports_add']) ?: array())
			)),
			'https' => array_unique(array_merge(
				array($rServers[SERVER_ID]['https_broadcast_port']),
				(explode(',', $rServers[SERVER_ID]['https_ports_add']) ?: array())
			)),
			'rtmp' => $rServers[SERVER_ID]['rtmp_port'],
		);

		foreach ($rConfig as $rKey => $rPorts) {
			if ($rKey === 'http') {
				$rListen = array();
				foreach ($rPorts as $rPort) {
					if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535) {
						$rListen[] = 'listen ' . intval($rPort) . ';';
					}
				}
				file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/http.conf', implode(' ', $rListen));
				file_put_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/live.conf', 'on_play http://127.0.0.1:' . intval($rPorts[0]) . '/stream/rtmp; on_publish http://127.0.0.1:' . intval($rPorts[0]) . '/stream/rtmp; on_play_done http://127.0.0.1:' . intval($rPorts[0]) . '/stream/rtmp;');
			} elseif ($rKey === 'https') {
				$rListen = array();
				foreach ($rPorts as $rPort) {
					if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535) {
						$rListen[] = 'listen ' . intval($rPort) . ' ssl;';
					}
				}
				file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/https.conf', implode(' ', $rListen));
			} elseif ($rKey === 'rtmp') {
				file_put_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/port.conf', 'listen ' . intval($rPorts) . ';');
			}
		}

		if (count($rConfig['http']) > 0) {
			echo 'HTTP Ports: ' . implode(', ', $rConfig['http']) . "\n";
		}
		if (count($rConfig['https']) > 0) {
			echo 'SSL Ports: ' . implode(', ', $rConfig['https']) . "\n";
		}
		if (!empty($rConfig['rtmp'])) {
			echo 'RTMP Port: ' . $rConfig['rtmp'] . "\n";
		}

		shell_exec('sudo ' . MAIN_HOME . 'service reload 2>/dev/null');
		echo "\n";
		return 0;
	}

	private function processImages($db): void {
		$rImages = array();
		$db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
		$rCount = $db->get_row()['count'];
		if ($rCount > 0) {
			$rSteps = range(0, $rCount, 1000);
			if (!$rSteps) {
				$rSteps = array(0);
			}
			foreach ($rSteps as $rStep) {
				try {
					$db->query('SELECT `stream_icon`, `movie_properties` FROM `streams` LIMIT ' . $rStep . ', 1000;');
					$rResults = $db->get_rows();
					foreach ($rResults as $rResult) {
						$rProperties = json_decode($rResult['movie_properties'], true);
						if (!empty($rResult['stream_icon']) && substr($rResult['stream_icon'], 0, 2) == 's:') {
							$rImages[] = $rResult['stream_icon'];
						}
						if (!empty($rProperties['movie_image']) && substr($rProperties['movie_image'], 0, 2) == 's:') {
							$rImages[] = $rProperties['movie_image'];
						}
						if (!empty($rProperties['cover_big']) && substr($rProperties['cover_big'], 0, 2) == 's:') {
							$rImages[] = $rProperties['cover_big'];
						}
						if (!empty($rProperties['backdrop_path'][0]) && substr($rProperties['backdrop_path'][0], 0, 2) == 's:') {
							$rImages[] = $rProperties['backdrop_path'][0];
						}
					}
				} catch (\Exception $e) {
					echo 'Error: ' . $e . "\n";
				}
			}
		}
		$db->query('SELECT COUNT(*) AS `count` FROM `streams_series`;');
		$rCount = $db->get_row()['count'];
		if ($rCount > 0) {
			$rSteps = range(0, $rCount, 1000);
			if (!$rSteps) {
				$rSteps = array(0);
			}
			foreach ($rSteps as $rStep) {
				try {
					$db->query('SELECT `cover`, `cover_big` FROM `streams_series` LIMIT ' . $rStep . ', 1000;');
					$rResults = $db->get_rows();
					foreach ($rResults as $rResult) {
						if (!empty($rResult['cover']) && substr($rResult['cover'], 0, 2) == 's:') {
							$rImages[] = $rResult['cover'];
						}
						if (!empty($rResult['cover_big']) && substr($rResult['cover_big'], 0, 2) == 's:') {
							$rImages[] = $rResult['cover_big'];
						}
					}
				} catch (\Exception $e) {
					echo 'Error: ' . $e . "\n";
				}
			}
		}
		$rImages = array_unique($rImages);
		foreach ($rImages as $rImage) {
			$rSplit = explode(':', $rImage, 3);
			if (intval($rSplit[1]) == SERVER_ID) {
				$rImageSplit = explode('/', $rSplit[2]);
				$rPathInfo = pathinfo($rImageSplit[count($rImageSplit) - 1]);
				$rImage = $rPathInfo['filename'];
				// Hashed filenames (long URLs) are not reversible — skip them.
				if (strncmp($rImage, 'h_', 2) === 0) {
					continue;
				}
				$rOriginalURL = Encryption::decrypt($rImage, SettingsManager::getAll()['live_streaming_pass'], OPENSSL_EXTRA);
				if (!empty($rOriginalURL) && substr($rOriginalURL, 0, 4) == 'http') {
					if (!file_exists(IMAGES_PATH . $rPathInfo['basename'])) {
						echo 'Downloading: ' . $rOriginalURL . "\n";
						ImageUtils::downloadImage($rOriginalURL);
					}
				}
			}
		}
	}

	private function processDuplicates($db): void {
		$rGroups = $rStreamIDs = array();
		$db->query('SELECT `a`.`id`, `a`.`stream_source` FROM `streams` `a` INNER JOIN (SELECT  `stream_source`, COUNT(*) `totalCount` FROM `streams` WHERE `type` IN (2,5) GROUP BY `stream_source`) `b` ON `a`.`stream_source` = `b`.`stream_source` WHERE `b`.`totalCount` > 1;');
		foreach ($db->get_rows() as $rRow) {
			$rGroups[md5($rRow['stream_source'])][] = $rRow['id'];
		}
		foreach ($rGroups as $rID => $rGroupIDs) {
			array_shift($rGroupIDs);
			foreach ($rGroupIDs as $rStreamID) {
				$rStreamIDs[] = intval($rStreamID);
			}
		}
		if (count($rStreamIDs) > 0) {
			foreach (array_chunk($rStreamIDs, 100) as $rChunk) {
				$this->deleteStreams($db, $rChunk);
			}
		}
	}

	private function processBouquets($db): void {
		$rStreamIDs = array(array(), array());
		$db->query('SELECT `id` FROM `streams`;');
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rStreamIDs[0][] = intval($rRow['id']);
			}
		}
		$db->query('SELECT `id` FROM `streams_series`;');
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rStreamIDs[1][] = intval($rRow['id']);
			}
		}
		$db->query('SELECT * FROM `bouquets` ORDER BY `bouquet_order` ASC;');
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rBouquet) {
				$UpdateData = array(array(), array(), array(), array());
				foreach ((json_decode($rBouquet['bouquet_channels'], true) ?: array()) as $rID) {
					if (0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0])) {
						$UpdateData[0][] = intval($rID);
					}
				}
				foreach ((json_decode($rBouquet['bouquet_movies'], true) ?: array()) as $rID) {
					if (0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0])) {
						$UpdateData[1][] = intval($rID);
					}
				}
				foreach ((json_decode($rBouquet['bouquet_radios'], true) ?: array()) as $rID) {
					if (0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0])) {
						$UpdateData[2][] = intval($rID);
					}
				}
				foreach ((json_decode($rBouquet['bouquet_series'], true) ?: array()) as $rID) {
					if (0 < intval($rID) && in_array(intval($rID), $rStreamIDs[1])) {
						$UpdateData[3][] = intval($rID);
					}
				}
				$db->query("UPDATE `bouquets` SET `bouquet_channels` = '[" . implode(',', array_map('intval', $UpdateData[0])) . "]', `bouquet_movies` = '[" . implode(',', array_map('intval', $UpdateData[1])) . "]', `bouquet_radios` = '[" . implode(',', array_map('intval', $UpdateData[2])) . "]', `bouquet_series` = '[" . implode(',', array_map('intval', $UpdateData[3])) . "]' WHERE `id` = ?;", $rBouquet['id']);
			}
		}
	}

	private function deleteStreams($db, $rIDs): bool {
		$db->query('DELETE FROM `lines_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `mag_claims` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams` WHERE `id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_errors` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_stats` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		\XcVm\Core\Events\EventDispatcher::dispatch(new \XcVm\Core\Events\Stream\StreamsDeletedEvent($rIDs));
		$db->query('DELETE FROM `lines_live` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `recordings` WHERE `created_id` IN (' . implode(',', $rIDs) . ') OR `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
		$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', SERVER_ID, time(), json_encode(array('type' => 'update_streams', 'id' => $rIDs)));
		foreach (array_keys(ServerRepository::getAll()) as $rServerID) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
		}
		return true;
	}
}
