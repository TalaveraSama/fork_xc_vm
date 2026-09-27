<?php

/**
 * Dev sandbox front controller.
 *
 * Stands in for Public/index.php: resolves the requested admin page, boots the
 * sandbox and hands the request to the module's REAL controller. Run it with
 * PHP's built-in server from the repository root:
 *
 *     php -S 0.0.0.0:8080 Modules/flussonic_1f4a9/dev/index.php
 *
 * @package XC_VM_Module_Flussonic_Dev
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

$rUri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rPath = trim($rUri, '/');
$rRepoRoot = dirname(__DIR__, 3) . '/';

// ── Static assets: served straight from the panel's admin theme ──
if (strncmp($rPath, 'assets/', 7) === 0) {
	$rFile = realpath($rRepoRoot . 'Public/assets/admin/' . substr($rPath, 7));
	$rBase = realpath($rRepoRoot . 'Public/assets/admin');

	if ($rFile === false || $rBase === false || strncmp($rFile, $rBase, strlen($rBase)) !== 0 || !is_file($rFile)) {
		http_response_code(404);
		exit;
	}

	$rTypes = [
		'css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png',
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
		'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
		'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject', 'ico' => 'image/x-icon',
		'map' => 'application/json', 'json' => 'application/json',
	];
	$rExtension = strtolower(pathinfo($rFile, PATHINFO_EXTENSION));

	header('Content-Type: ' . ($rTypes[$rExtension] ?? 'application/octet-stream'));
	header('Cache-Control: public, max-age=3600');
	readfile($rFile);
	exit;
}

if ($rPath === '' || $rPath === 'index') {
	header('Location: /flussonic');
	exit;
}

$rPage = strtolower(basename($rPath, '.php'));

// PAGE_NAME is what AdminHelpers::getPageName() (and therefore topbar.php)
// reads to decide which page it is decorating.
define('PAGE_NAME', $rPage);

require __DIR__ . '/bootstrap.php';

use XcVm\Core\Http\Router;
use XcVm\Module\Flussonic\FlussonicController;
use XcVm\Module\Flussonic\FlussonicModule;

$rModule = new FlussonicModule();
$rRouter = new Router();

if (method_exists($rRouter, 'beginModuleRegistration')) {
	$rRouter->beginModuleRegistration($rModule->getName());
}

$rModule->registerRoutes($rRouter);

// ── JSON actions ──
if ($rPage === 'api') {
	$rAction = (string) ($_REQUEST['action'] ?? '');

	if (!$rRouter->hasApiRoute($rAction)) {
		header('Content-Type: application/json');
		echo json_encode(['result' => false, 'error' => 'Unknown action "' . $rAction . '" in the dev sandbox.']);
		exit;
	}

	$rRouter->dispatchApi($rAction);
	exit;
}

// ── Module pages ──
$rPages = [
	'flussonic'         => 'index',
	'flussonic_server'  => 'server',
	'flussonic_streams' => 'streams',
];

if (isset($rPages[$rPage])) {
	$rController = new FlussonicController();
	$rController->{$rPages[$rPage]}();
	exit;
}

// ── Anything else: a stub so core links in the navbar are not dead ends ──
require __DIR__ . '/Support/placeholder.php';
