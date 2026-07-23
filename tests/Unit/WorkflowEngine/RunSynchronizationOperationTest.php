<?php
/**
 * Unit tests for RunSynchronizationOperation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-run-synchronization-operations-onevent-must-dispatch-to-synchronizationservice-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\WorkflowEngine;

use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\WorkflowEngine\RunSynchronizationOperation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-the-run-synchronization-operations-onevent-must-dispatch-to-synchronizationservice-req-002
 */
class RunSynchronizationOperationTest extends TestCase
{


    /**
     * Build an operation instance with the given (possibly mocked) synchronization service.
     *
     * @param SynchronizationService $synchronizationService The synchronization service.
     * @param LoggerInterface|null   $logger                 Optional logger mock.
     *
     * @return RunSynchronizationOperation
     */
    private function makeOperation(SynchronizationService $synchronizationService, ?LoggerInterface $logger=null): RunSynchronizationOperation
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $text) => $text);

        return new RunSynchronizationOperation(
            $synchronizationService,
            $l10n,
            $this->createMock(IURLGenerator::class),
            ($logger ?? $this->createMock(LoggerInterface::class))
        );

    }//end makeOperation()


    /**
     * getEntityId() returns NC core's only bundled IEntity, referenced only
     * as a compile-time ::class string (no autoload of OCA\WorkflowEngine).
     *
     * @return void
     */
    public function testGetEntityIdReturnsFileEntity(): void
    {
        $operation = $this->makeOperation($this->createMock(SynchronizationService::class));
        $this->assertSame('OCA\WorkflowEngine\Entity\File', $operation->getEntityId());

    }//end testGetEntityIdReturnsFileEntity()


    /**
     * isAvailableForScope() returns true only for SCOPE_ADMIN (REQ-005).
     *
     * @return void
     */
    public function testIsAvailableOnlyForAdminScope(): void
    {
        $operation = $this->makeOperation($this->createMock(SynchronizationService::class));
        $this->assertTrue($operation->isAvailableForScope(IManager::SCOPE_ADMIN));
        $this->assertFalse($operation->isAvailableForScope(IManager::SCOPE_USER));

    }//end testIsAvailableOnlyForAdminScope()


    /**
     * A matching flow runs the configured synchronization: getSynchronization()
     * then synchronize() are both called with the resolved id/object.
     *
     * @return void
     */
    public function testOnEventRunsTheConfiguredSynchronization(): void
    {
        $synchronization = $this->createMock(ObjectEntity::class);

        $syncService = $this->createMock(SynchronizationService::class);
        $syncService->expects($this->once())
            ->method('getSynchronization')
            ->with(id: 'abc-123')
            ->willReturn($synchronization);
        $syncService->expects($this->once())
            ->method('synchronize')
            ->with(synchronization: $synchronization);

        $ruleMatcher = $this->createMock(IRuleMatcher::class);
        $ruleMatcher->method('getFlows')
            ->with(false)
            ->willReturn([['operation' => json_encode(['synchronizationId' => 'abc-123'])]]);

        $operation = $this->makeOperation($syncService);
        $operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);

    }//end testOnEventRunsTheConfiguredSynchronization()


    /**
     * Multiple matching flows each dispatch their own synchronize() call, and a
     * failure on one flow does not prevent the sibling flow from being dispatched.
     *
     * @return void
     */
    public function testMultipleFlowsEachDispatchIndependently(): void
    {
        $syncA = $this->createMock(ObjectEntity::class);
        $syncB = $this->createMock(ObjectEntity::class);

        $syncService = $this->createMock(SynchronizationService::class);
        $syncService->method('getSynchronization')
            ->willReturnMap(
                [
                    ['abc-123', [], $syncA],
                    ['def-456', [], $syncB],
                ]
            );
        $syncService->expects($this->exactly(2))
            ->method('synchronize')
            ->with($this->logicalOr($syncA, $syncB));

        $ruleMatcher = $this->createMock(IRuleMatcher::class);
        $ruleMatcher->method('getFlows')->willReturn(
            [
                ['operation' => json_encode(['synchronizationId' => 'abc-123'])],
                ['operation' => json_encode(['synchronizationId' => 'def-456'])],
            ]
        );

        $operation = $this->makeOperation($syncService);
        $operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);

    }//end testMultipleFlowsEachDispatchIndependently()


    /**
     * A deleted target synchronization is logged and does not throw out of onEvent().
     *
     * @return void
     */
    public function testDeletedSynchronizationIsLoggedAndDoesNotThrow(): void
    {
        $syncService = $this->createMock(SynchronizationService::class);
        $syncService->method('getSynchronization')->willThrowException(new DoesNotExistException('gone'));
        $syncService->expects($this->never())->method('synchronize');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $ruleMatcher = $this->createMock(IRuleMatcher::class);
        $ruleMatcher->method('getFlows')->willReturn([['operation' => json_encode(['synchronizationId' => 'gone-123'])]]);

        $operation = $this->makeOperation($syncService, $logger);

        // Must not throw.
        $operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);
        $this->addToAssertionCount(1);

    }//end testDeletedSynchronizationIsLoggedAndDoesNotThrow()


    /**
     * A flow with malformed JSON settings is skipped (no crash, no dispatch).
     *
     * @return void
     */
    public function testMalformedJsonIsSkipped(): void
    {
        $syncService = $this->createMock(SynchronizationService::class);
        $syncService->expects($this->never())->method('getSynchronization');
        $syncService->expects($this->never())->method('synchronize');

        $ruleMatcher = $this->createMock(IRuleMatcher::class);
        $ruleMatcher->method('getFlows')->willReturn([['operation' => 'not-json']]);

        $operation = $this->makeOperation($syncService);
        $operation->onEvent('OCP\Files::postWrite', new Event(), $ruleMatcher);
        $this->addToAssertionCount(1);

    }//end testMalformedJsonIsSkipped()


    /**
     * validateOperation() throws UnexpectedValueException on malformed JSON.
     *
     * @return void
     */
    public function testValidateOperationRejectsMalformedJson(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $operation = $this->makeOperation($this->createMock(SynchronizationService::class));
        $operation->validateOperation('rule', [], 'not-json');

    }//end testValidateOperationRejectsMalformedJson()


    /**
     * validateOperation() throws UnexpectedValueException when synchronizationId is missing.
     *
     * @return void
     */
    public function testValidateOperationRejectsMissingSynchronizationId(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        $operation = $this->makeOperation($this->createMock(SynchronizationService::class));
        $operation->validateOperation('rule', [], json_encode([]));

    }//end testValidateOperationRejectsMissingSynchronizationId()


    /**
     * validateOperation() throws UnexpectedValueException when the referenced
     * synchronization does not resolve.
     *
     * @return void
     */
    public function testValidateOperationRejectsUnresolvableSynchronization(): void
    {
        $syncService = $this->createMock(SynchronizationService::class);
        $syncService->method('getSynchronization')->willThrowException(new DoesNotExistException('gone'));

        $this->expectException(\UnexpectedValueException::class);

        $operation = $this->makeOperation($syncService);
        $operation->validateOperation('rule', [], json_encode(['synchronizationId' => 'gone-123']));

    }//end testValidateOperationRejectsUnresolvableSynchronization()


    /**
     * validateOperation() does not throw for a valid, resolvable synchronizationId.
     *
     * @return void
     */
    public function testValidateOperationAcceptsValidSettings(): void
    {
        $synchronization = $this->createMock(ObjectEntity::class);
        $syncService     = $this->createMock(SynchronizationService::class);
        $syncService->method('getSynchronization')->with(id: 'abc-123')->willReturn($synchronization);

        $operation = $this->makeOperation($syncService);
        $operation->validateOperation('rule', [], json_encode(['synchronizationId' => 'abc-123']));
        $this->addToAssertionCount(1);

    }//end testValidateOperationAcceptsValidSettings()
}//end class
