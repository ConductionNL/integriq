<?php

/**
 * CHANGE DETECTION MUST NOT DEPEND ON HOW A TIMESTAMP IS TYPED.
 *
 * The skip test compares a mapping's `getUpdated()` — a DateTime — against a
 * contract's `sourceLastChecked`, an ATOM *string*. PHP does not order an object
 * against a string, so `<` was false for every mapped synchronization and the
 * skip branch could never be taken: a synchronization WITH a mapping rewrote
 * every object on every run, forever.
 *
 * Measured on the 2000-dataset CKAN benchmark with identical source data:
 * 1854 skipped without a mapping, 0 skipped with one; 6.8 s against 17.7 s.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use DateTime;
use OCA\OpenConnector\Service\SynchronizationService;
use PHPUnit\Framework\TestCase;

/**
 * Timestamp comparison used by the skip test.
 */
class SynchronizationServiceTimestampComparisonTest extends TestCase {

	/**
	 * Call the private isBefore() helper.
	 *
	 * @param mixed $moment    The earlier candidate.
	 * @param mixed $reference The later candidate.
	 *
	 * @return bool The helper's verdict.
	 */
	private function isBefore(mixed $moment, mixed $reference): bool {
		$method = new \ReflectionMethod(SynchronizationService::class, 'isBefore');
		$method->setAccessible(true);

		$instance = (new \ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();

		return $method->invoke($instance, $moment, $reference);
	}//end isBefore()

	/**
	 * THE DEFECT. A DateTime that is genuinely earlier than an ATOM string must
	 * compare as earlier. A bare `<` returns false here, which is what made every
	 * mapped synchronization rewrite all of its objects on every run.
	 *
	 * The values are the ones actually measured on the dev instance: the mapping
	 * was updated at 02:29:03 and the contract was checked 28 s later.
	 *
	 * @return void
	 */
	public function testDateTimeIsOrderedAgainstAnAtomString(): void {
		$this->assertTrue(
			$this->isBefore(moment: new DateTime('2026-08-20T02:29:03+00:00'), reference: '2026-08-20T02:29:31+00:00'),
			'a DateTime 28s earlier than an ATOM string must read as earlier'
		);
	}//end testDateTimeIsOrderedAgainstAnAtomString()

	/**
	 * The clause must keep working in the direction it was written for: a mapping
	 * changed SINCE the last check still forces a re-map.
	 *
	 * @return void
	 */
	public function testALaterMappingIsNotBefore(): void {
		$this->assertFalse(
			$this->isBefore(moment: new DateTime('2026-08-20T02:30:00+00:00'), reference: '2026-08-20T02:29:31+00:00')
		);
	}//end testALaterMappingIsNotBefore()

	/**
	 * Equal instants are not "before" — a strict comparison, as it was.
	 *
	 * @return void
	 */
	public function testEqualInstantsAreNotBefore(): void {
		$this->assertFalse(
			$this->isBefore(moment: new DateTime('2026-08-20T02:29:31+00:00'), reference: '2026-08-20T02:29:31+00:00')
		);
	}//end testEqualInstantsAreNotBefore()

	/**
	 * Two strings compare too — the sibling clause in the same condition passes
	 * `$synchronization['updated']`, which is a string on both sides.
	 *
	 * @return void
	 */
	public function testTwoStringsCompare(): void {
		$this->assertTrue($this->isBefore(moment: '2026-08-20T02:29:03+00:00', reference: '2026-08-20T02:29:31+00:00'));
		$this->assertFalse($this->isBefore(moment: '2026-08-20T02:29:31+00:00', reference: '2026-08-20T02:29:03+00:00'));
	}//end testTwoStringsCompare()

	/**
	 * A value that cannot be established must NOT report "older". Falling through
	 * to the update path costs a write; skipping on an unprovable comparison
	 * silently drops one.
	 *
	 * @return void
	 */
	public function testUnprovableComparisonDoesNotSkip(): void {
		$this->assertFalse($this->isBefore(moment: new DateTime('2026-08-20T02:29:03+00:00'), reference: null));
		$this->assertFalse($this->isBefore(moment: null, reference: '2026-08-20T02:29:31+00:00'));
		$this->assertFalse($this->isBefore(moment: new DateTime('2026-08-20T02:29:03+00:00'), reference: 'not-a-date'));
		$this->assertFalse($this->isBefore(moment: new DateTime('2026-08-20T02:29:03+00:00'), reference: '   '));
	}//end testUnprovableComparisonDoesNotSkip()

	/**
	 * Call the private mappingUnchangedSince() helper.
	 *
	 * @param mixed $mapping     The mapping (or null).
	 * @param mixed $lastChecked The contract's sourceLastChecked.
	 *
	 * @return bool The helper's verdict.
	 */
	private function mappingUnchangedSince(mixed $mapping, mixed $lastChecked): bool {
		$method = new \ReflectionMethod(SynchronizationService::class, 'mappingUnchangedSince');
		$method->setAccessible(true);

		$instance = (new \ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();

		return $method->invoke($instance, $mapping, $lastChecked);
	}//end mappingUnchangedSince()

	/**
	 * A mapping with NO `updated` timestamp must not pin the object to the update
	 * path forever. `null < 'somestring'` was true under the old code, so this
	 * skipped before and must keep skipping — an absent timestamp is not evidence
	 * that the mapping changed.
	 *
	 * Getting this wrong is not theoretical: a first cut of the fix treated every
	 * unknown as "cannot prove it is older" and broke
	 * EndoflifeDateSyncTest::testRepeatedSyncIsIdempotent, taking `skipped` from
	 * 2 to 0 — re-introducing the very defect being fixed, from the other side.
	 *
	 * @return void
	 */
	public function testMappingWithoutATimestampDoesNotBlockTheSkip(): void {
		$mapping = new class {
			/**
			 * @return mixed Always null — this mapping carries no timestamp.
			 */
			public function getUpdated(): mixed {
				return null;
			}
		};

		$this->assertTrue(
			$this->mappingUnchangedSince(mapping: $mapping, lastChecked: '2026-08-20T02:29:31+00:00')
		);
	}//end testMappingWithoutATimestampDoesNotBlockTheSkip()

	/**
	 * No mapping at all is trivially no reason to rewrite.
	 *
	 * @return void
	 */
	public function testNoMappingIsNoReasonToRewrite(): void {
		$this->assertTrue(
			$this->mappingUnchangedSince(mapping: null, lastChecked: '2026-08-20T02:29:31+00:00')
		);
	}//end testNoMappingIsNoReasonToRewrite()

	/**
	 * THE DEFECT, through the clause the engine actually evaluates: a mapping with
	 * a real DateTime older than the last check must not force a rewrite.
	 *
	 * @return void
	 */
	public function testOlderMappingDoesNotForceARewrite(): void {
		$mapping = new class {
			/**
			 * @return mixed The measured mapping timestamp.
			 */
			public function getUpdated(): mixed {
				return new DateTime('2026-08-20T02:29:03+00:00');
			}
		};

		$this->assertTrue(
			$this->mappingUnchangedSince(mapping: $mapping, lastChecked: '2026-08-20T02:29:31+00:00')
		);
	}//end testOlderMappingDoesNotForceARewrite()

	/**
	 * A mapping edited SINCE the last check still forces a rewrite, and a contract
	 * that was never checked cannot skip.
	 *
	 * @return void
	 */
	public function testNewerMappingAndUncheckedContractStillWrite(): void {
		$newer = new class {
			/**
			 * @return mixed A timestamp later than the last check.
			 */
			public function getUpdated(): mixed {
				return new DateTime('2026-08-20T02:30:00+00:00');
			}
		};

		$this->assertFalse(
			$this->mappingUnchangedSince(mapping: $newer, lastChecked: '2026-08-20T02:29:31+00:00')
		);
		$this->assertFalse(
			$this->mappingUnchangedSince(mapping: $newer, lastChecked: null)
		);
	}//end testNewerMappingAndUncheckedContractStillWrite()
}//end class
