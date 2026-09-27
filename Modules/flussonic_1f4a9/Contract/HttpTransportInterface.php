<?php
namespace XcVm\Module\Flussonic\Contract;

interface HttpTransportInterface {
	/** @return array{status:int,headers:array<string,string>,body:string} */
	public function request(string $method, string $url, array $headers, ?string $body, array $options): array;
}
