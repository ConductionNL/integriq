<?php

/**
 * OpenConnector — dashboard HTTP data-source resolve façade.
 *
 * Governed, read-only "resolve one value from a configured HTTP source"
 * service for dashboard/widget hosts (LaunchPad's `live-data-tile-widget` is
 * the first consumer). Runs the named `source` through the existing
 * {@see \OCA\OpenConnector\Service\CallService} HTTP-call engine (honouring
 * the source's own configured auth from the encrypted secret store),
 * evaluates a JSONPath-lite expression against the decoded response, and
 * caches the resolved value. No new transport code — this is a thin,
 * read-only orchestration layer on top of `source-management` +
 * `http-call-engine` + `authentication-twig`, none of which are modified.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Datasource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Datasource;

use DateTime;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves a single scalar value from a configured OpenConnector `source`.
 *
 * Single shared code path for both the HTTP controller and any in-process
 * caller — `resolve()` is the entire public surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 */
class DashboardDatasourceService {

	/**
	 * Default TTL (seconds) applied when the caller does not request one.
	 *
	 * @var integer
	 */
	private const DEFAULT_TTL_SECONDS = 300;

	/**
	 * Absolute ceiling on the effective TTL, used when neither the caller
	 * nor the source configure a maximum — protects an unconfigured source
	 * from being cached forever by a misbehaving caller-supplied ttl.
	 *
	 * @var integer
	 */
	private const DEFAULT_MAX_TTL_SECONDS = 3600;

	/**
	 * How long a "last known good" value is retained for stale-on-error
	 * fallback, independent of the fresh-value TTL. Long enough that a
	 * flapping upstream still has something sane to serve; short enough that
	 * a permanently-dead source eventually reports null again.
	 *
	 * @var integer
	 */
	private const STALE_RETENTION_SECONDS = 86400;

	/**
	 * Distributed cache used for both the fresh-value entries and the
	 * longer-lived stale-fallback entries.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService Resolves the `source` object, enforcing RBAC read-access.
	 * @param CallService $callService Runs the existing HTTP-call engine against the source.
	 * @param JsonPathLiteEvaluator $evaluator Evaluates the `valueExpr` against the decoded response.
	 * @param ICacheFactory $cacheFactory Builds the distributed cache used for resolved values.
	 * @param LoggerInterface $logger Logger for non-fatal upstream/decode failures.
	 * @param integer $rateLimitPerWindow Max resolve calls per source within
	 *                                    `rateLimitWindowSeconds`; excess calls are
	 *                                    served from cache or rejected without
	 *                                    hitting upstream.
	 * @param integer $rateLimitWindowSeconds Rate-limit window, in seconds.
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly CallService $callService,
		private readonly JsonPathLiteEvaluator $evaluator,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly int $rateLimitPerWindow = 30,
		private readonly int $rateLimitWindowSeconds = 60,
	) {
		$this->cache = $cacheFactory->createDistributed('openconnector.dashboarddatasource');

	}//end __construct()

	/**
	 * Resolve a single value from a named source.
	 *
	 * Read-only: only ever dispatches a GET/read call against the source
	 * (`CallService::call(..., method: 'GET', read: true)`); never mutates
	 * the source, its synchronizations, or any other object. The caller's
	 * `$params` are used exclusively as query parameters on the source's OWN
	 * configured location — any `url`/`host` entry is stripped before use,
	 * so a caller can never redirect egress away from the stored source
	 * (REQ "Egress is constrained to the source, never the caller").
	 *
	 * @param string $sourceId UUID of the `source` object to resolve against.
	 * @param string $valueExpr JSONPath-lite expression (`$.a.b.c`, `$.a[0].b`) evaluated against the response body.
	 * @param array $params Optional query parameters forwarded to the source call. `url`/`host` keys are ignored.
	 * @param int|null $ttl Optional requested cache TTL (seconds); clamped to the source-configured maximum.
	 *
	 * @return array{value: mixed, fetchedAt: string, stale: bool} The resolved value and cache-freshness metadata.
	 *
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the current user may not read the source.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the source does not exist.
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-egress-is-constrained-to-the-source-never-the-caller
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-responses-are-cached-with-stale-on-error-fallback
	 * @spec openspec/specs/dashboard-http-datasource/spec.md#requirement-caller-authorization-honours-the-source-s-read-access
	 */
	public function resolve(string $sourceId, string $valueExpr, array $params = [], ?int $ttl = null): array {
		// Egress guard: a caller-supplied url/host is NEVER forwarded to the
		// call engine — only the stored source's own location is ever used.
		unset($params['url'], $params['host']);

		// RBAC-enforced read: throws NotAuthorizedException/DoesNotExistException
		// when the current user may not read this source, or it does not
		// exist — the controller maps both to a clean HTTP status.
		$source = $this->orObjectService->find(
			id: $sourceId,
			register: 'openconnector',
			schema: 'source',
			_rbac: true,
			_multitenancy: true
		);

		$sourceData = $source->getObject();
		$cacheKey = $this->buildCacheKey(sourceId: $sourceId, valueExpr: $valueExpr, params: $params);
		$freshKey = 'fresh_' . $cacheKey;
		$staleKey = 'stale_' . $cacheKey;

		// Cache hit within TTL — ICache/Redis natively expires the fresh
		// entry once its own TTL elapses, so a hit here IS "within TTL" by
		// construction; no upstream fetch is performed.
		$cached = $this->cache->get($freshKey);
		if (is_array($cached) === true) {
			return [
				'value' => $cached['value'],
				'fetchedAt' => $cached['fetchedAt'],
				'stale' => false,
			];
		}

		// Per-source rate limit: excess calls within the window are served
		// from the stale-fallback cache (or a null/stale response when none
		// exists) and never reach the upstream.
		if ($this->isRateLimited(sourceId: $sourceId) === true) {
			$this->logger->info('dashboard-http-datasource: rate limit exceeded for source ' . $sourceId . ', serving stale/cached value');
			return $this->staleOrNullResponse(staleKey: $staleKey);
		}

		$effectiveTtl = $this->effectiveTtl(sourceData: $sourceData, requestedTtl: $ttl);

		$requestConfig = [];
		if (empty($params) === false) {
			$requestConfig = ['query' => $params];
		}

		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: '',
				method: 'GET',
				config: $requestConfig,
				read: true
			);
		} catch (\Throwable $e) {
			$this->logger->warning('dashboard-http-datasource: upstream call threw for source ' . $sourceId . ': ' . $e->getMessage());
			return $this->staleOrNullResponse(staleKey: $staleKey);
		}

		$logData = $callLog->getObject();
		$statusCode = (int)($logData['response']['statusCode'] ?? 0);

		if ($statusCode < 200 || $statusCode >= 300) {
			return $this->staleOrNullResponse(staleKey: $staleKey);
		}

		$decoded = json_decode((string)($logData['response']['body'] ?? ''), true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		$value = $this->evaluator->evaluate(data: $decoded, expr: $valueExpr);
		$fetchedAt = (new DateTime())->format(DateTime::ATOM);

		$entry = [
			'value' => $value,
			'fetchedAt' => $fetchedAt,
		];

		$this->cache->set($freshKey, $entry, $effectiveTtl);
		$this->cache->set($staleKey, $entry, self::STALE_RETENTION_SECONDS);

		return [
			'value' => $value,
			'fetchedAt' => $fetchedAt,
			'stale' => false,
		];

	}//end resolve()

	/**
	 * Build the stale-on-error / no-cache response shape.
	 *
	 * @param string $staleKey Cache key of the long-lived last-known-good entry.
	 *
	 * @return array{value: mixed, fetchedAt: string, stale: bool}
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-responses-are-cached-with-stale-on-error-fallback
	 */
	private function staleOrNullResponse(string $staleKey): array {
		$stale = $this->cache->get($staleKey);
		if (is_array($stale) === true) {
			return [
				'value' => $stale['value'],
				'fetchedAt' => $stale['fetchedAt'],
				'stale' => true,
			];
		}

		return [
			'value' => null,
			'fetchedAt' => (new DateTime())->format(DateTime::ATOM),
			'stale' => true,
		];

	}//end staleOrNullResponse()

	/**
	 * Compute the effective TTL: min(requested, source-configured maximum),
	 * defaulting to {@see DEFAULT_TTL_SECONDS} when the caller did not
	 * request one and {@see DEFAULT_MAX_TTL_SECONDS} when the source has no
	 * configured maximum. No `source` schema change is required — the
	 * optional `dashboardDatasource.maxTtl` key is read defensively and
	 * simply absent on sources that never set it.
	 *
	 * @param array $sourceData The decoded source object.
	 * @param int|null $requestedTtl The caller-requested TTL, if any.
	 *
	 * @return integer The effective TTL in seconds, always >= 1.
	 */
	private function effectiveTtl(array $sourceData, ?int $requestedTtl): int {
		$requested = $requestedTtl ?? self::DEFAULT_TTL_SECONDS;
		$sourceMax = ($sourceData['dashboardDatasource']['maxTtl'] ?? self::DEFAULT_MAX_TTL_SECONDS);

		return max(1, min((int)$requested, (int)$sourceMax));
	}//end effectiveTtl()

	/**
	 * Build the deterministic cache key for a (source, expression, params) triple.
	 *
	 * @param string $sourceId Source UUID.
	 * @param string $valueExpr JSONPath-lite expression.
	 * @param array $params Query parameters forwarded to the source call.
	 *
	 * @return string A short, control-character-safe cache key.
	 */
	private function buildCacheKey(string $sourceId, string $valueExpr, array $params): string {
		ksort($params);
		$paramsJson = json_encode($params);

		return 'oc_ddc_' . hash('sha256', $sourceId . '|' . $valueExpr . '|' . $paramsJson);
	}//end buildCacheKey()

	/**
	 * Whether the given source has exceeded its resolve rate limit for the
	 * current window. Uses a fixed calendar-window counter, mirroring
	 * {@see \OCA\OpenConnector\Service\RateLimit\InboundRateLimitService}.
	 *
	 * @param string $sourceId Source UUID.
	 *
	 * @return boolean True when the window's call budget is exhausted.
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-responses-are-cached-with-stale-on-error-fallback
	 */
	private function isRateLimited(string $sourceId): bool {
		$now = time();
		$windowStart = (intdiv($now, $this->rateLimitWindowSeconds) * $this->rateLimitWindowSeconds);
		$key = 'oc_ddc_rl_' . hash('sha256', $sourceId) . '_' . $windowStart;

		$count = ((int)($this->cache->get($key) ?? 0) + 1);
		$this->cache->set($key, $count, $this->rateLimitWindowSeconds);

		return ($count > $this->rateLimitPerWindow);
	}//end isRateLimited()
}//end class
