<?php

namespace XcVm\Module\Dvb;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\Dvb\Service\DvbAdapterService;
use XcVm\Module\Dvb\Service\DvbCamdService;
use XcVm\Module\Dvb\Service\DvbDecryptRunner;
use XcVm\Module\Dvb\Service\DvbImportService;
use XcVm\Module\Dvb\Service\DvbJobService;
use XcVm\Module\Dvb\Service\DvbScanService;
use XcVm\Module\Dvb\Service\DvbServiceCatalog;
use XcVm\Module\Dvb\Service\DvbStreamRunner;
use XcVm\Module\Dvb\Service\DvbTransponderService;

/**
 * Native DVB tuner support for XC_VM.
 *
 * Replaces the usual "put Cesbo Astra or TVHeadend in front of the card and
 * import its output" arrangement with something the panel drives directly:
 *
 *   - enumerate the DVB frontends present on any streaming node
 *     (a TBS6909X shows up as eight of them);
 *   - let the operator type a transponder — frequency, polarization,
 *     symbol rate — against one of those nodes;
 *   - tune it and scan it, producing a list of services with real names;
 *   - import the chosen services as ordinary panel streams.
 *
 * ## Why it is built around a job table
 *
 * The panel and the tuner are on different machines and that is the normal
 * case, not an edge case: a VPS cannot hold a PCIe card. The card's node is
 * registered as an ordinary XC_VM streaming server, and those point their
 * MySQL at the main server (Cli/Commands/LbInstallFlow.php). So the shared
 * database is the only transport that already exists and is already
 * authenticated.
 *
 * The obvious alternative — add an action to the node's internal API — is not
 * available: Public/Controllers/Api/InternalApiController.php is a fixed
 * switch whose `default:` answers `{"result":false}`, with no module hook.
 * Router::dispatchApi() is the panel's admin API, not the node's.
 *
 * So: the panel INSERTs into `dvb_jobs`, `cron:dvb` on the tuner node claims
 * rows addressed to its own SERVER_ID, drives the hardware, and writes the
 * result back. Slower than an RPC by up to a minute, which is nothing next to
 * a transponder scan that takes half of one.
 *
 * ## Why dvbv5 and DVBlast rather than a PHP implementation
 *
 * Tuning and PSI parsing are the kernel's job and libdvbv5's job respectively.
 * TBS ships an open-source driver for the 6909X (tbsdtv/media_build plus
 * tbsdtv/linux_media), so the card presents plain /dev/dvb/adapterN/frontendM
 * and every standard tool works against it — TBS documents DVBlast for this
 * exact card. `dvbv5-scan` does the scan, `dvblast` does the one-tune-many-
 * services streaming afterwards. Both are small, packaged, and replaceable.
 */
class DvbModule extends BaseModule {

	public function getName(): string {
		return 'dvb';
	}

	public function getVersion(): string {
		return '1.1.0';
	}

	/**
	 * Wire the module's services into the container.
	 *
	 * @param ServiceContainer $container DI container.
	 * @return void
	 */
	public function boot(ServiceContainer $container): void {
		// Services resolve their connection lazily through DatabaseAware →
		// DatabaseFactory. Wiring $db explicitly only matters when the host
		// bootstrap registered a handler that never reached the factory.
		if ($container->has('db')) {
			$rDb = $container->get('db');

			if ($rDb !== null && DatabaseFactory::get() === null) {
				DvbAdapterService::setDb($rDb);
				DvbCamdService::setDb($rDb);
				DvbDecryptRunner::setDb($rDb);
				DvbJobService::setDb($rDb);
				DvbImportService::setDb($rDb);
				DvbScanService::setDb($rDb);
				DvbServiceCatalog::setDb($rDb);
				DvbStreamRunner::setDb($rDb);
				DvbTransponderService::setDb($rDb);
			}
		}

		// Registered under the FQCN because Router::callHandler resolves
		// [Class, 'method'] handlers by class name.
		$container->set(DvbController::class, static function () {
			return new DvbController();
		});
	}

	/**
	 * Admin pages and JSON actions.
	 *
	 * Page routes follow the core convention where `dvb_transponder` in the
	 * URL resolves to the `dvb/transponder` route (Router::normalizePage
	 * swaps underscores for slashes).
	 *
	 * @param Router $router Application router.
	 * @return void
	 */
	public function registerRoutes(Router $router): void {
		$router->group('dvb', function (Router $r) {
			$r->get('', [DvbController::class, 'index'], [
				'permission' => ['adv', 'streams'],
			]);
			$r->any('transponder', [DvbController::class, 'transponder'], [
				'permission' => ['adv', 'streams'],
			]);
			$r->get('services', [DvbController::class, 'services'], [
				'permission' => ['adv', 'streams'],
			]);
			$r->get('adapters', [DvbController::class, 'adapters'], [
				'permission' => ['adv', 'streams'],
			]);
			$r->any('camd', [DvbController::class, 'camd'], [
				'permission' => ['adv', 'streams'],
			]);
		});

		$router->api('dvb_discover', [DvbController::class, 'apiDiscover'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_scan', [DvbController::class, 'apiScan'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_signal', [DvbController::class, 'apiSignal'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_signal_cache', [DvbController::class, 'apiSignalCache'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_job', [DvbController::class, 'apiJob'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_transponder', [DvbController::class, 'apiTransponder'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_import', [DvbController::class, 'apiImport'], [
			'permission' => ['adv', 'add_stream'],
		]);
		$router->api('dvb_unlink', [DvbController::class, 'apiUnlink'], [
			'permission' => ['adv', 'add_stream'],
		]);
		$router->api('dvb_stream', [DvbController::class, 'apiStream'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_stop_all', [DvbController::class, 'apiStopAll'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_camd', [DvbController::class, 'apiCamd'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('dvb_decrypt', [DvbController::class, 'apiDecrypt'], [
			'permission' => ['adv', 'add_stream'],
		]);
	}

	/**
	 * Navigation entries.
	 *
	 * Filed next to the other signal sources; the service browser sits under
	 * Content → Streams because that is where an operator looks for "what can
	 * I add?".
	 *
	 * @param NavbarRegistry $registry Navbar registry.
	 * @return void
	 */
	public function registerNavbar(NavbarRegistry $registry): void {
		$registry->add((new NavbarItem('management.service_setup.dvb'))
			->parent('management.service_setup')->url('dvb')
			->label('', 'DVB Tuners')->permissions(['streams'])->order(82));

		$registry->add((new NavbarItem('content.streams.dvb'))
			->parent('content.streams')->url('dvb_services')
			->label('', 'DVB Services')->permissions(['streams'])->order(62));

		$registry->add((new NavbarItem('management.service_setup.dvb_camd'))
			->parent('management.service_setup')->url('dvb_camd')
			->label('', 'DVB Card Servers')->permissions(['streams'])->order(83));
	}

	/**
	 * CLI commands owned by this module.
	 *
	 * @param CommandRegistry $registry Command registry.
	 * @return void
	 */
	public function registerCommands(CommandRegistry $registry): void {
		$registry->register(new DvbCronJob());
	}

	/**
	 * System crontab entry requested by this module.
	 *
	 * Every minute, because this is the queue's only consumer: the delay
	 * between "operator presses Scan" and "the card starts tuning" is exactly
	 * this interval. DvbCronJob holds a PID lock, so an overrunning scan will
	 * not stack.
	 *
	 * Do not remove. A module that does not implement this method inherits
	 * BaseModule's empty array, ModuleLoader::collectCronEntries() skips it,
	 * and the job silently never runs even though the command exists and works
	 * by hand — exactly the bug that kept Watch Folder idle until 2.4.4.
	 *
	 * @return array<string,string>
	 */
	/**
	 * Bring the carriers down with the panel.
	 *
	 * Without this a dvblast keeps `/dev/dvb/adapterN/frontend0` open after
	 * the panel stops, and the next start cannot have the tuner back. Every
	 * orphan chased during commissioning arrived this way.
	 *
	 * The rows are left marked as streaming on purpose: stopping the panel is
	 * not the operator saying these carriers should be off, and they come back
	 * on the next cron tick after a start.
	 *
	 * @return void
	 */
	public function shutdown(): void {
		foreach (DvbTransponderService::all() as $rTransponder) {
			DvbStreamRunner::stop($rTransponder);
		}

		DvbStreamRunner::killOrphans();
	}

	public function getCronEntries(): array {
		return ['* * * * *' => 'cron:dvb'];
	}

	/**
	 * Register the panel-side cron row so the job shows up with the others.
	 *
	 * Schema creation itself is handled by database.sql (ModuleMigrator).
	 *
	 * @return void
	 */
	public function install(): void {
		$rDb = DatabaseFactory::get();

		if ($rDb === null) {
			return;
		}

		$rDb->query("SELECT `id` FROM `crontab` WHERE `filename` = 'dvb' LIMIT 1;");

		if ($rDb->num_rows() === 0) {
			$rDb->query("INSERT INTO `crontab`(`filename`, `time`, `enabled`) VALUES('dvb', '* * * * *', 1);");
		}
	}

	/**
	 * Remove module-owned rows outside its own tables.
	 *
	 * Imported channels are intentionally left alone: they are ordinary panel
	 * streams by then, and silently deleting a subscriber-facing line-up on
	 * uninstall would be destructive. Only the cron row goes.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		$rDb = DatabaseFactory::get();

		if ($rDb === null) {
			return;
		}

		$rDb->query("DELETE FROM `crontab` WHERE `filename` = 'dvb';");
	}
}
