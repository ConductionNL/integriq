<?php

/**
 * The typed document search and fetch commands, answered through the listeners
 * with the real event classes, as a sibling app (dossiq) dispatches them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\DocumentFetchRequestedEvent;
use OCA\Integriq\Event\DocumentSearchRequestedEvent;
use OCA\Integriq\EventListener\DocumentFetchRequestedListener;
use OCA\Integriq\EventListener\DocumentSearchRequestedListener;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\EventListener\DocumentSearchRequestedListener
 * @covers \OCA\Integriq\EventListener\DocumentFetchRequestedListener
 * @covers \OCA\Integriq\Event\DocumentSearchRequestedEvent
 * @covers \OCA\Integriq\Event\DocumentFetchRequestedEvent
 */
class DocumentSearchAndFetchListenerTest extends TestCase {

	public function testASearchIsAnsweredWithTheAdaptersHits(): void {
		$adapter = $this->createMock(Microsoft365Adapter::class);
		$adapter->method('isConnected')->willReturn(true);
		$adapter->expects(self::once())->method('search')
			->with('Stationsplein', '2025-01-01', '2026-06-30', [], 50, 'pjansen')
			->willReturn(['hits' => [['remoteId' => 'driveItem:d1:i1']], 'moreCount' => 0, 'notices' => []]);

		$event = new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'Stationsplein', '2025-01-01', '2026-06-30');
		(new DocumentSearchRequestedListener($adapter, $this->createMock(LoggerInterface::class)))->handle($event);

		self::assertTrue($event->isHandled());
		self::assertSame('driveItem:d1:i1', $event->getResult()['hits'][0]['remoteId']);
		self::assertSame('dossiq', $event->getSourceApp());
		self::assertSame('', $event->getConnectionKey());
	}//end testASearchIsAnsweredWithTheAdaptersHits()

	public function testWithoutAConnectionTheSearchSaysSo(): void {
		$adapter = $this->createMock(Microsoft365Adapter::class);
		$adapter->method('isConnected')->willReturn(false);
		$adapter->expects(self::never())->method('search');

		$event = new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'x');
		(new DocumentSearchRequestedListener($adapter, $this->createMock(LoggerInterface::class)))->handle($event);

		self::assertSame(['hits' => [], 'moreCount' => 0, 'notices' => ['not-connected']], $event->getResult());
	}//end testWithoutAConnectionTheSearchSaysSo()

	public function testAFetchHandsOverTheFileAndLeavesAnUnknownHandleUnhandled(): void {
		$adapter = $this->createMock(Microsoft365Adapter::class);
		$adapter->method('fetch')->willReturnCallback(
			static fn (string $handle, string $userId): ?array => ($handle === 'driveItem:d1:i1') ? ['fileName' => 'a.docx', 'mimeType' => 'application/msword', 'content' => 'BYTES'] : null
		);
		$listener = new DocumentFetchRequestedListener($adapter, $this->createMock(LoggerInterface::class));

		$found = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'driveItem:d1:i1');
		$listener->handle($found);
		self::assertTrue($found->isHandled());
		self::assertSame('BYTES', $found->getResult()['content']);
		self::assertSame('driveItem:d1:i1', $found->getHandle());

		$missing = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'message:m1');
		$listener->handle($missing);
		self::assertFalse($missing->isHandled());
		self::assertNull($missing->getResult());
	}//end testAFetchHandsOverTheFileAndLeavesAnUnknownHandleUnhandled()

	public function testAnotherEventIsIgnoredAndAFailureLeavesTheEventUnhandled(): void {
		$adapter = $this->createMock(Microsoft365Adapter::class);
		$adapter->method('isConnected')->willReturn(true);
		$adapter->method('search')->willThrowException(new \RuntimeException('graph down'));
		$listener = new DocumentSearchRequestedListener($adapter, $this->createMock(LoggerInterface::class));

		$listener->handle(new Event());
		$event = new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'x');
		$listener->handle($event);

		self::assertFalse($event->isHandled());
	}//end testAnotherEventIsIgnoredAndAFailureLeavesTheEventUnhandled()
}//end class
