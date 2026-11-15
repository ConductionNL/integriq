<?php

/**
 * The document search and fetch commands carry their question and their answer.
 *
 * Pins the constructor, every getter and the result slot of the search and
 * fetch events, so their shared body can move without changing what a
 * sibling app or the listeners read.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\DocumentFetchRequestedEvent;
use OCA\Integriq\Event\DocumentSearchRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the document search and fetch events.
 */
class DocumentRequestEventsTest extends TestCase {

	/**
	 * A fetch carries its asker and handle, and is unanswered until a result is set.
	 *
	 * @return void
	 */
	public function testAFetchCarriesItsQuestionAndAnswer(): void {
		$event = new DocumentFetchRequestedEvent(sourceApp: 'dossiq', connectionKey: 'm365', userId: 'alice', handle: 'driveItem:42');

		self::assertInstanceOf(Event::class, $event);
		self::assertSame('dossiq', $event->getSourceApp());
		self::assertSame('m365', $event->getConnectionKey());
		self::assertSame('alice', $event->getUserId());
		self::assertSame('driveItem:42', $event->getHandle());
		self::assertFalse($event->isHandled());
		self::assertNull($event->getResult());

		$event->setResult(result: ['fileName' => 'a.pdf']);
		self::assertTrue($event->isHandled());
		self::assertSame(['fileName' => 'a.pdf'], $event->getResult());

	}//end testAFetchCarriesItsQuestionAndAnswer()

	/**
	 * A search carries its asker and criteria, with defaults for the optional ones.
	 *
	 * @return void
	 */
	public function testASearchCarriesItsQuestionAndAnswer(): void {
		$event = new DocumentSearchRequestedEvent(sourceApp: 'dossiq', connectionKey: 'm365', userId: 'bob', terms: 'budget', from: '2026-01-01', to: '2026-02-01', entityTypes: ['message'], limit: 5);

		self::assertSame('dossiq', $event->getSourceApp());
		self::assertSame('m365', $event->getConnectionKey());
		self::assertSame('bob', $event->getUserId());
		self::assertSame('budget', $event->getTerms());
		self::assertSame('2026-01-01', $event->getFrom());
		self::assertSame('2026-02-01', $event->getTo());
		self::assertSame(['message'], $event->getEntityTypes());
		self::assertSame(5, $event->getLimit());

		$bare = new DocumentSearchRequestedEvent(sourceApp: 's', connectionKey: 'c', userId: 'u', terms: 't');
		self::assertNull($bare->getFrom());
		self::assertNull($bare->getTo());
		self::assertSame([], $bare->getEntityTypes());
		self::assertSame(50, $bare->getLimit());
		self::assertFalse($bare->isHandled());

		$bare->setResult(result: []);
		self::assertTrue($bare->isHandled());
		self::assertSame([], $bare->getResult());

	}//end testASearchCarriesItsQuestionAndAnswer()
}//end class
