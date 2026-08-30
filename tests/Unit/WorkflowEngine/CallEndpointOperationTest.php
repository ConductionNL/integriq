<?php

/**
 * Unit tests for CallEndpointOperation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operations-onevent-must-dispatch-to-endpointservicetriggerfromflow-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\WorkflowEngine;

use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\WorkflowEngine\CallEndpointOperation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operations-onevent-must-dispatch-to-endpointservicetriggerfromflow-req-003
 */
class CallEndpointOperationTest extends TestCase {

	/**
	 * Build an operation instance with the given (possibly mocked) endpoint service.
	 *
	 * @param EndpointService $endpointService The endpoint service.
	 * @param LoggerInterface|null $logger Optional logger mock.
	 *
	 * @return CallEndpointOperation
	 */
	private function makeOperation(EndpointService $endpointService, ?LoggerInterface $logger = null): CallEndpointOperation {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		return new CallEndpointOperation(
			$endpointService,
			$l10n,
			$this->createMock(IURLGenerator::class),
			($logger ?? $this->createMock(LoggerInterface::class))
		);

	}//end makeOperation()

	/**
	 * getEntityId() returns NC core's only bundled IEntity.
	 *
	 * @return void
	 */
	public function testGetEntityIdReturnsFileEntity(): void {
		$operation = $this->makeOperation($this->createMock(EndpointService::class));
		$this->assertSame('OCA\WorkflowEngine\Entity\File', $operation->getEntityId());

	}//end testGetEntityIdReturnsFileEntity()

	/**
	 * isAvailableForScope() returns true only for SCOPE_ADMIN (REQ-005).
	 *
	 * @return void
	 */
	public function testIsAvailableOnlyForAdminScope(): void {
		$operation = $this->makeOperation($this->createMock(EndpointService::class));
		$this->assertTrue($operation->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertFalse($operation->isAvailableForScope(IManager::SCOPE_USER));

	}//end testIsAvailableOnlyForAdminScope()

	/**
	 * A matching flow resolves the endpoint and calls triggerFromFlow() with the
	 * configured static parameters.
	 *
	 * @return void
	 */
	public function testOnEventCallsTheConfiguredEndpoint(): void {
		$endpoint = $this->createMock(ObjectEntity::class);

		$endpointService = $this->createMock(EndpointService::class);
		$endpointService->expects($this->once())
			->method('getEndpointById')
			->with('ep-1')
			->willReturn($endpoint);
		$endpointService->expects($this->once())
			->method('triggerFromFlow')
			->with(endpoint: $endpoint, parameters: ['foo' => 'bar']);

		$ruleMatcher = $this->createMock(IRuleMatcher::class);
		$ruleMatcher->method('getFlows')->willReturn(
			[['operation' => json_encode(['endpointId' => 'ep-1', 'parameters' => ['foo' => 'bar']])]]
		);

		$operation = $this->makeOperation($endpointService);
		$operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);

	}//end testOnEventCallsTheConfiguredEndpoint()

	/**
	 * A missing endpoint is logged and skipped, not thrown.
	 *
	 * @return void
	 */
	public function testMissingEndpointIsLoggedAndSkipped(): void {
		$endpointService = $this->createMock(EndpointService::class);
		$endpointService->method('getEndpointById')->with('missing-1')->willReturn(null);
		$endpointService->expects($this->never())->method('triggerFromFlow');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$ruleMatcher = $this->createMock(IRuleMatcher::class);
		$ruleMatcher->method('getFlows')->willReturn([['operation' => json_encode(['endpointId' => 'missing-1'])]]);

		$operation = $this->makeOperation($endpointService, $logger);
		$operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);
		$this->addToAssertionCount(1);

	}//end testMissingEndpointIsLoggedAndSkipped()

	/**
	 * A flow with malformed JSON settings is skipped (no crash, no dispatch).
	 *
	 * @return void
	 */
	public function testMalformedJsonIsSkipped(): void {
		$endpointService = $this->createMock(EndpointService::class);
		$endpointService->expects($this->never())->method('getEndpointById');
		$endpointService->expects($this->never())->method('triggerFromFlow');

		$ruleMatcher = $this->createMock(IRuleMatcher::class);
		$ruleMatcher->method('getFlows')->willReturn([['operation' => 'not-json']]);

		$operation = $this->makeOperation($endpointService);
		$operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);
		$this->addToAssertionCount(1);

	}//end testMalformedJsonIsSkipped()

	/**
	 * validateOperation() throws UnexpectedValueException on malformed JSON.
	 *
	 * @return void
	 */
	public function testValidateOperationRejectsMalformedJson(): void {
		$this->expectException(\UnexpectedValueException::class);

		$operation = $this->makeOperation($this->createMock(EndpointService::class));
		$operation->validateOperation('rule', [], 'not-json');

	}//end testValidateOperationRejectsMalformedJson()

	/**
	 * validateOperation() throws UnexpectedValueException when endpointId is missing.
	 *
	 * @return void
	 */
	public function testValidateOperationRejectsMissingEndpointId(): void {
		$this->expectException(\UnexpectedValueException::class);

		$operation = $this->makeOperation($this->createMock(EndpointService::class));
		$operation->validateOperation('rule', [], json_encode([]));

	}//end testValidateOperationRejectsMissingEndpointId()

	/**
	 * validateOperation() throws UnexpectedValueException when the referenced
	 * endpoint does not resolve.
	 *
	 * @return void
	 */
	public function testValidateOperationRejectsUnresolvableEndpoint(): void {
		$endpointService = $this->createMock(EndpointService::class);
		$endpointService->method('getEndpointById')->willReturn(null);

		$this->expectException(\UnexpectedValueException::class);

		$operation = $this->makeOperation($endpointService);
		$operation->validateOperation('rule', [], json_encode(['endpointId' => 'gone-1']));

	}//end testValidateOperationRejectsUnresolvableEndpoint()

	/**
	 * validateOperation() does not throw for a valid, resolvable endpointId.
	 *
	 * @return void
	 */
	public function testValidateOperationAcceptsValidSettings(): void {
		$endpoint = $this->createMock(ObjectEntity::class);
		$endpointService = $this->createMock(EndpointService::class);
		$endpointService->method('getEndpointById')->with('ep-1')->willReturn($endpoint);

		$operation = $this->makeOperation($endpointService);
		$operation->validateOperation('rule', [], json_encode(['endpointId' => 'ep-1']));
		$this->addToAssertionCount(1);

	}//end testValidateOperationAcceptsValidSettings()
}//end class
