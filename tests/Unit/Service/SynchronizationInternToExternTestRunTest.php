<?php

/**
 * A test run of a register/schema (intern-to-extern) synchronization writes nothing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * synchronize() with isTest on an intern-to-extern synchronization must create
 * no contract and make no call to the target; a real run does both.
 */
class SynchronizationInternToExternTestRunTest extends TestCase {

	/**
	 * Contract service double.
	 *
	 * @var SynchronizationContractService&MockObject
	 */
	private SynchronizationContractService $contracts;

	/**
	 * Call service double: every target write goes through it.
	 *
	 * @var CallService&MockObject
	 */
	private CallService $calls;

	/**
	 * OpenRegister double: an OpenRegister target write goes through it.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService $openRegister;

	/**
	 * Integriq object service double: the write stage reaches OpenRegister through it.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * The engine over doubles, with an API target and no existing contract.
	 *
	 * @return SynchronizationService
	 */
	private function service(): SynchronizationService {
		$this->contracts = $this->createMock(SynchronizationContractService::class);
		$this->contracts->method('findAllObjects')->willReturn([]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === SynchronizationContractService::class ? $this->contracts : null)
		);

		$this->calls = $this->createMock(CallService::class);
		$this->calls->method('applyConfigDot')->willReturnArgument(0);

		$this->openRegister = $this->createMock(ORObjectService::class);
		$source = new ObjectEntity();
		$source->setUuid('target-source');
		$source->setObject(['uuid' => 'target-source', 'location' => 'https://target.invalid']);
		$this->openRegister->method('find')->willReturn($source);

		$logs = $this->createMock(SynchronizationLogService::class);
		$logs->method('createFromArray')->willReturnCallback(
			fn (array $object) => new SynchronizationRunLog()
		);
		$logs->method('persist')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$this->objects = $this->createMock(ObjectService::class);

		return new SynchronizationService(
			$this->calls,
			$this->createMock(MappingService::class),
			$container,
			$this->openRegister,
			$this->objects,
			$this->createMock(LoggerInterface::class),
			$logs,
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class),
		);
	}//end service()

	/**
	 * A register/schema synchronization to an API target.
	 *
	 * @return array<string, mixed>
	 */
	private function synchronization(): array {
		return [
			'id' => 'sync-1',
			'sourceType' => 'register/schema',
			'sourceId' => '1/2',
			'targetType' => 'api',
			'targetId' => 'target-source',
			'targetConfig' => ['endpoint' => '/zaken'],
		];
	}//end synchronization()

	/**
	 * A test run creates no contract and calls no target.
	 *
	 * @return void
	 */
	public function testATestRunCreatesNoContractAndWritesNothing(): void {
		$service = $this->service();
		$this->contracts->expects($this->never())->method('createFromArray');
		$this->calls->expects($this->never())->method('call');
		$this->openRegister->expects($this->never())->method('saveObject');
		$this->objects->expects($this->never())->method('getOpenRegisters');

		$object = ['@self' => ['id' => 'o-1'], 'naam' => 'test'];
		$result = $service->synchronize(synchronization: $this->synchronization(), isTest: true, object: $object);

		$this->assertSame('skip', ($result['resultAction'] ?? null));
	}//end testATestRunCreatesNoContractAndWritesNothing()

	/**
	 * The control: a real run of the same synchronization creates the contract
	 * and reaches the target write stage (which needs OpenRegister to go on).
	 *
	 * @return void
	 */
	public function testARealRunCreatesTheContractAndReachesTheTargetWrite(): void {
		$service = $this->service();
		$this->contracts->expects($this->once())->method('createFromArray')->willReturnCallback(
			fn (array $object) => array_merge($object, ['uuid' => 'contract-1'])
		);
		$this->objects->expects($this->atLeastOnce())->method('getOpenRegisters')->willThrowException(new \RuntimeException('target write reached'));

		$object = ['@self' => ['id' => 'o-1'], 'naam' => 'real'];
		$this->expectExceptionMessage('target write reached');
		$service->synchronize(synchronization: $this->synchronization(), isTest: false, object: $object);
	}//end testARealRunCreatesTheContractAndReachesTheTargetWrite()
}//end class
