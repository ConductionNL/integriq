<?php

/**
 * Wire-contract tests for SynchronizationsController::contracts().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SynchronizationsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/synchronizations/contracts/{id}` lists one synchronization's contracts.
 *
 * A synchronization is an OpenRegister object, so the id in the route is a
 * UUID. The filter handed to OpenRegister is asserted, not just the status: an
 * empty 200 is also what a filter on the wrong value returns.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
class SynchronizationsControllerContractsTest extends TestCase {

	/**
	 * A UUID reaches the filter unchanged and the contracts come back as rows.
	 *
	 * @return void
	 */
	public function testContractsFiltersOnTheSynchronizationUuidAndReturnsRows(): void {
		$uuid = '9b2f3c1e-6a7d-4e0b-8c1f-2d3e4f5a6b7c';
		$contract = ObjectServiceMockBuilder::objectEntity(
			$this,
			['synchronizationId' => $uuid, 'originId' => 'pw-1-c', 'targetId' => 'target-1'],
			'contract-uuid-1'
		);

		$filters = null;
		$orObjectService = $this->createMock(OrObjectService::class);
		$orObjectService->expects($this->once())
			->method('findAll')
			->willReturnCallback(
				function (array $config) use (&$filters, $contract): array {
					$filters = $config['filters'];
					return [$contract];
				}
			);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$controller = new SynchronizationsController(
			'integriq',
			$this->createMock(IRequest::class),
			$orObjectService,
			$this->createMock(SynchronizationService::class),
			$l,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ActionAuthService::class)
		);

		$response = $controller->contracts($uuid);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($uuid, $filters['synchronizationId']);
		$this->assertSame('synchronization_contract', $filters['schema']);
		$this->assertSame(
			['results' => [['synchronizationId' => $uuid, 'originId' => 'pw-1-c', 'targetId' => 'target-1']]],
			$response->getData()
		);
	}//end testContractsFiltersOnTheSynchronizationUuidAndReturnsRows()
}//end class
