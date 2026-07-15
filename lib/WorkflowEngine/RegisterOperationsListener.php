<?php
/**
 * OpenConnector WorkflowEngine RegisterOperationsListener.
 *
 * Registers OpenConnector's three thin `ISpecificOperation` adapters
 * ("Run synchronization", "Call endpoint", "Fire CloudEvent") with NC core's
 * `workflowengine` app. This is the only documented registration path
 * (discovery.md finding 2): `Manager::getOperatorList()` dispatches
 * `RegisterOperationsEvent` on every operator-list read (not just once at
 * boot), so calling `IManager::registerOperation()` directly from
 * `Application.php::register()` would not survive across requests.
 *
 * @category WorkflowEngine
 * @package  OCA\OpenConnector\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\WorkflowEngine;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

/**
 * Listener that registers the three OpenConnector WorkflowEngine operations
 * on every `RegisterOperationsEvent` dispatch.
 *
 * @implements IEventListener<RegisterOperationsEvent>
 *
 * @SuppressWarnings(PHPMD.LongVariable) The three constructor params are named
 * after their own operation classes for readability, not shortened.
 *
 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
 */
class RegisterOperationsListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param RunSynchronizationOperation $runSynchronizationOperation The "Run synchronization" operation.
     * @param CallEndpointOperation       $callEndpointOperation       The "Call endpoint" operation.
     * @param FireCloudEventOperation     $fireCloudEventOperation     The "Fire CloudEvent" operation.
     */
    public function __construct(
        private readonly RunSynchronizationOperation $runSynchronizationOperation,
        private readonly CallEndpointOperation $callEndpointOperation,
        private readonly FireCloudEventOperation $fireCloudEventOperation,
    ) {

    }//end __construct()

    /**
     * Register all three operations when a `RegisterOperationsEvent` fires.
     *
     * @param Event $event The incoming event.
     *
     * @return void
     *
     * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
     */
    public function handle(Event $event): void
    {
        if ($event instanceof RegisterOperationsEvent === false) {
            return;
        }

        $event->registerOperation($this->runSynchronizationOperation);
        $event->registerOperation($this->callEndpointOperation);
        $event->registerOperation($this->fireCloudEventOperation);

    }//end handle()
}//end class
