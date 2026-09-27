#!/usr/bin/env php
<?php

/**
 * flussonic-api.php — standalone CLI probe for a Flussonic Media Server.
 *
 * Handy for debugging credentials and inspecting raw API payloads without
 * touching the panel. It boots nothing from XC_VM: only the module's own
 * transport/client classes are loaded, so it runs anywhere PHP + cURL exist.
 *
 * Environment:
 *   FLUSSONIC_URL              required, e.g. http://10.0.0.5:8080
 *   FLUSSONIC_USER             Basic auth user
 *   FLUSSONIC_PASSWORD         Basic auth password
 *   FLUSSONIC_TOKEN            bearer token (used instead of user/password)
 *   FLUSSONIC_VERIFY_TLS       0/false/no to skip certificate validation
 *   FLUSSONIC_CONNECT_TIMEOUT  seconds, default 5
 *   FLUSSONIC_REQUEST_TIMEOUT  seconds, default 15
 *
 * Usage:
 *   flussonic-api.php ping
 *   flussonic-api.php info
 *   flussonic-api.php streams [limit] [cursor]
 *   flussonic-api.php all [hard-limit]
 *   flussonic-api.php stream <name>
 *   flussonic-api.php sessions [limit]
 *
 * @package XC_VM_Module_Flussonic
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

use XcVm\Module\Flussonic\Http\CurlTransport;
use XcVm\Module\Flussonic\Service\FlussonicApiClient;

$root = dirname(__DIR__);
require $root . '/Contract/HttpTransportInterface.php';
require $root . '/Exception/FlussonicApiException.php';
require $root . '/Http/CurlTransport.php';
require $root . '/Service/FlussonicApiClient.php';

$url = trim((string) getenv('FLUSSONIC_URL'));

if ($url === '') {
	fwrite(STDERR, "FLUSSONIC_URL is required.\n");
	exit(2);
}

$verifyTlsValue = strtolower(trim((string) (getenv('FLUSSONIC_VERIFY_TLS') ?: '1')));
$verifyTls = !in_array($verifyTlsValue, ['0', 'false', 'no', 'off'], true);

$client = new FlussonicApiClient(
	new CurlTransport(),
	$url,
	(string) getenv('FLUSSONIC_USER'),
	(string) getenv('FLUSSONIC_PASSWORD'),
	$verifyTls,
	(int) (getenv('FLUSSONIC_CONNECT_TIMEOUT') ?: 5),
	(int) (getenv('FLUSSONIC_REQUEST_TIMEOUT') ?: 15),
	(string) getenv('FLUSSONIC_TOKEN')
);

$command = $argv[1] ?? 'help';

try {
	switch ($command) {
		case 'ping':
			$result = $client->ping();
			break;

		case 'info':
			$result = $client->getServerInfo();
			break;

		case 'streams':
			$cursor = isset($argv[3]) && $argv[3] !== '' ? (string) $argv[3] : null;
			$result = $client->listStreams((int) ($argv[2] ?? 100), $cursor);
			break;

		case 'all':
			$result = $client->listAllStreams((int) ($argv[2] ?? 5000));
			break;

		case 'stream':
			if (!isset($argv[2])) {
				throw new InvalidArgumentException('Usage: flussonic-api.php stream <name>');
			}
			$result = $client->getStream((string) $argv[2]);
			break;

		case 'sessions':
			$result = $client->listSessions((int) ($argv[2] ?? 100));
			break;

		default:
			fwrite(STDERR, "Usage: flussonic-api.php ping | info | streams [limit] [cursor] | all [hard-limit] | stream <name> | sessions [limit]\n");
			exit(2);
	}

	echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $exception) {
	fwrite(STDERR, 'Flussonic API error: ' . $exception->getMessage() . "\n");
	exit(1);
}
