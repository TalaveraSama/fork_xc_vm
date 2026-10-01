<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;

/**
 * Управление сервисом \XC_VM (start/stop/restart/reload).
 *
 * Команда: service {start|stop|restart|reload}
 * Требует: root
 *
 * Заменяет shell-логику из src/service, оставляя shell-обёртку
 * для systemd, которая делегирует сюда.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServiceCommand implements CommandInterface {

	public function getName(): string {
		return 'service';
	}

	public function getDescription(): string {
		return 'Manage \XC_VM service: start, stop, restart, reload';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}

		$rAction = $rArgs[0] ?? null;

		switch ($rAction) {
			case 'start':
				return $this->start();
			case 'stop':
				return $this->stop();
			case 'restart':
				return $this->restart();
			case 'reload':
				return $this->reload();
			default:
				echo "Usage: console.php service {start|stop|restart|reload}\n";
				return 1;
		}
	}

	private function start(): int {
		$rPids = intval(trim(shell_exec('pgrep -u xc_vm nginx | wc -l')));
		if ($rPids > 0) {
			echo "XC_VM is already running\n";
			return 1;
		}
		// Clear the stop marker so module cron jobs resume.
		@unlink(TMP_PATH . 'panel_stopped');
		

		echo "Starting \XC_VM...\n";

		exec('sudo chown -R xc_vm:xc_vm /sys/class/net');
		exec('sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'content/streams');
		exec('sudo chown -R xc_vm:xc_vm ' . TMP_PATH);

		if (file_exists(MAIN_HOME . 'bin/redis/redis-server')) {
			exec('sudo -u xc_vm ' . MAIN_HOME . 'bin/redis/redis-server ' . MAIN_HOME . 'bin/redis/redis.conf >/dev/null 2>/dev/null');
		}

		exec('sudo -u xc_vm ' . MAIN_HOME . 'bin/nginx/sbin/nginx >/dev/null 2>/dev/null');
		exec('sudo -u xc_vm ' . MAIN_HOME . 'bin/nginx_rtmp/sbin/nginx_rtmp >/dev/null 2>/dev/null');
		exec('sudo ' . MAIN_HOME . 'bin/daemons.sh');

		exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php startup');
		exec('sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php signals >/dev/null 2>/dev/null &');
		exec('sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php watchdog >/dev/null 2>/dev/null &');
		exec('sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php queue >/dev/null 2>/dev/null &');

		exec('sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cache_handler >/dev/null 2>/dev/null &');

		echo "Running in foreground...\n";
		// sleep infinity handled by systemd shell wrapper
		return 0;
	}

	private function stop(): int {
		$rPids = intval(trim(shell_exec('pgrep -u xc_vm nginx | wc -l')));
		if ($rPids === 0) {
			echo "XC_VM is not running\n";
			return 1;
		}

		echo "Stopping \XC_VM...\n";
		// Leave a marker before anything else. Stopping the panel does not
		// touch the crontab, so cron:dvb and friends keep firing every minute
		// and will happily restart everything this stop is about to kill --
		// the panel goes down and the tuners stay claimed in a loop. Module
		// cron jobs check for this file and stand down while it exists.
		@file_put_contents(TMP_PATH . 'panel_stopped', (string) time());

		// Modules that run long-lived processes get told first. They detach
		// them deliberately, so nothing else reaps them and a tuner or a port
		// stays claimed after the panel is gone.
		try {
			$rTold = (new \XcVm\Core\Module\ModuleLoader())->loadAll()->shutdownAll();
		
			if ($rTold > 0) {
				echo "Notified " . $rTold . " module(s)\n";
			}
		} catch (\Throwable $e) {
			echo "Module shutdown skipped: " . $e->getMessage() . "\n";
		}
		
		exec('sudo killall -u xc_vm');
		sleep(1);
		exec('sudo killall -u xc_vm');
		sleep(1);
		exec('sudo killall -u xc_vm');
		return 0;
	}

	private function restart(): int {
		exec("ps -U xc_vm | egrep -v 'ffmpeg|PID' | awk '{print $1}' | xargs kill -9 2>/dev/null");
		return $this->start();
	}

	private function reload(): int {
		$rPids = intval(trim(shell_exec('pgrep -u xc_vm nginx | wc -l')));
		if ($rPids === 0) {
			echo "XC_VM is not running\n";
			return 1;
		}

		echo "Reloading \XC_VM...\n";
		exec('sudo -u xc_vm ' . MAIN_HOME . 'bin/nginx/sbin/nginx -s reload');
		exec('sudo -u xc_vm ' . MAIN_HOME . 'bin/nginx_rtmp/sbin/nginx_rtmp -s reload');
		return 0;
	}
}
