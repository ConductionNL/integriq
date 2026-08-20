<?php

/**
 * One synchronization must not be able to take the whole container.
 *
 * `maxPages` bounded how many REQUESTS a run makes. Nothing bounded how much it
 * HELD: a crawl accumulates every fetched page before the item loop starts, so
 * a source with large records reaches PHP's memory limit while still inside its
 * page budget. What happens then is a fatal mid-run with no log line and no
 * synchronization_log row — the process dies before writing either. Measured
 * 2026-08-14: a 19,822-object crawl held 1.4 GB on a container shared with 44
 * other apps.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\SynchronizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class SynchronizationResourceLimitsTest extends TestCase {
	private SynchronizationService $service;

	protected function setUp(): void {
		$this->service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();
	}

	/**
	 * @param string $method The private method to reach.
	 *
	 * @return ReflectionMethod The accessible method.
	 */
	private function reach(string $method): ReflectionMethod {
		$m = new ReflectionMethod(SynchronizationService::class, $method);
		$m->setAccessible(true);

		return $m;
	}

	/**
	 * THE trap this guard would otherwise walk into. `memory_limit` is a
	 * SHORTHAND string — `512M`, `2G` — so reading it as an int yields 512, and
	 * every run would look like it had blown a 512-BYTE ceiling on page one.
	 *
	 * @param string $ini The memory_limit value.
	 * @param int $expected Bytes.
	 */
	#[DataProvider('memoryLimitShorthands')]
	public function testTheMemoryLimitShorthandIsParsedAsBytes(string $ini, int $expected): void {
		$original = ini_get('memory_limit');
		ini_set('memory_limit', $ini);

		try {
			$this->assertSame($expected, $this->reach('phpMemoryLimitBytes')->invoke($this->service));
		} finally {
			ini_set('memory_limit', $original);
		}
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function memoryLimitShorthands(): array {
		return [
			'megabytes' => ['512M', (512 * 1024 * 1024)],
			'gigabytes' => ['2G', (2 * 1024 * 1024 * 1024)],
			// Deliberately no sub-100M cases: PHP REFUSES an ini_set of
			// memory_limit below what the process already holds, so a '1048576'
			// case silently never applies and the test asserts against whatever
			// the limit was before — passing or failing for reasons unrelated to
			// the parser. M and G are the shapes that occur in the wild anyway.
			'unlimited' => ['-1', -1],
		];
	}

	/**
	 * An unlimited limit cannot be exceeded, and inventing a ceiling for it would
	 * stop background imports that are working — CLI runs default to -1.
	 */
	public function testAnUnlimitedMemoryLimitNeverTripsTheCeiling(): void {
		$original = ini_get('memory_limit');
		ini_set('memory_limit', '-1');

		try {
			$this->assertFalse($this->reach('exceededMemoryCeiling')->invoke($this->service, []));
		} finally {
			ini_set('memory_limit', $original);
		}
	}

	/**
	 * A source that legitimately needs the whole limit, and runs where nothing
	 * else shares the container, can switch the ceiling off.
	 */
	public function testZeroPercentDisablesTheCeiling(): void {
		$this->assertFalse(
			$this->reach('exceededMemoryCeiling')->invoke($this->service, ['maxMemoryPercent' => 0])
		);
	}

	/**
	 * ...and a ceiling of 1% trips immediately, which is what proves the check
	 * is actually comparing against real usage rather than always returning
	 * false. A guard that cannot fire is not a guard.
	 */
	public function testAnAbsurdlyLowCeilingTrips(): void {
		$original = ini_get('memory_limit');
		ini_set('memory_limit', '512M');

		try {
			$this->assertTrue(
				$this->reach('exceededMemoryCeiling')->invoke($this->service, ['maxMemoryPercent' => 1]),
				'PHP already holds more than 1% of 512M; a ceiling that never trips guards nothing.'
			);
		} finally {
			ini_set('memory_limit', $original);
		}
	}

	/**
	 * The default leaves room for what comes AFTER the fetch — the item loop, the
	 * write buffers, the run log. Stopping at 95% would trade a fatal during
	 * fetching for a fatal during processing: the same outage, later.
	 */
	public function testTheDefaultLeavesHeadroomForProcessing(): void {
		$this->assertGreaterThanOrEqual(50, SynchronizationService::DEFAULT_MAX_MEMORY_PERCENT);
		$this->assertLessThanOrEqual(90, SynchronizationService::DEFAULT_MAX_MEMORY_PERCENT);
	}

	/**
	 * The concurrency ceiling exists because the window is per-source JSON and
	 * decides how many sockets one worker opens. It is also not conservatism:
	 * wider windows measured SLOWER — data.overheid.nl over 40 pages took 14.0 s
	 * at a window of 5 and 28.8 s at 40.
	 */
	public function testTheConcurrencyCeilingIsBoundedAndSane(): void {
		$default = (new \ReflectionClass(SynchronizationService::class))
			->getConstant('PREFETCH_WINDOW');

		$this->assertGreaterThanOrEqual(
			$default,
			SynchronizationService::MAX_PREFETCH_WINDOW,
			'The ceiling must not sit below the default, or the default is unreachable.'
		);
		$this->assertLessThanOrEqual(
			50,
			SynchronizationService::MAX_PREFETCH_WINDOW,
			'Nothing above this has ever measured faster.'
		);
	}
}
