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

use OCA\Integriq\Controller\StufProviderController;
use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufCaseQueryEvent;
use OCA\Integriq\Event\StufDocumentMutationEvent;
use OCA\Integriq\Event\StufPersonQueryEvent;
use OCA\Integriq\Event\StufProviderQueryEvent;
use OCA\Integriq\Service\StUFXMLBuilder;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCA\Integriq\Service\StufZkn\StufWsseInboundVerifier;
use OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver;
use OCA\Integriq\Service\StufZkn\StufZdsMessageLog;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufProviderReceiver
 * @covers \OCA\Integriq\Service\StufZkn\StufProviderRequestReader
 * @covers \OCA\Integriq\Service\StufZkn\StufProviderOperationMapper
 * @covers \OCA\Integriq\Controller\StufProviderController
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
	 * The logger the receiver under test gets.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The nonces the shared cache has seen, by key.
	 *
	 * @var array<string,int>
	 */
	private array $nonces = [];

	/**
	 * Build the verifier over a cache that remembers nonces for the test.
	 *
	 * @return StufWsseInboundVerifier
	 */
	private function verifier(): StufWsseInboundVerifier {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturnCallback(
			function (string $key, $value, int $ttl = 0): bool {
				if (isset($this->nonces[$key]) === true) {
					return false;
				}

				$this->nonces[$key] = $ttl;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$factory->method('isAvailable')->willReturn(true);

		return new StufWsseInboundVerifier($factory, $this->createMock(LoggerInterface::class));
	}//end verifier()

	/**
	 * Add a Nonce and a Created to an envelope's UsernameToken.
	 *
	 * @param string      $xml     The envelope.
	 * @param string|null $nonce   The nonce, null for none.
	 * @param string|null $created The Created time, null for none.
	 *
	 * @return string The envelope.
	 */
	private static function withFreshness(string $xml, ?string $nonce, ?string $created): string {
		$extra = '';
		if ($nonce !== null) {
			$extra .= '<wsse:Nonce>' . $nonce . '</wsse:Nonce>';
		}

		if ($created !== null) {
			$extra .= '<wsu:Created xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">' . $created . '</wsu:Created>';
		}

		return str_replace('</wsse:Password>', '</wsse:Password>' . $extra, $xml);
	}//end withFreshness()

	/**
	 * Set up the sources.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->nonces = [];
		$this->logger = $this->createMock(LoggerInterface::class);
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
	 * A token with a Nonce and a fresh Created is accepted once; a replay of the same request is refused like a wrong password (aud-f3).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	public function testAReplayedNonceIsRefused(): void {
		$receiver = $this->receiver();
		$request  = self::withFreshness(self::zakLv01(), 'nonce-1', gmdate('Y-m-d\TH:i:s\Z'));

		$first  = $receiver->receive(rawXml: $request, sourceId: 'zsh', service: 'cases');
		$replay = $receiver->receive(rawXml: $request, sourceId: 'zsh', service: 'cases');
		$other  = $receiver->receive(rawXml: self::withFreshness(self::zakLv01(), 'nonce-2', gmdate('Y-m-d\TH:i:s\Z')), sourceId: 'zsh', service: 'cases');

		$this->assertSame(200, $first['statusCode']);
		$this->assertSame(401, $replay['statusCode']);
		$this->assertSame(200, $other['statusCode'], 'a new nonce is accepted');
		$this->assertCount(2, $this->events, 'the replay never reached the owning app');
	}//end testAReplayedNonceIsRefused()

	/**
	 * A Created outside the window, an unreadable one and a Nonce without a Created are refused; a token without either is still accepted (aud-f3).
	 *
	 * @return void
	 */
	public function testAStaleOrIncompleteFreshnessIsRefused(): void {
		$receiver = $this->receiver();
		$requests = [
			self::withFreshness(self::zakLv01(), 'n-stale', gmdate('Y-m-d\TH:i:s\Z', (time() - 3600))),
			self::withFreshness(self::zakLv01(), 'n-future', gmdate('Y-m-d\TH:i:s\Z', (time() + 3600))),
			self::withFreshness(self::zakLv01(), 'n-garbage', 'not a date'),
			self::withFreshness(self::zakLv01(), 'n-no-created', null),
			self::withFreshness(self::zakLv01(), null, gmdate('Y-m-d\TH:i:s\Z')),
		];

		foreach ($requests as $index => $request) {
			$this->assertSame(401, $receiver->receive(rawXml: $request, sourceId: 'zsh', service: 'cases')['statusCode'], 'request ' . $index);
		}

		$this->assertSame([], $this->events);
		$this->assertSame(200, $receiver->receive(rawXml: self::zakLv01(), sourceId: 'zsh', service: 'cases')['statusCode'], 'a plain token still works');
	}//end testAStaleOrIncompleteFreshnessIsRefused()

	/**
	 * A source that requires a nonce refuses a plain token (aud-f3).
	 *
	 * @return void
	 */
	public function testASourceThatRequiresANonceRefusesAPlainToken(): void {
		$this->sources['zsh']['configuration']['authentication']['wsse']['requireNonce'] = true;
		$receiver = $this->receiver();

		$this->assertSame(401, $receiver->receive(rawXml: self::zakLv01(), sourceId: 'zsh', service: 'cases')['statusCode']);
		$this->assertSame(
			200,
			$receiver->receive(rawXml: self::withFreshness(self::zakLv01(), 'n-req', gmdate('Y-m-d\TH:i:s\Z')), sourceId: 'zsh', service: 'cases')['statusCode']
		);
	}//end testASourceThatRequiresANonceRefusesAPlainToken()

	/**
	 * An accepted request leaves one audit record naming the source, the message, the reference and the outcome, and no criterion or BSN (aud-f3).
	 *
	 * @return void
	 */
	public function testAnAcceptedRequestWritesOneAuditRecord(): void {
		$records = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$records): void {
				$records[] = [$message, $context];
			}
		);

		$this->receiver()->receive(rawXml: self::npsLv01(), sourceId: 'zsh', service: 'persons');

		$this->assertCount(1, $records);
		$this->assertStringStartsWith('[StUF audit] provider request accepted', $records[0][0], 'warning level, so the default log level keeps it');
		$this->assertSame('zsh', $records[0][1]['id']);
		$this->assertSame('npsLv01', $records[0][1]['type']);
		$this->assertSame('REQ-1', $records[0][1]['ref']);
		$this->assertSame('dossiq', $records[0][1]['app']);
		$this->assertSame(200, $records[0][1]['status']);
		$this->assertStringNotContainsString('999993653', (string)json_encode($records));
	}//end testAnAcceptedRequestWritesOneAuditRecord()

	/**
	 * A zakLv01 without any criterion, and an npsLv01 without a 9-digit BSN, are refused with a Fo02 and never put to the owning app (iq-f8).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function testAQueryWithoutACriterionIsRefusedWithoutAsking(): void {
		$receiver = $this->receiver();
		$requests = [
			['cases', self::zakLv01(criteria: '')],
			['cases', self::zakLv01(criteria: '<ZKN:identificatie>   </ZKN:identificatie>')],
			['persons', self::npsLv01(criteria: '')],
			['persons', self::npsLv01(criteria: '<BG:inp.bsn></BG:inp.bsn>')],
			['persons', self::npsLv01(criteria: '<BG:inp.bsn>99999365</BG:inp.bsn>')],
			['persons', self::npsLv01(criteria: '<BG:inp.bsn>9999936530</BG:inp.bsn>')],
			['persons', self::npsLv01(criteria: '<BG:inp.bsn>99999365a</BG:inp.bsn>')],
		];

		foreach ($requests as $index => [$service, $xml]) {
			$answer = $receiver->receive(rawXml: $xml, sourceId: 'zsh', service: $service);

			$this->assertSame(400, $answer['statusCode'], 'request ' . $index);
			$this->assertStringContainsString('Fo02', $answer['body'], 'request ' . $index);
			$this->assertStringContainsString('StUF058', $answer['body'], 'request ' . $index);
			$this->assertStringNotContainsString('La01', $answer['body'], 'request ' . $index);
		}

		$this->assertSame([], $this->events, 'the owning app was never asked');
	}//end testAQueryWithoutACriterionIsRefusedWithoutAsking()

	/**
	 * However many cases the owning app answers with, at most 100 are rendered (iq-f8).
	 *
	 * @return void
	 */
	public function testTheRenderedCasesAreCapped(): void {
		$this->owner = 'many';

		$answer = $this->receiver()->receive(rawXml: self::zakLv01(), sourceId: 'zsh', service: 'cases');

		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('<StUF:aantalVoorkomens>100</StUF:aantalVoorkomens>', $answer['body']);
		$this->assertStringContainsString('ZAAK-100<', $answer['body']);
		$this->assertStringNotContainsString('ZAAK-101<', $answer['body']);
	}//end testTheRenderedCasesAreCapped()

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
	 * A zakLk01 becomes a StufCaseMutationEvent and acceptance a Bv03.
	 *
	 * @return void
	 */
	public function testCaseMutationIsAcceptedAsBv03(): void {
		$answer = $this->receiver()->receive(rawXml: self::zakLk01(), sourceId: 'zsh', service: 'cases');

		$event = $this->events[0];
		$this->assertInstanceOf(StufCaseMutationEvent::class, $event);
		$this->assertSame('W', $event->getMutatiesoort());
		$this->assertSame('ZAAK-2026-7', $event->getFields()['identificatie']);
		$this->assertSame('Nieuwe omschrijving', $event->getFields()['omschrijving']);
		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('Bv03Bericht', $answer['body']);
		$this->assertStringContainsString('<StUF:crossRefnummer>REQ-1</StUF:crossRefnummer>', $answer['body']);
	}//end testCaseMutationIsAcceptedAsBv03()

	/**
	 * A refused kennisgeving is a Fo03 carrying the app's reason, HTTP 400.
	 *
	 * @return void
	 */
	public function testARefusedMutationIsAFo03(): void {
		$this->owner = 'refuse';
		$answer      = $this->receiver()->receive(rawXml: self::zakLk01(), sourceId: 'zsh', service: 'cases');

		$this->assertSame(400, $answer['statusCode']);
		$this->assertStringContainsString('Fo03Bericht', $answer['body']);
		$this->assertStringContainsString('Zaaktype is not mapped', $answer['body']);
	}//end testARefusedMutationIsAFo03()

	/**
	 * An unanswered kennisgeving is a Fo03 with StUF055, not a Bv03.
	 *
	 * @return void
	 */
	public function testAnUnansweredMutationIsAFo03(): void {
		$this->owner = 'silent';
		$answer      = $this->receiver()->receive(rawXml: self::zakLk01(), sourceId: 'zsh', service: 'cases');

		$this->assertSame(500, $answer['statusCode']);
		$this->assertStringContainsString('Fo03Bericht', $answer['body']);
		$this->assertStringContainsString('StUF055', $answer['body']);
		$this->assertStringNotContainsString('Bv03Bericht', $answer['body']);
	}//end testAnUnansweredMutationIsAFo03()

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
		$this->assertStringContainsString('Bv03Bericht', $answer['body']);
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
	 * A creeerZaak (zakLk01, T) reaches the owning app as a generic create, without StUF names.
	 *
	 * @return void
	 */
	public function testACreateMessageMapsToAGenericCaseOperation(): void {
		$answer = $this->receiver()->receive(rawXml: self::creeerZaak(), sourceId: 'zsh', service: 'cases');

		$this->assertSame(200, $answer['statusCode']);
		$this->assertStringContainsString('Bv03Bericht', $answer['body']);
		$this->assertStringNotContainsString('<StUF:body', $answer['body']);
		$event = $this->events[0];
		$this->assertInstanceOf(StufCaseMutationEvent::class, $event);
		$this->assertSame(
			[
				'action' => 'create',
				'identifier' => 'ZSH-2026-0001',
				'title' => 'Kapvergunning Dorpsstraat 1',
				'description' => 'Twee eiken',
				'startDate' => '2026-10-12',
				'registrationDate' => '2026-10-12',
				'plannedEndDate' => '2026-12-07',
				'caseTypeCode' => 'KAP',
				'caseTypeName' => 'Kapvergunning',
				'initiator' => ['type' => 'person', 'sourceId' => '999993653', 'name' => 'Jan Jansen'],
			],
			$event->getOperation()
		);
	}//end testACreateMessageMapsToAGenericCaseOperation()

	/**
	 * An updateZaak (zakLk01, W) sends the old object and then the new one: the new one counts.
	 *
	 * @return void
	 */
	public function testAnUpdateMessageTakesTheNewObject(): void {
		$body = '<ZKN:zakLk01>' . self::stuurgegevens(code: 'Lk01') . '<ZKN:parameters><StUF:mutatiesoort>W</StUF:mutatiesoort></ZKN:parameters>'
			. '<ZKN:object StUF:entiteittype="ZAK" StUF:verwerkingssoort="W"><ZKN:identificatie>ZSH-2026-0001</ZKN:identificatie>'
			. '<ZKN:omschrijving>Oud</ZKN:omschrijving></ZKN:object>'
			. '<ZKN:object StUF:entiteittype="ZAK" StUF:verwerkingssoort="W"><ZKN:identificatie>ZSH-2026-0001</ZKN:identificatie>'
			. '<ZKN:omschrijving>Nieuw</ZKN:omschrijving><ZKN:einddatumGepland>20261231</ZKN:einddatumGepland>'
			. '<ZKN:isVan StUF:entiteittype="ZAKZKT"><ZKN:gerelateerde StUF:entiteittype="ZKT"><ZKN:code>KAP</ZKN:code></ZKN:gerelateerde></ZKN:isVan>'
			. '</ZKN:object></ZKN:zakLk01>';
		$this->receiver()->receive(rawXml: self::envelope(body: $body), sourceId: 'zsh', service: 'cases');

		$event = $this->events[0];
		$this->assertInstanceOf(StufCaseMutationEvent::class, $event);
		$this->assertSame(
			['action' => 'update', 'identifier' => 'ZSH-2026-0001', 'title' => 'Nieuw', 'plannedEndDate' => '2026-12-31', 'caseTypeCode' => 'KAP'],
			$event->getOperation()
		);
		$this->assertSame('Nieuw', $event->getFields()['omschrijving']);
	}//end testAnUpdateMessageTakesTheNewObject()

	/**
	 * A voegZaakdocumentToe (edcLk01, T) reaches the owning app with the decoded bytes.
	 *
	 * @return void
	 */
	public function testADocumentMessageMapsToAGenericAddDocumentOperation(): void {
		$answer = $this->receiver()->receive(rawXml: self::voegZaakdocumentToe(inhoud: base64_encode('%PDF-1.7 besluit')), sourceId: 'zsh', service: 'cases');

		$this->assertSame(200, $answer['statusCode']);
		$event = $this->events[0];
		$this->assertInstanceOf(StufDocumentMutationEvent::class, $event);
		$this->assertSame(
			[
				'action' => 'add-document',
				'identifier' => 'DOC-1',
				'title' => 'Besluit',
				'description' => 'Het besluit op de aanvraag',
				'documentTypeName' => 'Besluit',
				'format' => 'application/pdf',
				'language' => 'nld',
				'author' => 'A. Ambtenaar',
				'caseIdentifier' => 'ZSH-2026-0001',
				'creationDate' => '2026-10-12',
				'confidentiality' => 'beperkt_openbaar',
				'fileName' => 'besluit.pdf',
				'content' => '%PDF-1.7 besluit',
			],
			$event->getOperation()
		);
		$this->assertArrayNotHasKey('inhoud', $event->getFields());
	}//end testADocumentMessageMapsToAGenericAddDocumentOperation()

	/**
	 * A delete, an end, a document change and broken base64 are refused here: the owning app is never asked.
	 *
	 * @return void
	 */
	public function testAWriteThisAdapterDoesNotCarryOutIsRefusedWithoutAsking(): void {
		$delete = str_replace('verwerkingssoort="W"', 'verwerkingssoort="V"', self::zakLk01());
		$change = str_replace('verwerkingssoort="T"', 'verwerkingssoort="W"', self::edcLk01());
		$broken = self::voegZaakdocumentToe(inhoud: 'geen base64 !!');

		foreach ([$delete, $change, $broken] as $xml) {
			$answer = $this->receiver()->receive(rawXml: $xml, sourceId: 'zsh', service: 'cases');
			$this->assertSame(400, $answer['statusCode']);
			$this->assertStringContainsString('Fo03Bericht', $answer['body']);
			$this->assertStringContainsString('StUF058', $answer['body']);
		}

		$this->assertSame([], $this->events);
	}//end testAWriteThisAdapterDoesNotCarryOutIsRefusedWithoutAsking()

	/**
	 * A write without the source's UsernameToken, or on a source that did not opt in, never reaches the owning app.
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedWriteNeverReachesTheOwningApp(): void {
		$anonymous = str_replace('>secret<', '>guess<', self::creeerZaak());
		foreach ([['zsh', $anonymous], ['closed', self::creeerZaak()], ['tokenless', self::creeerZaak()]] as [$sourceId, $xml]) {
			$answer = $this->receiver()->receive(rawXml: $xml, sourceId: $sourceId, service: 'cases');
			$this->assertSame(401, $answer['statusCode']);
			$this->assertStringContainsString('Fo03Bericht', $answer['body']);
			$this->assertStringContainsString('StUF063', $answer['body']);
		}

		$this->assertSame([], $this->events);
	}//end testAnUnauthenticatedWriteNeverReachesTheOwningApp()

	/**
	 * A write handed over from a sibling app's own URL is refused: writes come in on integriq's route only.
	 *
	 * @return void
	 */
	public function testAForwardedWriteIsRefusedAndAForwardedQueryIsNot(): void {
		foreach ([self::creeerZaak(), self::zakLk01(), self::voegZaakdocumentToe(inhoud: base64_encode('x'))] as $xml) {
			$answer = $this->receiver()->receiveForwarded(rawXml: $xml, sourceId: 'zsh', service: 'cases');
			$this->assertSame(403, $answer['statusCode']);
			$this->assertStringContainsString('Fo03Bericht', $answer['body']);
		}

		$this->assertSame([], $this->events);

		$query = $this->receiver()->receiveForwarded(rawXml: self::zakLv01(), sourceId: 'zsh', service: 'cases');
		$this->assertSame(200, $query['statusCode']);
		$this->assertCount(1, $this->events);
	}//end testAForwardedWriteIsRefusedAndAForwardedQueryIsNot()

	/**
	 * Every refusal reaches the caller as a StUF fault with a refusing HTTP status, through the middleware.
	 *
	 * The response is read after Nextcloud's OCSMiddleware has seen it, which is
	 * where a 401 or 403 from an OCSController turns into a 200.
	 *
	 * @return void
	 */
	public function testARefusedWriteReachesTheCallerAsAFaultNotAs200(): void {
		$cases = [
			'refuse' => [self::creeerZaak(), 400],
			'forbidden' => [self::creeerZaak(), 403],
			'not-found' => [self::zakLk01(), 404],
			'conflict' => [self::creeerZaak(), 409],
			'silent' => [self::creeerZaak(), 500],
			'throw' => [self::voegZaakdocumentToe(inhoud: base64_encode('x')), 500],
			'unauthenticated' => [str_replace('>secret<', '>guess<', self::creeerZaak()), 401],
		];
		foreach ($cases as $owner => [$xml, $status]) {
			$this->owner = $owner;
			$delivered   = $this->deliver(rawXml: $xml);

			$this->assertSame($status, $delivered->getStatus(), $owner);
			$this->assertStringContainsString('Fo03Bericht', (string)$delivered->render(), $owner);
			$this->assertStringContainsString('<faultcode>', (string)$delivered->render(), $owner);
			$this->assertStringNotContainsString('Bv03Bericht', (string)$delivered->render(), $owner);
		}

		$this->owner = 'answer';
		$accepted    = $this->deliver(rawXml: self::creeerZaak());
		$this->assertSame(200, $accepted->getStatus());
		$this->assertStringContainsString('Bv03Bericht', (string)$accepted->render());
	}//end testARefusedWriteReachesTheCallerAsAFaultNotAs200()

	/**
	 * Post one message to the real controller and read the response after the OCS middleware.
	 *
	 * Inside a Nextcloud server (CI) the real OCSMiddleware runs. Without one
	 * (the bare unit run) its rule, as measured live on 4 Oct 2026, is applied
	 * instead: a 401 or 403 from an OCSController becomes a 200.
	 *
	 * @param string $rawXml The request body.
	 *
	 * @return Response The response the caller gets.
	 */
	private function deliver(string $rawXml): Response {
		$request    = $this->createMock(IRequest::class);
		$controller = new class('integriq', $request, $this->receiver(), $rawXml) extends StufProviderController {
			/**
			 * Constructor.
			 *
			 * @param string               $appName  The app.
			 * @param IRequest             $request  The request.
			 * @param StufProviderReceiver $receiver The receiver.
			 * @param string               $rawBody  The body under test.
			 */
			public function __construct(string $appName, IRequest $request, StufProviderReceiver $receiver, private readonly string $rawBody) {
				parent::__construct(appName: $appName, request: $request, receiver: $receiver);
			}

			/**
			 * The body under test.
			 *
			 * @return string The body.
			 */
			protected function readRawBody(): string {
				return $this->rawBody;
			}
		};
		$this->assertInstanceOf(Controller::class, $controller);
		$this->assertNotInstanceOf(OCSController::class, $controller, 'An OCSController would have its 401 and 403 turned into 200.');

		$response = $controller->cases(sourceId: 'zsh');
		if (class_exists(\OC\AppFramework\Middleware\OCSMiddleware::class) === true) {
			return (new \OC\AppFramework\Middleware\OCSMiddleware($request))->afterController($controller, 'cases', $response);
		}

		if ($controller instanceof OCSController && in_array($response->getStatus(), [401, 403], true) === true) {
			$response->setStatus(200);
		}

		return $response;
	}//end deliver()

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
			$this->logger,
			$this->verifier()
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

		if (in_array($this->owner, ['forbidden', 'not-found', 'conflict'], true) === true && $event instanceof StufProviderQueryEvent) {
			$event->refuse(appId: 'dossiq', reason: 'Nee: ' . $this->owner, kind: $this->owner);
			return;
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

			if ($this->owner === 'many') {
				$cases = [];
				for ($i = 1; $i <= 150; $i++) {
					$cases[] = ['zaakidentificatie' => 'ZAAK-' . $i, 'omschrijving' => 'Zaak', 'zaaktype' => 'KAP'];
				}
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
	 * @param string      $criteria The criteria inside `gelijk`.
	 *
	 * @return string The envelope.
	 */
	private static function zakLv01(?string $password='secret', string $criteria='<ZKN:identificatie>ZAAK-2026-7</ZKN:identificatie>'): string {
		return self::envelope(
			body: '<ZKN:zakLv01>' . self::stuurgegevens(code: 'Lv01') . '<ZKN:gelijk StUF:entiteittype="ZAK">'
				. $criteria . '</ZKN:gelijk></ZKN:zakLv01>',
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
	 * A creeerZaak_Lk01 as a StUF-ZDS 1.2 client sends it.
	 *
	 * @return string The envelope.
	 */
	private static function creeerZaak(): string {
		return self::envelope(
			body: '<ZKN:zakLk01>' . self::stuurgegevens(code: 'Lk01') . '<ZKN:parameters><StUF:mutatiesoort>T</StUF:mutatiesoort>'
				. '<StUF:indicatorOvername>V</StUF:indicatorOvername></ZKN:parameters>'
				. '<ZKN:object StUF:entiteittype="ZAK" StUF:verwerkingssoort="T"><ZKN:identificatie>ZSH-2026-0001</ZKN:identificatie>'
				. '<ZKN:omschrijving>Kapvergunning Dorpsstraat 1</ZKN:omschrijving><ZKN:toelichting>Twee eiken</ZKN:toelichting>'
				. '<ZKN:startdatum>20261012</ZKN:startdatum><ZKN:registratiedatum>20261012</ZKN:registratiedatum>'
				. '<ZKN:einddatumGepland>20261207</ZKN:einddatumGepland><ZKN:einddatum StUF:noValue="geenWaarde" xsi:nil="true"'
				. ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>'
				. '<ZKN:isVan StUF:entiteittype="ZAKZKT" StUF:verwerkingssoort="T"><ZKN:gerelateerde StUF:entiteittype="ZKT" StUF:verwerkingssoort="I">'
				. '<ZKN:omschrijving>Kapvergunning</ZKN:omschrijving><ZKN:code>KAP</ZKN:code></ZKN:gerelateerde></ZKN:isVan>'
				. '<ZKN:heeftAlsInitiator StUF:entiteittype="ZAKBTRINI" StUF:verwerkingssoort="T"><ZKN:gerelateerde>'
				. '<ZKN:natuurlijkPersoon StUF:entiteittype="NPS" StUF:verwerkingssoort="I"><BG:inp.bsn>999993653</BG:inp.bsn>'
				. '<BG:geslachtsnaam>Jansen</BG:geslachtsnaam><BG:voornamen>Jan</BG:voornamen></ZKN:natuurlijkPersoon>'
				. '</ZKN:gerelateerde></ZKN:heeftAlsInitiator></ZKN:object></ZKN:zakLk01>'
		);
	}//end creeerZaak()

	/**
	 * A voegZaakdocumentToe_Lk01 as a StUF-ZDS 1.2 client sends it.
	 *
	 * @param string $inhoud The inhoud element's text.
	 *
	 * @return string The envelope.
	 */
	private static function voegZaakdocumentToe(string $inhoud): string {
		return self::envelope(
			body: '<ZKN:edcLk01>' . self::stuurgegevens(code: 'Lk01') . '<ZKN:parameters><StUF:mutatiesoort>T</StUF:mutatiesoort></ZKN:parameters>'
				. '<ZKN:object StUF:entiteittype="EDC" StUF:verwerkingssoort="T"><ZKN:identificatie>DOC-1</ZKN:identificatie>'
				. '<ZKN:dct.omschrijving>Besluit</ZKN:dct.omschrijving><ZKN:creatiedatum>20261012</ZKN:creatiedatum>'
				. '<ZKN:titel>Besluit</ZKN:titel><ZKN:beschrijving>Het besluit op de aanvraag</ZKN:beschrijving>'
				. '<ZKN:formaat>application/pdf</ZKN:formaat><ZKN:taal>nld</ZKN:taal>'
				. '<ZKN:vertrouwelijkAanduiding>BEPERKT OPENBAAR</ZKN:vertrouwelijkAanduiding><ZKN:auteur>A. Ambtenaar</ZKN:auteur>'
				. '<ZKN:inhoud StUF:bestandsnaam="besluit.pdf">' . $inhoud . '</ZKN:inhoud>'
				. '<ZKN:isRelevantVoor StUF:entiteittype="EDCZAK" StUF:verwerkingssoort="T"><ZKN:gerelateerde StUF:entiteittype="ZAK" StUF:verwerkingssoort="I">'
				. '<ZKN:identificatie>ZSH-2026-0001</ZKN:identificatie></ZKN:gerelateerde></ZKN:isRelevantVoor>'
				. '</ZKN:object></ZKN:edcLk01>'
		);
	}//end voegZaakdocumentToe()

	/**
	 * An npsLv01 asking for one BSN.
	 *
	 * @param string $criteria The criteria inside `gelijk`.
	 *
	 * @return string The envelope.
	 */
	private static function npsLv01(string $criteria='<BG:inp.bsn>999993653</BG:inp.bsn>'): string {
		return self::envelope(
			body: '<BG:npsLv01>' . self::stuurgegevens(code: 'Lv01') . '<BG:gelijk StUF:entiteittype="NPS">'
				. $criteria . '</BG:gelijk></BG:npsLv01>'
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
