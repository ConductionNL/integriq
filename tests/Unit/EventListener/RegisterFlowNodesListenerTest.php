<?php
/**
 * Unit tests for the flow-node registration listener.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\EventListener;

use OCA\OpenConnector\EventListener\RegisterFlowNodesListener;
use OCA\OpenConnector\Service\Flow\Nodes\SynchronizationNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenConnector\EventListener\RegisterFlowNodesListener
 */
class RegisterFlowNodesListenerTest extends TestCase
{

    private RegisterFlowNodesListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturn('/apps/openconnector/img/app-dark.svg');

        $this->listener = new RegisterFlowNodesListener(new SynchronizationNode($l10n, $urls));
    }

    public function testRegistersTheSynchronizationNodeOnTheEvent(): void
    {
        $event = new RegisterFlowNodesEvent();

        $this->listener->handle($event);

        $nodes = $event->getNodes();
        $this->assertCount(1, $nodes);
        $this->assertSame('openconnector.synchronization', $nodes[0]->getId());
    }

    public function testIgnoresAnUnrelatedEvent(): void
    {
        $other = new Event();

        $this->listener->handle($other);

        // No exception, no registration attempted on a non-matching event.
        $this->addToAssertionCount(1);
    }
}
