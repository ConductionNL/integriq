<?php

/**
 * Unit tests for StufZdsConfirmationReceiver.
 *
 * Real message log, credential resolver and acknowledgement builder; only
 * OpenRegister, ICrypto and the event dispatcher are doubles. Every row the
 * log writes is validated against the real `stuf_message` schema.
 *
 * Ported from dossiq's StufControllerInboundTest: the async Bv01/Fo01 that a
 * case system sends after processing a ZDS kennisgeving.
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

use OCA\Integriq\Event\StufZdsCaseConcludedEvent;
use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiver;
use OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver;
use OCA\Integriq\Service\StufZkn\StufZdsMessageLog;
use OCA\Integriq\Service\StufZkn\StufZknAcknowledgementBuilder;
use OCA\Integriq\Service\StufZkn\StufZknRequestBody;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Security\ICrypto;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiver
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsMessageLog
 * @uses   \OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver
 * @uses   \OCA\Integriq\Service\StufZkn\StufZknAcknowledgementBuilder
 * @uses   \OCA\Integriq\Service\StufZkn\StufZdsResponseReader
 * @uses   \OCA\Integriq\Service\Stuf\StufXmlParser
 * @uses   \OCA\Integriq\Event\StufZdsCaseConcludedEvent
 */
class StufZdsConfirmationReceiverTest extends TestCase {

	/**
	 * The stuf_message rows, by uuid.
	 *
	 * @var array<string,array>
	 */
	private array $rows = [];

	/**
	 * The source objects, by id.
	 *
	 * @var array<string,array>
	 */
	private array $sources = [];

	/**
	 * Dispatched events.
	 *
	 * @var object[]
	 */
	private array $events = [];

	/**
	 * Whether ICrypto fails to decrypt.
	 *
	 * @var bool
	 */
	private bool $cryptoBroken = false;

	/**
	 * Set up one outbound row and its source.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->events       = [];
		$this->cryptoBroken = false;
		$this->sources      = [
			'zsh' => [
				'type' => 'stuf-zkn',
				'configuration' => [
					'provider' => 'rest',
					'organisatie' => '0363',
					'applicatie' => 'dossiq',
					'authentication' => ['mode' => 'wsse', 'wsse' => ['username' => 'zds', 'encryptedPassword' => 'cipher']],
				],
			],
			'plain' => [
				'type' => 'stuf-zkn',
				'configuration' => ['provider' => 'rest', 'organisatie' => '0363', 'authentication' => ['mode' => 'token']],
			],
		];
		$this->rows = [
			'msg-1' => [
				'direction' => 'outbound',
				'berichttype' => 'zakLk01',
				'operation' => 'creeerZaak',
				'entiteittype' => 'ZAK',
				'referentienummer' => 'REF-1',
				'sourceId' => 'zsh',
				'sourceApp' => 'dossiq',
				'correlationId' => 'case-42',
				'status' => 'sent',
				'attempts' => 1,
				'httpStatus' => 200,
				'syncedAt' => '2026-10-11T08:00:00+00:00',
			],
		];
	}//end setUp()

	/**
	 * A Bv01 with the source's credentials confirms the row and announces it.
	 *
	 * @return void
	 */
	public function testBv01ConfirmsTheOutboundRow(): void {
		$answer = $this->receiver()->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1', identificatie: 'ZAAK-2026-7'));

		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('Bv03Bericht', $answer['body']);
		$this->assertStringContainsString('IN-9', $answer['body'], 'The Bv03 cross-references the confirmation it acknowledges');

		$row = $this->rows['msg-1'];
		$this->assertSame('sent', $row['status']);
		$this->assertSame('Bv01', $row['confirmation']);
		$this->assertNotEmpty($row['confirmedAt']);
		$this->assertSame('ZAAK-2026-7', $row['caseIdentification']);

		$this->assertCount(1, $this->events);
		$event = $this->events[0];
		$this->assertInstanceOf(StufZdsCaseConcludedEvent::class, $event);
		$this->assertSame('dossiq', $event->getSourceApp());
		$this->assertSame('case-42', $event->getCorrelationId());
		$this->assertSame('creeerZaak', $event->getOperation());
		$this->assertTrue($event->getResult()['success']);
		$this->assertSame('Bv01', $event->getResult()['confirmation']);
		$this->assertSame('ZAAK-2026-7', $event->getResult()['caseIdentification']);
	}//end testBv01ConfirmsTheOutboundRow()

	/**
	 * A Fo01 fails the row with the case system's fout and announces the failure.
	 *
	 * @return void
	 */
	public function testFo01FailsTheOutboundRow(): void {
		$answer = $this->receiver()->receive(rawXml: self::envelope(code: 'Fo01', crossRef: 'REF-1', fout: 'StUF064'));

		$this->assertSame(200, $answer['statusCode']);
		$row = $this->rows['msg-1'];
		$this->assertSame('failed', $row['status']);
		$this->assertSame('Fo01', $row['confirmation']);
		$this->assertSame('StUF064', $row['faultCode']);
		$this->assertStringContainsString('Zaaktype onbekend', $row['error']);

		$this->assertCount(1, $this->events);
		$this->assertFalse($this->events[0]->getResult()['success']);
		$this->assertSame('StUF064', $this->events[0]->getResult()['fout']['code']);
	}//end testFo01FailsTheOutboundRow()

	/**
	 * A Bv01 for a row still waiting on a retry concludes it, so the retry job finds nothing to send.
	 *
	 * @return void
	 */
	public function testBv01ConcludesARetryingRow(): void {
		$this->rows['msg-1'] = array_merge(
			$this->rows['msg-1'],
			['status' => 'retrying', 'envelope' => '<xml/>', 'nextAttemptAt' => '2026-10-11T08:10:00+00:00', 'faultCode' => 'TIMEOUT', 'error' => 'timed out']
		);

		$answer = $this->receiver()->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1'));

		$this->assertSame(200, $answer['statusCode']);
		$row = $this->rows['msg-1'];
		$this->assertSame('sent', $row['status']);
		$this->assertArrayNotHasKey('envelope', $row);
		$this->assertArrayNotHasKey('nextAttemptAt', $row);
		$this->assertArrayNotHasKey('faultCode', $row);
		$this->assertArrayNotHasKey('error', $row);
	}//end testBv01ConcludesARetryingRow()

	/**
	 * The same confirmation twice is acknowledged once more but announced only once.
	 *
	 * @return void
	 */
	public function testARepeatedConfirmationIsAnnouncedOnce(): void {
		$receiver = $this->receiver();
		$receiver->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1'));
		$answer = $receiver->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1'));

		$this->assertSame(200, $answer['statusCode']);
		$this->assertCount(1, $this->events);
	}//end testARepeatedConfirmationIsAnnouncedOnce()

	/**
	 * A wrong password is refused and changes nothing.
	 *
	 * @return void
	 */
	public function testAWrongPasswordIsRefused(): void {
		$before = $this->rows;
		$answer = $this->receiver()->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1', password: 'guess'));

		$this->assertSame(422, $answer['statusCode']);
		$this->assertStringContainsString('Fo03Bericht', $answer['body']);
		$this->assertSame($before, $this->rows);
		$this->assertSame([], $this->events);
	}//end testAWrongPasswordIsRefused()

	/**
	 * An envelope without a UsernameToken is refused.
	 *
	 * @return void
	 */
	public function testAnEnvelopeWithoutCredentialsIsRefused(): void {
		$answer = $this->receiver()->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1', password: null));

		$this->assertSame(422, $answer['statusCode']);
		$this->assertSame([], $this->events);
	}//end testAnEnvelopeWithoutCredentialsIsRefused()

	/**
	 * An unknown crossRefnummer answers exactly like a wrong password, so it cannot be probed.
	 *
	 * @return void
	 */
	public function testAnUnknownCrossRefnummerAnswersLikeAWrongPassword(): void {
		$unknown = $this->receiver()->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-404'));
		$wrong   = $this->receiver()->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1', password: 'guess'));

		$this->assertSame(422, $unknown['statusCode']);
		$this->assertSame($wrong['statusCode'], $unknown['statusCode']);
		$this->assertSame([], $this->events);
	}//end testAnUnknownCrossRefnummerAnswersLikeAWrongPassword()

	/**
	 * A source without WS-Security credentials accepts no confirmation (fail closed).
	 *
	 * @return void
	 */
	public function testASourceWithoutWsseAcceptsNoConfirmation(): void {
		$this->rows['msg-1']['sourceId'] = 'plain';

		$answer = $this->receiver()->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1'));

		$this->assertSame(422, $answer['statusCode']);
		$this->assertSame([], $this->events);
	}//end testASourceWithoutWsseAcceptsNoConfirmation()

	/**
	 * A stored password that cannot be decrypted answers 503, so the case system retries.
	 *
	 * @return void
	 */
	public function testAnUndecryptablePasswordAnswers503(): void {
		$this->cryptoBroken = true;

		$answer = $this->receiver()->receive(rawXml: self::envelope(code: 'Bv01', crossRef: 'REF-1'));

		$this->assertSame(503, $answer['statusCode']);
		$this->assertSame([], $this->events);
	}//end testAnUndecryptablePasswordAnswers503()

	/**
	 * An empty body, a DOCTYPE, a kennisgeving or a confirmation without crossRefnummer is a 400.
	 *
	 * @return void
	 */
	public function testWhatIsNotAConfirmationIsA400(): void {
		$receiver = $this->receiver();
		$doctype  = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>' . self::envelope(code: 'Bv01', crossRef: 'REF-1');

		$this->assertSame(400, $receiver->receive(rawXml: '')['statusCode']);
		$this->assertSame(400, $receiver->receive(rawXml: $doctype)['statusCode']);
		$this->assertSame(400, $receiver->receive(rawXml: self::envelope(code: 'Lk01', crossRef: 'REF-1'))['statusCode']);
		$this->assertSame(400, $receiver->receive(rawXml: self::envelope(code: 'Bv01', crossRef: ''))['statusCode']);
		$this->assertSame([], $this->events);
	}//end testWhatIsNotAConfirmationIsA400()

	/**
	 * A body over the ceiling is refused with a 413 Fo03 before it is parsed, and nothing is looked up (iq-f9).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function testABodyOverTheCeilingIsRefusedBeforeAnyLookup(): void {
		$objects = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objects->expects($this->never())->method('find');
		$objects->expects($this->never())->method('findAll');
		$objects->expects($this->never())->method('saveObject');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');
		$receiver = new StufZdsConfirmationReceiver(
			new StufZdsMessageLog($objects),
			new StufZdsCredentialResolver($this->createMock(ICrypto::class)),
			new StufZknAcknowledgementBuilder(),
			$dispatcher,
			$this->createMock(LoggerInterface::class)
		);

		$answer = $receiver->receive(rawXml: str_repeat('a', 2097153));

		$this->assertSame(2097153, (StufZknRequestBody::MAX_BYTES + 1));
		$this->assertSame(413, $answer['statusCode']);
		$this->assertStringContainsString('Fo03Bericht', $answer['body']);
		$this->assertStringContainsString('StUF001', $answer['body']);
	}//end testABodyOverTheCeilingIsRefusedBeforeAnyLookup()

	/**
	 * A body exactly at the ceiling is not refused for its size.
	 *
	 * @return void
	 */
	public function testABodyAtTheCeilingIsNotRefusedForItsSize(): void {
		$answer = $this->receiver()->receive(rawXml: str_repeat(' ', StufZknRequestBody::MAX_BYTES));

		$this->assertSame(400, $answer['statusCode'], 'refused as no confirmation, not as too large');
	}//end testABodyAtTheCeilingIsNotRefusedForItsSize()

	/**
	 * Build the receiver over the doubles.
	 *
	 * @return StufZdsConfirmationReceiver The receiver.
	 */
	private function receiver(): StufZdsConfirmationReceiver {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(
			function (): string {
				if ($this->cryptoBroken === true) {
					throw new RuntimeException('HMAC does not match');
				}

				return 'plain-password';
			}
		);

		$objects = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register=null, ?string $schema=null) {
				$this->assertSame('integriq', $register);
				if ($schema === 'source') {
					return isset($this->sources[$id]) === true ? ObjectServiceMockBuilder::objectEntity($this, $this->sources[$id], $id) : null;
				}

				return isset($this->rows[$id]) === true ? ObjectServiceMockBuilder::objectEntity($this, $this->rows[$id], $id) : null;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$this->assertSame('integriq', $filters['register']);
				$this->assertSame('stuf_message', $filters['schema']);
				$found = [];
				foreach ($this->rows as $uuid => $row) {
					if (($row['direction'] ?? '') === $filters['direction'] && ($row['referentienummer'] ?? '') === $filters['referentienummer']) {
						$found[] = ObjectServiceMockBuilder::objectEntity($this, $row, $uuid);
					}
				}

				return ['results' => $found, 'total' => count($found)];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true) {
				$this->assertSame('stuf_message', $schema);
				$this->assertFalse($_rbac);
				$this->assertSame([], self::schemaErrors($object), 'The row is one the stuf_message schema accepts');
				$this->rows[(string)$uuid] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = $event;
			}
		);

		return new StufZdsConfirmationReceiver(
			new StufZdsMessageLog($objects),
			new StufZdsCredentialResolver($crypto),
			new StufZknAcknowledgementBuilder(),
			$dispatcher,
			$this->createMock(LoggerInterface::class)
		);
	}//end receiver()

	/**
	 * A confirmation envelope from the case system.
	 *
	 * @param string      $code          The berichtcode (Bv01, Fo01, ...).
	 * @param string      $crossRef      The crossRefnummer, '' for none.
	 * @param string      $identificatie A zaak identificatie to include, or ''.
	 * @param string      $fout          A fout code to include (Fo only), or ''.
	 * @param string|null $password      The UsernameToken password, null for no token.
	 *
	 * @return string The envelope.
	 */
	private static function envelope(string $code, string $crossRef, string $identificatie='', string $fout='', ?string $password='plain-password'): string {
		$header = '';
		if ($password !== null) {
			$header = '<soapenv:Header><wsse:Security><wsse:UsernameToken><wsse:Username>zds</wsse:Username>'
				. '<wsse:Password Type="#PasswordText">' . $password . '</wsse:Password></wsse:UsernameToken></wsse:Security></soapenv:Header>';
		}

		$cross = '';
		if ($crossRef !== '') {
			$cross = '<stuf:crossRefnummer>' . $crossRef . '</stuf:crossRefnummer>';
		}

		$body = '';
		if ($identificatie !== '') {
			$body = '<zkn:zaak><zkn:identificatie>' . $identificatie . '</zkn:identificatie></zkn:zaak>';
		}

		if ($fout !== '') {
			$body = '<stuf:body><stuf:code>' . $fout . '</stuf:code><stuf:plek>server</stuf:plek>'
				. '<stuf:omschrijving>Zaaktype onbekend</stuf:omschrijving></stuf:body>';
		}

		return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stuf="http://www.egem.nl/StUF/StUF0301"'
			. ' xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"'
			. ' xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
			. $header . '<soapenv:Body><stuf:' . $code . 'Bericht><stuf:stuurgegevens><stuf:berichtcode>' . $code . '</stuf:berichtcode>'
			. '<stuf:zender><stuf:organisatie>0363</stuf:organisatie><stuf:applicatie>ZSH</stuf:applicatie></stuf:zender>'
			. '<stuf:referentienummer>IN-9</stuf:referentienummer>' . $cross . '</stuf:stuurgegevens>' . $body
			. '</stuf:' . $code . 'Bericht></soapenv:Body></soapenv:Envelope>';
	}//end envelope()

	/**
	 * Validate a row against the real stuf_message schema.
	 *
	 * @param array $row The row.
	 *
	 * @return array Formatted errors, empty when valid.
	 */
	private static function schemaErrors(array $row): array {
		$descriptor = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/integriq_register.json'), true, flags: JSON_THROW_ON_ERROR);
		$schema     = $descriptor['components']['schemas']['stuf_message'];
		$result     = (new Validator())->validate(json_decode(json_encode($row, JSON_THROW_ON_ERROR)), json_encode($schema, JSON_THROW_ON_ERROR));
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());
	}//end schemaErrors()
}//end class
