<?php
/**
 * OpenConnector Flow Node Listener.
 *
 * Contributes OpenConnector's node types to OpenRegister's flow palette when
 * OpenRegister dispatches `RegisterFlowNodesEvent`.
 *
 * This is Nextcloud's own discovery pattern — the same listener an app writes
 * for `RegisterOperationsEvent` in core's workflow engine — and it is the
 * reason OpenConnector contributes NODES rather than running a graph of its
 * own. The fleet has one flow engine (ADR-065); apps contribute steps to it.
 *
 * The listener is registered from `Application::register()` behind a
 * `class_exists()` guard on the EVENT class, and registration is a lazy
 * SERVICE listener. Together that means neither this class nor the two node
 * classes are ever loaded on an instance whose OpenRegister has no flow engine
 * — which matters, because both nodes `implements IFlowNode` and a
 * compile-time reference to an absent interface is a fatal error, not a
 * missing feature. The guard is not a caught-and-ignored error at call time;
 * it prevents the reference from being resolved at all.
 *
 * @category Flow
 * @package  OCA\OpenConnector\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Flow;

use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers OpenConnector's flow nodes when OpenRegister builds its palette.
 *
 * @template-implements IEventListener<RegisterFlowNodesEvent>
 *
 * @spec openspec/changes/openconnector-flow-nodes/tasks.md#task-1-flow-node-scaffolding-guarded-registration-shared-helpers
 */
class FlowNodeListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SourceCallNode         $sourceCallNode         The governed outbound-call node.
     * @param SynchronizationRunNode $synchronizationRunNode The synchronisation-run node.
     */
    public function __construct(
        private readonly SourceCallNode $sourceCallNode,
        private readonly SynchronizationRunNode $synchronizationRunNode
    ) {

    }//end __construct()

    /**
     * Contribute both node types.
     *
     * Node ids are app-namespaced, so `FlowNodeRegistry` refuses a collision at
     * registration rather than resolving it by load order — a clash is a
     * boot-time diagnosis, never a silently displaced node.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof RegisterFlowNodesEvent) === false) {
            return;
        }

        $event->registerNode(node: $this->sourceCallNode);
        $event->registerNode(node: $this->synchronizationRunNode);

    }//end handle()
}//end class
