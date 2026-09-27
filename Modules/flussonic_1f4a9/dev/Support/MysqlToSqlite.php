<?php

namespace XcVm\Module\Flussonic\Dev\Support;

/**
 * MysqlToSqlite — best-effort dialect shim for the dev sandbox.
 *
 * The sandbox has no MySQL, so the demo runs the module's *real* SQL against
 * SQLite. Two translation passes are needed:
 *
 *   schema()    rewrites the `CREATE TABLE` statements shipped in the real
 *               database.sql / the panel dump (types, AUTO_INCREMENT, index
 *               clauses, ENGINE/CHARSET tails).
 *   statement() rewrites the handful of MySQL-only constructs the module
 *               emits at runtime (`INSERT IGNORE`).
 *
 * This exists only to make the preview runnable. Production always talks to
 * MySQL through the panel's own DatabaseHandler.
 *
 * @package XC_VM_Module_Flussonic_Dev
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class MysqlToSqlite {

	/**
	 * Translate a script of `CREATE TABLE` statements.
	 *
	 * @param string $rSql One or more MySQL CREATE TABLE statements.
	 * @return string[] SQLite statements, ready to execute in order.
	 */
	public static function schema(string $rSql): array {
		$rStatements = [];

		foreach (self::splitStatements($rSql) as $rStatement) {
			if (stripos($rStatement, 'CREATE TABLE') === false) {
				continue;
			}

			$rStatements[] = self::createTable($rStatement);
		}

		return $rStatements;
	}

	/**
	 * Translate one runtime statement.
	 *
	 * @param string $rSql MySQL statement with `?` placeholders.
	 * @return string
	 */
	public static function statement(string $rSql): string {
		$rSql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $rSql) ?? $rSql;
		$rSql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $rSql) ?? $rSql;

		return $rSql;
	}

	/**
	 * Split a script into statements on `;` at end of line.
	 *
	 * @param string $rSql SQL script.
	 * @return string[]
	 */
	private static function splitStatements(string $rSql): array {
		// Strip comments first so a `;` inside one cannot split a statement.
		$rSql = preg_replace('/^\s*--.*$/m', '', $rSql) ?? $rSql;
		$rSql = preg_replace('#/\*.*?\*/#s', '', $rSql) ?? $rSql;

		$rParts = preg_split('/;\s*[\r\n]/', $rSql) ?: [];
		$rReturn = [];

		foreach ($rParts as $rPart) {
			$rPart = trim(rtrim(trim($rPart), ';'));

			if ($rPart !== '') {
				$rReturn[] = $rPart;
			}
		}

		return $rReturn;
	}

	/**
	 * Rewrite a single CREATE TABLE statement.
	 *
	 * @param string $rSql MySQL CREATE TABLE.
	 * @return string SQLite CREATE TABLE.
	 */
	private static function createTable(string $rSql): string {
		$rOpen = strpos($rSql, '(');
		$rClose = strrpos($rSql, ')');

		if ($rOpen === false || $rClose === false || $rClose <= $rOpen) {
			return $rSql;
		}

		$rHead = substr($rSql, 0, $rOpen);
		$rBody = substr($rSql, $rOpen + 1, $rClose - $rOpen - 1);

		$rHead = preg_replace('/\bCREATE\s+TABLE\b/i', 'CREATE TABLE IF NOT EXISTS', $rHead) ?? $rHead;
		$rHead = preg_replace('/\bIF\s+NOT\s+EXISTS\s+IF\s+NOT\s+EXISTS\b/i', 'IF NOT EXISTS', $rHead) ?? $rHead;

		$rAutoIncrementColumn = '';
		$rLines = [];

		foreach (self::splitColumns($rBody) as $rLine) {
			$rLine = trim(rtrim(trim($rLine), ','));

			if ($rLine === '') {
				continue;
			}

			// Drop plain indexes and foreign keys — SQLite does not accept them
			// inline and the demo does not need them.
			if (preg_match('/^(KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN\s+KEY)\b/i', $rLine)) {
				continue;
			}

			if (preg_match('/^UNIQUE\s+(KEY|INDEX)\s+`?[^`\s(]+`?\s*\((.+)\)$/is', $rLine, $rMatch)) {
				$rLines[] = 'UNIQUE (' . self::indexColumns($rMatch[2]) . ')';
				continue;
			}

			if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/is', $rLine, $rMatch)) {
				$rColumns = self::indexColumns($rMatch[1]);

				// A single-column PK on the AUTO_INCREMENT column is already
				// expressed inline as INTEGER PRIMARY KEY AUTOINCREMENT.
				if ($rAutoIncrementColumn !== '' && strcasecmp($rColumns, '`' . $rAutoIncrementColumn . '`') === 0) {
					continue;
				}

				$rLines[] = 'PRIMARY KEY (' . $rColumns . ')';
				continue;
			}

			$rLines[] = self::column($rLine, $rAutoIncrementColumn);
		}

		return $rHead . "(\n  " . implode(",\n  ", $rLines) . "\n)";
	}

	/**
	 * Split a CREATE TABLE body on top-level commas.
	 *
	 * @param string $rBody Everything between the outer parentheses.
	 * @return string[]
	 */
	private static function splitColumns(string $rBody): array {
		$rParts = [];
		$rDepth = 0;
		$rCurrent = '';
		$rQuote = '';
		$rLength = strlen($rBody);

		for ($rIndex = 0; $rIndex < $rLength; $rIndex++) {
			$rChar = $rBody[$rIndex];

			if ($rQuote !== '') {
				$rCurrent .= $rChar;

				if ($rChar === $rQuote) {
					$rQuote = '';
				}

				continue;
			}

			if ($rChar === "'" || $rChar === '"' || $rChar === '`') {
				$rQuote = $rChar;
				$rCurrent .= $rChar;
				continue;
			}

			if ($rChar === '(') {
				$rDepth++;
			} elseif ($rChar === ')') {
				$rDepth--;
			}

			if ($rChar === ',' && $rDepth === 0) {
				$rParts[] = $rCurrent;
				$rCurrent = '';
				continue;
			}

			$rCurrent .= $rChar;
		}

		if (trim($rCurrent) !== '') {
			$rParts[] = $rCurrent;
		}

		return $rParts;
	}

	/**
	 * Normalise an index column list: drop prefix lengths and sort directions.
	 *
	 * @param string $rColumns Raw column list from a KEY clause.
	 * @return string
	 */
	private static function indexColumns(string $rColumns): string {
		$rParts = [];

		foreach (explode(',', $rColumns) as $rColumn) {
			$rColumn = trim($rColumn);
			$rColumn = preg_replace('/\(\s*\d+\s*\)/', '', $rColumn) ?? $rColumn;
			$rColumn = preg_replace('/\s+(ASC|DESC)$/i', '', $rColumn) ?? $rColumn;
			$rParts[] = trim($rColumn);
		}

		return implode(', ', $rParts);
	}

	/**
	 * Rewrite one column definition.
	 *
	 * @param string $rLine                 MySQL column definition.
	 * @param string $rAutoIncrementColumn  Set to the column name when it carries AUTO_INCREMENT.
	 * @return string
	 */
	private static function column(string $rLine, string &$rAutoIncrementColumn): string {
		if (!preg_match('/^`?([^`\s]+)`?\s+(.*)$/s', $rLine, $rMatch)) {
			return $rLine;
		}

		$rName = $rMatch[1];
		$rRest = $rMatch[2];

		if (preg_match('/\bAUTO_INCREMENT\b/i', $rRest)) {
			$rAutoIncrementColumn = $rName;

			return '`' . $rName . '` INTEGER PRIMARY KEY AUTOINCREMENT';
		}

		// Type
		$rType = 'TEXT';

		if (preg_match('/^\s*(\w+)/', $rRest, $rTypeMatch)) {
			$rType = self::mapType(strtolower($rTypeMatch[1]));
		}

		$rFlags = '';

		if (preg_match('/\bNOT\s+NULL\b/i', $rRest)) {
			$rFlags .= ' NOT NULL';
		}

		if (preg_match("/\bDEFAULT\s+('(?:[^']|'')*'|[^\s,]+)/i", $rRest, $rDefaultMatch)) {
			$rDefault = trim($rDefaultMatch[1]);

			if (preg_match('/^current_timestamp(\(\))?$/i', $rDefault)) {
				$rDefault = 'CURRENT_TIMESTAMP';
			}

			$rFlags .= ' DEFAULT ' . $rDefault;
		}

		return '`' . $rName . '` ' . $rType . $rFlags;
	}

	/**
	 * Map a MySQL base type onto a SQLite storage class.
	 *
	 * @param string $rType Lowercased MySQL type keyword.
	 * @return string
	 */
	private static function mapType(string $rType): string {
		if (in_array($rType, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'bit', 'year'], true)) {
			return 'INTEGER';
		}

		if (in_array($rType, ['decimal', 'numeric', 'float', 'double', 'real'], true)) {
			return 'REAL';
		}

		if (in_array($rType, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true)) {
			return 'BLOB';
		}

		return 'TEXT';
	}
}
