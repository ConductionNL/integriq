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

	/**
	 * Each operation, its arguments, and the client call they make.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:array<int,mixed>}>
	 */
	public static function operations(): array {
		$bare = ['sourceApp' => 'dossiq', 'correlationId' => 'c-9', 'includeDocuments' => false];
		return [
			'creeerZaak, bare'           => ['creeerZaak', [], 'creeerZaak', ['src-1', [], '', $bare]],
			'creeerZaak, full'           => ['creeerZaak', ['case' => ['a' => 1], 'zaaktypeOmschrijving' => 7, 'includeDocuments' => 'yes', 'payloadLimitBytes' => '12'], 'creeerZaak', ['src-1', ['a' => 1], '7', array_replace($bare, ['includeDocuments' => true]) + ['payloadLimitBytes' => 12]]],
			'actualiseerZaak, bare'      => ['actualiseerZaak', [], 'actualiseerZaak', ['src-1', '', [], $bare]],
			'actualiseerZaak, full'      => ['actualiseerZaak', ['caseIdentification' => 5, 'changes' => ['b' => 2]], 'actualiseerZaak', ['src-1', '5', ['b' => 2], $bare]],
			'geefZaakdetails, bare'      => ['geefZaakdetails', [], 'geefZaakdetails', ['src-1', '', [], $bare]],
			'geefZaakdetails, full'      => ['geefZaakdetails', ['caseIdentification' => 'Z-1', 'wantedElements' => [1, 'omschrijving']], 'geefZaakdetails', ['src-1', 'Z-1', ['1', 'omschrijving'], $bare]],
			'genereerZaakIdentificatie'  => ['genereerZaakIdentificatie', ['includeDocuments' => 0], 'genereerZaakIdentificatie', ['src-1', $bare]],
			'vrijBericht, bare'          => ['vrijBericht', [], 'vrijBericht', ['src-1', '', [], $bare]],
			'vrijBericht, full'          => ['vrijBericht', ['name' => 'ping', 'payload' => ['c' => 3]], 'vrijBericht', ['src-1', 'ping', ['c' => 3], $bare]],
		];

	}//end operations()

	/**
	 * Each operation reaches its client method with exactly these arguments (compared strictly).
	 *
	 * @param string              $operation The operation.
	 * @param array<string,mixed> $arguments The event's arguments.
	 * @param string              $method    The client method.
	 * @param array<int,mixed>    $expected  The arguments it receives.
	 *
	 * @return void
	 *
	 * @dataProvider operations
	 */
	public function testEachOperationReachesItsClientCall(string $operation, array $arguments, string $method, array $expected): void {
		$client = $this->createMock(StufZdsCaseClient::class);
		$client->expects($this->once())->method($method)
			->with(...array_map(fn (mixed $argument) => $this->identicalTo($argument), $expected))
			->willReturn(['ok' => $operation]);

		$event = new StufZdsCaseRequestedEvent('dossiq', 'src-1', $operation, $arguments, 'c-9');
		(new StufZdsCaseRequestedListener($client, $this->createMock(LoggerInterface::class)))->handle($event);

		$this->assertSame(['ok' => $operation], $event->getResult());

	}//end testEachOperationReachesItsClientCall()

	/**
	 * A refusal is logged with the operation and the asking app, and an
	 * unknown operation is refused with its name.
	 *
	 * @return void
	 */
	public function testRefusalsAreLoggedAndNamed(): void {
		$client = $this->createMock(StufZdsCaseClient::class);
		$client->method('creeerZaak')->willThrowException(new \RuntimeException('no route'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[integriq] a StUF-ZDS case request was refused: no route', ['operation' => 'creeerZaak', 'sourceApp' => 'dossiq']);
		$listener = new StufZdsCaseRequestedListener($client, $logger);

		$refused = new StufZdsCaseRequestedEvent('dossiq', 'src-1', 'creeerZaak', [], 'c-4');
		$listener->handle($refused);
		$unknown = new StufZdsCaseRequestedEvent('dossiq', 'src-1', 'verwijderZaak', [], 'c-5');
		$listener->handle($unknown);

		$this->assertSame('no route', $refused->getRefusal());
		$this->assertSame('Unknown StUF-ZDS operation "verwijderZaak".', $unknown->getRefusal());

	}//end testRefusalsAreLoggedAndNamed()
}//end class
