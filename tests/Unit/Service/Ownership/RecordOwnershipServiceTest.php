<?php

/**
 * Integriq — record ownership read tests.
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

use OCA\Integriq\Service\Ownership\OwnershipState;
use OCA\Integriq\Service\Ownership\RecordOwnershipService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-SOR-001 and REQ-SOR-006: one read, and it never guesses.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
 */
class RecordOwnershipServiceTest extends TestCase {
	/**
	 * Build the service over canned contract and synchronisation rows.
	 *
	 * @param array<int,array<string,mixed>> $contracts The contract rows.
	 * @param array<int,array<string,mixed>> $synchronizations The synchronisation rows.
	 *
	 * @return RecordOwnershipService The service under test.
	 */
	private function service(array $contracts, array $synchronizations = []): RecordOwnershipService {
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($contracts, $synchronizations): array {
				$schema = ($config['filters']['schema'] ?? '');
				$rows = ($schema === 'synchronization_contract' ? $contracts : $synchronizations);

				return ['results' => array_map(fn (array $row): ObjectEntity => $this->entity($row), $rows)];
			}
		);

		return new RecordOwnershipService($objectService, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * An object entity double answering with one row.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return ObjectEntity The double.
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($row);

		return $entity;
	}//end entity()

	/**
	 * A person mirrored from the BRP says the BRP owns it, and carries the
	 * identifier and the last-seen timestamp.
	 *
	 * @return void
	 */
	public function testASourceOwnedRecordNamesItsSourceAndIdentifier(): void {
		$service = $this->service(
			[
				[
					'targetId' => 'obj-1',
					'synchronizationId' => 'sync-1',
					'originId' => '999993653',
					'sourceLastSeen' => '2026-09-18T08:00:00+00:00',
				],
			],
			[['uuid' => 'sync-1', 'name' => 'BRP personen', 'sourceId' => 'brp-haalcentraal', 'sourceConfig' => []]]
		);

		$answer = $service->forObject('obj-1')->toArray();

		$this->assertSame(OwnershipState::MODE_SOURCE, $answer['mode']);
		$this->assertSame('brp-haalcentraal', $answer['source']);
		$this->assertSame('999993653', $answer['originId']);
		$this->assertSame('2026-09-18T08:00:00+00:00', $answer['lastSeenAt']);
		$this->assertSame('known', $answer['lastSeen']);
		$this->assertSame('BRP personen', $answer['synchronization']['name']);
	}//end testASourceOwnedRecordNamesItsSourceAndIdentifier()

	/**
	 * A hand-made record says nobody else owns it, and carries no source and
	 * no origin identifier.
	 *
	 * @return void
	 */
	public function testAHandMadeRecordReadsLocal(): void {
		$answer = $this->service([])->forObject('obj-unknown')->toArray();

		$this->assertSame(OwnershipState::MODE_LOCAL, $answer['mode']);
		$this->assertNull($answer['source']);
		$this->assertNull($answer['originId']);
	}//end testAHandMadeRecordReadsLocal()

	/**
	 * Before the first run the last-seen answer is unknown, and no timestamp
	 * is borrowed from the synchronisation's own state.
	 *
	 * @return void
	 */
	public function testBeforeTheFirstRunTheLastSeenIsUnknownNotDerived(): void {
		$service = $this->service(
			[['targetId' => 'obj-1', 'synchronizationId' => 'sync-1', 'originId' => '999993653']],
			[
				[
					'uuid' => 'sync-1',
					'name' => 'BRP personen',
					'sourceId' => 'brp-haalcentraal',
					'sourceConfig' => [],
					'sourceLastSynced' => '2026-01-01T00:00:00+00:00',
				],
			]
		);

		$answer = $service->forObject('obj-1')->toArray();

		$this->assertSame('unknown', $answer['lastSeen']);
		$this->assertNull($answer['lastSeenAt'], 'The synchronisation\'s own lastSync is not the object\'s last-seen.');
	}//end testBeforeTheFirstRunTheLastSeenIsUnknownNotDerived()

	/**
	 * A declared mode of `source with local additions` is read back as such.
	 *
	 * @return void
	 */
	public function testADeclaredModeIsReadBack(): void {
		$service = $this->service(
			[['targetId' => 'obj-1', 'synchronizationId' => 'sync-1', 'originId' => '1']],
			[
				[
					'uuid' => 'sync-1',
					'sourceId' => 'brp-haalcentraal',
					'sourceConfig' => ['ownershipMode' => OwnershipState::MODE_SOURCE_WITH_LOCAL_ADDITIONS],
				],
			]
		);

		$this->assertSame(
			OwnershipState::MODE_SOURCE_WITH_LOCAL_ADDITIONS,
			$service->forObject('obj-1')->getMode()
		);
	}//end testADeclaredModeIsReadBack()

	/**
	 * A mode the engine does not know reads `local` rather than claiming an
	 * ownership integriq cannot substantiate.
	 *
	 * @return void
	 */
	public function testAnUnknownModeReadsLocal(): void {
		$service = $this->service(
			[['targetId' => 'obj-1', 'synchronizationId' => 'sync-1', 'originId' => '1']],
			[['uuid' => 'sync-1', 'sourceId' => 'brp', 'sourceConfig' => ['ownershipMode' => 'theirs']]]
		);

		$this->assertSame(OwnershipState::MODE_LOCAL, $service->forObject('obj-1')->getMode());
	}//end testAnUnknownModeReadsLocal()

	/**
	 * A contract whose synchronisation cannot be found reads `local`, rather
	 * than inferring ownership from the presence of a contract alone.
	 *
	 * @return void
	 */
	public function testAContractAloneDoesNotProveOwnership(): void {
		$service = $this->service(
			[['targetId' => 'obj-1', 'synchronizationId' => 'sync-gone', 'originId' => '1']],
			[]
		);

		$this->assertSame(OwnershipState::MODE_LOCAL, $service->forObject('obj-1')->getMode());
	}//end testAContractAloneDoesNotProveOwnership()

	/**
	 * A row for a different object does not answer this object's question,
	 * even when the read hands back rows the filter should have excluded.
	 *
	 * @return void
	 */
	public function testARowForAnotherObjectIsNotThisObjectsAnswer(): void {
		$service = $this->service(
			[['targetId' => 'obj-2', 'synchronizationId' => 'sync-1', 'originId' => '999993653']],
			[['uuid' => 'sync-1', 'sourceId' => 'brp', 'sourceConfig' => []]]
		);

		$this->assertSame(OwnershipState::MODE_LOCAL, $service->forObject('obj-1')->getMode());
	}//end testARowForAnotherObjectIsNotThisObjectsAnswer()

	/**
	 * The absence state reaches the consuming app in the same answer.
	 *
	 * @return void
	 */
	public function testTheAbsenceStateIsPartOfTheSameAnswer(): void {
		$service = $this->service(
			[
				[
					'targetId' => 'obj-1',
					'synchronizationId' => 'sync-1',
					'originId' => '999993653',
					'sourceLastSeen' => '2026-09-17T08:00:00+00:00',
					'absentAtSource' => true,
					'endedAt' => '2026-09-18T08:00:00+00:00',
				],
			],
			[['uuid' => 'sync-1', 'sourceId' => 'brp', 'sourceConfig' => []]]
		);

		$answer = $service->forObject('obj-1')->toArray();

		$this->assertTrue($answer['absentAtSource']);
		$this->assertSame('2026-09-18T08:00:00+00:00', $answer['endedAt']);
	}//end testTheAbsenceStateIsPartOfTheSameAnswer()
}//end class
