<?php

/**
 * Unit tests for StufZknClient's ZDS exchange refusals, config schema and token decryption.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Service\Mtls\ClientAuthenticationSchema;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\Stuf\StufXmlParser;
use OCA\Integriq\Service\StufZkn\SoapCall;
use OCA\Integriq\Service\StufZkn\StufZknClient;
use OCA\Integriq\Util\SafeXmlParser;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * How exchange() reports a case system's refusal, what the config schema declares,
 * and what happens when the stored token cannot be decrypted.
 */
#[CoversClass(StufZknClient::class)]
#[UsesClass(SoapCall::class)]
#[UsesClass(StufXmlParser::class)]
#[UsesClass(MtlsConfigResolver::class)]
#[UsesClass(MtlsTransportService::class)]
#[UsesClass(MtlsTransportOptionsBuilder::class)]
#[UsesClass(ClientAuthenticationSchema::class)]
#[UsesClass(StufZknProviderException::class)]
#[UsesClass(SafeXmlParser::class)]
class StufZknClientExchangeRefusalTest extends TestCase {

	/**
	 * The requests the mock handler received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $history = [];

	/**
	 * @var ICrypto&MockObject
	 */
	private $crypto;

	/**
	 * A token-mode configuration.
	 *
	 * @var array<string,mixed>
	 */
	private array $configuration = [
		'baseUrl' => 'https://zds.example.nl/services',
		'authentication' => ['encryptedToken' => 'ciphertext-blob'],
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->crypto = $this->createMock(ICrypto::class);

	}//end setUp()

	/**
	 * Build a client over a Guzzle mock handler.
	 *
	 * @param array<int,Response> $responses The queued responses.
	 *
	 * @return StufZknClient
	 */
	private function buildClient(array $responses): StufZknClient {
		$stack = HandlerStack::create(new MockHandler($responses));
		$this->history = [];
		$stack->push(Middleware::history($this->history));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		return new StufZknClient(
			new Client(['handler' => $stack]),
			$this->crypto,
			$l10n,
			$logger,
			new MtlsConfigResolver($this->crypto),
			new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger)
		);

	}//end buildClient()

	/**
	 * Run exchange() and return the refusal message it throws.
	 *
	 * @param Response $response The case system's answer.
	 *
	 * @return string
	 */
	private function refusalOf(Response $response): string {
		$this->crypto->method('decrypt')->willReturn('raw-token');
		$client = $this->buildClient([$response]);

		try {
			$client->exchange($this->configuration, '"http://www.egem.nl/StUF/sector/zkn/0310/voegZaakdocumentToe_Lk01"', '<env/>');
		} catch (StufZknProviderException $exception) {
			return $exception->getMessage();
		}

		$this->fail('exchange() accepted a non-2xx answer.');

	}//end refusalOf()

	/**
	 * A 2xx answer comes back verbatim, posted to the call's own address with its SOAPAction.
	 *
	 * @return void
	 */
	public function testExchangeReturnsTheBodyAndPostsToTheGivenUrl(): void {
		$this->crypto->method('decrypt')->willReturn('raw-token');
		$client = $this->buildClient([new Response(200, [], '<Du02>doc-1</Du02>')]);

		$body = $client->exchange($this->configuration, '"action"', '<env/>', 'https://zds.example.nl/other');

		$this->assertSame('<Du02>doc-1</Du02>', $body);
		$this->assertCount(1, $this->history);
		$request = $this->history[0]['request'];
		$this->assertSame('https://zds.example.nl/other', (string)$request->getUri());
		$this->assertSame('"action"', $request->getHeaderLine('SOAPAction'));
		$this->assertSame('Bearer raw-token', $request->getHeaderLine('Authorization'));

	}//end testExchangeReturnsTheBodyAndPostsToTheGivenUrl()

	/**
	 * A Fo03 refusal is reported with its code, omschrijving and details.
	 *
	 * @return void
	 */
	public function testExchangeReportsTheFo03CodeAndOmschrijving(): void {
		$fault = '<?xml version="1.0"?>'
			. '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" xmlns:st="http://www.egem.nl/StUF/StUF0301">'
			. '<s:Body><s:Fault><faultstring>Proces voor afhandelen bericht geeft fout</faultstring><detail>'
			. '<st:Fo03Bericht><st:body><st:code>StUF058</st:code><st:omschrijving> Zaak onbekend </st:omschrijving>'
			. '<st:details>ZAAK-9</st:details></st:body></st:Fo03Bericht>'
			. '</detail></s:Fault></s:Body></s:Envelope>';

		$this->assertSame(
			'The case system answered HTTP 500: StUF058 Zaak onbekend ZAAK-9',
			$this->refusalOf(new Response(500, [], $fault))
		);

	}//end testExchangeReportsTheFo03CodeAndOmschrijving()

	/**
	 * Without a Fo03 body, the SOAP faultstring is reported.
	 *
	 * @return void
	 */
	public function testExchangeFallsBackToTheFaultstring(): void {
		$fault = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault>'
			. '<faultcode>s:Server</faultcode><faultstring> Service unavailable </faultstring>'
			. '</s:Fault></s:Body></s:Envelope>';

		$this->assertSame(
			'The case system answered HTTP 503: Service unavailable',
			$this->refusalOf(new Response(503, [], $fault))
		);

	}//end testExchangeFallsBackToTheFaultstring()

	/**
	 * A body that is not XML says it carries no fault details.
	 *
	 * @return void
	 */
	public function testExchangeReportsNoDetailsForANonXmlBody(): void {
		$this->assertSame(
			'The case system answered HTTP 502: no fault details',
			$this->refusalOf(new Response(502, [], 'Bad gateway'))
		);

	}//end testExchangeReportsNoDetailsForANonXmlBody()

	/**
	 * XML without a Fo03 body or a faultstring also says it carries no fault details.
	 *
	 * @return void
	 */
	public function testExchangeReportsNoDetailsForXmlWithoutAFault(): void {
		$this->assertSame(
			'The case system answered HTTP 400: no fault details',
			$this->refusalOf(new Response(400, [], '<answer><status>refused</status></answer>'))
		);

	}//end testExchangeReportsNoDetailsForXmlWithoutAFault()

	/**
	 * A token that cannot be decrypted stops the call before anything is sent.
	 *
	 * @return void
	 */
	public function testAnUndecryptableTokenThrowsWithTheCause(): void {
		$this->crypto->method('decrypt')->willThrowException(new RuntimeException('bad mac'));
		$client = $this->buildClient([new Response(200, [], '')]);

		try {
			$client->exchange($this->configuration, '""', '<env/>');
			$this->fail('exchange() sent with an undecryptable token.');
		} catch (StufZknProviderException $exception) {
			$this->assertSame('The stored StUF-ZKN API token could not be decrypted: bad mac', $exception->getMessage());
		}

		$this->assertSame([], $this->history);

	}//end testAnUndecryptableTokenThrowsWithTheCause()

	/**
	 * A custom scheme from the configuration prefixes the decrypted token.
	 *
	 * @return void
	 */
	public function testTheConfiguredSchemePrefixesTheToken(): void {
		$this->crypto->method('decrypt')->with('ciphertext-blob')->willReturn('raw-token');
		$client = $this->buildClient([new Response(200, [], 'ok')]);
		$configuration = $this->configuration;
		$configuration['authentication']['scheme'] = 'Token';

		$client->exchange($configuration, '""', '<env/>');

		$this->assertSame('Token raw-token', $this->history[0]['request']->getHeaderLine('Authorization'));

	}//end testTheConfiguredSchemePrefixesTheToken()

	/**
	 * WS-Security mode sends no Authorization header and does not decrypt a token.
	 *
	 * @return void
	 */
	public function testWsseModeSendsNoAuthorizationHeader(): void {
		$this->crypto->expects($this->never())->method('decrypt');
		$client = $this->buildClient([new Response(200, [], 'ok')]);

		$client->exchange(['baseUrl' => 'https://zds.example.nl/services', 'authentication' => ['mode' => 'wsse']], '""', '<env/>');

		$this->assertFalse($this->history[0]['request']->hasHeader('Authorization'));

	}//end testWsseModeSendsNoAuthorizationHeader()

	/**
	 * The config schema requires authentication and offers the token, mtls and wsse modes.
	 *
	 * @return void
	 */
	public function testConfigSchemaDeclaresAuthenticationModes(): void {
		$schema = $this->buildClient([])->getConfigSchema();

		$this->assertSame('object', $schema['type']);
		$this->assertSame(['authentication'], $schema['required']);
		$this->assertSame(['baseUrl', 'authentication', 'organisatie'], array_keys($schema['properties']));

		$authentication = $schema['properties']['authentication']['properties'];
		$this->assertSame(['mode', 'wsse', 'encryptedToken', 'scheme', 'mtls'], array_keys($authentication));
		$this->assertSame(['token', 'mtls', 'wsse'], $authentication['mode']['enum']);
		$this->assertSame(['username', 'encryptedPassword', 'requireNonce'], array_keys($authentication['wsse']['properties']));
		$this->assertFalse($authentication['wsse']['properties']['requireNonce']['default']);

	}//end testConfigSchemaDeclaresAuthenticationModes()
}//end class
