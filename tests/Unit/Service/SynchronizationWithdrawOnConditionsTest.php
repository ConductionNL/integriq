<?php

/**
 * Unit tests for `targetConfig.deleteWhenConditionsFail` on a push
 * (synchronizeInternToExtern): an object pushed earlier whose conditions stop
 * holding is deleted at the target; without the flag, or without an earlier
 * push, nothing is sent.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-approved-leave-becomes-an-untis-teacher-absence-req-hrs-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Withdrawing a pushed object when its conditions stop holding.
 */
#[CoversClass(SynchronizationService::class)]
#[UsesClass(FlowToken::class)]
#[UsesClass(SynchronizationRunLog::class)]
class SynchronizationWithdrawOnConditionsTest extends TestCase {

	/**
	 * The contracts the store holds.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $contracts = [];

	/**
	 * The synchronizeContract calls: [contract, object, isTest, force, mutationType].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The contract filters asked for.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $lookups = [];

	/**
	 * The service, with synchronizeContract recorded.
	 *
	 * @return SynchronizationService The service.
	 */
	private function service(): SynchronizationService {
		$contracts = $this->createMock(SynchronizationContractService::class);
		$contracts->method('findAllObjects')->willReturnCallback(
			function (array $filters = []): array {
				$this->lookups[] = $filters;

				return array_map(
					static function (array $data): ObjectEntity {
						$entity = new ObjectEntity();
						$entity->setUuid($data['uuid']);
						$entity->setObject($data);

						return $entity;
					},
					$this->contracts
				);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => ($id === SynchronizationContractService::class ? $contracts : null));

		$calls = $this->createMock(CallService::class);
		$calls->method('applyConfigDot')->willReturnArgument(0);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$calls,
					$this->createMock(MappingService::class),
					$container,
					$this->createMock(ORObjectService::class),
					$this->createMock(ObjectService::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SynchronizationLogService::class),
					$appConfig,
					$this->createMock(SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['synchronizeContract'])
			->getMock();
		$service->method('synchronizeContract')->willReturnCallback(
			function (array $contract, FlowToken &$flowToken, ?array $synchronization = null, array &$object = [], ?bool $isTest = false, ?bool $force = false, ?SynchronizationRunLog $log = null, ?string $mutationType = null, $trace = null): array {
				$this->calls[] = [$contract, $object, $isTest, $force, $mutationType];

				return ['contract' => array_merge($contract, ['targetId' => null])];
			}
		);

		return $service;
	}//end service()

	/**
	 * Push one object through synchronizeInternToExtern.
	 *
	 * @param array<string,mixed> $object       The object.
	 * @param array<string,mixed> $targetConfig The synchronization's target configuration.
	 *
	 * @return array<string,mixed>|null The result.
	 */
	private function push(array $object, array $targetConfig): ?array {
		$synchronization = [
			'id' => 'sync-leave',
			'sourceConfig' => [],
			'targetConfig' => $targetConfig,
			'conditions' => ['==' => [['var' => 'status'], 'approved']],
		];
		$flowToken = new FlowToken();
		$args = [$synchronization, &$object, new SynchronizationRunLog(), &$flowToken, false, false, 'update', null];

		return (new ReflectionMethod(SynchronizationService::class, 'synchronizeInternToExtern'))->invokeArgs($this->service(), $args);
	}//end push()

	/**
	 * An approved request that is rejected later is deleted at the target through its contract.
	 *
	 * @return void
	 */
	public function testAPushedObjectWhoseConditionsFailIsDeletedAtTheTarget(): void {
		$this->contracts = [['uuid' => 'c-1', 'synchronizationId' => 'sync-leave', 'originId' => 'leave-1', 'targetId' => 'absence-77']];

		$result = $this->push(object: ['id' => 'leave-1', 'status' => 'rejected'], targetConfig: ['deleteWhenConditionsFail' => true]);

		$this->assertSame([['synchronizationId' => 'sync-leave', 'originId' => 'leave-1']], $this->lookups);
		$this->assertCount(1, $this->calls);
		[$contract, $object, $isTest, $force, $mutationType] = $this->calls[0];
		$this->assertSame('absence-77', $contract['targetId']);
		$this->assertSame(['id' => 'leave-1', 'status' => 'rejected'], $object);
		$this->assertFalse($isTest);
		$this->assertTrue($force);
		$this->assertSame('delete', $mutationType);
		$this->assertNull($result['targetId']);
	}//end testAPushedObjectWhoseConditionsFailIsDeletedAtTheTarget()

	/**
	 * Without the flag, or with a non-boolean flag, a failing object is skipped and no contract is looked up.
	 *
	 * @return void
	 */
	public function testWithoutTheFlagNothingIsSent(): void {
		$this->contracts = [['uuid' => 'c-1', 'synchronizationId' => 'sync-leave', 'originId' => 'leave-1', 'targetId' => 'absence-77']];

		$this->assertNull($this->push(object: ['id' => 'leave-1', 'status' => 'rejected'], targetConfig: []));
		$this->assertNull($this->push(object: ['id' => 'leave-1', 'status' => 'rejected'], targetConfig: ['deleteWhenConditionsFail' => 'true']));

		$this->assertSame([], $this->calls);
		$this->assertSame([], $this->lookups);
	}//end testWithoutTheFlagNothingIsSent()

	/**
	 * An object never pushed (no contract, or a contract without a target id) is skipped: a submitted request sends nothing.
	 *
	 * @return void
	 */
	public function testAnObjectNeverPushedIsSkipped(): void {
		$this->assertNull($this->push(object: ['id' => 'leave-2', 'status' => 'submitted'], targetConfig: ['deleteWhenConditionsFail' => true]));

		$this->contracts = [['uuid' => 'c-2', 'synchronizationId' => 'sync-leave', 'originId' => 'leave-2', 'targetId' => null]];
		$this->assertNull($this->push(object: ['id' => 'leave-2', 'status' => 'submitted'], targetConfig: ['deleteWhenConditionsFail' => true]));

		$this->assertSame([], $this->calls);
	}//end testAnObjectNeverPushedIsSkipped()

	/**
	 * When the conditions hold the push runs as before, not as a delete.
	 *
	 * @return void
	 */
	public function testAnObjectWhoseConditionsHoldIsPushedNormally(): void {
		$this->push(object: ['id' => 'leave-3', 'status' => 'approved'], targetConfig: ['deleteWhenConditionsFail' => true]);

		$this->assertCount(1, $this->calls);
		$this->assertSame('update', $this->calls[0][4]);
		$this->assertFalse($this->calls[0][3]);
	}//end testAnObjectWhoseConditionsHoldIsPushedNormally()
}//end class
