<?php

/**
 * Unit tests for OtelExportBreaker: the failure threshold, the cooldown,
 * and the once-per-pause skipped-trace count.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Observability\Otel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability\Otel;

use OCA\Integriq\Observability\Otel\OtelExportBreaker;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The breaker over an in-memory IAppConfig and a cache double.
 *
 * @covers \OCA\Integriq\Observability\Otel\OtelExportBreaker
 */
class OtelExportBreakerTest extends TestCase {

	/**
	 * Stored app settings.
	 *
	 * @var array<string, int>
	 */
	private array $config = [];

	/**
	 * Every write, as [key, value], in order.
	 *
	 * @var array<int, array{0:string,1:int}>
	 */
	private array $writes = [];

	/**
	 * An in-memory IAppConfig.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default): int {
				$this->assertSame('integriq', $app);
				return ($this->config[$key] ?? $default);
			}
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->assertSame('integriq', $app);
				$this->writes[] = [$key, $value];
				$this->config[$key] = $value;
				return true;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * A breaker whose distributed cache counts from the given start.
	 *
	 * @param int $start The counter before the first increment.
	 *
	 * @return OtelExportBreaker
	 */
	private function breaker(int $start = 0): OtelExportBreaker {
		$count = $start;
		$cache = $this->createMock(IMemcache::class);
		$cache->method('inc')->willReturnCallback(
			function (string $key) use (&$count): int {
				$this->assertSame('otel_skipped_total', $key);
				return ++$count;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->with('integriq')->willReturn($cache);

		return new OtelExportBreaker($this->appConfig(), $factory);
	}//end breaker()

	/**
	 * Below the threshold failures only count; the fifth opens the breaker
	 * for the cooldown and resets the count.
	 *
	 * @return void
	 */
	public function testTheFifthFailureOpensTheBreakerForTheCooldown(): void {
		$breaker = $this->breaker();

		for ($i = 1; $i < OtelExportBreaker::THRESHOLD; $i++) {
			$breaker->recordFailure(now: 1000);
			$this->assertSame($i, $this->config['otel_breaker_failures']);
			$this->assertFalse($breaker->isOpen(now: 1000));
		}

		$breaker->recordFailure(now: 1000);

		$this->assertSame(1000 + OtelExportBreaker::COOLDOWN_SECONDS, $breaker->openUntil());
		$this->assertSame(0, $this->config['otel_breaker_failures']);
		$this->assertSame(
			[['otel_breaker_open_until', 1300], ['otel_breaker_failures', 0]],
			array_slice($this->writes, -2)
		);
	}//end testTheFifthFailureOpensTheBreakerForTheCooldown()

	/**
	 * The breaker is open strictly before its end time and closed from it.
	 *
	 * @return void
	 */
	public function testTheBreakerClosesAtItsEndTime(): void {
		$this->config['otel_breaker_open_until'] = 1300;
		$breaker = $this->breaker();

		$this->assertTrue($breaker->isOpen(now: 1299));
		$this->assertFalse($breaker->isOpen(now: 1300));
		$this->assertFalse($breaker->isOpen(now: 1301));
	}//end testTheBreakerClosesAtItsEndTime()

	/**
	 * A success resets a running count, and writes nothing when there is none.
	 *
	 * @return void
	 */
	public function testASuccessResetsOnlyARunningCount(): void {
		$breaker = $this->breaker();

		$breaker->recordSuccess();
		$this->assertSame([], $this->writes);

		$this->config['otel_breaker_failures'] = 3;
		$breaker->recordSuccess();
		$this->assertSame([['otel_breaker_failures', 0]], $this->writes);
	}//end testASuccessResetsOnlyARunningCount()

	/**
	 * The first skip in a pause returns the running count and marks the
	 * pause; later skips in it return null; a new pause reports again.
	 *
	 * @return void
	 */
	public function testASkipIsReportedOncePerPause(): void {
		$this->config['otel_breaker_open_until'] = 1300;
		$breaker = $this->breaker(start: 10);

		$this->assertSame(11, $breaker->recordSkipped());
		$this->assertNull($breaker->recordSkipped());
		$this->assertSame([['otel_skipped_warned_until', 1300]], $this->writes);

		$this->config['otel_breaker_open_until'] = 1900;
		$this->assertSame(13, $breaker->recordSkipped());
	}//end testASkipIsReportedOncePerPause()

	/**
	 * A cache that cannot count, or that fails, reports zero skipped.
	 *
	 * @return void
	 */
	public function testAnUncountableCacheReportsZero(): void {
		$this->config['otel_breaker_open_until'] = 1300;

		$plainFactory = $this->createMock(ICacheFactory::class);
		$plainFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$this->assertSame(0, (new OtelExportBreaker($this->appConfig(), $plainFactory))->recordSkipped());

		$this->config['otel_breaker_open_until'] = 1600;
		$failingFactory = $this->createMock(ICacheFactory::class);
		$failingFactory->method('createDistributed')->willThrowException(new RuntimeException('no cache'));
		$this->assertSame(0, (new OtelExportBreaker($this->appConfig(), $failingFactory))->recordSkipped());

		$this->config['otel_breaker_open_until'] = 1900;
		$falseCache = $this->createMock(IMemcache::class);
		$falseCache->method('inc')->willReturn(false);
		$falseFactory = $this->createMock(ICacheFactory::class);
		$falseFactory->method('createDistributed')->willReturn($falseCache);
		$this->assertSame(0, (new OtelExportBreaker($this->appConfig(), $falseFactory))->recordSkipped());
	}//end testAnUncountableCacheReportsZero()
}//end class
