<?php
/**
 * Test stub for OpenRegister's RegisterFlowNodesEvent (peer app not in vendor).
 *
 * Mirrors `openregister/lib/Service/Flow/RegisterFlowNodesEvent.php` enough for
 * openconnector's RegisterFlowNodesListener to be unit-tested: it collects the
 * nodes an app registers so a test can assert the leaf was contributed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

class RegisterFlowNodesEvent extends Event
{
    /** @var array<int,IFlowNode> */
    private array $nodes = [];

    public function registerNode(IFlowNode $node): void
    {
        $this->nodes[] = $node;
    }

    /** @return array<int,IFlowNode> */
    public function getNodes(): array
    {
        return $this->nodes;
    }
}
