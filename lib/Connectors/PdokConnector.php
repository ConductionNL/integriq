<?php

/**
 * OpenConnector PDOK Connector
 *
 * Server-side connector for the Dutch PDOK Locatieserver v3.1 — proxies suggest,
 * lookup, free-text, and reverse-geocode calls; normalises the response into the
 * canonical OR PostalAddress schema; caches in APCu; writes lookup/reverse
 * results through to the OR `addresses` register; handles 429 backoff and a
 * 5-failure circuit breaker; emits structured observability log entries.
 *
 * Implements the `[openconnector]` subset of the Hydra umbrella
 * `shared-pdok-via-openconnector`.
 *
 * @category Connector
 * @package  OCA\OpenConnector\Connectors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Connectors;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Server-side connector for the PDOK Locatieserver v3.1.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class PdokConnector {
	/**
	 * Base URL for the PDOK Locatieserver v3.1.
	 */
	private const BASE_URL = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1';

	/**
	 * APCu cache key prefix for response caching.
	 */
	private const CACHE_PREFIX = 'pdok_connector';

	/**
	 * APCu cache key for circuit-breaker state.
	 */
	private const CIRCUIT_KEY = 'pdok_connector::circuit';

	/**
	 * TTL in seconds for the suggest/free endpoints (volatile query results).
	 */
	private const TTL_QUERY = 300;

	/**
	 * TTL in seconds for the lookup/reverse endpoints (stable resolved results).
	 */
	private const TTL_RESOLVED = 3600;

	/**
	 * Maximum retry attempts on 429.
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Base delay in milliseconds for exponential backoff.
	 */
	private const BACKOFF_BASE_MS = 200;

	/**
	 * Cap on backoff delay in milliseconds.
	 */
	private const BACKOFF_CAP_MS = 5000;

	/**
	 * Consecutive failures that open the breaker.
	 */
	private const BREAKER_THRESHOLD = 5;

	/**
	 * Seconds the breaker stays open before allowing a half-open probe.
	 */
	private const BREAKER_OPEN_SECONDS = 30;

	/**
	 * Cache for APCu-backed responses + breaker state.
	 *
	 * Nullable: if no cache backend is configured the connector still
	 * functions, just without caching or persistent breaker state.
	 *
	 * @var ICache|null
	 */
	private ?ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param ICacheFactory $cacheFactory Cache factory (may not be available).
	 * @param LoggerInterface $logger Structured logger.
	 * @param ContainerInterface $container Container for graceful OR resolution.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
		try {
			$this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PdokConnector: cache factory unavailable, proceeding uncached',
				['exception' => $e->getMessage()]
			);
			$this->cache = null;
		}

	}//end __construct()

	/**
	 * Suggest addresses for an autocomplete query.
	 *
	 * @param string $query Search query (must be non-empty).
	 *
	 * @return array Normalised suggestion documents.
	 */
	public function suggest(string $query): array {
		if (trim($query) === '') {
			return ['docs' => [], 'numFound' => 0];
		}

		return $this->fetch(endpoint: 'suggest', params: ['q' => $query, 'rows' => 10], ttl: self::TTL_QUERY, writeThrough: false);
	}//end suggest()

	/**
	 * Look up a single PDOK document by id.
	 *
	 * @param string $id PDOK identifier (e.g., `adr-0363200000218908`).
	 *
	 * @return array Normalised lookup document.
	 */
	public function lookup(string $id): array {
		if (trim($id) === '') {
			return ['docs' => [], 'numFound' => 0];
		}

		return $this->fetch(endpoint: 'lookup', params: ['id' => $id], ttl: self::TTL_RESOLVED, writeThrough: true);
	}//end lookup()

	/**
	 * Free-text search.
	 *
	 * @param string $query Search query.
	 * @param int $rows Page size (default 10).
	 * @param int $start Page offset (default 0).
	 *
	 * @return array Normalised free-search documents.
	 */
	public function free(string $query, int $rows = 10, int $start = 0): array {
		if (trim($query) === '') {
			return ['docs' => [], 'numFound' => 0];
		}

		$params = ['q' => $query, 'rows' => $rows, 'start' => $start];

		return $this->fetch(endpoint: 'free', params: $params, ttl: self::TTL_QUERY, writeThrough: false);
	}//end free()

	/**
	 * Reverse-geocode coordinates.
	 *
	 * @param float $lat Latitude (WGS84).
	 * @param float $lng Longitude (WGS84).
	 *
	 * @return array Normalised reverse-geocode document.
	 */
	public function reverse(float $lat, float $lng): array {
		$params = ['type' => 'adres', 'lat' => $lat, 'lon' => $lng, 'rows' => 1];

		return $this->fetch(endpoint: 'reverse', params: $params, ttl: self::TTL_RESOLVED, writeThrough: true);
	}//end reverse()

	/**
	 * Internal: fetch a normalised PDOK response with cache + write-through.
	 *
	 * Order of resolution:
	 *   1. APCu cache lookup (cheapest path, no logging)
	 *   2. OR addresses-register lookup by `pdokId` if `$writeThrough && lookup-by-id`
	 *   3. Circuit-breaker check
	 *   4. Upstream `callPdok()` with retry/backoff
	 *   5. Normalise → cache → write-through (when applicable)
	 *
	 * @param string $endpoint PDOK endpoint name (suggest|lookup|free|reverse).
	 * @param array $params Query string parameters.
	 * @param int $ttl Cache TTL in seconds.
	 * @param bool $writeThrough True for lookup/reverse (results stored to OR).
	 *
	 * @return array `{docs: array, numFound: int, stale?: bool}` normalised payload.
	 */
	private function fetch(string $endpoint, array $params, int $ttl, bool $writeThrough): array {
		$cacheKey = $this->cacheKey(endpoint: $endpoint, params: $params);
		$cached = $this->cacheGet(key: $cacheKey);
		if ($cached !== null) {
			return $cached;
		}

		// Check OR for an existing record before calling upstream (lookup/reverse only).
		if ($writeThrough === true) {
			$orHit = $this->orLookup(endpoint: $endpoint, params: $params);
			if ($orHit !== null) {
				$this->logCall(
					endpoint: $endpoint,
					cacheHit: true,
					orHit: true,
					upstreamLatencyMs: null,
					httpStatus: null,
					circuitState: $this->circuitState(),
					writeThrough: false
				);
				$this->cacheSet(key: $cacheKey, value: $orHit, ttl: $ttl);
				return $orHit;
			}
		}

		// Circuit breaker — short-circuit when open and no fallback is available.
		if ($this->circuitState() === 'open') {
			$this->logCall(
				endpoint: $endpoint,
				cacheHit: false,
				orHit: false,
				upstreamLatencyMs: null,
				httpStatus: null,
				circuitState: 'open',
				writeThrough: false
			);
			return ['docs' => [], 'numFound' => 0, 'stale' => true];
		}

		$started = microtime(true);
		$httpCode = null;
		try {
			$raw = $this->callPdok(endpoint: $endpoint, params: $params);
			$httpCode = 200;
		} catch (PdokUpstreamException $e) {
			$httpCode = $e->getStatusCode();
			$this->logCall(
				endpoint: $endpoint,
				cacheHit: false,
				orHit: false,
				upstreamLatencyMs: (int)((microtime(true) - $started) * 1000),
				httpStatus: $httpCode,
				circuitState: $this->circuitState(),
				writeThrough: false
			);
			return ['docs' => [], 'numFound' => 0, 'stale' => true];
		}

		$normalised = $this->normaliseResponse(raw: $raw);

		$this->cacheSet(key: $cacheKey, value: $normalised, ttl: $ttl);

		if ($writeThrough === true) {
			foreach ($normalised['docs'] as $doc) {
				$this->writeThrough(doc: $doc);
			}
		}

		$latencyMs = (int)((microtime(true) - $started) * 1000);
		$this->logCall(
			endpoint: $endpoint,
			cacheHit: false,
			orHit: false,
			upstreamLatencyMs: $latencyMs,
			httpStatus: $httpCode,
			circuitState: $this->circuitState(),
			writeThrough: $writeThrough
		);

		return $normalised;
	}//end fetch()

	/**
	 * Issue the HTTP GET to PDOK with retry on 429 and circuit-breaker bookkeeping.
	 *
	 * @param string $endpoint One of suggest|lookup|free|reverse.
	 * @param array $params Query string parameters.
	 *
	 * @return array Raw decoded PDOK response (the `response` envelope is unwrapped).
	 *
	 * @throws PdokUpstreamException On 5xx, timeout, or 429 exhaustion.
	 */
	private function callPdok(string $endpoint, array $params): array {
		$client = $this->clientService->newClient();
		$url = self::BASE_URL . '/' . $endpoint;

		$attempt = 0;
		while ($attempt < self::MAX_RETRIES) {
			try {
				$response = $client->get($url, ['query' => $params, 'timeout' => 10]);
				$status = $response->getStatusCode();
				if ($status >= 200 && $status < 300) {
					$this->circuitOnSuccess();
					return $this->decodePdokBody(response: $response);
				}

				if ($status === 429) {
					$attempt++;
					$this->sleepBackoff(attempt: $attempt);
					continue;
				}

				$this->circuitOnFailure();
				throw new PdokUpstreamException("PDOK upstream returned HTTP $status", $status);
			} catch (PdokUpstreamException $e) {
				throw $e;
			} catch (Throwable $e) {
				$this->circuitOnFailure();
				throw new PdokUpstreamException(
					'PDOK upstream call failed: ' . $e->getMessage(),
					503
				);
			}//end try
		}//end while

		// 429 retries exhausted.
		$this->circuitOnFailure();
		throw new PdokUpstreamException('PDOK rate limit retries exhausted', 503);
	}//end callPdok()

	/**
	 * Decode the JSON body from a PDOK HTTP response.
	 *
	 * @param IResponse $response The Guzzle/NC HTTP response.
	 *
	 * @return array Decoded JSON envelope.
	 *
	 * @throws PdokUpstreamException When the body cannot be decoded.
	 */
	private function decodePdokBody(IResponse $response): array {
		$body = (string)$response->getBody();
		$json = json_decode($body, true);
		if (is_array($json) === false) {
			throw new PdokUpstreamException('PDOK response body is not valid JSON', 502);
		}

		return $json;
	}//end decodePdokBody()

	/**
	 * Normalise an entire PDOK response into `{docs[], numFound}`.
	 *
	 * @param array $raw The raw PDOK envelope.
	 *
	 * @return array Normalised payload.
	 */
	private function normaliseResponse(array $raw): array {
		$envelope = ($raw['response'] ?? $raw);
		$docs = ($envelope['docs'] ?? []);
		$numFound = (int)($envelope['numFound'] ?? count($docs));

		$normalised = [];
		foreach ($docs as $doc) {
			$normalised[] = $this->normalize(pdokDoc: $doc);
		}

		return ['docs' => $normalised, 'numFound' => $numFound];
	}//end normaliseResponse()

	/**
	 * Map a single PDOK document to the canonical PostalAddress shape.
	 *
	 * Missing fields map to `null` (never absent). `centroide_ll` WKT
	 * `"POINT(lng lat)"` becomes a GeoJSON Point `[lng, lat]` per RFC 7946.
	 *
	 * @param array $pdokDoc Raw PDOK document.
	 *
	 * @return array Canonical PostalAddress object.
	 */
	public function normalize(array $pdokDoc): array {
		$rawHouseNumber = ($pdokDoc['huisnummer'] ?? null);
		$houseLetter = ($pdokDoc['huisletter'] ?? null);
		$houseNumber = null;
		if ($rawHouseNumber !== null) {
			$houseNumber = (string)$rawHouseNumber;
			if ($houseLetter !== null && $houseLetter !== '') {
				$houseNumber .= (string)$houseLetter;
			}
		}

		return [
			'pdokId' => ($pdokDoc['id'] ?? null),
			'displayName' => ($pdokDoc['weergavenaam'] ?? null),
			'streetAddress' => ($pdokDoc['straatnaam'] ?? null),
			'houseNumber' => $houseNumber,
			'houseNumberAddition' => ($pdokDoc['huisnummertoevoeging'] ?? null),
			'postalCode' => ($pdokDoc['postcode'] ?? null),
			'addressLocality' => ($pdokDoc['woonplaatsnaam'] ?? null),
			'addressRegion' => ($pdokDoc['provincienaam'] ?? null),
			'addressCountry' => 'NL',
			'bagAddressId' => ($pdokDoc['nummeraanduiding_id'] ?? null),
			'bagBuildingId' => ($pdokDoc['pandid'] ?? null),
			'location' => $this->parseWkt(wkt: ($pdokDoc['centroide_ll'] ?? null)),
			'source' => 'pdok',
			'fetchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];

	}//end normalize()

	/**
	 * Parse a WKT POINT into a GeoJSON Point geometry.
	 *
	 * @param string|null $wkt The WKT input like `POINT(4.882 52.371)`.
	 *
	 * @return array|null `{type: 'Point', coordinates: [lng, lat]}` or null.
	 */
	private function parseWkt(?string $wkt): ?array {
		if ($wkt === null) {
			return null;
		}

		if (preg_match('/POINT\\(([-\\d.]+)\\s+([-\\d.]+)\\)/i', $wkt, $matches) !== 1) {
			return null;
		}

		return [
			'type' => 'Point',
			'coordinates' => [
				(float)$matches[1],
				(float)$matches[2],
			],
		];

	}//end parseWkt()

	/**
	 * Compute the APCu cache key for a normalised query.
	 *
	 * @param string $endpoint The PDOK endpoint name.
	 * @param array $params Query parameters (will be ksort-normalised).
	 *
	 * @return string Deterministic cache key.
	 */
	private function cacheKey(string $endpoint, array $params): string {
		ksort($params);
		$hash = hash('sha256', (string)json_encode($params));
		return self::CACHE_PREFIX . '::' . $endpoint . '::' . $hash;
	}//end cacheKey()

	/**
	 * Fetch a previously-cached normalised response.
	 *
	 * @param string $key The cache key.
	 *
	 * @return array|null The cached payload, or null when missing/unavailable.
	 */
	private function cacheGet(string $key): ?array {
		if ($this->cache === null) {
			return null;
		}

		$value = $this->cache->get($key);
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end cacheGet()

	/**
	 * Store a normalised response in the cache.
	 *
	 * @param string $key The cache key.
	 * @param array $value The payload to cache.
	 * @param int $ttl TTL in seconds.
	 *
	 * @return void
	 */
	private function cacheSet(string $key, array $value, int $ttl): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set($key, $value, $ttl);

	}//end cacheSet()

	/**
	 * Look up an existing OR addresses record by query (lookup-by-id or reverse).
	 *
	 * @param string $endpoint PDOK endpoint name.
	 * @param array $params Query parameters.
	 *
	 * @return array|null Cached normalised payload from OR, or null on miss.
	 */
	private function orLookup(string $endpoint, array $params): ?array {
		$objectService = $this->getOpenRegisterObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			if ($endpoint === 'lookup' && isset($params['id']) === true) {
				$docs = $objectService->getMapper(register: 'openconnector', schema: 'addresses')->findAll(
					[
						'limit' => 1,
						'filter' => ['pdokId' => $params['id']],
					]
				);
			} elseif ($endpoint === 'reverse' && isset($params['lat'], $params['lon']) === true) {
				$docs = $objectService->getMapper(register: 'openconnector', schema: 'addresses')->findAll(
					[
						'limit' => 1,
						'geo' => [
							'near' => [(float)$params['lat'], (float)$params['lon']],
							'radius' => 10,
						],
					]
				);
			} else {
				return null;
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'PdokConnector: OR lookup failed',
				['endpoint' => $endpoint, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try

		if (empty($docs) === true) {
			return null;
		}

		$age = (time() - strtotime(($docs[0]['fetchedAt'] ?? '1970-01-01T00:00:00Z')));
		if ($age > self::TTL_RESOLVED) {
			return null;
		}

		return ['docs' => [$docs[0]], 'numFound' => 1];
	}//end orLookup()

	/**
	 * Persist a normalised PDOK document into OR's `addresses` register.
	 *
	 * @param array $doc Normalised PostalAddress.
	 *
	 * @return void
	 */
	public function writeThrough(array $doc): void {
		$objectService = $this->getOpenRegisterObjectService();
		if ($objectService === null || ($doc['pdokId'] ?? null) === null) {
			return;
		}

		try {
			$objectService->saveObject(
				register: 'openconnector',
				schema: 'addresses',
				object: $doc,
				uniqueOn: ['pdokId'],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PdokConnector: OR write-through failed',
				['pdokId' => $doc['pdokId'], 'exception' => $e->getMessage()]
			);
		}

	}//end writeThrough()

	/**
	 * Read the current breaker state from the cache.
	 *
	 * @return string `closed`, `open`, or `half-open`.
	 */
	private function circuitState(): string {
		if ($this->cache === null) {
			return 'closed';
		}

		$state = $this->cache->get(self::CIRCUIT_KEY);
		if (is_array($state) === false) {
			return 'closed';
		}

		if (($state['state'] ?? 'closed') === 'open') {
			$openedAt = (int)($state['opened_at'] ?? 0);
			if (time() - $openedAt >= self::BREAKER_OPEN_SECONDS) {
				return 'half-open';
			}

			return 'open';
		}

		return (string)($state['state'] ?? 'closed');
	}//end circuitState()

	/**
	 * Record a successful upstream call: close breaker, reset failure count.
	 *
	 * @return void
	 */
	private function circuitOnSuccess(): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set(
			self::CIRCUIT_KEY,
			['state' => 'closed', 'failures' => 0, 'opened_at' => 0]
		);

	}//end circuitOnSuccess()

	/**
	 * Record a failed upstream call; open the breaker after threshold.
	 *
	 * @return void
	 */
	private function circuitOnFailure(): void {
		if ($this->cache === null) {
			return;
		}

		$state = $this->cache->get(self::CIRCUIT_KEY);
		if (is_array($state) === false) {
			$state = ['state' => 'closed', 'failures' => 0, 'opened_at' => 0];
		}

		$failures = ((int)($state['failures'] ?? 0) + 1);
		if ($failures >= self::BREAKER_THRESHOLD) {
			$this->cache->set(
				self::CIRCUIT_KEY,
				['state' => 'open', 'failures' => $failures, 'opened_at' => time()]
			);
			return;
		}

		$this->cache->set(
			self::CIRCUIT_KEY,
			['state' => 'closed', 'failures' => $failures, 'opened_at' => 0]
		);

	}//end circuitOnFailure()

	/**
	 * Sleep for the configured backoff before the next 429 retry.
	 *
	 * Delay = `2^attempt * 200ms` with ±10% jitter, capped at 5000ms.
	 *
	 * @param int $attempt The 1-based retry attempt number.
	 *
	 * @return void
	 */
	private function sleepBackoff(int $attempt): void {
		$base = (self::BACKOFF_BASE_MS * (2 ** $attempt));
		$jitter = (int)($base * 0.1);
		$delay = ($base + random_int(-$jitter, $jitter));
		if ($delay > self::BACKOFF_CAP_MS) {
			$delay = self::BACKOFF_CAP_MS;
		}

		usleep($delay * 1000);

	}//end sleepBackoff()

	/**
	 * Resolve OR's ObjectService if installed; null otherwise.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null
	 */
	private function getOpenRegisterObjectService() {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

	}//end getOpenRegisterObjectService()

	/**
	 * Emit a single structured observability log entry per upstream call.
	 *
	 * @param string $endpoint PDOK endpoint name.
	 * @param bool $cacheHit True when APCu served the call.
	 * @param bool $orHit True when OR served the call.
	 * @param int|null $upstreamLatencyMs Upstream call latency (null on cache/OR hits).
	 * @param int|null $httpStatus HTTP status code from upstream (null on cache/OR hits).
	 * @param string $circuitState Current breaker state.
	 * @param bool $writeThrough True when this call wrote to OR.
	 *
	 * @return void
	 */
	private function logCall(
		string $endpoint,
		bool $cacheHit,
		bool $orHit,
		?int $upstreamLatencyMs,
		?int $httpStatus,
		string $circuitState,
		bool $writeThrough,
	): void {
		$level = 'debug';
		if ($circuitState === 'open' || ($httpStatus !== null && $httpStatus >= 500)) {
			$level = 'error';
		} elseif ($httpStatus === 429) {
			$level = 'warning';
		}

		$context = [
			'endpoint' => $endpoint,
			'cache_hit' => $cacheHit,
			'or_hit' => $orHit,
			'upstream_latency_ms' => $upstreamLatencyMs,
			'http_status' => $httpStatus,
			'circuit_state' => $circuitState,
			'write_through' => $writeThrough,
		];

		if ($level === 'error') {
			$this->logger->error('PdokConnector upstream call', $context);
		} elseif ($level === 'warning') {
			$this->logger->warning('PdokConnector upstream call', $context);
		} else {
			$this->logger->debug('PdokConnector upstream call', $context);
		}

	}//end logCall()
}//end class
