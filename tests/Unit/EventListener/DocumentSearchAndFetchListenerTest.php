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
use OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuard;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\Integriq\Service\ConnectionStore;
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

	/**
	 * A guard that answers whether the asking app has a linked connection.
	 *
	 * @param bool $linked The answer.
	 *
	 * @return DocumentConnectionGuard
	 */
	private function linked(bool $linked): DocumentConnectionGuard {
		$guard = $this->createMock(DocumentConnectionGuard::class);
		$guard->method('isLinked')->willReturn($linked);

		return $guard;
	}//end linked()

	public function testASearchFromAnUnlinkedAppIsRefusedWithoutAGraphCall(): void {
		$adapter = $this->createMock(Microsoft365Adapter::class);
		$adapter->method('isConnected')->willReturn(true);
		$adapter->expects(self::never())->method('search');

		$event = new DocumentSearchRequestedEvent('rogue-app', '', 'pjansen', 'Stationsplein');
		(new DocumentSearchRequestedListener($adapter, $this->linked(false), $this->createMock(LoggerInterface::class)))->handle($event);

		self::assertSame(['hits' => [], 'moreCount' => 0, 'notices' => ['not-linked']], $event->getResult());
	}//end testASearchFromAnUnlinkedAppIsRefusedWithoutAGraphCall()

	public function testAFetchFromAnUnlinkedAppIsRefusedWithoutAGraphCall(): void {
		$adapter = $this->createMock(Microsoft365Adapter::class);
		$adapter->expects(self::never())->method('fetch');

		$event = new DocumentFetchRequestedEvent('rogue-app', '', 'pjansen', 'driveItem:d1:i1');
		(new DocumentFetchRequestedListener($adapter, $this->linked(false), $this->createMock(LoggerInterface::class)))->handle($event);

		self::assertFalse($event->isHandled());
		self::assertNull($event->getResult());
	}//end testAFetchFromAnUnlinkedAppIsRefusedWithoutAGraphCall()

	public function testTheGuardResolvesTheConnectionFromAppAndKey(): void {
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findRows')->willReturnCallback(
			static fn (?string $app = null): array => ($app === 'dossiq')
				? [
					['uuid' => 'r1', 'data' => ['app' => 'dossiq', 'key' => 'documents', 'source' => 's-1']],
					['uuid' => 'r2', 'data' => ['app' => 'dossiq', 'key' => 'unlinked']],
					['uuid' => 'r3', 'data' => ['app' => 'dossiq', 'key' => 'gone', 'source' => 's-gone']],
				]
				: []
		);
		$store->method('findSource')->willReturnCallback(
			fn (string $uuid) => ($uuid === 's-1') ? $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class) : null
		);
		$guard = new DocumentConnectionGuard($store);

		self::assertTrue($guard->isLinked('dossiq', 'documents'));
		self::assertFalse($guard->isLinked('dossiq', 'unlinked'), 'a declared connection without a source is not linked');
		self::assertFalse($guard->isLinked('dossiq', 'gone'), 'a link to a deleted source is not linked');
		self::assertFalse($guard->isLinked('dossiq', 'other'), 'an unknown key is refused');
		self::assertFalse($guard->isLinked('dossiq', ''), 'an empty key is ambiguous when the app declares several');
		self::assertFalse($guard->isLinked('rogue-app', 'documents'), 'an app with no connection is refused');
		self::assertFalse($guard->isLinked('', 'documents'));
	}//end testTheGuardResolvesTheConnectionFromAppAndKey()

	public function testTheGuardAcceptsAnEmptyKeyForAnAppWithOneLinkedConnection(): void {
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findRows')->willReturn([['uuid' => 'r1', 'data' => ['app' => 'dossiq', 'key' => 'documents', 'source' => 's-1']]]);
		$store->method('findSource')->willReturn($this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class));

		self::assertTrue((new DocumentConnectionGuard($store))->isLinked('dossiq', ''));
	}//end testTheGuardAcceptsAnEmptyKeyForAnAppWithOneLinkedConnection()

	public function testTheGuardFailsClosedWhenTheRowsCannotBeRead(): void {
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findRows')->willThrowException(new \RuntimeException('register down'));

		self::assertFalse((new DocumentConnectionGuard($store))->isLinked('dossiq', 'documents'));
	}//end testTheGuardFailsClosedWhenTheRowsCannotBeRead()

	public function testASearchIsAnsweredWithTheAdaptersHits(): void {
		$adapter = $this->createMock(Microsoft365Adapter::class);
		$adapter->method('isConnected')->willReturn(true);
		$adapter->expects(self::once())->method('search')
			->with('Stationsplein', '2025-01-01', '2026-06-30', [], 50, 'pjansen')
			->willReturn(['hits' => [['remoteId' => 'driveItem:d1:i1']], 'moreCount' => 0, 'notices' => []]);

		$event = new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'Stationsplein', '2025-01-01', '2026-06-30');
		(new DocumentSearchRequestedListener($adapter, $this->linked(true), $this->createMock(LoggerInterface::class)))->handle($event);

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
		(new DocumentSearchRequestedListener($adapter, $this->linked(true), $this->createMock(LoggerInterface::class)))->handle($event);

		self::assertSame(['hits' => [], 'moreCount' => 0, 'notices' => ['not-connected']], $event->getResult());
	}//end testWithoutAConnectionTheSearchSaysSo()

	public function testAFetchHandsOverTheFileAndLeavesAnUnknownHandleUnhandled(): void {
		$adapter = $this->createMock(Microsoft365Adapter::class);
		$adapter->method('fetch')->willReturnCallback(
			static fn (string $handle, string $userId): ?array => ($handle === 'driveItem:d1:i1') ? ['fileName' => 'a.docx', 'mimeType' => 'application/msword', 'content' => 'BYTES'] : null
		);
		$listener = new DocumentFetchRequestedListener($adapter, $this->linked(true), $this->createMock(LoggerInterface::class));

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
		$listener = new DocumentSearchRequestedListener($adapter, $this->linked(true), $this->createMock(LoggerInterface::class));

		$listener->handle(new Event());
		$event = new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'x');
		$listener->handle($event);

		self::assertFalse($event->isHandled());
	}//end testAnotherEventIsIgnoredAndAFailureLeavesTheEventUnhandled()
}//end class
