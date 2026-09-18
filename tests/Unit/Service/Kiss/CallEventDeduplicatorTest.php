<?php

/**
 * A PBX retries, and the agent's panel should not pop the same caller twice.
 *
 * The rule worth guarding here is the fallback: on an instance with no
 * distributed cache the deduplicator lets everything through rather than
 * blocking everything. Without somewhere to remember, "have I seen this" has
 * no answer, and answering "yes" would silence every call event on that
 * instance while looking like perfect deduplication.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallEventDeduplicator;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the call-event deduplicator.
 */
class CallEventDeduplicatorTest extends TestCase {

	/**
	 * A deduplicator over an in-memory cache that behaves like a real one.
	 *
	 * @return CallEventDeduplicator The deduplicator.
	 */
	private function withCache(): CallEventDeduplicator {
		$store = [];

		$cache = $this->createMock(ICache::class);
		$cache->method('hasKey')->willReturnCallback(
			static function (string $key) use (&$store): bool {
				return array_key_exists($key, $store);
			}
		);
		$cache->method('set')->willReturnCallback(
			static function (string $key, mixed $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		return new CallEventDeduplicator(cacheFactory: $factory);

	}//end withCache()

	/**
	 * A deduplicator on an instance with no distributed cache.
	 *
	 * @return CallEventDeduplicator The deduplicator.
	 */
	private function withoutCache(): CallEventDeduplicator {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);

		return new CallEventDeduplicator(cacheFactory: $factory);

	}//end withoutCache()

	/**
	 * The first sight of an event is claimed.
	 *
	 * @return void
	 */
	public function testTheFirstSightOfAnEventIsClaimed(): void {
		$this->assertTrue(
			$this->withCache()->claim(sourceId: 'pbx-1', callId: '42', kind: 'ringing')
		);

	}//end testTheFirstSightOfAnEventIsClaimed()

	/**
	 * The same event arriving again is not.
	 *
	 * @return void
	 */
	public function testARepeatedEventIsNotClaimedTwice(): void {
		$deduplicator = $this->withCache();

		$this->assertTrue($deduplicator->claim(sourceId: 'pbx-1', callId: '42', kind: 'ringing'));
		$this->assertFalse($deduplicator->claim(sourceId: 'pbx-1', callId: '42', kind: 'ringing'));
		$this->assertFalse($deduplicator->claim(sourceId: 'pbx-1', callId: '42', kind: 'ringing'));

	}//end testARepeatedEventIsNotClaimedTwice()

	/**
	 * The next kind of the same call is a different event.
	 *
	 * @return void
	 */
	public function testTheNextKindOfTheSameCallIsADifferentEvent(): void {
		// A call rings, is answered and ends. Keying on the call alone would
		// swallow the last two, and the panel would never clear the caller.
		$deduplicator = $this->withCache();

		$this->assertTrue($deduplicator->claim(sourceId: 'pbx-1', callId: '42', kind: 'ringing'));
		$this->assertTrue($deduplicator->claim(sourceId: 'pbx-1', callId: '42', kind: 'answered'));
		$this->assertTrue($deduplicator->claim(sourceId: 'pbx-1', callId: '42', kind: 'ended'));

	}//end testTheNextKindOfTheSameCallIsADifferentEvent()

	/**
	 * Two phone systems numbering their calls from one do not silence each other.
	 *
	 * @return void
	 */
	public function testTwoSourcesDoNotSilenceEachOther(): void {
		// The symptom of getting this wrong is "the second PBX we installed
		// does not work", which nobody traces back to a cache key.
		$deduplicator = $this->withCache();

		$this->assertTrue($deduplicator->claim(sourceId: 'pbx-1', callId: '1', kind: 'ringing'));
		$this->assertTrue($deduplicator->claim(sourceId: 'pbx-2', callId: '1', kind: 'ringing'));

	}//end testTwoSourcesDoNotSilenceEachOther()

	/**
	 * With no cache, everything is let through rather than everything blocked.
	 *
	 * @return void
	 */
	public function testWithNoCacheEverythingIsLetThrough(): void {
		// Failing the other way would silence every call event on the
		// instance while looking like perfect deduplication.
		$deduplicator = $this->withoutCache();

		$this->assertFalse($deduplicator->isActive());
		$this->assertTrue($deduplicator->claim(sourceId: 'pbx-1', callId: '42', kind: 'ringing'));
		$this->assertTrue($deduplicator->claim(sourceId: 'pbx-1', callId: '42', kind: 'ringing'));

	}//end testWithNoCacheEverythingIsLetThrough()

	/**
	 * The key is built from all three parts.
	 *
	 * @return void
	 */
	public function testTheKeyIsBuiltFromAllThreeParts(): void {
		$deduplicator = $this->withCache();

		$base = $deduplicator->keyFor(sourceId: 'pbx-1', callId: '42', kind: 'ringing');

		$this->assertNotSame($base, $deduplicator->keyFor(sourceId: 'pbx-2', callId: '42', kind: 'ringing'));
		$this->assertNotSame($base, $deduplicator->keyFor(sourceId: 'pbx-1', callId: '43', kind: 'ringing'));
		$this->assertNotSame($base, $deduplicator->keyFor(sourceId: 'pbx-1', callId: '42', kind: 'answered'));

	}//end testTheKeyIsBuiltFromAllThreeParts()

	/**
	 * The separator cannot be smuggled across the parts.
	 *
	 * @return void
	 */
	public function testThePartsCannotBeSmuggledAcrossTheSeparator(): void {
		// Without a separator, source "a" + call "bc" and source "ab" + call
		// "c" would share a key, and one PBX could silence another's call by
		// naming it carefully.
		$deduplicator = $this->withCache();

		$this->assertNotSame(
			$deduplicator->keyFor(sourceId: 'a', callId: 'bc', kind: 'ringing'),
			$deduplicator->keyFor(sourceId: 'ab', callId: 'c', kind: 'ringing')
		);

	}//end testThePartsCannotBeSmuggledAcrossTheSeparator()

	/**
	 * The retention window outlives a PBX's retries.
	 *
	 * @return void
	 */
	public function testTheWindowOutlivesARetryButNotTheWeek(): void {
		// Long enough that every retry lands on the same answer, short enough
		// that a call id a phone system reuses after a nightly restart does
		// not collide with one from today.
		$this->assertGreaterThanOrEqual(3600, CallEventDeduplicator::TTL_SECONDS);
		$this->assertLessThan(86400, CallEventDeduplicator::TTL_SECONDS);

	}//end testTheWindowOutlivesARetryButNotTheWeek()

}//end class
