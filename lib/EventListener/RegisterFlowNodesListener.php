<?php

/**
 * Contributes openconnector's synchronisation node to OpenRegister's flow engine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category EventListener
 * @package  OCA\OpenConnector\EventListener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\Flow\Nodes\SynchronizationNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the synchronisation node when OpenRegister builds its node palette.
 *
 * The listener is only wired when `RegisterFlowNodesEvent` exists (guarded in
 * `Application.php`), so an instance whose OpenRegister predates the flow engine
 * still boots. This handler defensively re-checks the event type and no-ops on
 * anything else.
 *
 * @template-implements IEventListener<RegisterFlowNodesEvent>
 *
 * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
 */
class RegisterFlowNodesListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SynchronizationNode $synchronizationNode The synchronisation step node.
     */
    public function __construct(private readonly SynchronizationNode $synchronizationNode)
    {

    }//end __construct()

    /**
     * Contribute the synchronisation node.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof RegisterFlowNodesEvent) === false) {
            return;
        }

        $event->registerNode(node: $this->synchronizationNode);

    }//end handle()
}//end class
