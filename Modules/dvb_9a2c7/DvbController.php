<?php

namespace XcVm\Module\Dvb;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Module\Dvb\Service\DvbAdapterService;
use XcVm\Module\Dvb\Service\DvbCamdService;
use XcVm\Module\Dvb\Service\DvbImportService;
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

		$rServices   = $rTransponderID > 0 ? DvbServiceCatalog::forTransponder($rTransponderID) : [];
		$rCategories = CategoryService::getAllByType('live');
		$rBouquets   = BouquetService::getAllSimple();
		$rCamds      = DvbCamdService::enabled();
		$_TITLE      = 'DVB Services';
		$_STATUS     = $this->status();

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/dvb_services.php';
	}

	/**
	 * Tuners detected per node.
	 *
	 * @return void
	 */
	public function camd() {
		global $db, $language, $rSettings, $rMobile, $rUserInfo, $rPermissions, $rServers, $rThemes, $rHues;

		$rEditID = (int) $this->input('id', 0);
		$rNotice = '';
		$rError  = '';

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$rAction = (string) $this->input('do', 'save');

			if ($rAction === 'delete') {
				DvbCamdService::delete($rEditID);
				$rNotice = 'Card server deleted. Any service using it has stopped being decrypted.';
				$rEditID = 0;
			} else {
				$rSaved = DvbCamdService::save($_POST, $rEditID > 0 ? $rEditID : null);

				if ($rSaved['status']) {
					$rNotice = 'Card server saved.';
					$rEditID = 0;
				} else {
					$rError = $rSaved['error'];
				}
			}
		}

		$rCamds   = DvbCamdService::all();
		$rEditing = $rEditID > 0 ? DvbCamdService::find($rEditID) : null;
		$_TITLE   = 'DVB Card Servers';
		$_STATUS  = $this->status();

		renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
		include $this->viewsPath . '/dvb_camd.php';
	}

	/**
	 * Render the adapter inventory.
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
	/**
	 * Live signal reading for one transponder.
	 *
	 * Runs inline rather than through the job queue. cron:dvb ticks once a
	 * minute, and a meter that refreshes once a minute cannot be used to point
	 * a dish, which is the only reason this exists.
	 *
	 * @return void
	 */
	public function apiSignal() {
		$rID          = (int) $this->input('id', 0);
		$rTransponder = DvbTransponderService::find($rID);

		if ($rTransponder === null) {
			$this->json(['result' => false, 'error' => 'Transponder not found.']);
		}

		$rHere = defined('SERVER_ID') ? (int) SERVER_ID : 1;

		if ((int) $rTransponder['server_id'] !== $rHere) {
			// Refuse rather than queue. A job would answer in a minute and the
			// caller is a meter polling every two seconds; pretending to
			// support it would just look broken.
			$this->json([
				'result' => false,
				'error'  => 'The signal meter only runs on the node holding the card. Open the panel on that server, or run: dvbv5-zap -c <file> -m on it directly.',
			]);
		}

		if (!empty($rTransponder['streaming'])) {
			// pick() hands back the adapter this transponder already holds, so
			// measuring a live transponder would fight dvblast for the same
			// frontend. Refuse instead of disturbing channels that are on air.
			$this->json([
				'result' => false,
				'error'  => 'This transponder is streaming. Stop it before measuring, otherwise the meter and the running stream fight over the same tuner.',
			]);
		}

		$rAdapter = DvbAdapterService::pick($rTransponder);

		if ($rAdapter === null) {
			$this->json([
				'result' => false,
				'error'  => 'No free tuner on this server. Every adapter is either disabled or already claimed by a running transponder.',
			]);
		}

		$rResult = DvbScanService::measureSignal($rTransponder, $rAdapter);

		if (!$rResult['status']) {
			// Hand back what the tool actually printed. A canned "never locked"
			// hides the difference between a weak carrier and, say, a frontend
			// held open by another process, which are opposite problems.
			$this->json([
				'result' => false,
				'error'  => $rResult['error'],
				'log'    => substr((string) $rResult['log'], -2000),
			]);
		}

		$rSignal = $rResult['signal'];

		DvbTransponderService::recordSignal($rID, $rSignal);

		$this->json([
			'result'   => true,
			'locked'   => (bool) $rSignal['locked'],
			'strength' => $rSignal['strength'],
			'quality'  => $rSignal['quality'],
			'dbm'      => $rSignal['strength_dbm'],
			'cnr'      => $rSignal['cnr_db'],
			'ber'      => $rSignal['ber'],
			'ucb'      => $rSignal['ucb'],
			'adapter'  => '/dev/dvb/adapter' . (int) $rAdapter['adapter_num'] . '/frontend' . (int) $rAdapter['frontend_num'],
		]);
	}

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
		$rIDs = $this->input('service_ids', []);

		if (!is_array($rIDs)) {
			$rIDs = array_filter(explode(',', (string) $rIDs), 'strlen');
		}

		$rIDs = array_map('intval', $rIDs);

		if (empty($rIDs)) {
			$this->json(['result' => false, 'error' => 'No service selected.']);
		}

		$rCamdID = (int) $this->input('camd_id', 0);
		$rSkip   = !empty($this->input('skip_encrypted'));

		// Picking a CAMD and also skipping encrypted services cancels itself
		// out: the only services a CAMD is for are exactly the ones being
		// skipped, so the import would quietly create nothing worth having.
		if ($rCamdID > 0 && $rSkip) {
			$this->json([
				'result' => false,
				'error'  => 'You chose a CAMD to decrypt with, but "Skip encrypted" is still ticked. Those cancel out — the encrypted services are the only ones the CAMD would be used for. Untick it and import again.',
			]);
		}

		$rResult = DvbImportService::import($rIDs, [
			'category_id'    => (int) $this->input('category_id', 0),
			'bouquets'       => (array) $this->input('bouquets', []),
			'prefix'         => (string) $this->input('prefix', ''),
			'skip_encrypted' => $rSkip,
			// Without this the chosen CAMD never reached the importer, so
			// every encrypted channel was created with camd_id NULL and no
			// enc_port: DVBlast fed the panel the scrambled stream and the
			// channel simply would not open.
			'camd_id'        => $rCamdID,
			'start'          => !empty($this->input('start')),
		]);

		$this->json([
			'result'   => $rResult['status'],
			'imported' => $rResult['imported'],
			'skipped'  => $rResult['skipped'],
			'error'    => implode(' | ', $rResult['errors']),
			'note'     => $rResult['imported'] > 0
				? 'Created ' . $rResult['imported'] . ' channel(s). The tuner node starts feeding them on its next cron tick, within a minute.'
				: 'Nothing imported.',
		]);
	}

	/**
	 * Unlink a service from the channel it created.
	 *
	 * @return void
	 */
	public function apiCamd() {
		$rSub = (string) $this->input('sub', '');

		if ($rSub === 'probe') {
			$rID   = (int) $this->input('id', 0);
			$rCamd = $rID > 0 ? DvbCamdService::find($rID) : $this->payload();

			if ($rCamd === null) {
				$this->json(['result' => false, 'error' => 'No such card server.']);

				return;
			}

			$rResult = DvbCamdService::probe($rCamd);

			$this->json([
				'result' => $rResult['status'],
				'error'  => $rResult['status'] ? '' : $rResult['message'],
				'note'   => $rResult['message'],
			]);

			return;
		}

		if ($rSub === 'toggle') {
			$rCamd = DvbCamdService::find((int) $this->input('id', 0));

			if ($rCamd === null) {
				$this->json(['result' => false, 'error' => 'No such card server.']);

				return;
			}

			$rEnabled = empty($rCamd['enabled']) ? 1 : 0;

			DvbCamdService::setEnabled((int) $rCamd['id'], $rEnabled);

			$this->json([
				'result' => true,
				'note'   => $rEnabled ? 'Card server enabled.' : 'Card server disabled. Its channels stop being decrypted on the next tick.',
			]);

			return;
		}

		$this->json(['result' => false, 'error' => 'Unknown card server action.']);
	}

	/**
	 * Assign or clear the CAMD on an imported service.
	 *
	 * @return void
	 */
	public function apiDecrypt() {
		$rResult = DvbImportService::assignCamd(
			(int) $this->input('id', 0),
			(int) $this->input('camd_id', 0)
		);

		$this->json([
			'result' => $rResult['status'],
			'error'  => $rResult['status'] ? '' : $rResult['message'],
			'note'   => $rResult['message'],
		]);
	}

	/**
	 * Detach an imported service from its panel channel.
	 *
	 * @return void
	 */
	public function apiUnlink() {
		$rResult = DvbImportService::unlink((int) $this->input('id', 0));

		$this->json([
			'result' => $rResult['status'],
			'error'  => $rResult['status'] ? '' : $rResult['message'],
			'note'   => $rResult['message'],
		]);
	}

	/**
	 * Start or stop the DVBlast feeding a transponder.
	 *
	 * Both directions go through the job queue rather than acting here: the
	 * panel has no tuner and cannot start a process on another machine.
	 *
	 * @return void
	 */
	public function apiStream() {
		$rID          = (int) $this->input('id', 0);
		$rTransponder = DvbTransponderService::find($rID);

		if ($rTransponder === null) {
			$this->json(['result' => false, 'error' => 'Transponder not found.']);
		}

		$rStart = ((string) $this->input('sub', 'start')) !== 'stop';

		DvbTransponderService::setStreaming($rID, $rStart);

		$rJobID = DvbJobService::enqueue(
			(int) $rTransponder['server_id'],
			$rStart ? DvbJobService::TYPE_RESTREAM : DvbJobService::TYPE_STOP,
			$rID
		);

		$this->json([
			'result' => true,
			'job_id' => $rJobID,
			'note'   => $rStart
				? 'Queued. The tuner node starts DVBlast within a minute.'
				: 'Queued. The tuner node stops DVBlast within a minute.',
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
