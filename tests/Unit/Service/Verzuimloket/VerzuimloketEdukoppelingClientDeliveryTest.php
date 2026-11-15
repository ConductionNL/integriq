<?php

/**
 * Delivery-path unit tests for VerzuimloketEdukoppelingClient.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Verzuimloket
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Verzuimloket;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolverInterface;
use OCA\Integriq\Adapters\Digikoppeling\SignedSoapProfileInterface;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Service\Stuf\SignedEnvelopeDelivery;
use OCA\Integriq\Service\Stuf\SignedEnvelopeRequest;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEdukoppelingClient;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives send() through the signed-envelope delivery with the credential
 * resolver, signing profile and HTTP transport stubbed at their boundaries,
 * pinning each refusal the client produces and the request it dispatches.
 */
#[CoversClass(VerzuimloketEdukoppelingClient::class)]
#[UsesClass(SignedEnvelopeDelivery::class)]
#[UsesClass(SignedEnvelopeRequest::class)]
#[UsesClass(VerzuimloketProviderException::class)]
#[UsesClass(DigikoppelingException::class)]
class VerzuimloketEdukoppelingClientDeliveryTest extends TestCase {

	/**
	 * @var PkiOverheidCredentialResolverInterface&MockObject
	 */
	private $credentialResolver;

	/**
	 * @var SignedSoapProfileInterface&MockObject
	 */
	private $profile;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Requests the mocked transport received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $history = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->credentialResolver = $this->createMock(PkiOverheidCredentialResolverInterface::class);
		$this->profile            = $this->createMock(SignedSoapProfileInterface::class);
		$this->logger             = $this->createMock(LoggerInterface::class);
		$this->history            = [];

	}//end setUp()

	/**
	 * Build the client with a transport answering from the given queue.
	 *
	 * @param array<int, mixed> $queue Responses or exceptions for the MockHandler.
	 *
	 * @return VerzuimloketEdukoppelingClient The client under test.
	 */
	private function buildClient(array $queue=[]): VerzuimloketEdukoppelingClient {
		$stack = HandlerStack::create(new MockHandler($queue));
		$stack->push(Middleware::history($this->history));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[nl] ' . $text);

		return new VerzuimloketEdukoppelingClient(
			new Client(['handler' => $stack]),
			$this->credentialResolver,
			$this->profile,
			$l10n,
			$this->logger
		);

	}//end buildClient()

	/**
	 * Send one melding with a complete configuration unless overridden.
	 *
	 * @param VerzuimloketEdukoppelingClient $client        The client.
	 * @param array<string, mixed>           $configuration The source configuration.
	 *
	 * @return string The reference send() returns.
	 */
	private function sendMelding(VerzuimloketEdukoppelingClient $client, array $configuration=[
		'endpoint' => 'https://verzuimloket.example.nl/berichten/',
		'certificateRef' => 'cert-ref-1',
	]): string {
		return $client->send($configuration, 'herhaalmelding', 'kenmerk-7', '<VerzuimloketMelding/>');

	}//end sendMelding()

	/**
	 * The config schema requires endpoint and certificateRef and describes both.
	 *
	 * @return void
	 */
	public function testConfigSchemaRequiresEndpointAndCertificateRef(): void {
		$schema = $this->buildClient()->getConfigSchema();

		$this->assertSame('object', $schema['type']);
		$this->assertSame(['endpoint', 'certificateRef'], $schema['required']);
		$this->assertSame('uri', $schema['properties']['endpoint']['format']);
		$this->assertSame(
			'DUO Verzuimloket Edukoppeling endpoint URL (WUS transport profile).',
			$schema['properties']['endpoint']['description']
		);
		$this->assertStringStartsWith(
			'Broker credentialRef for the PKIoverheid / DUO software-vendor certificate. Never stored here',
			$schema['properties']['certificateRef']['description']
		);

	}//end testConfigSchemaRequiresEndpointAndCertificateRef()

	/**
	 * A signing refusal is wrapped, localized and keeps the resolver's cause.
	 *
	 * @return void
	 */
	public function testSigningRefusalKeepsCauseAndLocalizedPrefix(): void {
		$cause = new DigikoppelingException('no certificate');
		$this->credentialResolver->expects($this->once())
			->method('resolveSigningMaterial')
			->with('cert-ref-1')
			->willThrowException($cause);
		$this->profile->expects($this->never())->method('buildSignedRequest');

		try {
			$this->sendMelding($this->buildClient());
			$this->fail('Expected a VerzuimloketProviderException.');
		} catch (VerzuimloketProviderException $exception) {
			$this->assertSame('[nl] DUO Verzuimloket send refused: no certificate', $exception->getMessage());
			$this->assertSame($cause, $exception->getPrevious());
		}

		$this->assertSame([], $this->history);

	}//end testSigningRefusalKeepsCauseAndLocalizedPrefix()

	/**
	 * A missing endpoint is refused before anything is signed or sent.
	 *
	 * @return void
	 */
	public function testMissingEndpointIsRefusedBeforeSigning(): void {
		$this->credentialResolver->method('resolveSigningMaterial')->willReturn([]);
		$this->profile->expects($this->never())->method('buildSignedRequest');

		$this->expectException(VerzuimloketProviderException::class);
		$this->expectExceptionMessage('[nl] DUO Verzuimloket endpoint missing: `configuration.endpoint` is required.');

		$this->sendMelding($this->buildClient(), ['endpoint' => '/', 'certificateRef' => 'cert-ref-1']);

	}//end testMissingEndpointIsRefusedBeforeSigning()

	/**
	 * A successful send posts the signed body with the melding-type header
	 * to the trimmed endpoint and returns the verified response reference.
	 *
	 * @return void
	 */
	public function testSuccessfulSendPostsSignedEnvelopeAndReturnsVerifiedReference(): void {
		$this->credentialResolver->method('resolveSigningMaterial')->willReturn([]);
		$this->profile->expects($this->once())
			->method('buildSignedRequest')
			->with('cert-ref-1', '<VerzuimloketMelding/>')
			->willReturn('<signed/>');
		$this->profile->expects($this->once())
			->method('verifyResponse')
			->with('<ack>ref-99</ack>')
			->willReturn('ref-99');

		$reference = $this->sendMelding($this->buildClient([new Response(200, [], " <ack>ref-99</ack>\n")]));

		$this->assertSame('ref-99', $reference);
		$this->assertCount(1, $this->history);
		$request = $this->history[0]['request'];
		$this->assertSame('POST', $request->getMethod());
		$this->assertSame('https://verzuimloket.example.nl/berichten', (string)$request->getUri());
		$this->assertSame('herhaalmelding', $request->getHeaderLine('X-Melding-Type'));
		$this->assertSame('application/xml', $request->getHeaderLine('Content-Type'));
		$this->assertSame('<signed/>', (string)$request->getBody());

	}//end testSuccessfulSendPostsSignedEnvelopeAndReturnsVerifiedReference()

	/**
	 * An empty success body falls back to the caller's kenmerk.
	 *
	 * @return void
	 */
	public function testEmptySuccessBodyFallsBackToKenmerk(): void {
		$this->credentialResolver->method('resolveSigningMaterial')->willReturn([]);
		$this->profile->method('buildSignedRequest')->willReturn('<signed/>');
		$this->profile->expects($this->never())->method('verifyResponse');

		$this->assertSame('kenmerk-7', $this->sendMelding($this->buildClient([new Response(202, [], '   ')])));

	}//end testEmptySuccessBodyFallsBackToKenmerk()

	/**
	 * A non-2xx answer is refused with the HTTP status in the message.
	 *
	 * @return void
	 */
	public function testNonSuccessStatusIsRefusedWithStatus(): void {
		$this->credentialResolver->method('resolveSigningMaterial')->willReturn([]);
		$this->profile->method('buildSignedRequest')->willReturn('<signed/>');
		$this->profile->expects($this->never())->method('verifyResponse');

		$this->expectException(VerzuimloketProviderException::class);
		$this->expectExceptionMessage('DUO Verzuimloket endpoint responded with HTTP 503.');

		$this->sendMelding($this->buildClient([new Response(503, [], '<fault/>')]));

	}//end testNonSuccessStatusIsRefusedWithStatus()

	/**
	 * A transport failure is logged without secrets and wrapped with its cause.
	 *
	 * @return void
	 */
	public function testTransportFailureIsLoggedAndWrapped(): void {
		$this->credentialResolver->method('resolveSigningMaterial')->willReturn([]);
		$this->profile->method('buildSignedRequest')->willReturn('<signed/>');
		$cause = new ConnectException('connection refused', new Request('POST', 'https://verzuimloket.example.nl'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[VerzuimloketEdukoppelingClient] unexpected transport failure',
				['exception' => 'connection refused']
			);

		try {
			$this->sendMelding($this->buildClient([$cause]));
			$this->fail('Expected a VerzuimloketProviderException.');
		} catch (VerzuimloketProviderException $exception) {
			$this->assertSame(
				'The DUO Verzuimloket request failed unexpectedly: connection refused',
				$exception->getMessage()
			);
			$this->assertSame($cause, $exception->getPrevious());
		}

	}//end testTransportFailureIsLoggedAndWrapped()
}//end class
