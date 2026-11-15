<?php

/**
 * Unit tests for IStandardsClient's transport: the request it sends and the failures it wraps.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#scenario-the-rest-provider-sends-the-expected-bearer-auth-header
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\IwmoIjw;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\IwmoIjwProviderException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\IwmoIjw\IStandardsClient;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the request IStandardsClient sends in both modes and the failures it wraps.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#scenario-istandaardenclient-routes-through-the-mtls-transport-when-configured
 */
class IStandardsClientTransportTest extends TestCase {

	private const ENVELOPE = '<Wmo303 xmlns="http://www.istandaarden.nl/iwmo/3_2/wmo303/schema"/>';

	/**
	 * @var array<int,array{request:Request, options:array}>
	 */
	private array $history = [];

	/**
	 * The warnings the logger double saw.
	 *
	 * @var array<int,array{0:string,1:array}>
	 */
	private array $warnings = [];

	/**
	 * A logger double that keeps its warnings.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return $logger;

	}//end logger()

	/**
	 * A token-mode client over a MockHandler queue.
	 *
	 * @param array $answers The queued answers.
	 *
	 * @return IStandardsClient The client.
	 */
	private function tokenClient(array $answers): IStandardsClient {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('raw-token');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$stack = HandlerStack::create(new MockHandler($answers));
		$this->history = [];
		$stack->push(Middleware::history($this->history));
		$logger = $this->logger();

		return new IStandardsClient(
			new Client(['handler' => $stack]),
			$crypto,
			$l10n,
			$logger,
			new MtlsConfigResolver($crypto),
			new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger)
		);

	}//end tokenClient()

	/**
	 * An mTLS-mode client over a transport double.
	 *
	 * @param MtlsTransportService $transport The transport double.
	 *
	 * @return IStandardsClient The client.
	 */
	private function mtlsClient(MtlsTransportService $transport): IStandardsClient {
		$resolver = $this->createMock(MtlsConfigResolver::class);
		$resolver->method('isMtlsConfigured')->willReturn(true);
		$resolver->method('resolve')->willReturn(new MtlsCertificateBundle(certificatePem: 'CERT', privateKeyPem: 'KEY'));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('decrypt');

		return new IStandardsClient(new Client(), $crypto, $this->createMock(IL10N::class), $this->logger(), $resolver, $transport);

	}//end mtlsClient()

	/**
	 * Token mode posts the envelope to `<baseUrl>/berichten` with its headers and options.
	 *
	 * @return void
	 */
	public function testTokenModePostsTheEnvelopeWithItsHeaders(): void {
		$ref = $this->tokenClient(answers: [new Response(202, [], 'REF-1')])
			->send(['baseUrl' => 'https://ist.example/api/', 'authentication' => ['encryptedToken' => 'c']], 'Wmo303', self::ENVELOPE);

		$this->assertSame('REF-1', $ref);
		$request = $this->history[0]['request'];
		$this->assertSame('POST', $request->getMethod());
		$this->assertSame('https://ist.example/api/berichten', (string)$request->getUri());
		$this->assertSame(
			['application/xml', 'application/xml, application/json', 'Wmo303', 'Bearer raw-token'],
			[$request->getHeaderLine('Content-Type'), $request->getHeaderLine('Accept'), $request->getHeaderLine('X-Berichttype'), $request->getHeaderLine('Authorization')]
		);
		$this->assertSame(self::ENVELOPE, (string)$request->getBody());
		$this->assertFalse($this->history[0]['options']['http_errors']);

	}//end testTokenModePostsTheEnvelopeWithItsHeaders()

	/**
	 * mTLS mode hands the transport exactly the options, without an Authorization header.
	 *
	 * @return void
	 */
	public function testMtlsModeHandsTheTransportTheOptionsWithoutBearer(): void {
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->expects($this->once())->method('request')->with(
			$this->anything(),
			'POST',
			'https://ist.example/berichten',
			[
				'headers' => ['Content-Type' => 'application/xml', 'Accept' => 'application/xml, application/json', 'X-Berichttype' => 'Jw301'],
				'body' => self::ENVELOPE,
				'http_errors' => false,
			],
			$this->isInstanceOf(MtlsCertificateBundle::class)
		)->willReturn(new Response(200, [], '{"ref":"R-2"}'));

		$ref = $this->mtlsClient(transport: $transport)
			->send(['baseUrl' => 'https://ist.example', 'authentication' => ['mode' => 'mtls', 'mtls' => []]], 'Jw301', self::ENVELOPE);

		$this->assertSame('R-2', $ref);

	}//end testMtlsModeHandsTheTransportTheOptionsWithoutBearer()

	/**
	 * Transport failures are wrapped and logged; a 1xx or 3xx answer is refused.
	 *
	 * @return void
	 */
	public function testFailuresAreWrappedAndLogged(): void {
		$outcomes = [];
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->method('request')->willThrowException(new MtlsTransportException('handshake', 'mtls_handshake_failed'));
		$clients = [
			'mtls' => fn () => $this->mtlsClient(transport: $transport),
			'connect' => fn () => $this->tokenClient(answers: [new ConnectException('refused', new Request('POST', 'https://ist.example'))]),
			'http-199' => fn () => $this->tokenClient(answers: [new Response(199, [], 'x')]),
			'http-300' => fn () => $this->tokenClient(answers: [new Response(300, [], 'x')]),
		];
		foreach ($clients as $name => $client) {
			$this->warnings = [];
			try {
				$client()->send(['baseUrl' => 'https://ist.example', 'authentication' => ['encryptedToken' => 'c']], 'Wmo303', self::ENVELOPE);
				$outcomes[$name] = 'sent';
			} catch (IwmoIjwProviderException $exception) {
				$outcomes[$name] = [$exception->getMessage(), ($exception->getPrevious() !== null), $this->warnings];
			}
		}

		$this->assertSame(
			[
				'mtls' => [
					'The iStandaarden mTLS request failed (mtls_handshake_failed): handshake',
					true,
					[['[IStandardsClient] mTLS request failed', ['exception' => 'handshake', 'errorCode' => 'mtls_handshake_failed']]],
				],
				'connect' => [
					'The iStandaarden request failed unexpectedly: refused',
					true,
					[['[IStandardsClient] unexpected transport failure', ['exception' => 'refused']]],
				],
				'http-199' => ['iStandaarden endpoint responded with HTTP 199.', false, []],
				'http-300' => ['iStandaarden endpoint responded with HTTP 300.', false, []],
			],
			$outcomes
		);

	}//end testFailuresAreWrappedAndLogged()
}//end class
