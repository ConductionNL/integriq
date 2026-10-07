<?php

/**
 * Integriq — the WUS subscription check and the ebMS adapter client, on the wire.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Berichtenbox;

use DOMDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxBatch;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientHttp;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxException;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxValidatieClient;
use OCA\Integriq\Adapters\Berichtenbox\EbmsAdapterClient;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Tests\Unit\Service\Lti\Support\AesTestCrypto;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-DPA-004, REQ-DPA-008 and REQ-DPA-009, below the provider.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
 */
class BerichtenboxTransportClientsTest extends TestCase {
	private const CONTRACT = __DIR__ . '/../../../../lib/Adapters/Berichtenbox/Logius';

	/** @var array<int,array<string,mixed>> */
	private array $history = [];

	/**
	 * A guard that allows the test hosts.
	 *
	 * @return EgressGuard
	 */
	private function guard(): EgressGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('ebms,wus.example');

		return new EgressGuard($appConfig);
	}//end guard()

	/**
	 * A Guzzle client answering canned responses and recording requests.
	 *
	 * @param array<int,Response> $responses The answers.
	 *
	 * @return Client
	 */
	private function http(array $responses): Client {
		$stack = HandlerStack::create(new MockHandler($responses));
		$stack->push(Middleware::history($this->history));

		return new Client(['handler' => $stack]);
	}//end http()

	/**
	 * A WUS client whose transport answers one canned response.
	 *
	 * @param Response $response The answer.
	 * @param array<int,mixed> $captured Receives the request options.
	 *
	 * @return BerichtenboxValidatieClient
	 */
	private function wus(Response $response, array &$captured): BerichtenboxValidatieClient {
		$resolver = $this->createMock(MtlsConfigResolver::class);
		$resolver->method('resolve')->willReturn(new MtlsCertificateBundle('CERT', 'KEY', null, null));
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->method('request')->willReturnCallback(
			function (Client $client, string $method, string $url, array $options, MtlsCertificateBundle $bundle) use ($response, &$captured): Response {
				$captured = [$method, $url, $options, $bundle];
				return $response;
			}
		);

		return new BerichtenboxValidatieClient(new Client(), $resolver, $transport, $this->guard());
	}//end wus()

	/**
	 * The SOAP request validates against the vendored WSDL types, with the remote RDW imports mapped to the local files.
	 *
	 * @return void
	 */
	public function testTheSubscriptionRequestValidatesAgainstTheWsdlTypes(): void {
		$captured = [];
		$envelope = $this->wus(new Response(200), $captured)->envelope(['999993653', '999990019'], 'BESLUIT', '00000001234567890000');

		$document = new DOMDocument();
		$document->loadXML($envelope);
		$operation = new DOMDocument();
		$operation->appendChild($operation->importNode($document->getElementsByTagNameNS(BerichtenboxValidatieClient::NS_SERVICE, 'ValidateAbonnementen')->item(0), true));

		libxml_set_external_entity_loader(
			static function (?string $public, string $system): ?string {
				if (preg_match('/\?xsd=(xsd\d)$/', $system, $match) === 1) {
					return self::CONTRACT . '/BerichtenboxValidatieService/' . $match[1] . '.xsd';
				}

				return $system;
			}
		);
		try {
			$this->assertTrue($operation->schemaValidate(self::CONTRACT . '/BerichtenboxValidatieService/xsd0.xsd'));
		} finally {
			libxml_set_external_entity_loader(null);
		}
	}//end testTheSubscriptionRequestValidatesAgainstTheWsdlTypes()

	/**
	 * The check goes over the mTLS transport, with the WSDL's SOAPAction, unsigned, and reads every answer.
	 *
	 * @return void
	 */
	public function testTheSubscriptionCheckReadsEveryAnswer(): void {
		$captured = [];
		$answer = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><ValidateAbonnementenResponse xmlns="http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/2009/01">'
			. '<ValidateAbonnementenResult xmlns:a="http://schemas.rdw.nl/GEB/BerichtenboxValidatieService/Types/2009/01">'
			. '<a:Abonnement><a:klant xmlns:b="http://schemas.rdw.nl/GEB/Shared/Types/2009/01"><b:Key>999993653</b:Key><b:Rol>Burger</b:Rol></a:klant><a:isBerichtSturen>true</a:isBerichtSturen></a:Abonnement>'
			. '<a:Abonnement><a:klant xmlns:b="http://schemas.rdw.nl/GEB/Shared/Types/2009/01"><b:Key>999990019</b:Key><b:Rol>Burger</b:Rol></a:klant><a:isBerichtSturen>false</a:isBerichtSturen></a:Abonnement>'
			. '</ValidateAbonnementenResult></ValidateAbonnementenResponse></s:Body></s:Envelope>';

		$answers = $this->wus(new Response(200, [], $answer), $captured)->check(
			['999993653', '999990019'],
			'BESLUIT',
			['wusEndpoint' => 'https://wus.example/BerichtenboxValidatieService', 'senderOin' => '00000001234567890000', 'authentication' => ['mode' => 'mtls']]
		);

		$this->assertSame(['999993653' => true, '999990019' => false], $answers);
		$this->assertSame('"' . BerichtenboxValidatieClient::SOAP_ACTION . '"', $captured[2]['headers']['SOAPAction']);
		$this->assertStringNotContainsString('Security', $captured[2]['body']);
		$this->assertStringNotContainsString('KEY', $captured[2]['body'], 'The key travels as transport material, never in the request.');
	}//end testTheSubscriptionCheckReadsEveryAnswer()

	/**
	 * A SOAP fault is a refusal with the fault's message.
	 *
	 * @return void
	 */
	public function testASoapFaultIsARefusalWithItsMessage(): void {
		$captured = [];
		$fault = '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body><s:Fault><faultcode>s:Client</faultcode><faultstring>x</faultstring>'
			. '<detail><ApplicationFault xmlns="http://schemas.rdw.nl/GEB/Shared/Types/2009/01"><Message>LeverancierNietGeautoriseerd</Message></ApplicationFault></detail></s:Fault></s:Body></s:Envelope>';

		$this->expectException(BerichtenboxException::class);
		$this->expectExceptionMessage('ApplicationFault LeverancierNietGeautoriseerd');

		$this->wus(new Response(500, [], $fault), $captured)->check(['999993653'], 'BESLUIT', ['wusEndpoint' => 'https://wus.example/x', 'senderOin' => '1']);
	}//end testASoapFaultIsARefusalWithItsMessage()

	/**
	 * An unusable certificate fails closed before anything is sent.
	 *
	 * @return void
	 */
	public function testAnUnusableCertificateFailsClosed(): void {
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->expects($this->never())->method('request');
		$client = new BerichtenboxValidatieClient(new Client(), new MtlsConfigResolver(new AesTestCrypto()), $transport, $this->guard());

		try {
			$client->check(['999993653'], 'BESLUIT', ['wusEndpoint' => 'https://wus.example/x', 'authentication' => ['mode' => 'mtls', 'mtls' => []]]);
			$this->fail('A missing certificate must refuse.');
		} catch (BerichtenboxException $e) {
			$this->assertSame(BerichtenboxException::CODE_NOT_CONFIGURED, $e->getReason());
			$this->assertStringContainsString('PKIoverheid certificate cannot be used', $e->getMessage());
		}
	}//end testAnUnusableCertificateFailsClosed()

	/**
	 * A letter is posted as an ebms-core MessageRequest with the CPA values and the BerichtID as message id.
	 *
	 * @return void
	 */
	public function testALetterIsPostedAsAnEbmsMessageRequest(): void {
		$adapter = new EbmsAdapterClient($this->http([new Response(200, ['Content-Type' => 'text/plain'], 'b1@integriq.berichtenbox')]), $this->guard(), new AesTestCrypto());

		$id = $adapter->send(
			new BerichtenboxBatch('<x/>', 'batch-1', 'b1'),
			['adapterUrl' => 'http://ebms/service/rest/v19/ebms/', 'cpaId' => 'cpa-1', 'senderOin' => 'oin-1', 'toPartyId' => 'logius', 'service' => 'svc']
		);

		$this->assertSame('b1@integriq.berichtenbox', $id);
		$request = $this->history[0]['request'];
		$this->assertSame('http://ebms/service/rest/v19/ebms/messages', (string)$request->getUri());
		$body = json_decode((string)$request->getBody(), true);
		$this->assertSame(
			['cpaId' => 'cpa-1', 'fromPartyId' => 'oin-1', 'toPartyId' => 'logius', 'service' => 'svc', 'action' => 'GLOBE-R-BV-Request', 'conversationId' => 'b1', 'messageId' => 'b1@integriq.berichtenbox'],
			$body['properties']
		);
		$this->assertSame('<x/>', base64_decode($body['dataSources'][0]['content']));
	}//end testALetterIsPostedAsAnEbmsMessageRequest()

	/**
	 * An adapter that answers an error is a transport refusal naming the status.
	 *
	 * @return void
	 */
	public function testAnAdapterErrorIsATransportRefusal(): void {
		$adapter = new EbmsAdapterClient($this->http([new Response(503, [], 'down')]), $this->guard(), new AesTestCrypto());

		$this->expectExceptionMessage('The ebMS adapter answered HTTP 503 to POST messages: down');
		$adapter->send(new BerichtenboxBatch('<x/>', 'b', 'b1'), ['adapterUrl' => 'http://ebms/e', 'cpaId' => 'c']);
	}//end testAnAdapterErrorIsATransportRefusal()

	/**
	 * Results are read once per source per run, gunzipped when compressed, and keyed by BerichtID.
	 *
	 * @return void
	 */
	public function testResultsAreReadOncePerSourceAndKeyedByBerichtId(): void {
		$result = '<?xml version="1.0"?><ns0:BerichtVerwerkResponse xmlns:ns0="http://schemas.rdw.nl/GEB/BerichtVerwerkService/Types/2009/01" xmlns:ns1="http://schemas.rdw.nl/GEB/BerichtVerwerkService/BerichtResultaat/Types/2009/01">'
			. '<BatchInformatie><BerichtLeverancierCode>oin</BerichtLeverancierCode><BatchID>11111111-1111-4111-8111-111111111111</BatchID>'
			. '<TotaalAantalOntvangenBerichten>1</TotaalAantalOntvangenBerichten><AantalBerichtenSuccesvolVerwerkt>1</AantalBerichtenSuccesvolVerwerkt>'
			. '<AantalBerichtenGeenActieveBoxOfGeabonneertOpLeverancier>0</AantalBerichtenGeenActieveBoxOfGeabonneertOpLeverancier><AantalBerichtenMetTechnischProbleem>0</AantalBerichtenMetTechnischProbleem>'
			. '<AantalBerichtenBerichtTypeNietCorrect>0</AantalBerichtenBerichtTypeNietCorrect><AantalBerichtenPublicatieDatumNietCorrect>0</AantalBerichtenPublicatieDatumNietCorrect>'
			. '<AantalBerichtenAanmaakDatumNietCorrect>0</AantalBerichtenAanmaakDatumNietCorrect><DatumOntvangen>2026-10-07T20:00:00Z</DatumOntvangen><DatumVerwerkt>2026-10-07T20:01:00Z</DatumVerwerkt></BatchInformatie>'
			. '<Berichten><ns1:Bericht><BerichtID>22222222-2222-4222-8222-222222222222</BerichtID><BerichtType>BESLUIT</BerichtType><VerwerkingsCode>Verwerkt</VerwerkingsCode><Stadium>NA</Stadium></ns1:Bericht></Berichten>'
			. '</ns0:BerichtVerwerkResponse>';
		$http = $this->http([
			new Response(200, ['Content-Type' => 'application/json'], '["r1@logius"]'),
			new Response(200, ['Content-Type' => 'application/json'], json_encode(['dataSources' => [['content' => base64_encode((string)gzencode($result))]]])),
		]);
		$client = new BerichtenboxClientHttp(
			$this->createMock(BerichtenboxValidatieClient::class),
			new EbmsAdapterClient($http, $this->guard(), new AesTestCrypto()),
			$this->createMock(LoggerInterface::class)
		);
		$config = ['adapterUrl' => 'http://ebms/e', 'cpaId' => 'c'];

		$first = $client->results($config);
		$second = $client->results($config);

		$this->assertSame(['22222222-2222-4222-8222-222222222222' => ['code' => 'Verwerkt', 'stadium' => 'NA', 'resultMessageId' => 'r1@logius']], $first);
		$this->assertSame($first, $second);
		$this->assertCount(2, $this->history, 'One list and one read, not again for the second letter.');
		$this->assertStringContainsString('action=GLOBE-R-BV-Result', (string)$this->history[0]['request']->getUri());
	}//end testResultsAreReadOncePerSourceAndKeyedByBerichtId()

	/**
	 * A result that does not validate against the official response schema is left alone.
	 *
	 * @return void
	 */
	public function testAResultThatBreaksTheResponseSchemaIsIgnored(): void {
		$client = new BerichtenboxClientHttp(
			$this->createMock(BerichtenboxValidatieClient::class),
			$this->createMock(EbmsAdapterClient::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame([], $client->parseResult('<BerichtVerwerkResponse><Bericht><BerichtID>x</BerichtID><VerwerkingsCode>Verwerkt</VerwerkingsCode></Bericht></BerichtVerwerkResponse>'));
	}//end testAResultThatBreaksTheResponseSchemaIsIgnored()

	/**
	 * The adapter token is decrypted for the call and sent as a bearer token.
	 *
	 * @return void
	 */
	public function testTheAdapterTokenIsDecryptedForTheCall(): void {
		$crypto = new AesTestCrypto();
		$adapter = new EbmsAdapterClient($this->http([new Response(200, [], '[]')]), $this->guard(), $crypto);

		$adapter->unprocessedEvents(['adapterUrl' => 'http://ebms/e', 'cpaId' => 'c', 'authentication' => ['encryptedToken' => $crypto->encrypt('s3cret')]]);

		$this->assertSame('Bearer s3cret', $this->history[0]['request']->getHeaderLine('Authorization'));
		$this->assertStringContainsString('eventTypes=DELIVERED&eventTypes=FAILED&eventTypes=EXPIRED', (string)$this->history[0]['request']->getUri());
	}//end testTheAdapterTokenIsDecryptedForTheCall()
}//end class
