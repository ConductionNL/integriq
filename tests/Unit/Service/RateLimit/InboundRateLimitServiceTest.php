<?php

/**
 * Integriq — inbound rate-limit service tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\RateLimit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\RateLimit;

use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RateLimit\RateLimitDecision;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * In-memory IMemcache fake providing real atomic add()/inc() semantics.
 */
class FakeMemcache implements IMemcache {

	/** @var array<string, mixed> */
	public array $store = [];

	/** @var array<string, int> */
	public array $ttls = [];

	public function get($key) {
		return $this->store[$key] ?? null;
	}

	public function set($key, $value, $ttl = 0) {
		$this->store[$key] = $value;
		$this->ttls[$key] = $ttl;
		return true;
	}

	public function hasKey($key) {
		return isset($this->store[$key]);
	}

	public function remove($key) {
		unset($this->store[$key]);
		return true;
	}

	public function clear($prefix = '') {
		$this->store = [];
		return true;
	}

	public static function isAvailable(): bool {
		return true;
	}

	public function add($key, $value, $ttl = 0) {
		if (isset($this->store[$key])) {
			return false;
		}
		$this->store[$key] = $value;
		$this->ttls[$key] = $ttl;
		return true;
	}

	public function inc($key, $step = 1) {
		if (isset($this->store[$key]) === false) {
			return false;
		}
		$this->store[$key] = ((int)$this->store[$key] + $step);
		return $this->store[$key];
	}

	public function dec($key, $step = 1) {
		if (isset($this->store[$key]) === false) {
			return false;
		}
		$this->store[$key] = ((int)$this->store[$key] - $step);
		return $this->store[$key];
	}

	public function cas($key, $old, $new) {
		if (($this->store[$key] ?? null) === $old) {
			$this->store[$key] = $new;
			return true;
		}
		return false;
	}

	public function cad($key, $old) {
		if (($this->store[$key] ?? null) === $old) {
			unset($this->store[$key]);
			return true;
		}
		return false;
	}

	public function ncad(string $key, mixed $old): bool {
		if (isset($this->store[$key]) === true && $this->store[$key] !== $old) {
			unset($this->store[$key]);
			return true;
		}
		return false;
	}
}//end class

/**
 * Unit tests for InboundRateLimitService.
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
class InboundRateLimitServiceTest extends TestCase {

	private FakeMemcache $cache;

	/**
	 * Build a service backed by a fresh in-memory atomic cache.
	 *
	 * @return InboundRateLimitService
	 */
	private function makeService(): InboundRateLimitService {
		$this->cache = new FakeMemcache();
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		return new InboundRateLimitService($factory, new NullLogger());
	}//end makeService()

	/**
	 * An unconfigured consumer is unlimited and receives no headers.
	 *
	 * @return void
	 */
	public function testUnlimitedWhenUnconfigured(): void {
		$decision = $this->makeService()->enforce('consumer:a', null, null);

		$this->assertTrue($decision->allowed);
		$this->assertFalse($decision->hasRateLimit);
		$this->assertSame([], $decision->toHeaders());
	}//end testUnlimitedWhenUnconfigured()

	/**
	 * Under the limit, RateLimit-* headers reflect the remaining budget and the
	 * window key is seeded with a TTL equal to the window (self-expiry).
	 *
	 * @return void
	 */
	public function testUnderLimitEmitsHeaders(): void {
		$service = $this->makeService();
		$rateLimit = ['requestsPerWindow' => 10, 'windowSeconds' => 60];

		$decision = null;
		for ($i = 0; $i < 4; $i++) {
			$decision = $service->enforce('consumer:a', $rateLimit, null);
		}

		$this->assertTrue($decision->allowed);
		$headers = $decision->toHeaders();
		$this->assertSame('10', $headers['RateLimit-Limit']);
		$this->assertSame('6', $headers['RateLimit-Remaining']);
		$this->assertArrayHasKey('RateLimit-Reset', $headers);
		$this->assertArrayNotHasKey('Retry-After', $headers);

		// Window key carries a TTL equal to windowSeconds so it self-expires.
		$this->assertContains(60, $this->cache->ttls);
	}//end testUnderLimitEmitsHeaders()

	/**
	 * Over the short-window limit returns a rejected decision with Retry-After.
	 *
	 * @return void
	 */
	public function testOverLimitReturns429WithRetryAfter(): void {
		$service = $this->makeService();
		$rateLimit = ['requestsPerWindow' => 2, 'windowSeconds' => 60];

		$this->assertTrue($service->enforce('consumer:b', $rateLimit, null)->allowed);
		$this->assertTrue($service->enforce('consumer:b', $rateLimit, null)->allowed);
		$third = $service->enforce('consumer:b', $rateLimit, null);

		$this->assertFalse($third->allowed);
		$this->assertSame(RateLimitDecision::REASON_RATE_LIMIT, $third->reason);
		$headers = $third->toHeaders();
		$this->assertArrayHasKey('Retry-After', $headers);
		$this->assertSame('0', $headers['RateLimit-Remaining']);
	}//end testOverLimitReturns429WithRetryAfter()

	/**
	 * Over the quota returns a rejected decision with reason=quota.
	 *
	 * @return void
	 */
	public function testOverQuotaReturns429(): void {
		$service = $this->makeService();
		$quota = ['limit' => 2, 'period' => 'day'];

		$this->assertTrue($service->enforce('consumer:c', null, $quota)->allowed);
		$this->assertTrue($service->enforce('consumer:c', null, $quota)->allowed);
		$third = $service->enforce('consumer:c', null, $quota);

		$this->assertFalse($third->allowed);
		$this->assertSame(RateLimitDecision::REASON_QUOTA, $third->reason);
		$this->assertArrayHasKey('Retry-After', $third->toHeaders());
	}//end testOverQuotaReturns429()

	/**
	 * A consumer with only rateLimit is never rejected on quota grounds.
	 *
	 * @return void
	 */
	public function testRateLimitAndQuotaAreIndependent(): void {
		$service = $this->makeService();
		$rateLimit = ['requestsPerWindow' => 100, 'windowSeconds' => 60];

		for ($i = 0; $i < 10; $i++) {
			$decision = $service->enforce('consumer:d', $rateLimit, null);
			$this->assertTrue($decision->allowed);
			$this->assertNull($decision->reason);
		}
	}//end testRateLimitAndQuotaAreIndependent()

	/**
	 * Atomic increment admits exactly the limit under many sequential calls;
	 * everything above is rejected (no over-admission).
	 *
	 * @return void
	 */
	public function testAtomicCounterAdmitsExactlyTheLimit(): void {
		$service = $this->makeService();
		$rateLimit = ['requestsPerWindow' => 5, 'windowSeconds' => 60];

		$allowed = 0;
		$rejected = 0;
		for ($i = 0; $i < 20; $i++) {
			if ($service->enforce('consumer:e', $rateLimit, null)->allowed === true) {
				$allowed++;
			} else {
				$rejected++;
			}
		}

		$this->assertSame(5, $allowed);
		$this->assertSame(15, $rejected);
	}//end testAtomicCounterAdmitsExactlyTheLimit()

	/**
	 * Distinct keys (e.g. distinct anonymous client IPs) are counted separately.
	 *
	 * @return void
	 */
	public function testDistinctKeysAreIndependent(): void {
		$service = $this->makeService();
		$rateLimit = ['requestsPerWindow' => 1, 'windowSeconds' => 60];

		$this->assertTrue($service->enforce('ip:10.0.0.1', $rateLimit, null)->allowed);
		$this->assertTrue($service->enforce('ip:10.0.0.2', $rateLimit, null)->allowed);
		$this->assertFalse($service->enforce('ip:10.0.0.1', $rateLimit, null)->allowed);
	}//end testDistinctKeysAreIndependent()
	/**
	 * Golden: every decision enforce() returns, with its fields, and the counters it keeps.
	 *
	 * The reset seconds depend on the clock; they are checked to lie inside the window or period.
	 *
	 * @return void
	 */
	public function testGoldenDecisions(): void {
		$fields = static fn (\OCA\Integriq\Service\RateLimit\RateLimitDecision $d): array => [$d->allowed, $d->hasRateLimit, $d->limit, $d->remaining, $d->reason];
		$rate = ['requestsPerWindow' => 2, 'windowSeconds' => 3600];
		$quota = ['limit' => 3, 'period' => 'day'];

		$service = $this->makeService();
		$this->assertSame([true, false, null, null, null], $fields($service->enforce('k', null, null)));
		$this->assertSame([true, false, null, null, null], $fields($service->enforce('k', ['requestsPerWindow' => 0, 'windowSeconds' => 60], ['limit' => 1, 'period' => 'week'])));
		$this->assertSame([], $this->cache->store);

		$first = $service->enforce('k', $rate, $quota);
		$this->assertSame([true, true, 2, 1, null], $fields($first));
		$this->assertGreaterThanOrEqual(1, $first->resetSeconds);
		$this->assertLessThanOrEqual(3600, $first->resetSeconds);
		$this->assertNull($first->retryAfter);
		$this->assertSame([true, true, 2, 0, null], $fields($service->enforce('k', $rate, $quota)));
		$burst = $service->enforce('k', $rate, $quota);
		$this->assertSame([false, true, 2, 0, 'rate_limit'], $fields($burst));
		$this->assertSame(max(1, $burst->resetSeconds), $burst->retryAfter);
		$keys = array_keys($this->cache->store);
		sort($keys);
		$this->assertCount(2, $keys, 'one window counter and one quota counter');
		$this->assertSame([2, 3], [$this->cache->store[$keys[0]], $this->cache->store[$keys[1]]], 'a burst refusal does not count against the quota');
		$this->assertMatchesRegularExpression('/^oc_q_[0-9a-f]{32}_day_\d{4}-\d\d-\d\d$/', $keys[0]);
		$this->assertMatchesRegularExpression('/^oc_rl_[0-9a-f]{32}_\d+$/', $keys[1]);
		$this->assertSame(3600, $this->cache->ttls[$keys[1]]);

		$service = $this->makeService();
		$this->assertSame([true, false, null, null, null], $fields($service->enforce('q', null, $quota)));
		$service->enforce('q', null, $quota);
		$service->enforce('q', null, $quota);
		$over = $service->enforce('q', null, $quota);
		$this->assertSame([false, false, null, 0, 'quota'], $fields($over));
		$this->assertSame($over->resetSeconds, $over->retryAfter);
		$this->assertLessThanOrEqual(86400, $over->resetSeconds);

		$service = $this->makeService();
		$service->enforce('r', $rate, ['limit' => 1, 'period' => 'hour']);
		$overWithRate = $service->enforce('r', $rate, ['limit' => 1, 'period' => 'hour']);
		$this->assertSame([false, true, 2, 0, 'quota'], $fields($overWithRate));
		$this->assertLessThanOrEqual(3600, $overWithRate->resetSeconds);
		$this->assertSame(1, $service->enforce('m', $rate, ['limit' => 5, 'period' => 'month'])->remaining);

	}//end testGoldenDecisions()
}//end class
