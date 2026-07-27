<?php
/**
 * Unit tests for the `openconnector.synchronization` flow-node leaf.
 *
 * Covers the OpenRegister-independent surface (id, metadata, admin scope,
 * config validation) and the private return-normaliser (`toItems`/`toJson`).
 * The `execute()` → `SynchronizationService::synchronize()` adapter path
 * depends on the service container and is covered by the Playwright e2e
 * (`tests/e2e/flow-node/synchronization-node.spec.ts`), per the test plan.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Flow\Nodes
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

namespace OCA\OpenConnector\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenConnector\Service\Flow\Nodes\SynchronizationNode;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenConnector\Service\Flow\Nodes\SynchronizationNode
 */
class SynchronizationNodeTest extends TestCase
{

    private SynchronizationNode $node;

    protected function setUp(): void
    {
        parent::setUp();

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('imagePath')->willReturn('/apps/openconnector/img/app-dark.svg');

        $this->node = new SynchronizationNode($l10n, $urls);
    }

    public function testIdIsTheNamespacedType(): void
    {
        $this->assertSame('openconnector.synchronization', $this->node->getId());
    }

    public function testMetadataIsNonEmpty(): void
    {
        $this->assertNotSame('', $this->node->getDisplayName());
        $this->assertNotSame('', $this->node->getDescription());
        $this->assertNotSame('', $this->node->getIcon());
    }

    public function testOfferedInAdminScopeOnly(): void
    {
        $this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
        $this->assertFalse($this->node->isAvailableForScope(IManager::SCOPE_USER));
    }

    public function testValidateConfigRejectsMissingId(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig([]);
    }

    public function testValidateConfigRejectsBlankId(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->node->validateConfig(['synchronizationId' => '   ']);
    }

    public function testValidateConfigAcceptsAValidId(): void
    {
        $this->node->validateConfig(['synchronizationId' => '00000000-0000-0000-0000-000000000000', 'force' => true]);
        $this->addToAssertionCount(1);
    }

    public function testNullResultYieldsAnEmptyItemList(): void
    {
        $this->assertSame([], $this->invokeToItems(null));
        $this->assertSame([], $this->invokeToItems([]));
    }

    public function testAListResultIsSpreadOneItemPerRecord(): void
    {
        $items = $this->invokeToItems([['a' => 1], ['a' => 2]]);

        $this->assertCount(2, $items);
        $this->assertSame(['a' => 1], $items[0]['json']);
        $this->assertSame(['item' => 0], $items[0]['pairedItem']);
        $this->assertSame(['a' => 2], $items[1]['json']);
        $this->assertSame([], $items[0]['binary']);
    }

    public function testAnAssociativeSummaryResultIsWrappedAsOneItem(): void
    {
        $items = $this->invokeToItems(['created' => 3, 'updated' => 1]);

        $this->assertCount(1, $items);
        $this->assertSame(['created' => 3, 'updated' => 1], $items[0]['json']);
        $this->assertNull($items[0]['pairedItem']);
    }

    public function testObjectEntityRecordsAreJsonSerialised(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['title' => 'Row']);

        $items = $this->invokeToItems([$entity]);

        $this->assertCount(1, $items);
        $this->assertIsArray($items[0]['json']);
    }

    /**
     * @param array|null $result The synchronise result to normalise.
     *
     * @return array The item list.
     */
    private function invokeToItems(array | null $result): array
    {
        $method = new ReflectionMethod(SynchronizationNode::class, 'toItems');
        $method->setAccessible(true);

        return $method->invoke($this->node, $result);
    }
}
