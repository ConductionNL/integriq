<?php

/**
 * A synchronization never uses a source of another organisation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The engine reads sources in system context, so the synchronization's own
 * organisation decides which source it may call.
 */
class SynchronizationSourceOrganisationTest extends TestCase {

	/**
	 * Organisation A.
	 *
	 * @var string
	 */
	private const ORG_A = '0a0a0a0a-0000-4000-8000-00000000000a';

	/**
	 * Organisation B.
	 *
	 * @var string
	 */
	private const ORG_B = '0b0b0b0b-0000-4000-8000-00000000000b';

	/**
	 * The call service double.
	 *
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * The service under test.
	 *
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * Build the service over a store whose only source belongs to the given organisation.
	 *
	 * @param string $sourceOrganisation The source's organisation.
	 *
	 * @return void
	 */
	private function build(string $sourceOrganisation): void {
		$source = new ObjectEntity();
		$source->setUuid('source-1');
		$source->setObject(['name' => 'BRP', 'location' => 'https://brp.example.invalid', 'isEnabled' => true]);
		$source->setOrganisation($sourceOrganisation);

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('find')->willReturn($source);

		$this->callService = $this->createMock(originalClassName: CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);

		$this->service = new SynchronizationService(
			callService: $this->callService,
			mappingService: $this->createMock(originalClassName: MappingService::class),
			containerInterface: $this->createMock(originalClassName: ContainerInterface::class),
			orObjectService: $orObjectService,
			objectService: $this->createMock(originalClassName: ObjectService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			synchronizationLogService: $this->createMock(originalClassName: SynchronizationLogService::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			approvalGate: $this->createMock(originalClassName: SynchronizationApprovalGate::class)
		);
	}//end build()

	/**
	 * A synchronization of organisation A.
	 *
	 * @return array
	 */
	private function synchronization(): array {
		return [
			'id' => 'sync-1',
			'sourceId' => 'source-1',
			'targetId' => 'source-1',
			'@self' => ['organisation' => self::ORG_A],
		];
	}//end synchronization()

	/**
	 * Organisation A's synchronization does not call organisation B's source.
	 *
	 * @return void
	 */
	public function testASourceOfAnotherOrganisationIsNotCalled(): void {
		$this->build(sourceOrganisation: self::ORG_B);
		$this->callService->expects($this->never())->method('call');

		$this->expectException(exception: DoesNotExistException::class);
		$this->service->getObjectFromSource(synchronization: $this->synchronization(), endpoint: '/personen/1');
	}//end testASourceOfAnotherOrganisationIsNotCalled()

	/**
	 * Control: the synchronization calls a source of its own organisation.
	 *
	 * @return void
	 */
	public function testASourceOfTheSameOrganisationIsCalled(): void {
		$this->build(sourceOrganisation: self::ORG_A);
		$this->callService->expects($this->once())->method('call')->willThrowException(new RuntimeException('called'));

		$this->expectExceptionMessage(message: 'called');
		$this->service->getObjectFromSource(synchronization: $this->synchronization(), endpoint: '/personen/1');
	}//end testASourceOfTheSameOrganisationIsCalled()

	/**
	 * The raw target read for a document push refuses a target of another organisation.
	 *
	 * @return void
	 */
	public function testARawTargetOfAnotherOrganisationIsRefused(): void {
		$this->build(sourceOrganisation: self::ORG_B);

		$this->expectException(exception: DoesNotExistException::class);
		(new ReflectionMethod(SynchronizationService::class, 'rawSourceObject'))
			->invoke($this->service, $this->synchronization(), 'source-1');
	}//end testARawTargetOfAnotherOrganisationIsRefused()

	/**
	 * Control: a raw target of the same organisation is returned.
	 *
	 * @return void
	 */
	public function testARawTargetOfTheSameOrganisationIsReturned(): void {
		$this->build(sourceOrganisation: self::ORG_A);

		$raw = (new ReflectionMethod(SynchronizationService::class, 'rawSourceObject'))
			->invoke($this->service, $this->synchronization(), 'source-1');

		$this->assertSame(expected: 'BRP', actual: $raw['name']);
	}//end testARawTargetOfTheSameOrganisationIsReturned()

}//end class
