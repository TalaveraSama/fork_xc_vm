<?php

namespace XcVm\Module\Flussonic\Dev\Support;

use XcVm\Module\Flussonic\Service\FlussonicServerService;
use XcVm\Module\Flussonic\Service\FlussonicSyncService;

/**
 * DevSeed — creates the sandbox database and fills it with demo content.
 *
 * The schema is not hand-written: it is the module's own `database.sql` plus
 * the `CREATE TABLE` blocks of the panel tables the import touches, lifted
 * straight from the repository's SQL dump and translated to SQLite. That way
 * the preview exercises the shipped schema rather than a convenient copy.
 *
 * @package XC_VM_Module_Flussonic_Dev
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DevSeed {

	/** Panel tables the module reads or writes during an import. */
	private const PANEL_TABLES = [
		'streams',
		'streams_servers',
		'streams_categories',
		'bouquets',
		'servers',
		'settings',
		'crontab',
	];

	/**
	 * Create the schema and the minimal panel content.
	 *
	 * Split from demoContent() because the panel settings must be loaded into
	 * SettingsManager before anything touches the stream pipeline.
	 *
	 * @param DevDatabase $rDb       Sandbox connection.
	 * @param string      $rRepoRoot Repository root (with trailing slash).
	 * @return void
	 */
	public static function install(DevDatabase $rDb, string $rRepoRoot): void {
		self::schema($rDb, $rRepoRoot);
		self::panelContent($rDb);
	}

	/**
	 * Register the demo origins and run a real discovery + import pass.
	 *
	 * @param DevDatabase $rDb Sandbox connection.
	 * @return void
	 */
	public static function demoContent(DevDatabase $rDb): void {
		self::flussonicServers($rDb);

		// A real discovery pass against the sample origins, so the preview
		// opens on a populated catalogue.
		FlussonicSyncService::syncAll(true);

		// Show both halves of the workflow: the first origin arrives with its
		// channels already imported, the second one waits for the operator.
		FlussonicSyncService::importStreams(1, [], true);
	}

	/**
	 * Create every table.
	 *
	 * @param DevDatabase $rDb       Sandbox connection.
	 * @param string      $rRepoRoot Repository root (with trailing slash).
	 * @return void
	 */
	private static function schema(DevDatabase $rDb, string $rRepoRoot): void {
		$rSql = (string) file_get_contents($rRepoRoot . 'Modules/flussonic_1f4a9/database.sql');

		foreach (MysqlToSqlite::schema($rSql) as $rStatement) {
			$rDb->exec($rStatement . ';');
		}

		foreach (self::panelSchema($rRepoRoot) as $rStatement) {
			$rDb->exec($rStatement . ';');
		}
	}

	/**
	 * Extract the panel tables' CREATE statements from the repository dump.
	 *
	 * @param string $rRepoRoot Repository root (with trailing slash).
	 * @return string[] SQLite CREATE statements.
	 */
	private static function panelSchema(string $rRepoRoot): array {
		$rDump = '';

		foreach (glob($rRepoRoot . 'backup_*.sql') ?: [] as $rFile) {
			$rDump = (string) file_get_contents($rFile);
			break;
		}

		if ($rDump === '') {
			throw new \RuntimeException('DevSeed: no backup_*.sql dump found to build the panel schema from.');
		}

		$rStatements = [];

		foreach (self::PANEL_TABLES as $rTable) {
			if (!preg_match('/CREATE TABLE `' . preg_quote($rTable, '/') . '`\s*\(.*?\n\)[^;]*;/s', $rDump, $rMatch)) {
				throw new \RuntimeException('DevSeed: table `' . $rTable . '` not found in the dump.');
			}

			$rStatements = array_merge($rStatements, MysqlToSqlite::schema($rMatch[0]));
		}

		return $rStatements;
	}

	/**
	 * Minimal panel content: one streaming node, categories, bouquets, settings.
	 *
	 * @param DevDatabase $rDb Sandbox connection.
	 * @return void
	 */
	private static function panelContent(DevDatabase $rDb): void {
		$rNow = time();
		$rDb->query(
			"INSERT INTO `servers`(`id`, `server_type`, `server_name`, `server_ip`, `is_main`, `status`, `last_check_ago`) VALUES(1, 0, 'Main Server', '127.0.0.1', 1, 1, ?);",
			$rNow
		);
		$rDb->query(
			"INSERT INTO `servers`(`id`, `server_type`, `server_name`, `server_ip`, `is_main`, `status`, `last_check_ago`) VALUES(2, 0, 'Edge Server EU', '10.20.0.2', 0, 1, ?);",
			$rNow
		);

		$rCategories = [
			[1, 'News'],
			[2, 'Sports'],
			[3, 'Documentary'],
			[4, 'Local TV'],
			[5, 'Flussonic Imports'],
		];

		foreach ($rCategories as $rIndex => $rCategory) {
			$rDb->query(
				"INSERT INTO `streams_categories`(`id`, `category_type`, `category_name`, `cat_order`, `is_adult`) VALUES(?, 'live', ?, ?, 0);",
				$rCategory[0],
				$rCategory[1],
				$rIndex + 1
			);
		}

		$rDb->query("INSERT INTO `bouquets`(`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_series`, `bouquet_radios`, `bouquet_order`) VALUES(1, 'Full Package', '[]', '[]', '[]', '[]', 1);");
		$rDb->query("INSERT INTO `bouquets`(`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_series`, `bouquet_radios`, `bouquet_order`) VALUES(2, 'Basic', '[]', '[]', '[]', '[]', 2);");

		$rDb->query("INSERT INTO `settings`(`id`, `enable_search`, `header_stats`, `js_navigate`) VALUES(1, 0, 0, 0);");
		$rDb->query("INSERT INTO `crontab`(`filename`, `time`, `enabled`) VALUES('flussonic', '*/5 * * * *', 1);");
	}

	/**
	 * Register the two demo origins through the module's own save() path.
	 *
	 * @param DevDatabase $rDb Sandbox connection.
	 * @return void
	 */
	private static function flussonicServers(DevDatabase $rDb): void {
		FlussonicServerService::save([
			'name'             => 'Flussonic Main (demo)',
			'api_scheme'       => 'http',
			'api_host'         => 'demo.flussonic.local',
			'api_port'         => 8080,
			'api_username'     => DevTransport::DEMO_USER,
			'api_password'     => DevTransport::DEMO_PASSWORD,
			'verify_tls'       => 0,
			'protocol'         => 'hls',
			'enabled'          => 1,
			'auto_import'      => 0,
			'auto_remove'      => 1,
			'sync_interval'    => 300,
			'target_server_id' => 1,
			'category_id'      => 5,
			'bouquets'         => [1],
			'stream_prefix'    => '',
		]);

		FlussonicServerService::save([
			'name'             => 'Flussonic Edge NI (demo)',
			'api_scheme'       => 'http',
			'api_host'         => 'edge.flussonic.local',
			'api_port'         => 8080,
			'api_username'     => DevTransport::DEMO_USER,
			'api_password'     => DevTransport::DEMO_PASSWORD,
			'verify_tls'       => 0,
			'protocol'         => 'mpegts',
			'enabled'          => 1,
			'auto_import'      => 0,
			'auto_remove'      => 1,
			'sync_interval'    => 300,
			'target_server_id' => 2,
			'category_id'      => 4,
			'bouquets'         => [1, 2],
			'stream_prefix'    => 'NI |',
		]);

		// A third, deliberately unreachable origin so the error states in the
		// UI (red status dot, last_error text) are visible in the preview.
		FlussonicServerService::save([
			'name'         => 'Offline Origin (demo)',
			'api_scheme'   => 'http',
			'api_host'     => 'unreachable.flussonic.local',
			'api_port'     => 8080,
			'api_username' => DevTransport::DEMO_USER,
			'api_password' => 'wrong-password',
			'verify_tls'   => 0,
			'protocol'     => 'hls',
			'enabled'      => 1,
			'auto_remove'  => 1,
		]);
	}
}
