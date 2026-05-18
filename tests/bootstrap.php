<?php

declare(strict_types=1);

// Bootstrap for unit tests. PSL (azjezz/psl) is disabled in vendor/composer/autoload_static.php
// because it uses PHP 8.3+ typed class constant syntax, incompatible with PHP 8.2.

require_once __DIR__ . '/../vendor/autoload.php';

// Nextcloud OCP stubs for unit tests run outside a full NC environment.
// These are minimal interface/class definitions so PHPUnit createMock() can build
// mock objects without needing the full Nextcloud framework installed.

if (interface_exists('OCP\IRequest') === false) {
    interface_exists('OCP\IRequest') || eval('namespace OCP; interface IRequest { public function getParams(): array; public function getHeader(string $name): string; }');
}

if (interface_exists('OCP\Http\Client\IClientService') === false) {
    eval('namespace OCP\Http\Client; interface IClientService { public function newClient(array $config = []): IClient; }');
}

if (interface_exists('OCP\Http\Client\IClient') === false) {
    eval('namespace OCP\Http\Client; interface IClient { public function get(string $url, array $options = []): IResponse; public function post(string $url, array $options = []): IResponse; }');
}

if (interface_exists('OCP\Http\Client\IResponse') === false) {
    eval('namespace OCP\Http\Client; interface IResponse { public function getBody(): string; public function getStatusCode(): int; }');
}

if (interface_exists('OCP\IAppConfig') === false) {
    eval('namespace OCP; interface IAppConfig { public function hasKey(string $app, string $key): bool; public function getValueString(string $app, string $key, string $default = ""): string; }');
}

if (class_exists('OCP\AppFramework\Controller') === false) {
    eval('namespace OCP\AppFramework; abstract class Controller { public function __construct(protected string $appName, protected \OCP\IRequest $request) {} }');
}

if (class_exists('OCP\AppFramework\Http\JSONResponse') === false) {
    eval('namespace OCP\AppFramework\Http; class JSONResponse { private mixed $data; private int $status; public function __construct(mixed $data = null, int $statusCode = 200) { $this->data = $data; $this->status = $statusCode; } public function getData(): mixed { return $this->data; } public function getStatus(): int { return $this->status; } }');
}

if (class_exists('OCP\AppFramework\Http') === false) {
    eval('namespace OCP\AppFramework; class Http { public const STATUS_OK = 200; public const STATUS_ACCEPTED = 202; public const STATUS_BAD_REQUEST = 400; public const STATUS_UNAUTHORIZED = 401; public const STATUS_FORBIDDEN = 403; public const STATUS_NOT_FOUND = 404; }');
}
