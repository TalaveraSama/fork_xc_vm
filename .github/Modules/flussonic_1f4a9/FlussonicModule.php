<?php

namespace XcVm\Module\Flussonic;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Module\Flussonic\Service\FlussonicServerService;
use XcVm\Module\Flussonic\Service\FlussonicStreamService;
use XcVm\Module\Flussonic\Service\FlussonicSyncService;

/**
 * Flussonic Module
 *
 * Adds first-class support for Flussonic Media Server origins to the panel:
 * register one or more Flussonic servers, discover every stream they publish
 * through the v3 HTTP API, and import the ones you want as live channels.
 *
 * ──────────────────────────────────────────────────────────────────
 * What it ships
 * ──────────────────────────────────────────────────────────────────
 *
 *   Services:
 *     - FlussonicApiClient     — v3 API client (cursor-paginated streams list)
 *     - FlussonicUrlBuilder    — HLS / LL-HLS / MPEG-TS / DASH / RTMP / RTSP URLs
 *     - FlussonicServerService — CRUD for origins + connection probing
 *     - FlussonicStreamService — catalogue of discovered streams
 *     - FlussonicSyncService   — discovery + import into `streams`
 *
 *   Controller:
 *     - FlussonicController    — pages and JSON actions
 *
 *   Pages:
 *     - flussonic          — origin servers
 *     - flussonic_server   — add / edit an origin
 *     - flussonic_streams  — available streams + import
 *
 *   API actions:
 *     - flussonic_probe    — test connection (saved row or form values)
 *     - flussonic_sync     — refresh the stream catalogue
 *     - flussonic_import   — create panel channels from discovered streams
 *     - flussonic_server   — delete / enable / disable / forget / refresh URLs
 *
 *   Cron:
 *     - cron:flussonic     — periodic discovery (and auto-import when enabled)
 *
 * @see FlussonicController
 * @see Service\FlussonicSyncService
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FlussonicModule extends BaseModule {

	public function getName(): string {
		return 'flussonic';
	}

	public function getVersion(): string {
		return '1.0.0';
	}

	/**
	 * Wire the module's services into the container.
	 *
	 * @param ServiceContainer $container DI container.
	 * @return void
	 */
	public function boot(ServiceContainer $container): void {
		// The services pull their connection lazily through DatabaseAware →
		// DatabaseFactory, so nothing needs injecting here. Wiring $db
		// explicitly only matters when the host bootstrap registered a handler
		// that never reached the factory.
		if ($container->has('db')) {
			$rDb = $container->get('db');

			if ($rDb !== null && DatabaseFactory::get() === null) {
				FlussonicServerService::setDb($rDb);
				FlussonicStreamService::setDb($rDb);
				FlussonicSyncService::setDb($rDb);
			}
		}

		// Registered under the FQCN because Router::callHandler resolves
		// [Class, 'method'] handlers by class name.
		$container->set(FlussonicController::class, static function () {
			return new FlussonicController();
		});
	}

	/**
	 * Admin pages and JSON actions.
	 *
	 * Page routes use the core convention where `flussonic_server` in the URL
	 * resolves to the `flussonic/server` route (Router::normalizePage swaps
	 * underscores for slashes).
	 *
	 * @param Router $router Application router.
	 * @return void
	 */
	public function registerRoutes(Router $router): void {
		$router->group('flussonic', function (Router $r) {
			$r->get('', [FlussonicController::class, 'index'], [
				'permission' => ['adv', 'streams'],
			]);
			$r->any('server', [FlussonicController::class, 'server'], [
				'permission' => ['adv', 'streams'],
			]);
			$r->get('streams', [FlussonicController::class, 'streams'], [
				'permission' => ['adv', 'streams'],
			]);
		});

		$router->api('flussonic_probe', [FlussonicController::class, 'apiProbe'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('flussonic_sync', [FlussonicController::class, 'apiSync'], [
			'permission' => ['adv', 'streams'],
		]);
		$router->api('flussonic_import', [FlussonicController::class, 'apiImport'], [
			'permission' => ['adv', 'add_stream'],
		]);
		$router->api('flussonic_server', [FlussonicController::class, 'apiServer'], [
			'permission' => ['adv', 'streams'],
		]);
	}

	/**
	 * Navigation entries.
	 *
	 * The origin list lives with the other service integrations; the stream
	 * browser is filed under Content → Streams because that is where an
	 * operator looks for "what can I add?".
	 *
	 * @param NavbarRegistry $registry Navbar registry.
	 * @return void
	 */
	public function registerNavbar(NavbarRegistry $registry): void {
		$registry->add((new NavbarItem('management.service_setup.flussonic'))
			->parent('management.service_setup')->url('flussonic')
			->label('', 'Flussonic Servers')->permissions(['streams'])->order(80));

		$registry->add((new NavbarItem('content.streams.flussonic'))
			->parent('content.streams')->url('flussonic_streams')
			->label('', 'Flussonic Streams')->permissions(['streams'])->order(60));
	}

	/**
	 * CLI commands (the cron entrypoint).
	 *
	 * @param CommandRegistry $registry Command registry.
	 * @return void
	 */
	public function registerCommands(CommandRegistry $registry): void {
		$registry->register(new FlussonicCronJob());
	}

	/**
	 * System crontab entry requested by this module.
	 *
	 * @return array<string,string>
	 */
	public function getCronEntries(): array {
		return ['*/5 * * * *' => 'cron:flussonic'];
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

		$rDb->query("SELECT `id` FROM `crontab` WHERE `filename` = 'flussonic' LIMIT 1;");

		if ($rDb->num_rows() === 0) {
			$rDb->query("INSERT INTO `crontab`(`filename`, `time`, `enabled`) VALUES('flussonic', '*/5 * * * *', 1);");
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

		$rDb->query("DELETE FROM `crontab` WHERE `filename` = 'flussonic';");
	}
}
