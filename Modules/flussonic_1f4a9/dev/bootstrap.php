<?php

/**
 * Dev sandbox bootstrap for the Flussonic module.
 *
 * Boots just enough of XC_VM to run the module's REAL controller, services and
 * views outside a production install:
 *
 *   - PSR-4 autoloading for `XcVm\` (repo root) and `XcVm\Module\Flussonic\`
 *     (this module), mirroring what ModuleLoader does in production;
 *   - a SQLite-backed DatabaseHandler subclass instead of MySQL;
 *   - the globals the admin layout expects ($db, $language, $rUserInfo,
 *     $rPermissions, $rSettings, $rServers, …);
 *   - the module's navbar entries registered through the real NavbarRegistry;
 *   - a recorded Flussonic API transport so "Test connection", "Sync" and
 *     "Import" all do real work against sample payloads.
 *
 * Nothing here ships to production — see dev/README.md.
 *
 * @package XC_VM_Module_Flussonic_Dev
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Module\CoreNavbarProvider;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\Flussonic\Dev\Support\DevDatabase;
use XcVm\Module\Flussonic\Dev\Support\DevSeed;
use XcVm\Module\Flussonic\Dev\Support\DevTransport;
use XcVm\Module\Flussonic\FlussonicModule;
use XcVm\Module\Flussonic\Service\FlussonicServerService;

// ───────────────────────────────────────────────────────────
//  Paths and constants
// ───────────────────────────────────────────────────────────

$rModuleDir = dirname(__DIR__);
$rRepoRoot = dirname($rModuleDir, 2) . '/';
$rDevDir = __DIR__ . '/';
$rRuntimeDir = $rDevDir . 'runtime/';

if (!is_dir($rRuntimeDir)) {
	mkdir($rRuntimeDir, 0o777, true);
}

define('MAIN_HOME', $rRepoRoot);
define('TMP_PATH', $rRuntimeDir);
define('CACHE_TMP_PATH', $rRuntimeDir);
define('LOGS_TMP_PATH', $rRuntimeDir);
define('STREAMS_TMP_PATH', $rRuntimeDir);
// Plain constants file (version, feature flags) with no dependencies.
require_once $rRepoRoot . 'Core/Config/AppConfig.php';

define('SERVER_ID', 1);
define('SERVER_PORT', 8080);
define('PHP_BIN', '/usr/bin/php');
define('XC_VM_DEV_SANDBOX', true);

// Status codes, mirroring bootstrap.php. Only the ones the admin layout and
// the module's own views reference are needed, but the full list is cheap and
// keeps core includes (post.php / modals.php) happy.
foreach ([
	'STATUS_FAILURE' => 0, 'STATUS_SUCCESS' => 1, 'STATUS_SUCCESS_MULTI' => 2,
	'STATUS_CODE_LENGTH' => 3, 'STATUS_NO_SOURCES' => 4, 'STATUS_DISABLED' => 5,
	'STATUS_NOT_ADMIN' => 6, 'STATUS_INVALID_EMAIL' => 7, 'STATUS_INVALID_PASSWORD' => 8,
	'STATUS_INVALID_IP' => 9, 'STATUS_INVALID_PLAYLIST' => 10, 'STATUS_INVALID_NAME' => 11,
	'STATUS_INVALID_CAPTCHA' => 12, 'STATUS_INVALID_CODE' => 13, 'STATUS_INVALID_DATE' => 14,
	'STATUS_INVALID_FILE' => 15, 'STATUS_INVALID_GROUP' => 16, 'STATUS_INVALID_DATA' => 17,
	'STATUS_INVALID_DIR' => 18, 'STATUS_INVALID_MAC' => 19, 'STATUS_EXISTS_CODE' => 20,
	'STATUS_EXISTS_NAME' => 21, 'STATUS_EXISTS_USERNAME' => 22, 'STATUS_EXISTS_MAC' => 23,
	'STATUS_EXISTS_SOURCE' => 24, 'STATUS_EXISTS_IP' => 25, 'STATUS_EXISTS_DIR' => 26,
	'STATUS_SUCCESS_REPLACE' => 27, 'STATUS_FLUSH' => 28, 'STATUS_TOO_MANY_RESULTS' => 29,
	'STATUS_SPACE_ISSUE' => 30, 'STATUS_INVALID_USER' => 31, 'STATUS_CERTBOT' => 32,
	'STATUS_CERTBOT_INVALID' => 33, 'STATUS_INVALID_INPUT' => 34, 'STATUS_NOT_RESELLER' => 35,
	'STATUS_NO_TRIALS' => 36, 'STATUS_INSUFFICIENT_CREDITS' => 37, 'STATUS_INVALID_PACKAGE' => 38,
	'STATUS_INVALID_TYPE' => 39, 'STATUS_INVALID_USERNAME' => 40, 'STATUS_INVALID_SUBRESELLER' => 41,
	'STATUS_NO_DESCRIPTION' => 42, 'STATUS_NO_KEY' => 43, 'STATUS_EXISTS_HMAC' => 44,
	'STATUS_CERTBOT_RUNNING' => 45, 'STATUS_RESERVED_CODE' => 46, 'STATUS_NO_TITLE' => 47,
	'STATUS_NO_SOURCE' => 48,
] as $rConstant => $rValue) {
	define($rConstant, $rValue);
}

// ───────────────────────────────────────────────────────────
//  Autoloading
// ───────────────────────────────────────────────────────────

spl_autoload_register(static function (string $rClass) use ($rRepoRoot, $rModuleDir): void {
	$rPrefixes = [
		'XcVm\\Module\\Flussonic\\Dev\\' => $rModuleDir . '/dev/',
		'XcVm\\Module\\Flussonic\\'      => $rModuleDir . '/',
		'XcVm\\'                         => $rRepoRoot,
	];

	foreach ($rPrefixes as $rPrefix => $rBase) {
		if (strncmp($rClass, $rPrefix, strlen($rPrefix)) !== 0) {
			continue;
		}

		$rRelative = substr($rClass, strlen($rPrefix));
		$rFile = $rBase . str_replace('\\', '/', $rRelative) . '.php';

		if (is_file($rFile)) {
			require_once $rFile;
		}

		return;
	}
});

require_once $rRepoRoot . 'vendor/autoload.php';
require_once $rDevDir . 'Support/MysqlToSqlite.php';
require_once $rDevDir . 'Support/DevDatabase.php';
require_once $rDevDir . 'Support/DevTransport.php';
require_once $rDevDir . 'Support/DevSeed.php';

// ───────────────────────────────────────────────────────────
//  Database (SQLite sandbox, rebuilt on demand)
// ───────────────────────────────────────────────────────────

$rDbFile = $rRuntimeDir . 'flussonic-demo.sqlite';
$rFresh = !is_file($rDbFile) || isset($_GET['reset']);

if ($rFresh && is_file($rDbFile)) {
	unlink($rDbFile);
}

$db = new DevDatabase($rDbFile);
DatabaseFactory::set($db);
$GLOBALS['db'] = $db;

// The module talks to recorded Flussonic payloads instead of the network.
FlussonicServerService::setTransport(new DevTransport());

// ───────────────────────────────────────────────────────────
//  Globals the admin layout and the core services expect
// ───────────────────────────────────────────────────────────

Translator::init($rRepoRoot . 'resources/langs/');
Translator::setLanguage('en');
$language = Translator::class;

$rUserInfo = [
	'id'              => 1,
	'username'        => 'admin',
	'member_group_id' => 1,
	'hue'             => '',
	'theme'           => 0,
	'email'           => 'admin@example.com',
	'credits'         => 0,
	'notes'           => '',
];

$rPermissions = [
	'is_admin'    => 1,
	'advanced'    => [],
	'all_reports' => [],
];

$rMobile = false;
$rThemes = [];
$rHues = [];
$rModal = false;
$rUpdate = false;
$rServerError = false;
$allServersHealthy = true;

if ($rFresh) {
	DevSeed::install($db, $rRepoRoot);
}

$db->query('SELECT * FROM `settings` LIMIT 1;');
$rSettings = $db->num_rows() > 0 ? $db->get_row() : [];
$rSettings += [
	'enable_search' => 0,
	'header_stats'  => 0,
	'js_navigate'   => 0,
	'enable_cache'  => 0,
	'dark_mode'     => 0,
];
SettingsManager::set($rSettings);

$rServers = ServerRepository::getStreamingSimple($rPermissions);
$rProxyServers = [];
$allServers = $rServers;

foreach ([
	'db', 'language', 'rUserInfo', 'rPermissions', 'rSettings', 'rServers',
	'allServers', 'rProxyServers', 'rMobile', 'rThemes', 'rHues', 'rModal',
	'rUpdate', 'rServerError', 'allServersHealthy',
] as $rGlobal) {
	$GLOBALS[$rGlobal] = $$rGlobal;
}

// Everything the import pipeline reads ($rSettings, $rServers) is live now, so
// the demo content is produced by exactly the code paths production uses.
if ($rFresh) {
	DevSeed::demoContent($db);
}

// ───────────────────────────────────────────────────────────
//  Navbar (core entries + this module's)
// ───────────────────────────────────────────────────────────

CoreNavbarProvider::register();
(new FlussonicModule())->registerNavbar(new XcVm\Core\Module\NavbarRegistry());
