<?php

/**
 * The change set a gated synchronization shows before anyone accepts it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\ChangeSetBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Created, changed with before and after per field, removed, unchanged.
 *
 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
 */
class ChangeSetBuilderTest extends TestCase {

	/**
	 * One new, one changed, one unchanged object and one removed target.
	 *
	 * @return array{0: array, 1: array}
	 */
	private function aCaseTypeResync(): array {
		$entries = [
			[
				'originId' => 'zt-new',
				'targetId' => null,
				'mapped' => ['identificatie' => 'ZT-3', 'omschrijving' => 'Parkeervergunning'],
				'existing' => null,
			],
			[
				'originId' => 'zt-changed',
				'targetId' => 'target-2',
				'mapped' => ['identificatie' => 'ZT-2', 'statustypen' => ['Ontvangen', 'In behandeling', 'Afgehandeld']],
				'existing' => ['id' => 'target-2', 'identificatie' => 'ZT-2', 'statustypen' => ['Ontvangen', 'Afgehandeld']],
			],
			[
				'originId' => 'zt-same',
				'targetId' => 'target-1',
				'mapped' => ['identificatie' => 'ZT-1', 'omschrijving' => 'Kapvergunning'],
				'existing' => ['id' => 'target-1', '@self' => ['id' => 'target-1'], 'omschrijving' => 'Kapvergunning', 'identificatie' => 'ZT-1'],
			],
		];
		$removed = [['originId' => 'zt-withdrawn', 'targetId' => 'target-9']];

		return [$entries, $removed];
	}//end aCaseTypeResync()

	/**
	 * A gated run with one new, one changed and one removed object lists
	 * them with field diffs, counts the unchanged one, and carries a
	 * fingerprint.
	 *
	 * @return void
	 */
	public function testTheChangeSetListsEachObjectWithItsFieldDiffs(): void {
		[$entries, $removed] = $this->aCaseTypeResync();

		$set = (new ChangeSetBuilder())->build(entries: $entries, removed: $removed, removalsAllowed: true);

		$this->assertSame(
			[['originId' => 'zt-new', 'fields' => ['identificatie' => 'ZT-3', 'omschrijving' => 'Parkeervergunning']]],
			$set['created']
		);
		$this->assertSame(
			[
				[
					'originId' => 'zt-changed',
					'targetId' => 'target-2',
					'fields' => [
						[
							'field' => 'statustypen',
							'before' => ['Ontvangen', 'Afgehandeld'],
							'after' => ['Ontvangen', 'In behandeling', 'Afgehandeld'],
						],
					],
				],
			],
			$set['changed']
		);
		$this->assertSame([['originId' => 'zt-withdrawn', 'targetId' => 'target-9']], $set['removed']);
		$this->assertSame(['created' => 1, 'changed' => 1, 'removed' => 1, 'unchanged' => 1], $set['counts']);
		$this->assertFalse($set['truncated']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $set['fingerprint']);
	}//end testTheChangeSetListsEachObjectWithItsFieldDiffs()

	/**
	 * An incomplete fetch lists no removals, because REQ-010 forbids a
	 * deletion in that run.
	 *
	 * @return void
	 */
	public function testAnIncompleteFetchListsNoRemovals(): void {
		[$entries, $removed] = $this->aCaseTypeResync();

		$set = (new ChangeSetBuilder())->build(entries: $entries, removed: $removed, removalsAllowed: false);

		$this->assertSame([], $set['removed']);
		$this->assertSame(0, $set['counts']['removed']);
	}//end testAnIncompleteFetchListsNoRemovals()

	/**
	 * The same source gives the same fingerprint whatever order it arrives
	 * in; a source that changed gives another.
	 *
	 * @return void
	 */
	public function testTheFingerprintFollowsTheChangesNotTheirOrder(): void {
		[$entries, $removed] = $this->aCaseTypeResync();
		$builder = new ChangeSetBuilder();

		$first = $builder->build(entries: $entries, removed: $removed, removalsAllowed: true);
		$reordered = $builder->build(entries: array_reverse($entries), removed: $removed, removalsAllowed: true);
		$this->assertSame($first['fingerprint'], $reordered['fingerprint']);

		$entries[0]['mapped']['omschrijving'] = 'Parkeervergunning bewoners';
		$changed = $builder->build(entries: $entries, removed: $removed, removalsAllowed: true);
		$this->assertNotSame($first['fingerprint'], $changed['fingerprint']);
	}//end testTheFingerprintFollowsTheChangesNotTheirOrder()

	/**
	 * A large run lists the first 500 objects per kind in full, keeps the
	 * counts exact, says it cut, and still fingerprints everything.
	 *
	 * @return void
	 */
	public function testALargeRunIsCutAt500WithExactCounts(): void {
		$entries = [];
		for ($i = 0; $i < 501; $i++) {
			$entries[] = ['originId' => 'o-' . $i, 'targetId' => null, 'mapped' => ['n' => $i], 'existing' => null];
		}

		$builder = new ChangeSetBuilder();
		$set = $builder->build(entries: $entries, removed: [], removalsAllowed: true);

		$this->assertCount(ChangeSetBuilder::LIMIT, $set['created']);
		$this->assertSame(501, $set['counts']['created']);
		$this->assertTrue($set['truncated']);

		$entries[500]['mapped']['n'] = 'changed beyond the cut';
		$this->assertNotSame(
			$set['fingerprint'],
			$builder->build(entries: $entries, removed: [], removalsAllowed: true)['fingerprint']
		);
	}//end testALargeRunIsCutAt500WithExactCounts()
}//end class
