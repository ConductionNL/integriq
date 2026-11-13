<?php

/**
 * Unit tests for ExecutionTraceService's reads.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ExecutionTraceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A miss reads as null; anything else reaches the caller; lists keep their total.
 */
#[CoversClass(ExecutionTraceService::class)]
class ExecutionTraceServiceLookupTest extends TestCase {

	/**
	 * The OpenRegister object service.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService&MockObject $objects;

	/**
	 * The service under test.
	 *
	 * @var ExecutionTraceService
	 */
	private ExecutionTraceService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ORObjectService::class);
		$this->service = new ExecutionTraceService(
			orObjectService: $this->objects,
			containerInterface: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * findForExport reads without access checks and returns the plain data.
	 *
	 * @return void
	 */
	public function testFindForExportReturnsThePlainData(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['traceId' => 't-1', 'status' => 'success']);
		$this->objects->expects($this->once())->method('find')
			->with('t-1', 'integriq', 'execution_trace', false, false)
			->willReturn($entity);

		$this->assertSame(['traceId' => 't-1', 'status' => 'success'], $this->service->findForExport('t-1'));
	}//end testFindForExportReturnsThePlainData()

	/**
	 * findForExport answers null for a trace that no longer exists.
	 *
	 * @return void
	 */
	public function testFindForExportAnswersNullForAMiss(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->assertNull($this->service->findForExport('t-1'));
	}//end testFindForExportAnswersNullForAMiss()

	/**
	 * find keeps OpenRegister's access checks on and returns the entity.
	 *
	 * @return void
	 */
	public function testFindKeepsAccessChecksOn(): void {
		$entity = new ObjectEntity();
		$this->objects->expects($this->once())->method('find')
			->with('t-2', 'integriq', 'execution_trace', true, true)
			->willReturn($entity);

		$this->assertSame($entity, $this->service->find('t-2'));
	}//end testFindKeepsAccessChecksOn()

	/**
	 * find answers null only for a miss.
	 *
	 * @return void
	 */
	public function testFindAnswersNullForAMiss(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$this->assertNull($this->service->find('t-2'));
	}//end testFindAnswersNullForAMiss()

	/**
	 * find lets an outage reach the caller instead of reading as a miss.
	 *
	 * @return void
	 */
	public function testFindPropagatesAnOutage(): void {
		$this->objects->method('find')->willThrowException(new \RuntimeException('backend down'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('backend down');
		$this->service->find('t-2');
	}//end testFindPropagatesAnOutage()

	/**
	 * list pins register and schema ahead of the caller's filters and keeps the total.
	 *
	 * @return void
	 */
	public function testListPinsTheSchemaAndKeepsTheTotal(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'execution_trace', 'status' => 'failed'], 'limit' => 5, 'offset' => 10])
			->willReturn(['results' => ['a', 'b'], 'total' => 42]);

		$this->assertSame(['results' => ['a', 'b'], 'total' => 42], $this->service->list(['status' => 'failed'], 5, 10));
	}//end testListPinsTheSchemaAndKeepsTheTotal()

	/**
	 * list counts a bare result list when no total is given.
	 *
	 * @return void
	 */
	public function testListCountsABareResultList(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'execution_trace'], 'limit' => 20, 'offset' => 0])
			->willReturn(['a', 'b', 'c']);

		$this->assertSame(['results' => ['a', 'b', 'c'], 'total' => 3], $this->service->list());
	}//end testListCountsABareResultList()
}//end class
