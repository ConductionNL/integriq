<?php

/**
 * THE KEEP-RULE FOR DUPLICATE CONTRACTS.
 *
 * Deleting the wrong contract is worse than leaving the duplicate: the engine
 * would re-create the object it maps. `synchronization-engine` REQ-013 refuses
 * to do this automatically for exactly that reason, so the rule this command
 * uses has to be pinned rather than assumed.
 *
 * Survivor = newest `sourceLastChecked`, ties broken on uuid so a dry run and an
 * apply always choose the same row.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\DedupeContracts;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the duplicate-contract keep-rule.
 */
class DedupeContractsTest extends TestCase {

	/**
	 * Build a contract payload.
	 *
	 * @param string $uuid The contract uuid.
	 * @param string $origin The origin id.
	 * @param string $checked The sourceLastChecked stamp.
	 *
	 * @return array The payload.
	 */
	private function contract(string $uuid, string $origin, string $checked): array {
		return ['uuid' => $uuid, 'originId' => $origin, 'sourceLastChecked' => $checked];
	}//end contract()

	/**
	 * The newest sourceLastChecked survives and everything older goes. These are
	 * the timestamps actually observed on probe origin 86bee4b2, which had
	 * accumulated nine contracts, one per run.
	 *
	 * @return void
	 */
	public function testKeepsTheNewestAndDeletesTheRest(): void {
		$plan = DedupeContracts::planDeletions(
			contracts: [
				$this->contract('a', 'origin-1', '2026-08-20T02:29:34+00:00'),
				$this->contract('b', 'origin-1', '2026-08-20T11:08:02+00:00'),
				$this->contract('c', 'origin-1', '2026-08-20T08:12:01+00:00'),
			]
		);

		$this->assertSame(['origin-1' => 'b'], $plan['keep']);
		$this->assertEqualsCanonicalizing(['a', 'c'], $plan['delete']);
	}//end testKeepsTheNewestAndDeletesTheRest()

	/**
	 * Each origin is deduped independently — one survivor per origin, not one
	 * survivor per synchronization.
	 *
	 * @return void
	 */
	public function testEachOriginKeepsItsOwnSurvivor(): void {
		$plan = DedupeContracts::planDeletions(
			contracts: [
				$this->contract('a1', 'origin-1', '2026-08-20T01:00:00+00:00'),
				$this->contract('a2', 'origin-1', '2026-08-20T02:00:00+00:00'),
				$this->contract('b1', 'origin-2', '2026-08-20T03:00:00+00:00'),
			]
		);

		$this->assertSame(['origin-1' => 'a2', 'origin-2' => 'b1'], $plan['keep']);
		$this->assertSame(['a1'], $plan['delete']);
	}//end testEachOriginKeepsItsOwnSurvivor()

	/**
	 * A single contract is not a duplicate. The common case must delete NOTHING.
	 *
	 * @return void
	 */
	public function testASingleContractIsNeverDeleted(): void {
		$plan = DedupeContracts::planDeletions(
			contracts: [$this->contract('only', 'origin-1', '2026-08-20T01:00:00+00:00')]
		);

		$this->assertSame(['origin-1' => 'only'], $plan['keep']);
		$this->assertSame([], $plan['delete']);
	}//end testASingleContractIsNeverDeleted()

	/**
	 * Ties break deterministically, so a dry run predicts exactly what an apply
	 * does. Without this a re-sort could keep a different row than the one the
	 * operator was shown.
	 *
	 * @return void
	 */
	public function testTiesBreakDeterministically(): void {
		$stamp = '2026-08-20T02:00:00+00:00';

		$first = DedupeContracts::planDeletions(
			contracts: [
				$this->contract('aaa', 'origin-1', $stamp),
				$this->contract('zzz', 'origin-1', $stamp),
			]
		);
		$reordered = DedupeContracts::planDeletions(
			contracts: [
				$this->contract('zzz', 'origin-1', $stamp),
				$this->contract('aaa', 'origin-1', $stamp),
			]
		);

		$this->assertSame($first['keep'], $reordered['keep']);
		$this->assertSame($first['delete'], $reordered['delete']);
	}//end testTiesBreakDeterministically()

	/**
	 * A contract with no sourceLastChecked sorts oldest, so a row that HAS been
	 * checked always beats one that has not.
	 *
	 * @return void
	 */
	public function testAnUncheckedContractLosesToACheckedOne(): void {
		$plan = DedupeContracts::planDeletions(
			contracts: [
				['uuid' => 'never', 'originId' => 'origin-1'],
				$this->contract('checked', 'origin-1', '2026-08-20T01:00:00+00:00'),
			]
		);

		$this->assertSame(['origin-1' => 'checked'], $plan['keep']);
		$this->assertSame(['never'], $plan['delete']);
	}//end testAnUncheckedContractLosesToACheckedOne()

	/**
	 * A row carrying `id` rather than `uuid` is still identifiable — that is the
	 * shape every contract read back from OpenRegister has, and the shape whose
	 * mishandling caused the duplicates in the first place.
	 *
	 * @return void
	 */
	public function testIdIsAcceptedAsTheIdentity(): void {
		$plan = DedupeContracts::planDeletions(
			contracts: [
				['id' => 'from-id-old', 'originId' => 'origin-1', 'sourceLastChecked' => '2026-08-20T01:00:00+00:00'],
				['id' => 'from-id-new', 'originId' => 'origin-1', 'sourceLastChecked' => '2026-08-20T02:00:00+00:00'],
			]
		);

		$this->assertSame(['origin-1' => 'from-id-new'], $plan['keep']);
		$this->assertSame(['from-id-old'], $plan['delete']);
	}//end testIdIsAcceptedAsTheIdentity()

	/**
	 * A row we cannot identify, or cannot group, is LEFT ALONE. Guessing is how a
	 * cleanup deletes the wrong contract, which is the risk REQ-013 names.
	 *
	 * @return void
	 */
	public function testUnidentifiableOrUngroupableRowsAreLeftAlone(): void {
		$plan = DedupeContracts::planDeletions(
			contracts: [
				['originId' => 'origin-1', 'sourceLastChecked' => '2026-08-20T09:00:00+00:00'],
				['uuid' => 'no-origin', 'sourceLastChecked' => '2026-08-20T09:00:00+00:00'],
				$this->contract('real', 'origin-1', '2026-08-20T01:00:00+00:00'),
			]
		);

		// The uuid-less and origin-less rows are neither kept nor deleted, and the
		// one real contract for origin-1 is not treated as a duplicate of them.
		$this->assertSame(['origin-1' => 'real'], $plan['keep']);
		$this->assertSame([], $plan['delete']);
	}//end testUnidentifiableOrUngroupableRowsAreLeftAlone()

	/**
	 * The heaviest real grouping observed: 321 contracts for one pair. All but one
	 * go, and the survivor is the newest.
	 *
	 * @return void
	 */
	public function testCollapsesALargeGroupingToOne(): void {
		$contracts = [];
		for ($i = 1; $i <= 321; $i++) {
			$contracts[] = $this->contract(
				sprintf('uuid-%03d', $i),
				'origin-1',
				sprintf('2026-08-20T%02d:%02d:00+00:00', intdiv($i, 60), ($i % 60))
			);
		}

		$plan = DedupeContracts::planDeletions(contracts: $contracts);

		$this->assertCount(1, $plan['keep']);
		$this->assertCount(320, $plan['delete']);
		$this->assertSame('uuid-321', $plan['keep']['origin-1']);
		$this->assertNotContains('uuid-321', $plan['delete']);
	}//end testCollapsesALargeGroupingToOne()
}//end class
