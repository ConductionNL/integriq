<?php

/**
 * After a pull, the object its written objects point at is moved once, and only from the declared state.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-pull-can-move-the-object-its-written-objects-point-at-req-hrx-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\RelatedWriteBack;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers lib/Service/Synchronization/RelatedWriteBack.php over a fake OpenRegister.
 */
final class RelatedWriteBackTest extends TestCase {

	/**
	 * The objects in the fake register, by "register/schema/id".
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Every save: register, schema, uuid, object, silent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Whether every save fails.
	 *
	 * @var boolean
	 */
	private bool $saveFails = false;

	/**
	 * Payslips of two handoffs and one of none, and the two handoffs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			'humaniq/Payslip/slip-1' => ['employeeId' => 'e1', 'payrollHandoffId' => 'handoff-1'],
			'humaniq/Payslip/slip-2' => ['employeeId' => 'e2', 'payrollHandoffId' => 'handoff-1'],
			'humaniq/Payslip/slip-3' => ['employeeId' => 'e3', 'payrollHandoffId' => 'handoff-2'],
			'humaniq/Payslip/slip-4' => ['employeeId' => 'e4'],
			'humaniq/PayrollHandoff/handoff-1' => ['period' => '2026-09', 'status' => 'verzonden'],
			'humaniq/PayrollHandoff/handoff-2' => ['period' => '2026-09', 'status' => 'afgesloten'],
		];
	}//end setUp()

	/**
	 * The pull synchronization, with the declared write-back.
	 *
	 * @param array<string, mixed> $override Replaces the relatedWriteBack keys given.
	 *
	 * @return array<string, mixed>
	 */
	private function pull(array $override = []): array {
		return [
			'id' => 'pull-1',
			'targetType' => 'register/schema',
			'targetId' => 'humaniq/Payslip',
			'targetConfig' => [
				'relatedWriteBack' => array_merge(
					[
						'schema' => 'humaniq/PayrollHandoff',
						'field' => 'payrollHandoffId',
						'when' => ['status' => 'verzonden'],
						'set' => ['status' => 'ontvangen', 'receivedAt' => '{{ now }}', 'payslipsReceived' => '{{ count }}'],
					],
					$override
				),
			],
		];
	}//end pull()

	/**
	 * The write-back over the fake register.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return RelatedWriteBack
	 */
	private function writeBack(?LoggerInterface $logger = null): RelatedWriteBack {
		$objects = $this->createMock(originalClassName: OrObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, ?ObjectFindOptions $options = null): ?ObjectEntity {
				$key = $options->register . '/' . $options->schema . '/' . $id;
				if (isset($this->store[$key]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->store[$key]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ObjectSaveOptions $options): ObjectEntity {
				if ($this->saveFails === true) {
					throw new \RuntimeException('database gone');
				}

				$this->saves[] = [
					'register' => $options->target->register,
					'schema' => $options->target->schema,
					'uuid' => $options->uuid,
					'object' => $object,
					'silent' => ($options->policy?->silent ?? false),
				];
				return new ObjectEntity();
			}
		);

		return new RelatedWriteBack(objectService: $objects, logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)));
	}//end writeBack()

	/**
	 * Two payslips of one handoff move it to ontvangen once, with the time and the count, through a normal save.
	 *
	 * @return void
	 */
	public function testTheHandoffIsMovedOnceAfterTheRun(): void {
		$moved = $this->writeBack()->apply(synchronization: $this->pull(), targetIds: ['slip-1', 'slip-2', 'slip-1']);

		$this->assertSame(expected: 1, actual: $moved);
		$this->assertCount(expectedCount: 1, haystack: $this->saves);
		$save = $this->saves[0];
		$this->assertSame(expected: ['humaniq', 'PayrollHandoff', 'handoff-1'], actual: [$save['register'], $save['schema'], $save['uuid']]);
		$this->assertSame(expected: 'ontvangen', actual: $save['object']['status']);
		$this->assertSame(expected: '2026-09', actual: $save['object']['period'], message: 'The handoff keeps its own fields.');
		$this->assertSame(expected: 2, actual: $save['object']['payslipsReceived']);
		$this->assertMatchesRegularExpression(
			pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			string: $save['object']['receivedAt']
		);
		$this->assertFalse(condition: $save['silent'], message: 'A normal save, so the app listening for the transition runs its intake check.');
	}//end testTheHandoffIsMovedOnceAfterTheRun()

	/**
	 * A handoff not in the `when` state is left alone, and a payslip naming none is skipped.
	 *
	 * @return void
	 */
	public function testOnlyAnObjectInTheDeclaredStateIsMoved(): void {
		$moved = $this->writeBack()->apply(synchronization: $this->pull(), targetIds: ['slip-3', 'slip-4']);

		$this->assertSame(expected: 0, actual: $moved);
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testOnlyAnObjectInTheDeclaredStateIsMoved()

	/**
	 * A related object that does not exist moves nothing.
	 *
	 * @return void
	 */
	public function testAMissingRelatedObjectMovesNothing(): void {
		$this->store['humaniq/Payslip/slip-1']['payrollHandoffId'] = 'gone';

		$this->assertSame(expected: 0, actual: $this->writeBack()->apply(synchronization: $this->pull(), targetIds: ['slip-1']));
	}//end testAMissingRelatedObjectMovesNothing()

	/**
	 * A failed save is logged and does not fail the run.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedNotThrown(): void {
		$this->saveFails = true;
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'database gone'));

		$this->assertSame(expected: 0, actual: $this->writeBack(logger: $logger)->apply(synchronization: $this->pull(), targetIds: ['slip-1']));
	}//end testAFailedSaveIsLoggedNotThrown()

	/**
	 * A declaration without a set, or with a schema that is not register/schema, is ignored with a warning.
	 *
	 * @return void
	 */
	public function testAMalformedDeclarationIsIgnored(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->exactly(count: 2))->method('warning')->with($this->stringContains(string: 'relatedWriteBack needs'));
		$writeBack = $this->writeBack(logger: $logger);

		$this->assertSame(expected: 0, actual: $writeBack->apply(synchronization: $this->pull(override: ['set' => []]), targetIds: ['slip-1']));
		$this->assertSame(
			expected: 0,
			actual: $writeBack->apply(synchronization: $this->pull(override: ['schema' => 'PayrollHandoff']), targetIds: ['slip-1'])
		);
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testAMalformedDeclarationIsIgnored()

	/**
	 * Nothing is read without a declaration, without written objects, or for a target that is not a register.
	 *
	 * @return void
	 */
	public function testNothingHappensWithoutADeclarationOrWrites(): void {
		$writeBack = $this->writeBack();
		$api = $this->pull();
		$api['targetType'] = 'api';

		$this->assertSame(
			expected: 0,
			actual: $writeBack->apply(synchronization: ['targetType' => 'register/schema', 'targetId' => 'humaniq/Payslip'], targetIds: ['slip-1'])
		);
		$this->assertSame(expected: 0, actual: $writeBack->apply(synchronization: $this->pull(), targetIds: []));
		$this->assertSame(expected: 0, actual: $writeBack->apply(synchronization: $api, targetIds: ['slip-1']));
		$this->assertSame(expected: [], actual: $this->saves);
	}//end testNothingHappensWithoutADeclarationOrWrites()
}//end class
