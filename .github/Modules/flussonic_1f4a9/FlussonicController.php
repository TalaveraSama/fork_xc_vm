<?php

namespace XcVm\Module\Flussonic;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Module\Flussonic\Service\FlussonicServerService;
use XcVm\Module\Flussonic\Service\FlussonicStreamService;
use XcVm\Module\Flussonic\Service\FlussonicSyncService;
use XcVm\Module\Flussonic\Service\FlussonicUrlBuilder;

/**
 * FlussonicController — admin pages and JSON actions of the Flussonic module.
 *
 * Pages
 *   flussonic          Origin servers list (status, streams found, actions)
 *   flussonic_server   Add / edit one Flussonic origin
 *   flussonic_streams  Browse every stream available on the origins and import
 *
 * JSON actions (dispatched through `./api?action=...`)
 *   flussonic_probe    Test connectivity/credentials, optionally un-saved ones
 *   flussonic_sync     Refresh the catalogue of one server (or all)
 *   flussonic_import   Create panel channels from selected catalogue rows
 *   flussonic_server   delete / enable / disable / forget / refresh_sources
 *
 * @see Service\FlussonicSyncService
 * @see FlussonicModule
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FlussonicController {

	/** @var string Absolute path to this module's views directory. */
	protected $viewsPath;

	public function __construct() {
		$this->viewsPath = __DIR__ . '/views';

		require_once MAIN_HOME . 'Public/Views/layouts/admin.php';
		require_once MAIN_HOME . 'Public/Views/layouts/footer.php';
	}

	// ───────────────────────────────────────────────────────────
	//  Pages
	// ───────────────────────────────────────────────────────────

	/**
	 * Origin servers list.
	 *
	 * @return void
	 */
	public function index() {
		global $db, $language, $rSettings, $rMobile, $rUserInfo, $rPermissions, $rServers, $rThemes, $rHues;

		$rFlussonicServers = FlussonicServerService::getAll();
		$rStats = FlussonicStreamService::statsByServer();
		$_TITLE = 'Flussonic Servers';
		$_STATUS = $this->status();

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/flussonic.php';
	}

	/**
	 * Add / edit an origin. Handles its own POST submit.
	 *
	 * @return void
	 */
	public function server() {
		global $db, $language, $rSettings, $rMobile, $rUserInfo, $rPermissions, $rServers, $rThemes, $rHues;

		$rError = '';
		$rFlussonic = null;
		$rID = (int) ($this->input('id', 0));

		if ($rID > 0) {
			$rFlussonic = FlussonicServerService::getById($rID);

			if ($rFlussonic === null) {
				AdminHelpers::goHome();
				exit;
			}
		}

		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
			if (!Authorization::check('adv', 'streams')) {
				AdminHelpers::goHome();
				exit;
			}

			$rPayload = $this->payload();

			if ($rFlussonic !== null) {
				$rPayload['edit'] = (int) $rFlussonic['id'];
			}

			$rSaved = FlussonicServerService::save($rPayload);

			if ($rSaved['status']) {
				// A saved origin is immediately probed + catalogued so the
				// operator lands on a populated list instead of an empty one.
				FlussonicSyncService::syncServer($rSaved['id']);
				header('Location: flussonic?status=1');
				exit;
			}

			$rError = $rSaved['error'];
			$rFlussonic = array_merge($rFlussonic ?? self::defaults(), $rPayload);
		}

		if ($rFlussonic === null) {
			$rFlussonic = self::defaults();
		}

		$rCategories = CategoryService::getAllByType('live');
		$rBouquets = BouquetService::getAllSimple();
		$rProtocols = FlussonicUrlBuilder::PROTOCOLS;
		$rSelectedBouquets = json_decode((string) ($rFlussonic['bouquets'] ?? '[]'), true) ?: [];
		$_TITLE = $rID > 0 ? 'Edit Flussonic Server' : 'Add Flussonic Server';

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/flussonic_server.php';
	}

	/**
	 * Browse the streams available on the configured origins.
	 *
	 * @return void
	 */
	public function streams() {
		global $db, $language, $rSettings, $rMobile, $rUserInfo, $rPermissions, $rServers, $rThemes, $rHues;

		$rServerFilter = (int) ($this->input('server', 0));
		$rStatusFilter = (string) ($this->input('filter', ''));
		$rSearch = trim((string) ($this->input('search', '')));

		$rFlussonicServers = FlussonicServerService::getAllKeyed();
		$rRows = FlussonicStreamService::getAll([
			'server_id' => $rServerFilter,
			'status'    => $rStatusFilter,
			'search'    => $rSearch,
		]);
		$rStats = FlussonicStreamService::statsByServer();
		$_TITLE = 'Flussonic Streams';
		$_STATUS = $this->status();

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/flussonic_streams.php';
	}

	// ───────────────────────────────────────────────────────────
	//  JSON actions
	// ───────────────────────────────────────────────────────────

	/**
	 * action=flussonic_probe — connectivity + credentials check.
	 *
	 * Accepts either `id` (a saved origin) or the raw form fields, so the
	 * "Test connection" button works before the row has ever been saved.
	 *
	 * @return void
	 */
	public function apiProbe() {
		$rID = (int) $this->input('id', 0);
		$rStored = $rID > 0 ? FlussonicServerService::getById($rID) : null;
		$rFromForm = $this->input('api_host', null) !== null;

		if (!$rFromForm && $rStored === null) {
			$this->json(['result' => false, 'error' => 'Server not found.']);
		}

		if ($rFromForm) {
			$rServer = [
				'api_scheme'   => (string) $this->input('api_scheme', 'http'),
				'api_host'     => (string) $this->input('api_host', ''),
				'api_port'     => (int) $this->input('api_port', 80),
				'api_username' => (string) $this->input('api_username', ''),
				'api_password' => (string) $this->input('api_password', ''),
				'bearer_token' => (string) $this->input('bearer_token', ''),
				'verify_tls'   => (int) $this->input('verify_tls', 0),
			];

			// The edit form never echoes secrets back, so an empty field means
			// "keep what is stored" rather than "authenticate anonymously".
			if ($rStored !== null) {
				if ($rServer['api_password'] === '') {
					$rServer['api_password'] = (string) $rStored['api_password'];
				}
				if ($rServer['bearer_token'] === '') {
					$rServer['bearer_token'] = (string) $rStored['bearer_token'];
				}
			}
		} else {
			$rServer = $rStored;
		}

		$rProbe = FlussonicServerService::probe($rServer);

		// Only let a probe update the saved row when it actually targeted that
		// endpoint — testing edited-but-unsaved values must not flip its status.
		if ($rStored !== null
			&& (string) $rServer['api_host'] === (string) $rStored['api_host']
			&& (int) $rServer['api_port'] === (int) $rStored['api_port']) {
			FlussonicServerService::recordStatus($rID, $rProbe['ok'], $rProbe['error'], $rProbe['streams']);
		}

		$this->json([
			'result'  => $rProbe['ok'],
			'error'   => $rProbe['error'],
			'streams' => $rProbe['streams'],
			'version' => $rProbe['version'],
		]);
	}

	/**
	 * action=flussonic_sync — refresh the catalogue.
	 *
	 * @return void
	 */
	public function apiSync() {
		$rID = (int) $this->input('id', 0);

		if ($rID > 0) {
			$rResult = FlussonicSyncService::syncServer($rID);

			$this->json([
				'result'   => $rResult['ok'],
				'error'    => $rResult['error'],
				'found'    => $rResult['found'],
				'new'      => $rResult['new'],
				'removed'  => $rResult['removed'],
				'imported' => $rResult['imported'],
			]);
		}

		$rResults = FlussonicSyncService::syncAll(true);
		$rFound = 0;
		$rNew = 0;
		$rImported = 0;
		$rErrors = [];

		foreach ($rResults as $rResult) {
			$rFound += $rResult['found'];
			$rNew += $rResult['new'];
			$rImported += $rResult['imported'];

			if (!$rResult['ok'] && $rResult['error'] !== '') {
				$rErrors[] = $rResult['server'] . ': ' . $rResult['error'];
			}
		}

		$this->json([
			'result'   => $rErrors === [],
			'error'    => implode(' | ', $rErrors),
			'servers'  => count($rResults),
			'found'    => $rFound,
			'new'      => $rNew,
			'imported' => $rImported,
		]);
	}

	/**
	 * action=flussonic_import — turn catalogue rows into panel channels.
	 *
	 * @return void
	 */
	public function apiImport() {
		$rServerID = (int) $this->input('server_id', 0);
		$rAll = (string) $this->input('all', '') === '1';
		$rIDs = $this->input('ids', []);

		if (is_string($rIDs)) {
			$rIDs = array_filter(explode(',', $rIDs));
		}
		if (!is_array($rIDs)) {
			$rIDs = [];
		}

		if (!$rAll && $rIDs === []) {
			$this->json(['result' => false, 'error' => 'No stream selected.']);
		}

		// Selected rows may span several origins; group them so each import
		// run applies its own server's category/bouquet/target settings.
		if (!$rAll && $rIDs !== []) {
			$rGrouped = [];

			foreach (FlussonicStreamService::getByIds($rIDs) as $rRow) {
				$rGrouped[(int) $rRow['server_id']][] = (int) $rRow['id'];
			}

			$rImported = 0;
			$rSkipped = 0;
			$rErrors = [];

			foreach ($rGrouped as $rGroupServerID => $rGroupIDs) {
				$rResult = FlussonicSyncService::importStreams($rGroupServerID, $rGroupIDs);
				$rImported += $rResult['imported'];
				$rSkipped += $rResult['skipped'];
				$rErrors = array_merge($rErrors, $rResult['errors']);
			}

			$this->json(['result' => true, 'imported' => $rImported, 'skipped' => $rSkipped, 'error' => implode(' | ', $rErrors)]);
		}

		if ($rServerID <= 0) {
			$rImported = 0;
			$rSkipped = 0;
			$rErrors = [];

			foreach (FlussonicServerService::getEnabled() as $rServer) {
				$rResult = FlussonicSyncService::importStreams($rServer, [], true);
				$rImported += $rResult['imported'];
				$rSkipped += $rResult['skipped'];
				$rErrors = array_merge($rErrors, $rResult['errors']);
			}

			$this->json(['result' => true, 'imported' => $rImported, 'skipped' => $rSkipped, 'error' => implode(' | ', $rErrors)]);
		}

		$rResult = FlussonicSyncService::importStreams($rServerID, [], true);

		$this->json([
			'result'   => true,
			'imported' => $rResult['imported'],
			'skipped'  => $rResult['skipped'],
			'error'    => implode(' | ', $rResult['errors']),
		]);
	}

	/**
	 * action=flussonic_server — per-row maintenance actions.
	 *
	 * @return void
	 */
	public function apiServer() {
		$rSub = (string) $this->input('sub', '');
		$rID = (int) $this->input('id', 0);

		if ($rID <= 0 || FlussonicServerService::getById($rID) === null) {
			$this->json(['result' => false, 'error' => 'Server not found.']);
		}

		switch ($rSub) {
			case 'delete':
				$this->json(['result' => FlussonicServerService::deleteById($rID)]);
				break;

			case 'enable':
				$this->json(['result' => FlussonicServerService::setEnabled($rID, true)]);
				break;

			case 'disable':
				$this->json(['result' => FlussonicServerService::setEnabled($rID, false)]);
				break;

			case 'forget':
				FlussonicStreamService::deleteByServer($rID);
				$this->json(['result' => true]);
				break;

			case 'refresh_sources':
				$this->json(['result' => true, 'updated' => FlussonicSyncService::refreshImportedSources($rID)]);
				break;

			default:
				$this->json(['result' => false, 'error' => 'Unknown action.']);
		}
	}

	// ───────────────────────────────────────────────────────────
	//  Helpers
	// ───────────────────────────────────────────────────────────

	/**
	 * Column defaults for a brand-new origin.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'id'               => 0,
			'name'             => '',
			'api_scheme'       => 'http',
			'api_host'         => '',
			'api_port'         => 8080,
			'api_username'     => '',
			'api_password'     => '',
			'bearer_token'     => '',
			'verify_tls'       => 1,
			'play_scheme'      => '',
			'play_host'        => '',
			'play_port'        => 0,
			'rtmp_port'        => 1935,
			'rtsp_port'        => 554,
			'protocol'         => 'hls',
			'play_token'       => '',
			'enabled'          => 1,
			'auto_import'      => 0,
			'auto_remove'      => 1,
			'direct_source'    => 0,
			'sync_interval'    => 300,
			'target_server_id' => 0,
			'category_id'      => 0,
			'bouquets'         => '[]',
			'stream_prefix'    => '',
			'status'           => 0,
			'last_sync'        => 0,
			'last_error'       => '',
			'server_info'      => '',
			'streams_found'    => 0,
			'added'            => 0,
		];
	}

	/**
	 * Read one request parameter (RequestManager first, then $_REQUEST).
	 *
	 * @param string $rKey     Parameter name.
	 * @param mixed  $rDefault Fallback value.
	 * @return mixed
	 */
	protected function input(string $rKey, $rDefault = null) {
		$rAll = RequestManager::getAll();

		if (isset($rAll[$rKey])) {
			return $rAll[$rKey];
		}

		return $_REQUEST[$rKey] ?? $rDefault;
	}

	/**
	 * Full submitted payload (RequestManager merged over $_POST).
	 *
	 * @return array<string,mixed>
	 */
	protected function payload(): array {
		$rAll = RequestManager::getAll();

		return is_array($rAll) ? array_merge($_POST, $rAll) : $_POST;
	}

	/**
	 * `?status=` flag used by the views to show the success banner.
	 *
	 * @return int
	 */
	protected function status(): int {
		return (int) $this->input('status', 0);
	}

	/**
	 * Emit a JSON response and stop.
	 *
	 * @param array<string,mixed> $rData Payload.
	 * @return void
	 */
	protected function json(array $rData) {
		if (!headers_sent()) {
			header('Content-Type: application/json');
		}

		echo json_encode($rData);
		exit;
	}
}
