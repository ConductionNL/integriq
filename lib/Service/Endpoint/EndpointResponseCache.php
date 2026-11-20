<?php

/**
 * Integriq EndpointResponseCache.
 *
 * The response cache of an endpoint (gateway-response-cache-and-problem-errors
 * D1 to D3): answers of a source endpoint kept in Nextcloud's distributed
 * cache, keyed on the endpoint, the request and, unless the endpoint shares
 * its answers, the consumer. An upstream answer marked `private` or
 * `no-store` is never kept, a lower upstream `max-age` shortens the lifetime,
 * and clearing an endpoint bumps a generation that is part of every key.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Endpoint;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Keeps, finds and clears the cached answers of an endpoint.
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
 */
class EndpointResponseCache {

	/**
	 * The distributed cache prefix.
	 *
	 * @var string
	 */
	public const PREFIX = 'integriq.endpoint.response';

	/**
	 * The header a cached answer carries, and its values.
	 *
	 * @var string
	 */
	public const HEADER = 'X-Cache';

	/**
	 * A lifetime when the endpoint names none, in seconds.
	 *
	 * @var int
	 */
	public const DEFAULT_TTL = 60;

	/**
	 * The longest lifetime an endpoint may set, in seconds.
	 *
	 * @var int
	 */
	public const MAX_TTL = 86400;

	/**
	 * The methods that may be cached at all: only safe ones.
	 *
	 * @var list<string>
	 */
	public const SAFE_METHODS = ['GET', 'HEAD'];

	/**
	 * The largest answer kept, in bytes of its JSON; a larger one is served but not cached.
	 *
	 * @var int
	 */
	public const MAX_ENTRY_BYTES = 1048576;

	/**
	 * The cache, created on first use.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache = null;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Nextcloud's cache factory; the distributed cache is shared by a cluster.
	 */
	public function __construct(
		private readonly ICacheFactory $cacheFactory,
	) {
	}//end __construct()

	/**
	 * The endpoint's cache settings with their defaults, or null when its cache is off.
	 *
	 * @param array<string, mixed> $endpointData The endpoint object.
	 *
	 * @return array<string, mixed>|null ttlSeconds (int), methods (list of string), statusCodes (list of int),
	 *                                   varyHeaders (list of string), sharedAcrossConsumers and ignoreUpstreamMaxAge (bool).
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
	 */
	public function settings(array $endpointData): ?array {
		$cache = ($endpointData['cache'] ?? null);
		if (is_array($cache) === false || ($cache['enabled'] ?? false) !== true) {
			return null;
		}

		$ttl = self::DEFAULT_TTL;
		if (is_numeric($cache['ttlSeconds'] ?? null) === true) {
			$ttl = max(1, min(self::MAX_TTL, (int)$cache['ttlSeconds']));
		}

		$methods = self::upperList(values: ($cache['methods'] ?? self::SAFE_METHODS));
		$methods = array_values(array_intersect($methods, self::SAFE_METHODS));

		$statusCodes = [200];
		if (is_array($cache['statusCodes'] ?? null) === true && $cache['statusCodes'] !== []) {
			$statusCodes = array_values(array_map('intval', array_filter($cache['statusCodes'], 'is_numeric')));
		}

		return [
			'ttlSeconds' => $ttl,
			'methods' => $methods,
			'statusCodes' => $statusCodes,
			'varyHeaders' => array_map('strtolower', self::stringList(values: ($cache['varyHeaders'] ?? []))),
			'sharedAcrossConsumers' => (($cache['sharedAcrossConsumers'] ?? false) === true),
			'ignoreUpstreamMaxAge' => (($cache['ignoreUpstreamMaxAge'] ?? false) === true),
		];
	}//end settings()

	/**
	 * Whether a request of this method may be answered from, or kept in, the cache.
	 *
	 * @param array<string, mixed> $settings The endpoint's cache settings.
	 * @param string               $method   The request method.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
	 */
	public function cachesMethod(array $settings, string $method): bool {
		return in_array(strtoupper($method), $settings['methods'], true);
	}//end cachesMethod()

	/**
	 * The cached answer of this request, or null.
	 *
	 * @param string               $endpointId The endpoint uuid.
	 * @param array<string, mixed> $settings   The endpoint's cache settings.
	 * @param array<string, mixed> $request    The request: method, path, query, headers (lower-case names), consumer.
	 *
	 * @return array{status: int, answer: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
	 */
	public function lookup(string $endpointId, array $settings, array $request): ?array {
		if ($this->cachesMethod(settings: $settings, method: (string)($request['method'] ?? '')) === false) {
			return null;
		}

		$stored = $this->cache()->get($this->key(endpointId: $endpointId, settings: $settings, request: $request));
		if (is_string($stored) === false) {
			return null;
		}

		$entry = json_decode($stored, true);
		if (is_array($entry) === false || is_int($entry['status'] ?? null) === false || is_array($entry['answer'] ?? null) === false) {
			return null;
		}

		return ['status' => $entry['status'], 'answer' => $entry['answer']];
	}//end lookup()

	/**
	 * Keep an upstream answer, when the settings and the upstream allow it.
	 *
	 * @param string               $endpointId The endpoint uuid.
	 * @param array<string, mixed> $settings   The endpoint's cache settings.
	 * @param array<string, mixed> $request    The request, as for lookup().
	 * @param int                  $status     The answer's status code.
	 * @param array<string, mixed> $answer     The answer as the caller sees it (body, headers).
	 *
	 * @return bool Whether it was kept.
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
	 */
	public function store(string $endpointId, array $settings, array $request, int $status, array $answer): bool {
		if ($this->cachesMethod(settings: $settings, method: (string)($request['method'] ?? '')) === false
			|| in_array($status, $settings['statusCodes'], true) === false
		) {
			return false;
		}

		$ttl = $this->lifetime(settings: $settings, headers: (array)($answer['headers'] ?? []));
		if ($ttl === null) {
			return false;
		}

		$encoded = json_encode(['status' => $status, 'answer' => $answer]);
		if ($encoded === false || strlen($encoded) > self::MAX_ENTRY_BYTES) {
			return false;
		}

		$this->cache()->set($this->key(endpointId: $endpointId, settings: $settings, request: $request), $encoded, $ttl);

		return true;
	}//end store()

	/**
	 * Forget every cached answer of one endpoint, by moving it to a new generation.
	 *
	 * @param string $endpointId The endpoint uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
	 */
	public function clear(string $endpointId): void {
		$this->cache()->set($this->generationKey(endpointId: $endpointId), self::newGeneration());
	}//end clear()

	/**
	 * How long an answer may be kept: the endpoint's lifetime, shortened by a
	 * lower upstream `max-age`; null when the upstream forbids keeping it.
	 *
	 * @param array<string, mixed> $settings The endpoint's cache settings.
	 * @param array<string, mixed> $headers  The upstream answer's headers.
	 *
	 * @return int|null Seconds, or null to keep nothing.
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
	 */
	public function lifetime(array $settings, array $headers): ?int {
		$cacheControl = strtolower(self::header(headers: $headers, name: 'cache-control'));
		$directives = array_map('trim', explode(',', $cacheControl));
		if (in_array('private', $directives, true) === true || in_array('no-store', $directives, true) === true) {
			return null;
		}

		$ttl = (int)$settings['ttlSeconds'];
		if ($settings['ignoreUpstreamMaxAge'] === true) {
			return $ttl;
		}

		foreach ($directives as $directive) {
			if (preg_match('/^max-age\s*=\s*"?(\d+)"?$/', $directive, $match) === 1) {
				$ttl = min($ttl, (int)$match[1]);
			}
		}

		if ($ttl <= 0) {
			return null;
		}

		return $ttl;
	}//end lifetime()

	/**
	 * The cache key of a request: endpoint, generation, method, path, sorted
	 * query, the vary headers and the consumer unless answers are shared.
	 *
	 * @param string               $endpointId The endpoint uuid.
	 * @param array<string, mixed> $settings   The endpoint's cache settings.
	 * @param array<string, mixed> $request    The request.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
	 */
	public function key(string $endpointId, array $settings, array $request): string {
		$headers = (array)($request['headers'] ?? []);
		$vary = [];
		foreach ($settings['varyHeaders'] as $name) {
			$vary[$name] = self::header(headers: $headers, name: $name);
		}

		$consumer = '*';
		if ($settings['sharedAcrossConsumers'] === false) {
			$consumer = 'consumer:' . (string)($request['consumer'] ?? '');
		}

		$parts = [
			$endpointId,
			$this->generation(endpointId: $endpointId),
			strtoupper((string)($request['method'] ?? '')),
			(string)($request['path'] ?? ''),
			self::sorted(value: (array)($request['query'] ?? [])),
			$vary,
			$consumer,
		];

		return 'r:' . hash('sha256', (string)json_encode($parts));
	}//end key()

	/**
	 * The endpoint's current generation, started afresh when the cache lost it.
	 *
	 * A lost generation never brings older answers back: a new one is drawn.
	 *
	 * @param string $endpointId The endpoint uuid.
	 *
	 * @return string
	 */
	private function generation(string $endpointId): string {
		$key = $this->generationKey(endpointId: $endpointId);
		$generation = $this->cache()->get($key);
		if (is_string($generation) === true && $generation !== '') {
			return $generation;
		}

		$generation = self::newGeneration();
		$this->cache()->set($key, $generation);

		return $generation;
	}//end generation()

	/**
	 * The key holding an endpoint's generation.
	 *
	 * @param string $endpointId The endpoint uuid.
	 *
	 * @return string
	 */
	private function generationKey(string $endpointId): string {
		return 'g:' . hash('sha256', $endpointId);
	}//end generationKey()

	/**
	 * A fresh generation.
	 *
	 * @return string
	 */
	private static function newGeneration(): string {
		return bin2hex(random_bytes(8));
	}//end newGeneration()

	/**
	 * The distributed cache.
	 *
	 * @return ICache
	 */
	private function cache(): ICache {
		if ($this->cache === null) {
			$this->cache = $this->cacheFactory->createDistributed(self::PREFIX);
		}

		return $this->cache;
	}//end cache()

	/**
	 * One header's value, its name matched without case; a list is joined with commas.
	 *
	 * @param array<string, mixed> $headers The headers.
	 * @param string               $name    The header name.
	 *
	 * @return string
	 */
	private static function header(array $headers, string $name): string {
		foreach ($headers as $key => $value) {
			if (strtolower((string)$key) !== strtolower($name)) {
				continue;
			}

			if (is_array($value) === true) {
				return implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
			}

			if (is_scalar($value) === true) {
				return (string)$value;
			}
		}

		return '';
	}//end header()

	/**
	 * An array with its keys sorted at every level, so the same query is one key.
	 *
	 * @param array<mixed> $value The array.
	 *
	 * @return array<mixed>
	 */
	private static function sorted(array $value): array {
		ksort($value);
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$value[$key] = self::sorted(value: $item);
			}
		}

		return $value;
	}//end sorted()

	/**
	 * The strings of a list, upper case.
	 *
	 * @param mixed $values The list.
	 *
	 * @return list<string>
	 */
	private static function upperList(mixed $values): array {
		return array_map('strtoupper', self::stringList(values: $values));
	}//end upperList()

	/**
	 * The non-empty strings of a list.
	 *
	 * @param mixed $values The list.
	 *
	 * @return list<string>
	 */
	private static function stringList(mixed $values): array {
		if (is_array($values) === false) {
			return [];
		}

		return array_values(array_filter(array_map('trim', array_filter($values, 'is_string')), fn (string $v): bool => $v !== ''));
	}//end stringList()
}//end class
