<?php
namespace XcVm\Module\Flussonic\Exception;

use RuntimeException;

final class FlussonicApiException extends RuntimeException {
	private int $statusCode;

	public function __construct(string $message, int $statusCode = 0) {
		parent::__construct($message, $statusCode);
		$this->statusCode = $statusCode;
	}

	public function getStatusCode(): int {
		return $this->statusCode;
	}
}
