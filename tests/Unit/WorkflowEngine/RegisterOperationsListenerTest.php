<?php
/**
 * Unit tests for RegisterOperationsListener.
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
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\WorkflowEngine;

use OCA\OpenConnector\WorkflowEngine\CallEndpointOperation;
use OCA\OpenConnector\WorkflowEngine\FireCloudEventOperation;
use OCA\OpenConnector\WorkflowEngine\RegisterOperationsListener;
use OCA\OpenConnector\WorkflowEngine\RunSynchronizationOperation;
use OCP\EventDispatcher\Event;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
 */
class RegisterOperationsListenerTest extends TestCase
{


    /**
     * handle() registers all three operations exactly once each when a
     * RegisterOperationsEvent fires.
     *
     * @return void
     */
    public function testHandleRegistersAllThreeOperations(): void
    {
        $runSynchronizationOperation = $this->createMock(RunSynchronizationOperation::class);
        $callEndpointOperation       = $this->createMock(CallEndpointOperation::class);
        $fireCloudEventOperation     = $this->createMock(FireCloudEventOperation::class);

        $manager = $this->createMock(IManager::class);
        $manager->expects($this->exactly(3))
            ->method('registerOperation')
            ->with(
                $this->logicalOr($runSynchronizationOperation, $callEndpointOperation, $fireCloudEventOperation)
            );

        $listener = new RegisterOperationsListener($runSynchronizationOperation, $callEndpointOperation, $fireCloudEventOperation);
        $listener->handle(new RegisterOperationsEvent($manager));

    }//end testHandleRegistersAllThreeOperations()


    /**
     * handle() is a no-op for any event other than RegisterOperationsEvent.
     *
     * @return void
     */
    public function testHandleIgnoresOtherEvents(): void
    {
        $runSynchronizationOperation = $this->createMock(RunSynchronizationOperation::class);
        $callEndpointOperation       = $this->createMock(CallEndpointOperation::class);
        $fireCloudEventOperation     = $this->createMock(FireCloudEventOperation::class);

        $listener = new RegisterOperationsListener($runSynchronizationOperation, $callEndpointOperation, $fireCloudEventOperation);

        // Must not throw or attempt to register anything against an unrelated event.
        $listener->handle(new Event());
        $this->addToAssertionCount(1);

    }//end testHandleIgnoresOtherEvents()
}//end class
