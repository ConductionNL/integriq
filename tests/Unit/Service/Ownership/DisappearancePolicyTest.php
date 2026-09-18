<?php

/**
 * Integriq — disappearance policy tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Ownership
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Ownership;

use InvalidArgumentException;
use OCA\Integriq\Service\Ownership\DisappearanceApplier;
use OCA\Integriq\Service\Ownership\DisappearancePolicy;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SOR-002 and REQ-SOR-003.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-what-happens-when-a-record-disappears-is-declared-not-hardcoded-req-sor-002
 */
class DisappearancePolicyTest extends TestCase {
	/**
	 * A synchronisation that declares nothing keeps deleting, which is what
	 * the engine did before this change.
	 *
	 * @return void
	 */
	public function testAnUndeclaredPolicyIsDelete(): void {
		$this->assertSame(DisappearancePolicy::DELETE, DisappearancePolicy::fromSourceConfig([]));
		$this->assertSame(DisappearancePolicy::DELETE, DisappearancePolicy::fromSourceConfig(['disappearancePolicy' => '']));
	}//end testAnUndeclaredPolicyIsDelete()

	/**
	 * Each accepted value is read back as itself.
	 *
	 * @return void
	 */
	public function testEachAcceptedValueIsReadBack(): void {
		foreach (DisappearancePolicy::ACCEPTED as $policy) {
			$this->assertSame($policy, DisappearancePolicy::fromSourceConfig(['disappearancePolicy' => $policy]));
		}
	}//end testEachAcceptedValueIsReadBack()

	/**
	 * A misspelled policy is refused, naming the key and the three values, and
	 * is never quietly read as the default.
	 *
	 * @return void
	 */
	public function testAMisspelledPolicyIsRefusedNamingTheKeyAndTheValues(): void {
		try {
			DisappearancePolicy::fromSourceConfig(['disappearancePolicy' => 'remove']);
			$this->fail('A policy the engine does not know must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('sourceConfig.disappearancePolicy', $e->getMessage());
			$this->assertStringContainsString('delete', $e->getMessage());
			$this->assertStringContainsString('markEnded', $e->getMessage());
			$this->assertStringContainsString('keepAndFlag', $e->getMessage());
			$this->assertStringContainsString('not treated as the default', $e->getMessage());
		}
	}//end testAMisspelledPolicyIsRefusedNamingTheKeyAndTheValues()

	/**
	 * A non-string declaration is refused too, rather than cast into one of
	 * the accepted values.
	 *
	 * @return void
	 */
	public function testANonStringDeclarationIsRefused(): void {
		$this->assertFalse(DisappearancePolicy::isValid(['disappearancePolicy' => ['delete']]));
		$this->assertFalse(DisappearancePolicy::isValid(['disappearancePolicy' => 3]));
	}//end testANonStringDeclarationIsRefused()

	/**
	 * Under markEnded the record keeps its values and gains an end date.
	 *
	 * @return void
	 */
	public function testMarkEndedWritesAnEndDateAndKeepsTheValues(): void {
		$applier = new DisappearanceApplier();

		$after = $applier->applyToObject(
			DisappearancePolicy::MARK_ENDED,
			['naam' => 'De Vries', 'functie' => 'behandelaar'],
			'2026-09-18T08:00:00+00:00'
		);

		$this->assertSame('2026-09-18T08:00:00+00:00', $after['sourceEndedAt']);
		$this->assertSame('De Vries', $after['naam'], 'The record keeps its history.');
		$this->assertTrue($after['absentAtSource']);
		$this->assertSame('ended', $applier->countKey(DisappearancePolicy::MARK_ENDED));
	}//end testMarkEndedWritesAnEndDateAndKeepsTheValues()

	/**
	 * Under keepAndFlag the values are untouched and only the absence is
	 * written, with both timestamps.
	 *
	 * @return void
	 */
	public function testKeepAndFlagLeavesTheValuesAloneAndRecordsBothTimestamps(): void {
		$applier = new DisappearanceApplier();

		$before = ['naam' => 'De Vries', 'sourceLastSeen' => '2026-09-17T08:00:00+00:00'];
		$after = $applier->applyToObject(DisappearancePolicy::KEEP_AND_FLAG, $before, '2026-09-18T08:00:00+00:00');

		$this->assertSame('De Vries', $after['naam']);
		$this->assertArrayNotHasKey('sourceEndedAt', $after, 'keepAndFlag ends nothing.');
		$this->assertTrue($after['absentAtSource']);
		$this->assertSame('2026-09-18T08:00:00+00:00', $after['sourceAbsentSince']);
		$this->assertSame('2026-09-17T08:00:00+00:00', $after['sourceLastSeen'], 'The last run that saw it is still readable.');
		$this->assertSame('flagged', $applier->countKey(DisappearancePolicy::KEEP_AND_FLAG));
	}//end testKeepAndFlagLeavesTheValuesAloneAndRecordsBothTimestamps()

	/**
	 * A record that comes back stops being flagged, and the clearing is
	 * reported rather than silent.
	 *
	 * @return void
	 */
	public function testARecordThatComesBackStopsBeingFlagged(): void {
		$applier = new DisappearanceApplier();

		$outcome = $applier->markSeen(
			['absentAtSource' => true, 'sourceAbsentSince' => '2026-09-18T08:00:00+00:00', 'endedAt' => '2026-09-18T08:00:00+00:00'],
			'2026-09-19T08:00:00+00:00'
		);

		$this->assertTrue($outcome['cleared']);
		$this->assertFalse($outcome['contract']['absentAtSource']);
		$this->assertNull($outcome['contract']['endedAt']);
		$this->assertSame('2026-09-19T08:00:00+00:00', $outcome['contract']['sourceLastSeen']);
	}//end testARecordThatComesBackStopsBeingFlagged()

	/**
	 * A record that was never flagged reports no clearing, so the run's count
	 * cannot be inflated by every record that simply stayed.
	 *
	 * @return void
	 */
	public function testARecordThatWasNeverFlaggedReportsNoClearing(): void {
		$outcome = (new DisappearanceApplier())->markSeen(['absentAtSource' => false], '2026-09-19T08:00:00+00:00');

		$this->assertFalse($outcome['cleared']);
	}//end testARecordThatWasNeverFlaggedReportsNoClearing()
}//end class
