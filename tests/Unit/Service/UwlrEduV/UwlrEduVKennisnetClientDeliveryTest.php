<?php

/**
 * Unit tests for UwlrEduVKennisnetClient's delivery: what is posted where, and
 * how each refusal reads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\UwlrEduV;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Service\Stuf\SignedEnvelopeDelivery;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRequest;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVKennisnetClient;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A signed export is posted to the endpoint plus the target, and every refusal names what went wrong.
 */
#[CoversClass(UwlrEduVKennisnetClient::class)]
#[UsesClass(SignedEnvelopeDelivery::class)]
#[UsesClass(SignedEnvelopeRequest::class)]
#[UsesClass(UwlrEduVProviderException::class)]
final class UwlrEduVKennisnetClientDeliveryTest extends TestCase {

	private PkiOverheidCredentialResolverInterface&MockObject $credentials;
	private SignedSoapProfileInterface&MockObject $profile;
	private LoggerInterface&MockObject $logger;
	private MockHandler $responses;

	/**
	 * The requests the HTTP client sent.
	 *
	 * @var array<int, array{request: Request}>
	 */
	private array $sent = [];

	protected function setUp(): void {
		$this->credentials = $this->createMock(PkiOverheidCredentialResolverInterface::class);
		$this->credentials->method('resolveSigningMaterial')->willReturn(['certificate' => 'pem']);
		$this->profile = $this->createMock(SignedSoapProfileInterface::class);
		$this->profile->method('buildSignedRequest')->willReturn('<Signed/>');
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->responses = new MockHandler();
	}//end setUp()

	/**
	 * The client over a mocked HTTP transport.
	 *
	 * @return UwlrEduVKennisnetClient
	 */
	private function client(): UwlrEduVKennisnetClient {
		$stack = HandlerStack::create($this->responses);
		$stack->push(Middleware::history($this->sent));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new UwlrEduVKennisnetClient(new Client(['handler' => $stack]), $this->credentials, $this->profile, $l10n, $this->logger);
	}//end client()

	/**
	 * Send one export to the uwlr target.
	 *
	 * @param array<string, mixed> $configuration The source configuration.
	 *
	 * @return string The delivery reference.
	 */
	private function send(array $configuration = ['endpoint' => 'https://eduv.test/api/', 'certificateRef' => 'cert-1']): string {
		return $this->client()->send($configuration, 'uwlr', 'kenmerk-1', '<UwlrExport/>');
	}//end send()

	public function testTheConfigSchemaRequiresAnEndpointAndACertificateRef(): void {
		$schema = $this->client()->getConfigSchema();

		$this->assertSame(['endpoint', 'certificateRef'], $schema['required']);
		$this->assertSame('uri', $schema['properties']['endpoint']['format']);
		$this->assertSame('UWLR/Edu-V/Basispoort/Entree-content koppelvlak endpoint URL.', $schema['properties']['endpoint']['description']);
		$this->assertStringStartsWith('Broker credentialRef for the PKIoverheid certificate.', $schema['properties']['certificateRef']['description']);
	}//end testTheConfigSchemaRequiresAnEndpointAndACertificateRef()

	public function testASignedExportIsPostedToTheTargetAndTheVerifiedReferenceReturned(): void {
		$this->responses->append(new Response(200, [], " <Ack/> "));
		$this->profile->expects($this->once())->method('buildSignedRequest')->with('cert-1', '<UwlrExport/>');
		$this->profile->expects($this->once())->method('verifyResponse')->with('<Ack/>')->willReturn('ref-77');

		$this->assertSame('ref-77', $this->send());

		$request = $this->sent[0]['request'];
		$this->assertSame('POST', $request->getMethod());
		$this->assertSame('https://eduv.test/api/uwlr', (string)$request->getUri());
		$this->assertSame('application/xml', $request->getHeaderLine('Content-Type'));
		$this->assertSame('<Signed/>', (string)$request->getBody());
	}//end testASignedExportIsPostedToTheTargetAndTheVerifiedReferenceReturned()

	public function testAnEmptyAnswerFallsBackToTheKenmerk(): void {
		$this->responses->append(new Response(204));
		$this->profile->expects($this->never())->method('verifyResponse');

		$this->assertSame('kenmerk-1', $this->send());
	}//end testAnEmptyAnswerFallsBackToTheKenmerk()

	public function testANonSuccessStatusIsRefusedWithTheStatus(): void {
		$this->responses->append(new Response(503, [], 'down'));

		$this->expectException(UwlrEduVProviderException::class);
		$this->expectExceptionMessage('UWLR/Edu-V endpoint responded with HTTP 503.');

		$this->send();
	}//end testANonSuccessStatusIsRefusedWithTheStatus()

	public function testAMissingEndpointIsRefusedBeforeAnythingIsSigned(): void {
		$this->profile->expects($this->never())->method('buildSignedRequest');

		try {
			$this->send(['endpoint' => '/', 'certificateRef' => 'cert-1']);
			$this->fail('A send without an endpoint went out.');
		} catch (UwlrEduVProviderException $e) {
			$this->assertSame('UWLR/Edu-V endpoint missing: `configuration.endpoint` is required.', $e->getMessage());
		}

		$this->assertSame([], $this->sent);
	}//end testAMissingEndpointIsRefusedBeforeAnythingIsSigned()

	public function testATransportFailureIsLoggedAndRefusedWithItsCause(): void {
		$cause = new ConnectException('connection refused', new Request('POST', 'https://eduv.test/api/uwlr'));
		$this->responses->append($cause);
		$this->logger->expects($this->once())
			->method('warning')
			->with('[UwlrEduVKennisnetClient] unexpected transport failure', ['exception' => 'connection refused']);

		try {
			$this->send();
			$this->fail('A transport failure was not refused.');
		} catch (UwlrEduVProviderException $e) {
			$this->assertSame('The UWLR/Edu-V send request failed unexpectedly: connection refused', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testATransportFailureIsLoggedAndRefusedWithItsCause()
}//end class
