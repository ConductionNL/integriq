<?php

/**
 * Integriq — local delete guard tests.
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
use OCA\Integriq\Service\Ownership\LocalDeleteGuard;
use OCA\Integriq\Service\Ownership\OwnershipState;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SOR-005: a refusal that names the synchronisation, and an override that
 * is a written statement.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
 */
class LocalDeleteGuardTest extends TestCase {
	/**
	 * A source-owned record.
	 *
	 * @param string $mode The ownership mode.
	 *
	 * @return OwnershipState The state.
	 */
	private function owned(string $mode = OwnershipState::MODE_SOURCE): OwnershipState {
		return new OwnershipState($mode, 'brp-haalcentraal', '999993653', null, true, false, null, 'sync-1', 'BRP personen');
	}//end owned()

	/**
	 * A handler cannot quietly remove a BRP person, and the refusal names the
	 * synchronisation.
	 *
	 * @return void
	 */
	public function testADeleteOfASourceOwnedRecordIsRefusedNamingTheSynchronisation(): void {
		try {
			(new LocalDeleteGuard())->guard($this->owned());
			$this->fail('A source-owned record must not be deleted without an override.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('BRP personen', $e->getMessage());
		}
	}//end testADeleteOfASourceOwnedRecordIsRefusedNamingTheSynchronisation()

	/**
	 * `source with local additions` is refused just the same.
	 *
	 * @return void
	 */
	public function testSourceWithLocalAdditionsIsRefusedToo(): void {
		$this->expectException(InvalidArgumentException::class);

		(new LocalDeleteGuard())->guard($this->owned(OwnershipState::MODE_SOURCE_WITH_LOCAL_ADDITIONS));
	}//end testSourceWithLocalAdditionsIsRefusedToo()

	/**
	 * An override is a written statement: reason, user and timestamp.
	 *
	 * @return void
	 */
	public function testAnOverrideIsAWrittenStatement(): void {
		$override = (new LocalDeleteGuard())->guard($this->owned(), 'duplicate row, merged into 999993654', 'behandelaar1');

		$this->assertNotNull($override);
		$this->assertSame('duplicate row, merged into 999993654', $override['reason']);
		$this->assertSame('behandelaar1', $override['user']);
		$this->assertNotEmpty($override['at']);
		$this->assertSame('sync-1', $override['synchronization']);
	}//end testAnOverrideIsAWrittenStatement()

	/**
	 * An override with an empty reason is refused, and says a reason is
	 * required.
	 *
	 * @return void
	 */
	public function testAnOverrideWithAnEmptyReasonIsRefused(): void {
		try {
			(new LocalDeleteGuard())->guard($this->owned(), '   ', 'behandelaar1');
			$this->fail('An override without a reason must not delete anything.');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('requires a reason', $e->getMessage());
			$this->assertStringContainsString('Nothing was deleted', $e->getMessage());
		}
	}//end testAnOverrideWithAnEmptyReasonIsRefused()

	/**
	 * A local record needs no override at all.
	 *
	 * @return void
	 */
	public function testALocalRecordNeedsNoOverride(): void {
		$this->assertNull((new LocalDeleteGuard())->guard(OwnershipState::local()));
	}//end testALocalRecordNeedsNoOverride()
}//end class
