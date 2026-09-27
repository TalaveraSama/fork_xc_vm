<?php
namespace XcVm\Module\Flussonic\Http;

use XcVm\Module\Flussonic\Contract\HttpTransportInterface;
use XcVm\Module\Flussonic\Exception\FlussonicApiException;

final class CurlTransport implements HttpTransportInterface {
	public function request(string $method, string $url, array $headers, ?string $body, array $options): array {
		$ch = curl_init($url);
		if ($ch === false) {
			throw new FlussonicApiException('Unable to initialise the HTTP client.');
		}

		$responseHeaders = [];
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => strtoupper($method),
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => (int) ($options['connect_timeout'] ?? 5),
			CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? 15),
			CURLOPT_SSL_VERIFYPEER => (bool) ($options['verify_tls'] ?? true),
			CURLOPT_SSL_VERIFYHOST => ($options['verify_tls'] ?? true) ? 2 : 0,
			CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
				$length = strlen($line);
				$parts = explode(':', $line, 2);
				if (count($parts) === 2) {
					$responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
				return $length;
			},
		]);
		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$responseBody = curl_exec($ch);
		if ($responseBody === false) {
			$message = curl_error($ch);
			curl_close($ch);
			throw new FlussonicApiException('Flussonic request failed: ' . $message);
		}
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $responseBody];
	}
}
