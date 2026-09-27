<?php

namespace XcVm\Module\Flussonic\Dev\Support;

use XcVm\Core\Database\DatabaseHandler;

/**
 * DevDatabase — SQLite-backed stand-in for the panel's DatabaseHandler.
 *
 * It extends the real handler so every consumer (the module services, the core
 * StreamRepository / BouquetService / CategoryService …) keeps using the exact
 * same API — `query($sql, ...$binds)`, `get_rows()`, `get_row()`, `num_rows()`,
 * `last_insert_id()`. Only two things are replaced:
 *
 *   - the connection: a SQLite file instead of MySQL;
 *   - `query()`: dialect translation plus an eager result buffer, because
 *     PDO/SQLite reports rowCount() == 0 for SELECTs while the panel's code
 *     relies on it to decide whether a result exists.
 *
 * @package XC_VM_Module_Flussonic_Dev
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DevDatabase extends DatabaseHandler {

	/** @var string[] Executed statements, newest last (shown in the dev banner). */
	public $log = [];

	/**
	 * @param string $rFile Path to the SQLite database file.
	 */
	public function __construct(string $rFile) {
		// Deliberately skips the parent constructor: it would try to reach the
		// XC_VM MySQL server through the proprietary extension.
		$this->dbh = new \PDO('sqlite:' . $rFile);
		$this->dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->dbh->exec('PRAGMA foreign_keys = OFF;');
		$this->connected = true;
	}

	/**
	 * Execute a statement.
	 *
	 * @param string $query    SQL with `?` placeholders.
	 * @param mixed  $buffered First bind value (legacy positional overload).
	 * @return bool
	 */
	public function query($query, $buffered = false) {
		$rBinds = [];
		$rArgs = func_get_args();
		$rCount = count($rArgs);

		for ($rIndex = 1; $rIndex < $rCount; $rIndex++) {
			$rValue = $rArgs[$rIndex];

			if ($rValue === true) {
				// The legacy "unbuffered" flag — not a bind value.
				continue;
			}

			if ($rValue === null || (is_string($rValue) && strtolower($rValue) === 'null')) {
				$rBinds[] = null;
				continue;
			}

			$rBinds[] = is_bool($rValue) ? (int) $rValue : $rValue;
		}

		$rSql = MysqlToSqlite::statement($query);
		$this->last_query = $rSql;
		$this->log[] = $rSql;

		try {
			$rStatement = $this->dbh->prepare($rSql);
			$rStatement->execute($rBinds);
		} catch (\Throwable $rThrowable) {
			$this->result = null;
			$this->setError($rThrowable->getMessage() . ' — ' . $rSql);

			return false;
		}

		if (preg_match('/^\s*\(?\s*(SELECT|PRAGMA|SHOW|WITH)\b/i', $rSql)) {
			$this->result = new DevResult($rStatement->fetchAll(\PDO::FETCH_ASSOC));
		} else {
			$this->result = new DevResult([], $rStatement->rowCount());
		}

		$this->setError('');

		return true;
	}

	/**
	 * Run a raw statement with no translation and no result buffering.
	 *
	 * @param string $rSql SQL to execute.
	 * @return void
	 */
	public function exec(string $rSql): void {
		$this->dbh->exec($rSql);
	}

	/**
	 * Store the driver message for error().
	 *
	 * @param string $rMessage Message, '' to clear.
	 * @return void
	 */
	private function setError(string $rMessage): void {
		$this->lastError = $rMessage;
	}
}

/**
 * DevResult — the slice of PDOStatement the panel's Database class touches.
 *
 * Rows are fetched eagerly so rowCount() is meaningful for SELECTs under
 * SQLite, which is the behaviour the panel's code was written against.
 *
 * @package XC_VM_Module_Flussonic_Dev
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DevResult {

	/** @var array<int,array<string,mixed>> Buffered rows. */
	private array $rows;

	/** @var int Rows affected (writes) or available (reads). */
	private int $count;

	/** @var int Cursor for fetch(). */
	private int $cursor = 0;

	/**
	 * @param array<int,array<string,mixed>> $rRows     Buffered rows.
	 * @param int|null                       $rAffected Affected-row count for writes.
	 */
	public function __construct(array $rRows, ?int $rAffected = null) {
		$this->rows = $rRows;
		$this->count = $rAffected ?? count($rRows);
	}

	/**
	 * @return int
	 */
	public function rowCount(): int {
		return $this->count;
	}

	/**
	 * @param int|null $rMode Ignored — always associative.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetchAll($rMode = null): array {
		return $this->rows;
	}

	/**
	 * @param int|null $rMode PDO::FETCH_NUM returns a positional row.
	 * @return array<string,mixed>|array<int,mixed>|false
	 */
	public function fetch($rMode = null) {
		if (!isset($this->rows[$this->cursor])) {
			return false;
		}

		$rRow = $this->rows[$this->cursor];
		$this->cursor++;

		return $rMode === \PDO::FETCH_ASSOC ? $rRow : array_merge($rRow, array_values($rRow));
	}

	/**
	 * @return int
	 */
	public function columnCount(): int {
		return $this->rows === [] ? 0 : count($this->rows[0]);
	}

	/**
	 * @return void
	 */
	public function debugDumpParams(): void {
	}

	/**
	 * @return array<int,mixed>
	 */
	public function errorInfo(): array {
		return ['00000', null, null];
	}
}
