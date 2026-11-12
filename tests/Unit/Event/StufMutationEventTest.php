<?php

/**
 * Characterisation tests for the StUF case and document mutation events.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufDocumentMutationEvent;
use OCA\Integriq\Event\StufProviderQueryEvent;
use PHPUnit\Framework\TestCase;

/**
 * Pins the getters, the default operation and the acceptance answer of both events.
 */
final class StufMutationEventTest extends TestCase {

	/**
	 * The two event classes under test.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function eventClasses(): array {
		return [
			'case' => [StufCaseMutationEvent::class],
			'document' => [StufDocumentMutationEvent::class],
		];
	}//end eventClasses()

	/**
	 * Each event carries what it was built with and answers acceptance with the identificatie.
	 *
	 * @param string $class The event class.
	 *
	 * @return void
	 *
	 * @dataProvider eventClasses
	 */
	public function testCarriesFieldsAndAcceptsWithIdentification(string $class): void {
		$event = new $class('source-1', 'ref-9', 'W', ['identificatie' => 'X-1'], ['action' => 'update']);

		$this->assertInstanceOf(StufProviderQueryEvent::class, $event);
		$this->assertSame('source-1', $event->getSourceId());
		$this->assertSame('ref-9', $event->getReferentienummer());
		$this->assertSame('W', $event->getMutatiesoort());
		$this->assertSame(['identificatie' => 'X-1'], $event->getFields());
		$this->assertSame(['action' => 'update'], $event->getOperation());
		$this->assertFalse($event->isAnswered());

		$event->accept('procest', 'X-2');

		$this->assertTrue($event->isAnswered());
		$this->assertSame('procest', $event->getAnsweredBy());
		$this->assertSame(['identification' => 'X-2'], $event->getAnswer());
	}//end testCarriesFieldsAndAcceptsWithIdentification()

	/**
	 * Without an operation the event carries an empty one.
	 *
	 * @param string $class The event class.
	 *
	 * @return void
	 *
	 * @dataProvider eventClasses
	 */
	public function testOperationDefaultsToEmpty(string $class): void {
		$event = new $class('source-1', 'ref-9', '', []);

		$this->assertSame([], $event->getOperation());
		$this->assertSame('', $event->getMutatiesoort());
	}//end testOperationDefaultsToEmpty()
}//end class
