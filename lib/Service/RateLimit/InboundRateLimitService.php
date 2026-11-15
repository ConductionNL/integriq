<?php

/**
 * Integriq — inbound per-consumer rate-limit + quota enforcement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The inbound counterpart to the outbound source rate-limiting in
 * {@see \OCA\Integriq\Service\CallService}. After a consumer is resolved
 * and authenticated on an endpoint request, this service counts the request
 * against the consumer's short-window `rateLimit` and longer-horizon `quota`
 * and returns a {@see RateLimitDecision} the endpoint uses to emit IETF
 * RateLimit headers or a 429.
 *
 * Counters are kept in the Nextcloud distributed cache with an atomic
 * increment so they are correct under concurrency and across PHP-FPM workers
 * (D3). Short-window keys carry a TTL equal to the window so they self-expire
 * with no cleanup job; quota keys carry a TTL equal to the seconds remaining in
 * the calendar period so the quota resets on rollover.
 *
 * @category Service
 * @package  OCA\Integriq\Service\RateLimit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\RateLimit;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * Enforces inbound per-consumer rate limits and quotas.
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
class InboundRateLimitService {

	/**
	 * Distributed cache holding the window + quota counters.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Whether the cache backend supports atomic increment (IMemcache).
	 *
	 * @var boolean
	 */
	private readonly bool $atomic;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Cache factory used to build the distributed counter store.
	 * @param LoggerInterface $logger Logger used for the non-atomic-fallback warning.
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('integriq.ratelimit');
		$this->atomic = ($this->cache instanceof IMemcache);

	}//end __construct()

	/**
	 * Evaluate a request against the consumer's rateLimit and quota.
	 *
	 * Ordering (D2): the short-window rate limit is checked first; if it
	 * rejects, the quota is NOT incremented (the request never ran). A request
	 * that passes the window is then counted against the quota.
	 *
	 * @param string $consumerKey Stable per-consumer key (consumer uuid, or client IP for anonymous).
	 * @param array|null $rateLimit `{requestsPerWindow:int, windowSeconds:int}` or null (unlimited).
	 * @param array|null $quota `{limit:int, period:"hour"|"day"|"month"}` or null (unlimited).
	 *
	 * @return RateLimitDecision The decision, including header values.
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: Inbound rate-limit enforcement after authentication (REQ-CON-RL-002)
	 */
	public function enforce(string $consumerKey, ?array $rateLimit, ?array $quota): RateLimitDecision {
		$check = new RateLimitCheck(consumerKey: $consumerKey, rateLimit: $rateLimit, quota: $quota);
		$refusal = ($this->burstRefusal(check: $check) ?? $this->quotaRefusal(check: $check));
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->allowed(check: $check);
	}//end enforce()

	/**
	 * Short-window burst limiter: count the request, refuse it past the window's limit.
	 *
	 * @param RateLimitCheck $check The request's limits.
	 *
	 * @return RateLimitDecision|null The refusal, or null when within the limit or unlimited.
	 */
	private function burstRefusal(RateLimitCheck $check): ?RateLimitDecision {
		if ($check->hasRateLimit() === false) {
			return null;
		}

		$window = $this->currentWindow(check: $check);
		$count = $this->increment(key: $window['key'], ttl: (int)$check->rateLimit['windowSeconds']);
		if ($count <= $check->windowLimit()) {
			return null;
		}

		return new RateLimitDecision(
			allowed: false,
			hasRateLimit: true,
			limit: $check->windowLimit(),
			remaining: 0,
			resetSeconds: $window['resetSeconds'],
			retryAfter: max(1, $window['resetSeconds']),
			reason: RateLimitDecision::REASON_RATE_LIMIT
		);

	}//end burstRefusal()

	/**
	 * Longer-horizon quota: count the request, refuse it past the period's limit.
	 *
	 * @param RateLimitCheck $check The request's limits.
	 *
	 * @return RateLimitDecision|null The refusal, or null when within the quota or none is configured.
	 */
	private function quotaRefusal(RateLimitCheck $check): ?RateLimitDecision {
		if ($check->hasQuota() === false) {
			return null;
		}

		$period = (string)$check->quota['period'];
		$bucket = $this->periodBucket(period: $period);
		$quotaTtl = $this->secondsUntilPeriodRollover(period: $period);
		$quotaKey = 'oc_q_' . $this->hashKey(consumerKey: $check->consumerKey) . '_' . $period . '_' . $bucket;
		if ($this->increment(key: $quotaKey, ttl: $quotaTtl) <= (int)$check->quota['limit']) {
			return null;
		}

		return new RateLimitDecision(
			allowed: false,
			hasRateLimit: $check->hasRateLimit(),
			limit: $check->windowLimit(),
			remaining: 0,
			resetSeconds: $quotaTtl,
			retryAfter: max(1, $quotaTtl),
			reason: RateLimitDecision::REASON_QUOTA
		);

	}//end quotaRefusal()

	/**
	 * An allowed request. When a rateLimit is configured, expose the remaining window
	 * budget (reflecting the increment above) in the response headers; an unlimited
	 * consumer gets no headers.
	 *
	 * @param RateLimitCheck $check The request's limits.
	 *
	 * @return RateLimitDecision The decision.
	 */
	private function allowed(RateLimitCheck $check): RateLimitDecision {
		if ($check->hasRateLimit() === false) {
			return new RateLimitDecision(allowed: true, hasRateLimit: false);
		}

		$window = $this->currentWindow(check: $check);
		$current = (int)($this->cache->get($window['key']) ?? 0);

		return new RateLimitDecision(
			allowed: true,
			hasRateLimit: true,
			limit: $check->windowLimit(),
			remaining: max(0, ($check->windowLimit() - $current)),
			resetSeconds: $window['resetSeconds']
		);

	}//end allowed()

	/**
	 * The current burst window: its counter key and the seconds until it resets.
	 *
	 * @param RateLimitCheck $check The request's limits, with a burst limit configured.
	 *
	 * @return array{key: string, resetSeconds: int} The window.
	 */
	private function currentWindow(RateLimitCheck $check): array {
		$window = (int)$check->rateLimit['windowSeconds'];
		$now = time();
		$windowStart = (intdiv($now, $window) * $window);

		return [
			'key' => 'oc_rl_' . $this->hashKey(consumerKey: $check->consumerKey) . '_' . $windowStart,
			'resetSeconds' => (($windowStart + $window) - $now),
		];

	}//end currentWindow()

	/**
	 * Atomically increment a counter key, seeding it with the given TTL.
	 *
	 * Uses `IMemcache::add()` + `inc()` when the distributed backend supports
	 * atomic increment (Redis/APCu); otherwise falls back to a non-atomic
	 * get/set (best-effort on a single-node local cache, logged once).
	 *
	 * @param string $key The cache key.
	 * @param int $ttl Time-to-live in seconds for a freshly seeded key.
	 *
	 * @return int The counter value after the increment.
	 */
	private function increment(string $key, int $ttl): int {
		if ($this->atomic === true && $this->cache instanceof IMemcache) {
			// Seed the key (with TTL) only when it does not yet exist, so the
			// TTL is set once per window and inc() is atomic thereafter.
			$this->cache->add($key, 0, $ttl);
			$value = $this->cache->inc($key);
			if ($value !== false) {
				return (int)$value;
			}
		}

		// Non-atomic fallback for a local (single-node) cache backend.
		$this->logger->debug(
			'integriq inbound rate-limit using non-atomic counter fallback (no distributed IMemcache backend configured)'
		);
		$value = ((int)($this->cache->get($key) ?? 0) + 1);
		$this->cache->set($key, $value, $ttl);
		return $value;
	}//end increment()

	/**
	 * The current calendar bucket identifier for a quota period.
	 *
	 * @param string $period One of hour|day|month.
	 *
	 * @return string A bucket id that changes on each period rollover.
	 */
	private function periodBucket(string $period): string {
		return match ($period) {
			'hour' => gmdate('Y-m-d-H'),
			'month' => gmdate('Y-m'),
			default => gmdate('Y-m-d'),
		};

	}//end periodBucket()

	/**
	 * Seconds remaining until the given quota period rolls over (UTC).
	 *
	 * @param string $period One of hour|day|month.
	 *
	 * @return int Seconds until the next period boundary (>= 1).
	 */
	private function secondsUntilPeriodRollover(string $period): int {
		$now = time();
		$next = match ($period) {
			'hour' => strtotime(gmdate('Y-m-d H:00:00', ($now + 3600))),
			'month' => strtotime(gmdate('Y-m-01 00:00:00', strtotime('first day of next month', $now))),
			default => strtotime(gmdate('Y-m-d 00:00:00', ($now + 86400))),
		};

		return max(1, ($next - $now));
	}//end secondsUntilPeriodRollover()

	/**
	 * Hash a consumer key so cache keys stay short and control-character safe.
	 *
	 * @param string $consumerKey The raw consumer identity (uuid or IP).
	 *
	 * @return string A short hex digest.
	 */
	private function hashKey(string $consumerKey): string {
		return substr(hash('sha256', $consumerKey), 0, 32);
	}//end hashKey()
}//end class
