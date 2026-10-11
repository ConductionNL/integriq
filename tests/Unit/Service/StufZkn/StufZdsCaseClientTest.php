<?php

/**
 * Unit tests for StufZdsCaseClient.
 *
 * Real builder, reader, breaker, credential resolver, transport (Guzzle mock
 * handler) and message log; only OpenRegister, the job list and the event
 * dispatcher are doubles. Every row the log writes is validated against the
 * real `stuf_message` schema in integriq_register.json.
 *
 * Ported from dossiq's StufAdapterServiceReferentienummerTest, extended with the
 * StufOutboundTransport classification it delegated to.
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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\BackgroundJob\StufZdsRetryJob;
use OCA\Integriq\Event\StufZdsCaseConcludedEvent;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClient;
use OCA\Integriq\Service\StufZkn\StufZdsCircuitBreaker;
use OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver;
use OCA\Integriq\Service\StufZkn\StufZdsMessageBuilder;
use OCA\Integriq\Service\StufZkn\StufZdsMessageLog;
use OCA\Integriq\Service\StufZkn\StufZdsOutbound;
use OCA\Integriq\Service\StufZkn\StufZdsResponseReader;
use OCA\Integriq\Service\StufZkn\StufZknClient;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsCaseClient
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsMessageLog
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsOutbound
 * @covers \OCA\Integriq\Service\StufZkn\StufZdsCredentialResolver
 * @covers \OCA\Integriq\Service\StufZkn\StufZknClient
 * @uses   \OCA\Integriq\Service\StufZkn\StufZdsMessageBuilder
 * @uses   \OCA\Integriq\Service\StufZkn\StufZdsResponseReader
 * @uses   \OCA\Integriq\Service\StufZkn\StufZdsCircuitBreaker
 * @uses   \OCA\Integriq\Event\StufZdsCaseConcludedEvent
 */
class StufZdsCaseClientTest extends TestCase {

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
	 * Sent requests.
	 *
	 * @var array
	 */
	private array $history = [];

	/**
	 * Scheduled retries: [class, runAfter, argument].
	 *
	 * @var array
	 */
	private array $scheduled = [];

	/**
	 * Dispatched events.
	 *
	 * @var object[]
	 */
	private array $events = [];

	/**
	 * App config values for the breaker.
	 *
	 * @var array<string,int>
	 */
	private array $config = [];

	/**
	 * The outbound side of the last built client (it owns retry()).
	 *
	 * @var StufZdsOutbound
	 */
	private StufZdsOutbound $outbound;

	/**
	 * Set up the source and the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows      = [];
		$this->history   = [];
		$this->scheduled = [];
		$this->events    = [];
		$this->config    = [];
		$this->sources   = [
			'zsh' => [
				'type' => 'stuf-zkn',
				'configuration' => [
					'provider' => 'rest',
					'baseUrl' => 'https://zsh.example.nl/stuf',
					'ontvangAsynchroonUrl' => 'https://zsh.example.nl/OntvangAsynchroon',
					'vrijeBerichtenUrl' => 'https://zsh.example.nl/VrijeBerichten',
					'beantwoordVraagUrl' => 'https://zsh.example.nl/BeantwoordVraag',
					'organisatie' => '0363',
					'applicatie' => 'dossiq',
					'ontvangerOrganisatie' => '0363',
					'ontvangerApplicatie' => 'ZSH',
					'authentication' => ['mode' => 'wsse', 'wsse' => ['username' => 'zds', 'encryptedPassword' => 'cipher']],
					'freeMessageTemplates' => [['name' => 'meldBesluit', 'verplichteVelden' => ['besluit']]],
				],
			],
		];
	}//end setUp()

	/**
	 * Build the client over a queue of answers.
	 *
	 * @param array $answers Guzzle responses or exceptions.
	 *
	 * @return StufZdsCaseClient The client.
	 */
	private function client(array $answers): StufZdsCaseClient {
		$stack = HandlerStack::create(new MockHandler($answers));
		$stack->push(Middleware::history($this->history));

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('plain-password');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

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
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true) {
				$this->assertSame('stuf_message', $schema);
				$this->assertFalse($_rbac);
				$this->assertSame([], self::schemaErrors($object), 'The row is one the stuf_message schema accepts');
				$uuid = $uuid ?? ('msg-' . (count($this->rows) + 1));
				$this->rows[$uuid] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default=0): int => ($this->config[$key] ?? $default));
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, $argument): void {
				$this->scheduled[] = [$job, $runAfter, $argument];
			}
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->events[] = $event;
			}
		);

		$breaker        = new StufZdsCircuitBreaker($appConfig, $logger);
		$log            = new StufZdsMessageLog($objects);
		$this->outbound = new StufZdsOutbound(
			new StufZknClient(new Client(['handler' => $stack]), $crypto, $l10n, $logger, new MtlsConfigResolver($crypto), new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger)),
			new StufZdsResponseReader(),
			$breaker,
			$log,
			$jobs,
			$dispatcher,
			$logger
		);

		return new StufZdsCaseClient(new StufZdsMessageBuilder(), $this->outbound, new StufZdsResponseReader(), $breaker, new StufZdsCredentialResolver($crypto), $log);
	}//end client()

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

	/**
	 * A Bv03 answer.
	 *
	 * @param string $identificatie An identificatie to include, or ''.
	 *
	 * @return Response The answer.
	 */
	private static function bevestiging(string $identificatie=''): Response {
		$object = '';
		if ($identificatie !== '') {
			$object = '<zkn:zaak><zkn:identificatie>' . $identificatie . '</zkn:identificatie></zkn:zaak>';
		}

		return new Response(200, [], '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stuf="http://www.egem.nl/StUF/StUF0301" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"><soapenv:Body><stuf:Bv03Bericht><stuf:stuurgegevens><stuf:crossRefnummer>X</stuf:crossRefnummer></stuf:stuurgegevens>' . $object . '</stuf:Bv03Bericht></soapenv:Body></soapenv:Envelope>');
	}//end bevestiging()

	/**
	 * A Fo03 answer with a code.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $code   The StUF code.
	 *
	 * @return Response The answer.
	 */
	private static function fout(int $status, string $code): Response {
		return new Response($status, [], '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:stuf="http://www.egem.nl/StUF/StUF0301"><soapenv:Body><stuf:Fo03Bericht><stuf:body><stuf:code>' . $code . '</stuf:code><stuf:omschrijving>Fout</stuf:omschrijving></stuf:body></stuf:Fo03Bericht></soapenv:Body></soapenv:Envelope>');
	}//end fout()

	/**
	 * A confirmed creeerZaak: sent row, envelope erased, referentienummer reported and in the envelope, conclusion announced.
	 *
	 * @return void
	 */
	public function testConfirmedCreeerZaakIsSentAndConcluded(): void {
		$result = $this->client([self::bevestiging('0363-Z-1')])->creeerZaak('zsh', ['omschrijving' => 'Melding'], 'Melding openbare ruimte', ['sourceApp' => 'dossiq', 'correlationId' => 'case-1']);

		$this->assertTrue($result['success']);
		$this->assertFalse($result['retrying']);
		$this->assertSame('0363-Z-1', $result['caseIdentification']);
		$this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $result['referentienummer']);

		$request = $this->history[0]['request'];
		$this->assertSame('https://zsh.example.nl/OntvangAsynchroon', (string)$request->getUri());
		$this->assertSame('"http://www.egem.nl/StUF/sector/zkn/0310/creeerZaak_Lk01"', $request->getHeaderLine('SOAPAction'));
		$this->assertFalse($request->hasHeader('Authorization'), 'wsse mode sends no bearer header');
		$body = (string)$request->getBody();
		$this->assertStringContainsString('<stuf:referentienummer>' . $result['referentienummer'] . '</stuf:referentienummer>', $body);
		$this->assertStringContainsString('<wsse:Password>plain-password</wsse:Password>', $body);

		$row = $this->rows[$result['messageId']];
		$this->assertSame('sent', $row['status']);
		$this->assertSame('zakLk01', $row['berichttype']);
		$this->assertSame('creeerZaak', $row['operation']);
		$this->assertSame('0363-Z-1', $row['caseIdentification']);
		$this->assertArrayNotHasKey('envelope', $row, 'A concluded row keeps no envelope');

		$this->assertCount(1, $this->events);
		$this->assertInstanceOf(StufZdsCaseConcludedEvent::class, $this->events[0]);
		$this->assertSame('case-1', $this->events[0]->getCorrelationId());
		$this->assertTrue($this->events[0]->getResult()['success']);
	}//end testConfirmedCreeerZaakIsSentAndConcluded()

	/**
	 * A 503 is retried: row retrying with the envelope, a retry scheduled after 5 s, nothing announced yet.
	 *
	 * @return void
	 */
	public function testTransientFailureSchedulesARetryAndKeepsTheEnvelope(): void {
		$before = time();
		$result = $this->client([new Response(503, [], '')])->actualiseerZaak('zsh', 'Z-1', ['einddatum' => '20261231'], ['sourceApp' => 'dossiq']);

		$this->assertFalse($result['success']);
		$this->assertTrue($result['retrying']);
		$row = $this->rows[$result['messageId']];
		$this->assertSame('retrying', $row['status']);
		$this->assertSame(1, $row['attempts']);
		$this->assertStringContainsString('<zkn:einddatum>20261231</zkn:einddatum>', $row['envelope']);
		$this->assertSame(StufZdsRetryJob::class, $this->scheduled[0][0]);
		$this->assertGreaterThanOrEqual($before + 5, $this->scheduled[0][1]);
		$this->assertSame(['messageId' => $result['messageId']], $this->scheduled[0][2]);
		$this->assertSame([], $this->events);
	}//end testTransientFailureSchedulesARetryAndKeepsTheEnvelope()

	/**
	 * The retry resends the same envelope; its success concludes the row and announces it.
	 *
	 * @return void
	 */
	public function testRetryResendsTheSameEnvelopeAndConcludes(): void {
		$client = $this->client([new ConnectException('Connection timed out', new Request('POST', 'https://zsh.example.nl')), self::bevestiging()]);
		$first  = $client->vrijBericht('zsh', 'meldBesluit', ['besluit' => 'toegekend'], ['sourceApp' => 'dossiq', 'correlationId' => 'c-9']);
		$this->assertTrue($first['retrying']);
		$this->assertSame('TIMEOUT', $first['fout']['code']);

		$this->outbound->retry($first['messageId']);

		$this->assertSame((string)$this->history[0]['request']->getBody(), (string)$this->history[1]['request']->getBody());
		$this->assertSame('"http://www.egem.nl/StUF/sector/zkn/0310/meldBesluit_Du01"', $this->history[1]['request']->getHeaderLine('SOAPAction'));
		$row = $this->rows[$first['messageId']];
		$this->assertSame('sent', $row['status']);
		$this->assertSame(2, $row['attempts']);
		$this->assertArrayNotHasKey('envelope', $row);
		$this->assertSame('c-9', $this->events[0]->getCorrelationId());
		$this->assertSame(2, $this->events[0]->getAttempts());
	}//end testRetryResendsTheSameEnvelopeAndConcludes()

	/**
	 * A permanent StUF fout fails at once: no retry, conclusion announced.
	 *
	 * @return void
	 */
	public function testPermanentFoutFailsAtOnce(): void {
		$result = $this->client([self::fout(500, 'StUF055')])->creeerZaak('zsh', [], 'Melding');
		$this->assertFalse($result['success']);
		$this->assertFalse($result['retrying']);
		$this->assertSame('StUF055', $result['fout']['code']);
		$this->assertSame('failed', $this->rows[$result['messageId']]['status']);
		$this->assertSame('StUF055', $this->rows[$result['messageId']]['faultCode']);
		$this->assertSame([], $this->scheduled, 'A 5xx carrying a permanent fout is not retried');
		$this->assertFalse($this->events[0]->getResult()['success']);
	}//end testPermanentFoutFailsAtOnce()

	/**
	 * After the fourth retry the message fails for good.
	 *
	 * @return void
	 */
	public function testRetriesStopAfterTheSchedule(): void {
		$client = $this->client(array_fill(0, 5, new Response(502, [], '')));
		$first  = $client->creeerZaak('zsh', [], 'Melding');
		for ($i = 0; $i < 3; $i++) {
			$this->outbound->retry($first['messageId']);
		}

		// Four failures opened the circuit: the next retry is not an attempt, it waits out the cooldown.
		$this->outbound->retry($first['messageId']);
		$this->assertCount(4, $this->history);
		$this->assertSame('retrying', $this->rows[$first['messageId']]['status']);

		foreach (array_keys($this->config) as $key) {
			if (str_starts_with($key, 'stuf.zds.o.') === true) {
				$this->config[$key] = (time() - StufZdsCircuitBreaker::COOLDOWN_SECONDS);
			}
		}

		$this->outbound->retry($first['messageId']);

		$this->assertCount(5, $this->history);
		foreach ([5, 30, 120, 600, StufZdsCircuitBreaker::COOLDOWN_SECONDS] as $index => $delay) {
			$this->assertEqualsWithDelta(time() + $delay, $this->scheduled[$index][1], 2);
		}

		$this->assertCount(5, $this->scheduled, 'No retry after the fifth attempt');
		$this->assertSame('failed', $this->rows[$first['messageId']]['status']);
		$this->assertSame(5, $this->rows[$first['messageId']]['attempts']);
		$this->assertCount(1, $this->events);
	}//end testRetriesStopAfterTheSchedule()

	/**
	 * Four failures open the circuit; the next request sends nothing.
	 *
	 * @return void
	 */
	public function testAnOpenCircuitSendsNothing(): void {
		$client = $this->client(array_fill(0, 4, self::fout(400, 'StUF055')));
		for ($i = 0; $i < 4; $i++) {
			$client->creeerZaak('zsh', [], 'Melding');
		}

		$result = $client->creeerZaak('zsh', [], 'Melding');
		$this->assertSame('CIRCUIT_OPEN', $result['fout']['code']);
		$this->assertCount(4, $this->history);
	}//end testAnOpenCircuitSendsNothing()

	/**
	 * With caseIdentificationStrategy vooraf, the identificatie is asked first and used in the Lk01.
	 *
	 * @return void
	 */
	public function testVoorafAllocatesTheIdentificatieFirst(): void {
		$this->sources['zsh']['configuration']['caseIdentificationStrategy'] = 'vooraf';
		$du02   = new Response(200, [], '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"><soapenv:Body><zkn:genereerZaakIdentificatie_Du02><zkn:zaak><zkn:identificatie>PRE-1</zkn:identificatie></zkn:zaak></zkn:genereerZaakIdentificatie_Du02></soapenv:Body></soapenv:Envelope>');
		$result = $this->client([$du02, self::bevestiging()])->creeerZaak('zsh', [], 'Melding');

		$this->assertSame('https://zsh.example.nl/VrijeBerichten', (string)$this->history[0]['request']->getUri());
		$this->assertStringContainsString('<zkn:identificatie>PRE-1</zkn:identificatie>', (string)$this->history[1]['request']->getBody());
		$this->assertSame('PRE-1', $result['caseIdentification']);
	}//end testVoorafAllocatesTheIdentificatieFirst()

	/**
	 * geefZaakdetails reads the zaak from the La01 and concludes its row at once.
	 *
	 * @return void
	 */
	public function testGeefZaakdetailsReturnsTheZaak(): void {
		$la01   = new Response(200, [], '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"><soapenv:Body><zkn:zakLa01><zkn:antwoord><zkn:object><zkn:identificatie>Z-5</zkn:identificatie><zkn:omschrijving>Klacht</zkn:omschrijving></zkn:object></zkn:antwoord></zkn:zakLa01></soapenv:Body></soapenv:Envelope>');
		$result = $this->client([$la01])->geefZaakdetails('zsh', 'Z-5', ['omschrijving']);

		$this->assertTrue($result['success']);
		$this->assertSame('Klacht', $result['case']['omschrijving']);
		$this->assertSame('https://zsh.example.nl/BeantwoordVraag', (string)$this->history[0]['request']->getUri());
		$this->assertSame('sent', $this->rows[$result['messageId']]['status']);
		$this->assertArrayNotHasKey('envelope', $this->rows[$result['messageId']]);
		$this->assertSame([], $this->events, 'A question is answered in the request; nothing to announce');
	}//end testGeefZaakdetailsReturnsTheZaak()

	/**
	 * A Du02 without identificatie is a failure, not an empty success.
	 *
	 * @return void
	 */
	public function testGenereerZaakIdentificatieWithoutAnIdentificatieFails(): void {
		$result = $this->client([self::bevestiging()])->genereerZaakIdentificatie('zsh');
		$this->assertFalse($result['success']);
		$this->assertSame('NO_IDENTIFICATION', $result['fout']['code']);
	}//end testGenereerZaakIdentificatieWithoutAnIdentificatieFails()

	/**
	 * A source in log mode is refused before anything is built or sent.
	 *
	 * @return void
	 */
	public function testALogModeSourceIsRefused(): void {
		$this->sources['zsh']['configuration']['provider'] = 'log';
		try {
			$this->client([])->creeerZaak('zsh', [], 'Melding');
			$this->fail('A log-mode source must be refused');
		} catch (StufZknProviderException $exception) {
			$this->assertSame([], $this->rows);
		}
	}//end testALogModeSourceIsRefused()

	/**
	 * An unregistered vrij bericht is refused.
	 *
	 * @return void
	 */
	public function testAnUnregisteredVrijBerichtIsRefused(): void {
		$this->expectException(StufZknTranslationException::class);
		$this->client([])->vrijBericht('zsh', 'onbekend', []);
	}//end testAnUnregisteredVrijBerichtIsRefused()

	/**
	 * A plain-HTTP address is refused by the transport: a permanent failure, nothing sent.
	 *
	 * @return void
	 */
	public function testAPlainHttpAddressIsNeverSentTo(): void {
		$this->sources['zsh']['configuration']['ontvangAsynchroonUrl'] = 'http://zsh.example.nl/OntvangAsynchroon';
		$result = $this->client([])->creeerZaak('zsh', [], 'Melding');
		$this->assertSame('TRANSPORT_NON_HTTPS', $result['fout']['code']);
		$this->assertFalse($result['retrying']);
		$this->assertSame([], $this->history);
	}//end testAPlainHttpAddressIsNeverSentTo()
}//end class
