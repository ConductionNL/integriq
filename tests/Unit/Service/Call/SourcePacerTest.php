<?php

/**
 * Unit tests: the source pacer spaces calls under a source's limit.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Call;

use OCA\Integriq\Service\Call\PacerClock;
use OCA\Integriq\Service\Call\PacingDecision;
use OCA\Integriq\Service\Call\SourcePacer;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * A fake clock, an in-memory compare-and-swap cache, and the pacer between them.
 *
 * @covers \OCA\Integriq\Service\Call\SourcePacer
 * @covers \OCA\Integriq\Service\Call\PacingDecision
 * @uses \OCA\Integriq\Service\Call\PacerClock
 */
class SourcePacerTest extends TestCase {

	/**
	 * The fake clock's start: 2026-11-14 12:00:00 UTC, in microseconds.
	 *
	 * @var int
	 */
	private const START = 1794744000000000;

	/**
	 * The fake clock every pacer in a test reads.
	 *
	 * @var PacerClock
	 */
	private PacerClock $clock;

	/**
	 * Set up the fake clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clock = new class(self::START) extends PacerClock {
			/**
			 * @param int $now The time the clock starts at.
			 */
			public function __construct(public int $now) {
			}

			/**
			 * @return int
			 */
			public function nowMicros(): int {
				return $this->now;
			}

			/**
			 * @param int $micros How long to wait.
			 *
			 * @return void
			 */
			public function sleepMicros(int $micros): void {
				$this->now += max(0, $micros);
			}
		};
	}//end setUp()

	/**
	 * An in-memory memcache with add and compare-and-swap.
	 *
	 * @param int $failCas How many compare-and-swaps fail first, as if another worker moved the value.
	 *
	 * @return IMemcache
	 */
	private function memcache(int $failCas = 0): IMemcache {
		return new class($failCas) implements IMemcache {
			/** @var array<string,mixed> */
			public array $values = [];

			/**
			 * @param int $failCas Compare-and-swaps that fail first.
			 */
			public function __construct(public int $failCas) {
			}

			public function get(string $key) {
				return ($this->values[$key] ?? null);
			}

			public function set(string $key, $value, int $ttl = 0) {
				$this->values[$key] = $value;
				return true;
			}

			public function hasKey(string $key) {
				return array_key_exists($key, $this->values);
			}

			public function remove(string $key) {
				unset($this->values[$key]);
				return true;
			}

			public function clear(string $prefix = '') {
				$this->values = [];
				return true;
			}

			public static function isAvailable(): bool {
				return true;
			}

			public function add($key, $value, $ttl = 0) {
				if (array_key_exists($key, $this->values) === true) {
					return false;
				}

				$this->values[$key] = $value;
				return true;
			}

			public function inc($key, $step = 1) {
				$this->values[$key] = (($this->values[$key] ?? 0) + $step);
				return $this->values[$key];
			}

			public function dec($key, $step = 1) {
				$this->values[$key] = (($this->values[$key] ?? 0) - $step);
				return $this->values[$key];
			}

			public function cas($key, $old, $new) {
				if ($this->failCas > 0) {
					$this->failCas--;
					// Another worker took a turn: the stored due time moved on by a second.
					$this->values[$key] = ((int)$this->values[$key] + 1000000);
					return false;
				}

				if (($this->values[$key] ?? null) !== $old) {
					return false;
				}

				$this->values[$key] = $new;
				return true;
			}

			public function cad($key, $old) {
				return false;
			}

			public function ncad(string $key, mixed $old): bool {
				return false;
			}
		};
	}//end memcache()

	/**
	 * A pacer over the given cache, or over none.
	 *
	 * @param ICache|null $cache The distributed cache; null for a server without one.
	 *
	 * @return SourcePacer
	 */
	private function pacer(?ICache $cache): SourcePacer {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cache !== null);
		if ($cache !== null) {
			$factory->expects($this->once())->method('createDistributed')->with('integriq.source.pace')->willReturn($cache);
		} else {
			$factory->expects($this->never())->method('createDistributed');
		}

		return new SourcePacer($factory, $this->clock);
	}//end pacer()

	/**
	 * Make one background call: take the turn and wait for it.
	 *
	 * @param SourcePacer $pacer The pacer.
	 * @param array $source The source.
	 * @param string $key The source's key.
	 *
	 * @return PacingDecision
	 */
	private function backgroundCall(SourcePacer $pacer, array $source, string $key = 'kvk'): PacingDecision {
		$decision = $pacer->acquire(sourceKey: $key, sourceData: $source, live: false);
		$pacer->wait(decision: $decision);

		return $decision;
	}//end backgroundCall()

	/**
	 * Thirty background calls at ten a second take about three seconds, and none is refused.
	 *
	 * @return void
	 */
	public function testThirtyCallsAtTenASecondTakeAboutThreeSeconds(): void {
		$pacer = $this->pacer(cache: $this->memcache());
		$source = ['pace' => ['maxCalls' => 10, 'perSeconds' => 1]];

		$starts = [];
		for ($i = 0; $i < 30; $i++) {
			$decision = $this->backgroundCall(pacer: $pacer, source: $source);
			$this->assertFalse($decision->refused);
			$starts[] = $this->clock->nowMicros();
		}

		$this->assertSame(self::START, $starts[0]);
		$this->assertSame(self::START + 2900000, $starts[29]);
		for ($i = 1; $i < 30; $i++) {
			$this->assertSame(100000, ($starts[$i] - $starts[$i - 1]));
		}
	}//end testThirtyCallsAtTenASecondTakeAboutThreeSeconds()

	/**
	 * Five calls remaining and a reset in ten seconds: the calls are spaced about two seconds apart, all before the reset.
	 *
	 * @return void
	 */
	public function testAnnouncedRemainingCallsAreSpreadOverTheWindow(): void {
		$pacer = $this->pacer(cache: $this->memcache());
		$reset = (int)(self::START / 1000000) + 10;
		$source = ['rateLimitRemaining' => 5, 'rateLimitReset' => $reset];

		$starts = [];
		for ($i = 0; $i < 5; $i++) {
			$decision = $this->backgroundCall(pacer: $pacer, source: $source);
			$this->assertFalse($decision->refused);
			$starts[] = $this->clock->nowMicros();
			// The source counts the call down, as its next answer announces.
			$source['rateLimitRemaining']--;
		}

		$this->assertSame(self::START, $starts[0]);
		$this->assertSame(2000000, ($starts[1] - $starts[0]));
		for ($i = 1; $i < 5; $i++) {
			$gap = ($starts[$i] - $starts[$i - 1]);
			$this->assertGreaterThanOrEqual(2000000, $gap);
			$this->assertLessThan(2900000, $gap);
		}

		$this->assertLessThan($reset * 1000000, $starts[4]);
	}//end testAnnouncedRemainingCallsAreSpreadOverTheWindow()

	/**
	 * The larger of the configured and the announced gap wins.
	 *
	 * @return void
	 */
	public function testTheLargerGapWins(): void {
		$pacer = $this->pacer(cache: $this->memcache());
		$reset = (int)(self::START / 1000000) + 10;
		// Configured: one call a second. Announced: 100 calls in 10 seconds, 0.1 s apart.
		$source = ['pace' => ['maxCalls' => 1, 'perSeconds' => 1], 'rateLimitRemaining' => 100, 'rateLimitReset' => $reset];

		$this->assertSame(0, $this->backgroundCall(pacer: $pacer, source: $source)->waitMicros);
		$this->assertSame(1000000, $this->backgroundCall(pacer: $pacer, source: $source)->waitMicros);

		// Announced: 2 calls in the 9 seconds left, 4.5 s apart, against the configured 1 s.
		// This call waits for the turn the previous one left (1 s); the next one 4.5 s.
		$source['rateLimitRemaining'] = 2;
		$this->assertSame(1000000, $this->backgroundCall(pacer: $pacer, source: $source)->waitMicros);
		$this->assertSame(4500000, $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);
	}//end testTheLargerGapWins()

	/**
	 * A burst lets that many calls go back to back before spacing starts.
	 *
	 * @return void
	 */
	public function testABurstGoesBackToBack(): void {
		$pacer = $this->pacer(cache: $this->memcache());
		$source = ['pace' => ['maxCalls' => 10, 'perSeconds' => 1, 'burst' => 3]];

		$waits = [];
		for ($i = 0; $i < 5; $i++) {
			$waits[] = $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros;
		}

		$this->assertSame([0, 0, 0, 100000, 200000], $waits);
	}//end testABurstGoesBackToBack()

	/**
	 * With nothing remaining, background work waits for the reset; a live call that would wait longer than its limit is refused.
	 *
	 * @return void
	 */
	public function testNothingRemainingWaitsForTheReset(): void {
		$pacer = $this->pacer(cache: $this->memcache());
		$source = ['rateLimitRemaining' => 0, 'rateLimitReset' => (int)(self::START / 1000000) + 5];

		$live = $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: true);
		$this->assertTrue($live->refused);
		$this->assertSame(5, $live->retryAfterSeconds);
		$this->assertSame(0, $live->waitMicros);

		$background = $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false);
		$this->assertFalse($background->refused);
		$this->assertSame(5000000, $background->waitMicros);
		$this->assertSame(5000, $background->waitMs());
	}//end testNothingRemainingWaitsForTheReset()

	/**
	 * A live call whose turn is five seconds away, with a two-second limit, is told to retry in five and reserves nothing.
	 *
	 * @return void
	 */
	public function testALiveCallTooFarAwayIsRefusedAndReservesNothing(): void {
		$cache = $this->memcache();
		$pacer = $this->pacer(cache: $cache);
		$source = ['pace' => ['maxCalls' => 1, 'perSeconds' => 5]];

		$this->assertSame(0, $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: true)->waitMicros);
		$due = $cache->get('due.kvk');

		$refused = $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: true);
		$this->assertTrue($refused->refused);
		$this->assertSame(5, $refused->retryAfterSeconds);
		$this->assertSame($due, $cache->get('due.kvk'));

		// Background work takes the very turn the refused call did not.
		$this->assertSame(5000000, $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);
	}//end testALiveCallTooFarAwayIsRefusedAndReservesNothing()

	/**
	 * The live limit is the source's own, when it sets one; background work stops at its own limit.
	 *
	 * @return void
	 */
	public function testTheWaitLimitsComeFromThePace(): void {
		$pacer = $this->pacer(cache: $this->memcache());
		$source = ['pace' => ['maxCalls' => 1, 'perSeconds' => 5, 'maxLiveWaitMs' => 6000, 'maxBackgroundWaitSeconds' => 8]];

		$pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false);
		$this->assertSame(5000000, $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: true)->waitMicros);

		// The next turn is ten seconds away: past the background limit of eight.
		$refused = $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false);
		$this->assertTrue($refused->refused);
		$this->assertSame(10, $refused->retryAfterSeconds);
	}//end testTheWaitLimitsComeFromThePace()

	/**
	 * Two workers on one shared cache spend one budget: their calls interleave at the pace, not twice it.
	 *
	 * @return void
	 */
	public function testTwoWorkersShareOneBudget(): void {
		$cache = $this->memcache();
		$first = $this->pacer(cache: $cache);
		$second = $this->pacer(cache: $cache);
		$source = ['pace' => ['maxCalls' => 10, 'perSeconds' => 1]];

		$this->assertTrue($first->isShared());
		$this->assertSame(0, $first->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);
		$this->assertSame(100000, $second->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);
		$this->assertSame(200000, $first->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);
		$this->assertSame(300000, $second->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);

		// Another source has a budget of its own.
		$this->assertSame(0, $second->acquire(sourceKey: 'brp', sourceData: $source, live: false)->waitMicros);
	}//end testTwoWorkersShareOneBudget()

	/**
	 * When another worker moves the due time between read and swap, the turn is taken after that worker's.
	 *
	 * @return void
	 */
	public function testALostSwapReadsAgain(): void {
		$cache = $this->memcache(failCas: 1);
		$pacer = $this->pacer(cache: $cache);
		$source = ['pace' => ['maxCalls' => 10, 'perSeconds' => 1]];

		$pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false);
		// Stored due: START + 0.1 s. The other worker moves it a second on before our swap.
		$decision = $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false);

		$this->assertSame(1100000, $decision->waitMicros);
		$this->assertSame(self::START + 1200000, $cache->get('due.kvk'));
	}//end testALostSwapReadsAgain()

	/**
	 * Without a distributed cache the budget is kept per process.
	 *
	 * @return void
	 */
	public function testWithoutADistributedCacheTheBudgetIsPerProcess(): void {
		$pacer = $this->pacer(cache: null);
		$source = ['pace' => ['maxCalls' => 2, 'perSeconds' => 1]];

		$this->assertFalse($pacer->isShared());
		$this->assertSame(0, $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);
		$this->assertSame(500000, $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);
	}//end testWithoutADistributedCacheTheBudgetIsPerProcess()

	/**
	 * A cache that cannot compare-and-swap is not shared.
	 *
	 * @return void
	 */
	public function testACacheWithoutSwapIsNotShared(): void {
		$this->assertFalse($this->pacer(cache: $this->createMock(ICache::class))->isShared());
	}//end testACacheWithoutSwapIsNotShared()

	/**
	 * A source without a usable pace or an announced window is not paced, and its calls never wait.
	 *
	 * @return void
	 */
	public function testAnUnpacedSourceNeverWaits(): void {
		$cache = $this->memcache();
		$pacer = $this->pacer(cache: $cache);

		foreach ([[], ['pace' => ['maxCalls' => 0, 'perSeconds' => 60]], ['pace' => 'fast'], ['rateLimitRemaining' => 3]] as $source) {
			$this->assertFalse($pacer->isPaced(sourceData: $source));
			$this->assertSame(0, $pacer->acquire(sourceKey: 'kvk', sourceData: $source, live: false)->waitMicros);
		}

		// A window whose reset has passed is not waited for either.
		$passed = ['rateLimitRemaining' => 0, 'rateLimitReset' => (int)(self::START / 1000000) - 1];
		$this->assertTrue($pacer->isPaced(sourceData: $passed));
		$this->assertSame(0, $pacer->acquire(sourceKey: 'kvk', sourceData: $passed, live: true)->waitMicros);

		$this->assertSame([], $cache->values);
		$this->assertTrue($pacer->isPaced(sourceData: ['pace' => ['maxCalls' => 100, 'perSeconds' => 60]]));
	}//end testAnUnpacedSourceNeverWaits()

	/**
	 * The wait is advanced on the clock, and a refusal is not waited for.
	 *
	 * @return void
	 */
	public function testWaitSleepsOnlyForATurn(): void {
		$pacer = $this->pacer(cache: $this->memcache());

		$pacer->wait(decision: new PacingDecision(waitMicros: 250000));
		$this->assertSame(self::START + 250000, $this->clock->nowMicros());

		$pacer->wait(decision: new PacingDecision(refused: true, retryAfterSeconds: 9));
		$this->assertSame(self::START + 250000, $this->clock->nowMicros());

		$this->assertSame(1, (new PacingDecision(waitMicros: 1))->waitMs());
	}//end testWaitSleepsOnlyForATurn()
}//end class
