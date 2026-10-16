<?php

/**
 * Unit tests for StufZdsCaseRequestedListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\StufZdsCaseRequestedEvent;
use OCA\Integriq\EventListener\StufZdsCaseRequestedListener;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClient;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\EventListener\StufZdsCaseRequestedListener
 * @covers \OCA\Integriq\Event\StufZdsCaseRequestedEvent
 */
class StufZdsCaseRequestedListenerTest extends TestCase {

	/**
	 * A creeerZaak request reaches the client with its arguments and the result lands in the slot.
	 *
	 * @return void
	 */
	public function testCreeerZaakIsRoutedAndTheResultRecorded(): void {
		$result = ['success' => true, 'retrying' => false, 'referentienummer' => 'R', 'messageId' => 'm', 'caseIdentification' => 'Z', 'fout' => null];
		$client = $this->createMock(StufZdsCaseClient::class);
		$client->expects($this->once())->method('creeerZaak')
			->with('src-1', ['omschrijving' => 'x'], 'Melding', ['sourceApp' => 'dossiq', 'correlationId' => 'c-1', 'includeDocuments' => true, 'payloadLimitBytes' => 10])
			->willReturn($result);

		$event = new StufZdsCaseRequestedEvent('dossiq', 'src-1', 'creeerZaak', ['case' => ['omschrijving' => 'x'], 'zaaktypeOmschrijving' => 'Melding', 'includeDocuments' => true, 'payloadLimitBytes' => 10], 'c-1');
		(new StufZdsCaseRequestedListener($client, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame($result, $event->getResult());
		$this->assertNull($event->getRefusal());
	}//end testCreeerZaakIsRoutedAndTheResultRecorded()

	/**
	 * A refusal from the client is a refusal, never a result.
	 *
	 * @return void
	 */
	public function testAClientRefusalIsRecordedAsARefusal(): void {
		$client = $this->createMock(StufZdsCaseClient::class);
		$client->method('vrijBericht')->willThrowException(new StufZknTranslationException('vrijBericht "x" is not registered'));

		$event = new StufZdsCaseRequestedEvent('dossiq', 'src-1', 'vrijBericht', ['name' => 'x'], 'c-2');
		(new StufZdsCaseRequestedListener($client, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getResult());
		$this->assertStringContainsString('not registered', (string)$event->getRefusal());
	}//end testAClientRefusalIsRecordedAsARefusal()

	/**
	 * An unknown operation is refused; another event type is ignored.
	 *
	 * @return void
	 */
	public function testUnknownOperationIsRefusedAndOtherEventsIgnored(): void {
		$client   = $this->createMock(StufZdsCaseClient::class);
		$listener = new StufZdsCaseRequestedListener($client, $this->createMock(LoggerInterface::class));

		$event = new StufZdsCaseRequestedEvent('dossiq', 'src-1', 'verwijderZaak', [], 'c-3');
		$listener->handle($event);
		$this->assertStringContainsString('Unknown', (string)$event->getRefusal());

		$client->expects($this->never())->method($this->anything());
		$listener->handle(new Event());
	}//end testUnknownOperationIsRefusedAndOtherEventsIgnored()
}//end class
