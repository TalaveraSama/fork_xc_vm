<?php

namespace XcVm\Module\Dvb;

use XcVm\Core\Http\RequestManager;
use XcVm\Module\Dvb\Service\DvbAdapterService;
use XcVm\Module\Dvb\Service\DvbJobService;
use XcVm\Module\Dvb\Service\DvbScanService;
use XcVm\Module\Dvb\Service\DvbServiceCatalog;
use XcVm\Module\Dvb\Service\DvbTransponderService;

/**
 * DvbController — admin pages and JSON actions of the DVB module.
 *
 * Pages
 *   dvb              Transponder list: signal, last scan, services found
 *   dvb_transponder  Add / edit one transponder
 *   dvb_services     Browse everything found and import the chosen services
 *   dvb_adapters     Tuners detected on each node
 *
 * JSON actions (dispatched through `./api?action=...`)
 *   dvb_discover     Queue adapter discovery on a node
 *   dvb_scan         Queue a scan of one transponder
 *   dvb_job          Poll a queued job (the UI's progress spinner)
 *   dvb_transponder  delete / enable / disable / preview the tuning file
 *   dvb_import       Create panel channels from selected services
 *
 * Nothing here talks to hardware. Every page is a read of what the tuner node
 * last wrote, and every action that needs a tuner enqueues a job and returns
 * immediately — a transponder scan takes far longer than a request should.
 *
 * @see Service\DvbScanService
 * @see DvbModule
 * @package XC_VM_Module_Dvb
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DvbController {

	/** @var string Absolute path of this module's views directory. */
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
	 * Transponder list.
	 *
	 * @return void
	 */
	public function index() {
		global $db, $language, $rSettings, $rMobile, $rUserInfo, $rPermissions, $rServers, $rThemes, $rHues;

		$rTransponders = DvbTransponderService::all();
		$rAdapters     = $this->adaptersByServer();
		$_TITLE        = 'DVB Tuners';
		$_STATUS       = $this->status();

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/dvb.php';
	}

	/**
	 * Add / edit a transponder. Handles its own POST submit.
	 *
	 * @return void
	 */
	public function transponder() {
		global $db, $language, $rSettings, $rMobile, $rUserInfo, $rPermissions, $rServers, $rThemes, $rHues;

		$rEditID = (int) $this->input('id', 0);
		$rErrors = [];
		$rNotes  = [];

		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
			$rResult = DvbTransponderService::save($this->payload(), $rEditID > 0 ? $rEditID : null);

			if ($rResult['status']) {
				// Scanning straight after saving is what the operator wants
				// nine times out of ten, so offer it rather than making them
				// find the button.
				if (!empty($this->input('scan_now'))) {
					$rSaved = DvbTransponderService::find($rResult['id']);

					if ($rSaved !== null) {
						DvbJobService::enqueue((int) $rSaved['server_id'], DvbJobService::TYPE_SCAN, (int) $rSaved['id']);
					}
				}

				header('Location: dvb?status=1');
				exit;
			}

			$rErrors[] = $rResult['error'];
			$rNotes    = $rResult['notes'];
		}

		$rTransponder = $rEditID > 0 ? DvbTransponderService::find($rEditID) : null;

		if ($rTransponder === null) {
			$rTransponder = $this->blankTransponder();
		}

		$rAdapters       = $this->adaptersByServer();
		$rDeliverySystems = DvbTransponderService::DELIVERY_SYSTEMS;
		$_TITLE          = $rEditID > 0 ? 'Edit Transponder' : 'Add Transponder';

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/dvb_transponder.php';
	}

	/**
	 * Browse discovered services and import them.
	 *
	 * @return void
	 */
	public function services() {
		global $db, $language, $rSettings, $rMobile, $rUserInfo, $rPermissions, $rServers, $rThemes, $rHues;

		$rTransponderID = (int) $this->input('transponder_id', 0);
		$rTransponders  = DvbTransponderService::all();

		if ($rTransponderID === 0 && !empty($rTransponders)) {
			$rTransponderID = (int) $rTransponders[0]['id'];
		}

		$rServices = $rTransponderID > 0 ? DvbServiceCatalog::forTransponder($rTransponderID) : [];
		$_TITLE    = 'DVB Services';
		$_STATUS   = $this->status();

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/dvb_services.php';
	}

	/**
	 * Tuners detected per node.
	 *
	 * @return void
	 */
	public function adapters() {
		global $db, $language, $rSettings, $rMobile, $rUserInfo, $rPermissions, $rServers, $rThemes, $rHues;

		$rAdapters = $this->adaptersByServer();
		$_TITLE    = 'DVB Adapters';
		$_STATUS   = $this->status();

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/dvb_adapters.php';
	}

	// ───────────────────────────────────────────────────────────
	//  JSON actions
	// ───────────────────────────────────────────────────────────

	/**
	 * Queue adapter discovery on a node.
	 *
	 * @return void
	 */
	public function apiDiscover() {
		$rServerID = (int) $this->input('server_id', 0);

		if ($rServerID <= 0) {
			$this->json(['result' => false, 'error' => 'Pick a server.']);
		}

		$rJobID = DvbJobService::enqueue($rServerID, DvbJobService::TYPE_DISCOVER);

		$this->json([
			'result' => true,
			'job_id' => $rJobID,
			'note'   => 'Queued. The node picks this up on its next cron tick, within a minute.',
		]);
	}

	/**
	 * Queue a scan of one transponder.
	 *
	 * @return void
	 */
	public function apiScan() {
		$rID          = (int) $this->input('id', 0);
		$rTransponder = DvbTransponderService::find($rID);

		if ($rTransponder === null) {
			$this->json(['result' => false, 'error' => 'Transponder not found.']);
		}

		DvbTransponderService::recordScan($rID, 'scanning', 'Queued, waiting for the tuner node.');
		$rJobID = DvbJobService::enqueue((int) $rTransponder['server_id'], DvbJobService::TYPE_SCAN, $rID);

		$this->json([
			'result' => true,
			'job_id' => $rJobID,
			'note'   => 'Queued. Scanning starts within a minute and takes up to two more.',
		]);
	}

	/**
	 * Poll a queued job.
	 *
	 * @return void
	 */
	public function apiJob() {
		$rJob = DvbJobService::find((int) $this->input('job_id', 0));

		if ($rJob === null) {
			$this->json(['result' => false, 'error' => 'Job not found.']);
		}

		$this->json([
			'result'   => true,
			'status'   => $rJob['status'],
			'message'  => (string) $rJob['result'],
			'finished' => in_array($rJob['status'], ['done', 'error'], true),
		]);
	}

	/**
	 * Per-transponder maintenance actions.
	 *
	 * @return void
	 */
	public function apiTransponder() {
		$rID          = (int) $this->input('id', 0);
		$rTransponder = DvbTransponderService::find($rID);

		if ($rTransponder === null) {
			$this->json(['result' => false, 'error' => 'Transponder not found.']);
		}

		switch ((string) $this->input('sub', '')) {
			case 'delete':
				$this->json(['result' => DvbTransponderService::delete($rID)]);
				break;

			case 'enable':
				$this->json(['result' => $this->setEnabled($rID, 1)]);
				break;

			case 'disable':
				$this->json(['result' => $this->setEnabled($rID, 0)]);
				break;

			case 'preview':
				// Showing the generated tuning file is the fastest way to
				// diagnose a transponder that will not lock.
				$this->json([
					'result'  => true,
					'preview' => DvbScanService::buildInitialFile($rTransponder),
				]);
				break;

			default:
				$this->json(['result' => false, 'error' => 'Unknown action.']);
		}
	}

	/**
	 * Import selected services as panel streams.
	 *
	 * Deliberately not implemented yet — see the module README. Returning an
	 * explicit "not yet" beats a silent no-op that looks like a bug.
	 *
	 * @return void
	 */
	public function apiImport() {
		$this->json([
			'result' => false,
			'error'  => 'Importing services as channels is not wired up yet in 1.0.0. Scan results are stored and visible; the import step lands next.',
		]);
	}

	// ───────────────────────────────────────────────────────────
	//  Helpers
	// ───────────────────────────────────────────────────────────

	/**
	 * Adapters grouped by node, for the server pickers.
	 *
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	protected function adaptersByServer() {
		global $rServers;

		$rGrouped = [];

		foreach ((array) $rServers as $rServerID => $rServer) {
			$rGrouped[(int) $rServerID] = DvbAdapterService::forServer((int) $rServerID);
		}

		return $rGrouped;
	}

	/**
	 * Flip a transponder's enabled flag.
	 *
	 * @param int $rID      Transponder id.
	 * @param int $rEnabled 1 or 0.
	 * @return bool
	 */
	protected function setEnabled($rID, $rEnabled) {
		return DvbTransponderService::setEnabled($rID, (bool) $rEnabled);
	}

	/**
	 * Defaults for the add form. Universal LNB and 27.5 MSym/s QPSK cover most
	 * of Ku band, so the operator usually only types a frequency.
	 *
	 * @return array<string,mixed>
	 */
	protected function blankTransponder() {
		return [
			'id'              => 0,
			'server_id'       => 0,
			'adapter_id'      => null,
			'name'            => '',
			'satellite'       => '',
			'delivery_system' => 'DVBS2',
			'frequency'       => '',
			'polarization'    => 'H',
			'symbol_rate'     => 27500000,
			'modulation'      => 'QPSK',
			'inner_fec'       => 'AUTO',
			'rolloff'         => 'AUTO',
			'pilot'           => 'AUTO',
			'bandwidth'       => 0,
			'isi'             => -1,
			'pls_mode'        => 'ROOT',
			'pls_code'        => 0,
			'lnb_type'        => 'UNIVERSAL',
			'lnb_low'         => 9750000,
			'lnb_high'        => 10600000,
			'lnb_switch'      => 11700000,
			'diseqc'          => 0,
			'scan_status'     => 'never',
			'scan_message'    => '',
			'last_scan'       => null,
			'signal_strength' => null,
			'signal_quality'  => null,
			'enabled'         => 1,
			'service_count'   => 0,
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
