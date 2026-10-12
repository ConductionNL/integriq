<?php

/**
 * Unit tests for StufProviderReceiver.
 *
 * Real message log (source reads), credential resolver, XML parser and XML
 * builder; only OpenRegister, ICrypto and the event dispatcher are doubles.
 * The dispatcher double plays the owning app: it answers, refuses or stays
 * silent, so every path through the answer slot is driven.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
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

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufCaseQueryEvent;
use OCA\Integriq\Event\StufDocumentMutationEvent;
use OCA\Integriq\Event\StufPersonQueryEvent;
use OCA\Integriq\Event\StufProviderQueryEvent;
use OCA\Integriq\Service\StUFXMLBuilder;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver;
use OCA\Integriq\Service\StufZkn\StufZdsMessageLog;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufProviderReceiver
 * @covers \OCA\Integriq\Service\StufZkn\StufProviderRequestReader
 * @covers \OCA\Integriq\Event\StufProviderQueryEvent
 * @covers \OCA\Integriq\Event\StufCaseQueryEvent
 * @covers \OCA\Integriq\Event\StufCaseMutationEvent
 * @covers \OCA\Integriq\Event\StufPersonQueryEvent
 * @covers \OCA\Integriq\Event\StufDocumentMutationEvent
 * @covers \OCA\Integriq\Service\StUFXMLBuilder
 * @uses   \OCA\Integriq\Service\StufZkn\StufZdsMessageLog
 * @uses   \OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver
 * @uses   \OCA\Integriq\Service\Stuf\StufXmlParser
 */
class StufProviderReceiverTest extends TestCase {

	/**
	 * The source objects, by id.
	 *
	 * @var array<string,array>
	 */
	private array $sources = [];

	/**
	 * Dispatched events.
	 *
	 * @var StufProviderQueryEvent[]
	 */
	private array $events = [];

	/**
	 * What the owning app does with the event: answer, refuse, silent or throw.
	 *
	 * @var string
	 */
	private string $owner = 'answer';

	/**
	 * Set up the sources.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->events  = [];
		$this->owner   = 'answer';
		$wsse          = ['mode' => 'wsse', 'wsse' => ['username' => 'zsh', 'encryptedPassword' => 'cipher']];
		$this->sources = [
			'zsh' => [
				'type' => 'stuf-zkn',
				'configuration' => [
					'organisatie' => '0363',
					'applicatie' => 'Dossiq',
					'acceptsProviderRequests' => true,
					'authentication' => $wsse,
				],
			],
			'closed' => [
				'type' => 'stuf-zkn',
				'configuration' => ['organisatie' => '0363', 'authentication' => $wsse],
			],
			'tokenless' => [
				'type' => 'stuf-zkn',
				'configuration' => ['organisatie' => '0363', 'acceptsProviderRequests' => true, 'authentication' => ['mode' => 'token']],
			],
			'rest' => [
				'type' => 'rest',
				'configuration' => ['acceptsProviderRequests' => true, 'authentication' => $wsse],
			],
		];
	}//end setUp()

	/**
	 * A zakLv01 becomes a StufCaseQueryEvent and the answer a zakLa01.
	 *
	 * @return void
	 */
	public function testCaseQueryIsAnsweredAsZakLa01(): void {
		$answer = $this->receiver()->receive(rawXml: self::zakLv01(), sourceId: 'zsh', service: 'cases');

		$this->assertCount(1, $this->events);
		$event = $this->events[0];
		$this->assertInstanceOf(StufCaseQueryEvent::class, $event);
		$this->assertSame('zsh', $event->getSourceId());
		$this->assertSame('REQ-1', $event->getReferentienummer());
		$this->assertSame(['identificatie' => 'ZAAK-2026-7'], $event->getCriteria());

		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('zakLa01', $answer['body']);
		$this->assertStringContainsString('<StUF:aantalVoorkomens>1</StUF:aantalVoorkomens>', $answer['body']);
		$this->assertStringContainsString('ZAAK-2026-7', $answer['body']);
		$this->assertStringContainsString('<StUF:crossRefnummer>REQ-1</StUF:crossRefnummer>', $answer['body']);
		$this->assertStringContainsString('<StUF:organisatie>0363</StUF:organisatie>', $answer['body']);
	}//end testCaseQueryIsAnsweredAsZakLa01()

	/**
	 * Zero cases is an answer: the app looked and found none.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerIsAnEmptyZakLa01NotAFault(): void {
		$this->owner = 'empty';
		$answer      = $this->receiver()->receive(rawXml: self::zakLv01(), sourceId: 'zsh', service: 'cases');

		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('<StUF:aantalVoorkomens>0</StUF:aantalVoorkomens>', $answer['body']);
	}//end testAnEmptyAnswerIsAnEmptyZakLa01NotAFault()

	/**
	 * Nobody answering is a Fo02 with StUF055, never an empty zakLa01.
	 *
	 * @return void
	 */
	public function testAnUnansweredCaseQueryIsAFo02(): void {
		$this->owner = 'silent';
		$answer      = $this->receiver()->receive(rawXml: self::zakLv01(), sourceId: 'zsh', service: 'cases');

		$this->assertSame(500, $answer['statusCode']);
		$this->assertStringContainsString('Fo02Bericht', $answer['body']);
		$this->assertStringContainsString('StUF055', $answer['body']);
		$this->assertStringNotContainsString('zakLa01', $answer['body']);
	}//end testAnUnansweredCaseQueryIsAFo02()

	/**
	 * A listener that throws counts as unanswered.
	 *
	 * @return void
	 */
	public function testAFailingListenerIsAFault(): void {
		$this->owner = 'throw';
		$answer      = $this->receiver()->receive(rawXml: self::npsLv01(), sourceId: 'zsh', service: 'persons');

		$this->assertSame(500, $answer['statusCode']);
		$this->assertStringContainsString('Fo02Bericht', $answer['body']);
		$this->assertStringNotContainsString('npsLa01', $answer['body']);
		$this->assertStringNotContainsString('database down', $answer['body'], 'The fault leaks nothing');
	}//end testAFailingListenerIsAFault()

	/**
	 * An npsLv01 becomes a StufPersonQueryEvent and the answer an npsLa01.
	 *
	 * @return void
	 */
	public function testPersonQueryIsAnsweredAsNpsLa01(): void {
		$answer = $this->receiver()->receive(rawXml: self::npsLv01(), sourceId: 'zsh', service: 'persons');

		$this->assertInstanceOf(StufPersonQueryEvent::class, $this->events[0]);
		$this->assertSame('999993653', $this->events[0]->getBsn());
		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('npsLa01', $answer['body']);
		$this->assertStringContainsString('Jansen', $answer['body']);
	}//end testPersonQueryIsAnsweredAsNpsLa01()

	/**
	 * A zakLk01 becomes a StufCaseMutationEvent and acceptance a Bv01.
	 *
	 * @return void
	 */
	public function testCaseMutationIsAcceptedAsBv01(): void {
		$answer = $this->receiver()->receive(rawXml: self::zakLk01(), sourceId: 'zsh', service: 'cases');

		$event = $this->events[0];
		$this->assertInstanceOf(StufCaseMutationEvent::class, $event);
		$this->assertSame('W', $event->getMutatiesoort());
		$this->assertSame('ZAAK-2026-7', $event->getFields()['identificatie']);
		$this->assertSame('Nieuwe omschrijving', $event->getFields()['omschrijving']);
		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('Bv01Bericht', $answer['body']);
		$this->assertStringContainsString('<StUF:crossRefnummer>REQ-1</StUF:crossRefnummer>', $answer['body']);
	}//end testCaseMutationIsAcceptedAsBv01()

	/**
	 * A refused kennisgeving is a Fo01 carrying the app's reason, HTTP 400.
	 *
	 * @return void
	 */
	public function testARefusedMutationIsAFo01(): void {
		$this->owner = 'refuse';
		$answer      = $this->receiver()->receive(rawXml: self::zakLk01(), sourceId: 'zsh', service: 'cases');

		$this->assertSame(400, $answer['statusCode']);
		$this->assertStringContainsString('Fo01Bericht', $answer['body']);
		$this->assertStringContainsString('Zaaktype is not mapped', $answer['body']);
	}//end testARefusedMutationIsAFo01()

	/**
	 * An unanswered kennisgeving is a Fo01 with StUF055, not a Bv01.
	 *
	 * @return void
	 */
	public function testAnUnansweredMutationIsAFo01(): void {
		$this->owner = 'silent';
		$answer      = $this->receiver()->receive(rawXml: self::zakLk01(), sourceId: 'zsh', service: 'cases');

		$this->assertSame(500, $answer['statusCode']);
		$this->assertStringContainsString('Fo01Bericht', $answer['body']);
		$this->assertStringContainsString('StUF055', $answer['body']);
		$this->assertStringNotContainsString('Bv01Bericht', $answer['body']);
	}//end testAnUnansweredMutationIsAFo01()

	/**
	 * An edcLk01 becomes a StufDocumentMutationEvent.
	 *
	 * @return void
	 */
	public function testDocumentMutationIsDispatched(): void {
		$answer = $this->receiver()->receive(rawXml: self::edcLk01(), sourceId: 'zsh', service: 'cases');

		$event = $this->events[0];
		$this->assertInstanceOf(StufDocumentMutationEvent::class, $event);
		$this->assertSame('T', $event->getMutatiesoort());
		$this->assertSame('DOC-1', $event->getFields()['identificatie']);
		$this->assertSame('ZAAK-2026-7', $event->getFields()['zaakidentificatie']);
		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('Bv01Bericht', $answer['body']);
	}//end testDocumentMutationIsDispatched()

	/**
	 * A wrong password, a closed source, a tokenless source, an unknown source
	 * and a non-StUF source all get the same 401 and no event.
	 *
	 * @return void
	 */
	public function testEveryAuthenticationFailureLooksTheSame(): void {
		$bodies   = [];
		$receiver = $this->receiver();
		$cases    = [
			['zsh', self::zakLv01(password: 'wrong')],
			['zsh', self::zakLv01(password: null)],
			['closed', self::zakLv01()],
			['tokenless', self::zakLv01()],
			['missing', self::zakLv01()],
			['rest', self::zakLv01()],
		];
		foreach ($cases as [$sourceId, $xml]) {
			$answer = $receiver->receive(rawXml: $xml, sourceId: $sourceId, service: 'cases');
			$this->assertSame(401, $answer['statusCode'], 'source ' . $sourceId);
			$bodies[] = preg_replace('/<StUF:(referentienummer|tijdstipBericht)>[^<]*</', '<x><', $answer['body']);
		}

		$this->assertSame([], $this->events, 'No question reaches an app before authentication');
		$this->assertCount(1, array_unique($bodies), 'The refusals cannot be told apart');
	}//end testEveryAuthenticationFailureLooksTheSame()

	/**
	 * A message on the wrong route, an unknown message and malformed XML are refused with 400.
	 *
	 * @return void
	 */
	public function testUnsupportedOrMalformedRequestsAreRefused(): void {
		$receiver = $this->receiver();

		$this->assertSame(400, $receiver->receive(rawXml: self::npsLv01(), sourceId: 'zsh', service: 'cases')['statusCode']);
		$this->assertSame(400, $receiver->receive(rawXml: self::zakLv01(), sourceId: 'zsh', service: 'persons')['statusCode']);
		$this->assertSame(400, $receiver->receive(rawXml: self::envelope(body: '<ZKN:adrLv01/>'), sourceId: 'zsh', service: 'cases')['statusCode']);
		$this->assertSame(400, $receiver->receive(rawXml: '<not-xml', sourceId: 'zsh', service: 'cases')['statusCode']);
		$this->assertSame(400, $receiver->receive(rawXml: '', sourceId: 'zsh', service: 'cases')['statusCode']);
		$doctype = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>' . self::zakLv01();
		$this->assertSame(400, $receiver->receive(rawXml: $doctype, sourceId: 'zsh', service: 'cases')['statusCode']);
		$this->assertSame(413, $receiver->receive(rawXml: str_repeat('a', 2097153), sourceId: 'zsh', service: 'cases')['statusCode']);
		$this->assertSame([], $this->events);
	}//end testUnsupportedOrMalformedRequestsAreRefused()

	/**
	 * Build the receiver over real collaborators.
	 *
	 * @return StufProviderReceiver
	 */
	private function receiver(): StufProviderReceiver {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('secret');

		$objects = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register=null, ?string $schema=null) {
				$this->assertSame('integriq', $register);
				$this->assertSame('source', $schema);
				return isset($this->sources[$id]) === true ? ObjectServiceMockBuilder::objectEntity($this, $this->sources[$id], $id) : null;
			}
		);
		$objects->expects($this->never())->method('saveObject');

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = $event;
				$this->own(event: $event);
			}
		);

		return new StufProviderReceiver(
			new StufZdsMessageLog($objects),
			new StufZdsCredentialResolver($crypto),
			new StUFXMLBuilder(),
			$dispatcher,
			$this->createMock(LoggerInterface::class)
		);
	}//end receiver()

	/**
	 * Play the owning app.
	 *
	 * @param object $event The dispatched event.
	 *
	 * @return void
	 */
	private function own(object $event): void {
		if ($this->owner === 'silent') {
			return;
		}

		if ($this->owner === 'throw') {
			throw new RuntimeException('database down');
		}

		if ($this->owner === 'refuse' && $event instanceof StufProviderQueryEvent) {
			$event->refuse(appId: 'dossiq', reason: 'Zaaktype is not mapped');
			return;
		}

		if ($event instanceof StufCaseQueryEvent) {
			$cases = [['zaakidentificatie' => 'ZAAK-2026-7', 'omschrijving' => 'Kapvergunning', 'zaaktype' => 'KAP']];
			if ($this->owner === 'empty') {
				$cases = [];
			}

			$event->answerCases(appId: 'dossiq', cases: $cases);
		}

		if ($event instanceof StufPersonQueryEvent) {
			$event->answerPersons(appId: 'dossiq', persons: [['inp.bsn' => '999993653', 'geslachtsnaam' => 'Jansen']]);
		}

		if ($event instanceof StufCaseMutationEvent || $event instanceof StufDocumentMutationEvent) {
			$event->accept(appId: 'dossiq', identification: 'ZAAK-2026-7');
		}
	}//end own()

	/**
	 * A zakLv01 asking for one zaak.
	 *
	 * @param string|null $password The UsernameToken password, null for no header.
	 *
	 * @return string The envelope.
	 */
	private static function zakLv01(?string $password='secret'): string {
		return self::envelope(
			body: '<ZKN:zakLv01>' . self::stuurgegevens(code: 'Lv01') . '<ZKN:gelijk StUF:entiteittype="ZAK">'
				. '<ZKN:identificatie>ZAAK-2026-7</ZKN:identificatie></ZKN:gelijk></ZKN:zakLv01>',
			password: $password
		);
	}//end zakLv01()

	/**
	 * A zakLk01 changing a zaak.
	 *
	 * @return string The envelope.
	 */
	private static function zakLk01(): string {
		return self::envelope(
			body: '<ZKN:zakLk01>' . self::stuurgegevens(code: 'Lk01') . '<ZKN:object StUF:entiteittype="ZAK" StUF:verwerkingssoort="W">'
				. '<ZKN:identificatie>ZAAK-2026-7</ZKN:identificatie><ZKN:omschrijving>Nieuwe omschrijving</ZKN:omschrijving>'
				. '</ZKN:object></ZKN:zakLk01>'
		);
	}//end zakLk01()

	/**
	 * An edcLk01 adding a document to a zaak.
	 *
	 * @return string The envelope.
	 */
	private static function edcLk01(): string {
		return self::envelope(
			body: '<ZKN:edcLk01>' . self::stuurgegevens(code: 'Lk01') . '<ZKN:object StUF:entiteittype="EDC" StUF:verwerkingssoort="T">'
				. '<ZKN:identificatie>DOC-1</ZKN:identificatie><ZKN:titel>Besluit</ZKN:titel>'
				. '<ZKN:isRelevantVoor StUF:entiteittype="EDCZAK"><ZKN:gerelateerde StUF:entiteittype="ZAK">'
				. '<ZKN:identificatie>ZAAK-2026-7</ZKN:identificatie></ZKN:gerelateerde></ZKN:isRelevantVoor>'
				. '</ZKN:object></ZKN:edcLk01>'
		);
	}//end edcLk01()

	/**
	 * An npsLv01 asking for one BSN.
	 *
	 * @return string The envelope.
	 */
	private static function npsLv01(): string {
		return self::envelope(
			body: '<BG:npsLv01>' . self::stuurgegevens(code: 'Lv01') . '<BG:gelijk StUF:entiteittype="NPS">'
				. '<BG:inp.bsn>999993653</BG:inp.bsn></BG:gelijk></BG:npsLv01>'
		);
	}//end npsLv01()

	/**
	 * The request's stuurgegevens.
	 *
	 * @param string $code The berichtcode.
	 *
	 * @return string The stuurgegevens element.
	 */
	private static function stuurgegevens(string $code): string {
		return '<ZKN:stuurgegevens><StUF:berichtcode>' . $code . '</StUF:berichtcode>'
			. '<StUF:zender><StUF:organisatie>0363</StUF:organisatie><StUF:applicatie>ZSH</StUF:applicatie></StUF:zender>'
			. '<StUF:ontvanger><StUF:organisatie>0363</StUF:organisatie><StUF:applicatie>Dossiq</StUF:applicatie></StUF:ontvanger>'
			. '<StUF:referentienummer>REQ-1</StUF:referentienummer><StUF:tijdstipBericht>20261011120000000</StUF:tijdstipBericht>'
			. '</ZKN:stuurgegevens>';
	}//end stuurgegevens()

	/**
	 * Wrap a body in a SOAP envelope with a UsernameToken.
	 *
	 * @param string      $body     The SOAP body content.
	 * @param string|null $password The UsernameToken password, null for no header.
	 *
	 * @return string The envelope.
	 */
	private static function envelope(string $body, ?string $password='secret'): string {
		$header = '';
		if ($password !== null) {
			$header = '<soapenv:Header><wsse:Security><wsse:UsernameToken><wsse:Username>zsh</wsse:Username>'
				. '<wsse:Password Type="#PasswordText">' . $password . '</wsse:Password></wsse:UsernameToken></wsse:Security></soapenv:Header>';
		}

		return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:StUF="http://www.egem.nl/StUF/StUF0301"'
			. ' xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:BG="http://www.egem.nl/StUF/sector/bg/0310"'
			. ' xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
			. $header . '<soapenv:Body>' . $body . '</soapenv:Body></soapenv:Envelope>';
	}//end envelope()
}//end class
