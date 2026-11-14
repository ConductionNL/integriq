<?php

/**
 * Unit tests for DsoClient::download().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-multiple-bijlagen-downloaded-and-linked
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\DsoProviderException;
use OCA\Integriq\Exception\MtlsTransportException;
use OCA\Integriq\Service\Dso\DsoClient;
use OCA\Integriq\Service\Mtls\MtlsCertificateBundle;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\PayloadTooLargeException;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the bijlage download: its authentication, transport routing, refusals and size cap.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-multiple-bijlagen-downloaded-and-linked
 */
class DsoClientDownloadTest extends TestCase {

	private const URL = 'https://dso-lv.example.nl/bijlagen/1';

	private const TOKEN_CONFIG = ['authentication' => ['encryptedToken' => 'ciphertext-blob']];

	private const MTLS_CONFIG = ['authentication' => ['mode' => 'mtls', 'mtls' => []]];

	/**
	 * @var array<int,array{request:Request, options:array}>
	 */
	private array $history = [];

	/**
	 * Build a token-mode client backed by a MockHandler queue.
	 *
	 * @param array $answers The queued answers.
	 *
	 * @return DsoClient The client under test.
	 */
	private function tokenClient(array $answers): DsoClient {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('raw-token-value');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		$stack = HandlerStack::create(new MockHandler($answers));
		$this->history = [];
		$stack->push(Middleware::history($this->history));

		return new DsoClient(
			new Client(['handler' => $stack]),
			$crypto,
			$l10n,
			$logger,
			new MtlsConfigResolver($crypto),
			new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger)
		);

	}//end tokenClient()

	/**
	 * Build an mTLS-mode client whose transport is the given double.
	 *
	 * @param MtlsTransportService $transport The mTLS transport double.
	 *
	 * @return DsoClient The client under test.
	 */
	private function mtlsClient(MtlsTransportService $transport): DsoClient {
		$resolver = $this->createMock(MtlsConfigResolver::class);
		$resolver->method('isMtlsConfigured')->willReturn(true);
		$resolver->method('resolve')->willReturn(new MtlsCertificateBundle(certificatePem: 'CERT', privateKeyPem: 'KEY'));

		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->never())->method('decrypt');

		return new DsoClient(
			new Client(),
			$crypto,
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
			$resolver,
			$transport
		);

	}//end mtlsClient()

	/**
	 * Token mode sends a GET with the Bearer header and returns the body as a stream.
	 *
	 * @return void
	 */
	public function testTokenModeDownloadsWithBearerHeader(): void {
		$stream = $this->tokenClient(answers: [new Response(200, [], 'bijlage-bytes')])
			->download(self::TOKEN_CONFIG, self::URL, 100);

		$this->assertSame('bijlage-bytes', stream_get_contents($stream));
		$request = $this->history[0]['request'];
		$this->assertSame('GET', $request->getMethod());
		$this->assertSame(self::URL, (string)$request->getUri());
		$this->assertSame('Bearer raw-token-value', $request->getHeaderLine('Authorization'));
		$this->assertSame('*/*', $request->getHeaderLine('Accept'));
		$this->assertTrue($this->history[0]['options']['stream']);
		$this->assertFalse($this->history[0]['options']['http_errors']);

	}//end testTokenModeDownloadsWithBearerHeader()

	/**
	 * Token mode without a stored token is refused before anything is sent.
	 *
	 * @return void
	 */
	public function testTokenModeWithoutTokenIsRefusedBeforeSending(): void {
		$client = $this->tokenClient(answers: [new Response(200, [], 'x')]);

		try {
			$client->download(['authentication' => []], self::URL, 100);
			$this->fail('A download without a credential must be refused.');
		} catch (DsoProviderException $e) {
			$this->assertStringStartsWith('DSO credential missing: ', $e->getMessage());
		}

		$this->assertSame([], $this->history);

	}//end testTokenModeWithoutTokenIsRefusedBeforeSending()

	/**
	 * A non-2xx answer is refused.
	 *
	 * @return void
	 */
	public function testNon2xxAnswerIsRefused(): void {
		$this->expectException(DsoProviderException::class);
		$this->expectExceptionMessage('DSO-LV answered the download with HTTP 404.');

		$this->tokenClient(answers: [new Response(404, [], 'nope')])->download(self::TOKEN_CONFIG, self::URL, 100);

	}//end testNon2xxAnswerIsRefused()

	/**
	 * A declared Content-Length above the cap stops the download.
	 *
	 * @return void
	 */
	public function testDeclaredLengthAboveCapIsRefused(): void {
		$this->expectException(PayloadTooLargeException::class);
		$this->expectExceptionMessage('The bijlage is 500 bytes, more than the maximum of 100 bytes.');

		$this->tokenClient(answers: [new Response(200, ['Content-Length' => '500'], 'x')])
			->download(self::TOKEN_CONFIG, self::URL, 100);

	}//end testDeclaredLengthAboveCapIsRefused()

	/**
	 * A body that grows past the cap stops the download.
	 *
	 * @return void
	 */
	public function testBodyAboveCapIsRefused(): void {
		$this->expectException(PayloadTooLargeException::class);
		$this->expectExceptionMessage('The bijlage is more than the maximum of 3 bytes.');

		$this->tokenClient(answers: [new Response(200, [], 'four')])->download(self::TOKEN_CONFIG, self::URL, 3);

	}//end testBodyAboveCapIsRefused()

	/**
	 * A transport failure becomes a provider exception.
	 *
	 * @return void
	 */
	public function testTransportFailureIsWrapped(): void {
		$this->expectException(DsoProviderException::class);
		$this->expectExceptionMessage('The DSO-LV download failed: refused');

		$this->tokenClient(answers: [new ConnectException('refused', new Request('GET', self::URL))])
			->download(self::TOKEN_CONFIG, self::URL, 100);

	}//end testTransportFailureIsWrapped()

	/**
	 * mTLS mode routes the GET through the mTLS transport, without an Authorization header.
	 *
	 * @return void
	 */
	public function testMtlsModeRoutesThroughTransportWithoutBearer(): void {
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->expects($this->once())
			->method('request')
			->with(
				$this->anything(),
				'GET',
				self::URL,
				['headers' => ['Accept' => '*/*'], 'http_errors' => false, 'stream' => true],
				$this->isInstanceOf(MtlsCertificateBundle::class)
			)
			->willReturn(new Response(200, [], 'mtls-bytes'));

		$stream = $this->mtlsClient(transport: $transport)->download(self::MTLS_CONFIG, self::URL, 100);

		$this->assertSame('mtls-bytes', stream_get_contents($stream));

	}//end testMtlsModeRoutesThroughTransportWithoutBearer()

	/**
	 * An mTLS failure becomes a provider exception naming its code.
	 *
	 * @return void
	 */
	public function testMtlsFailureIsWrapped(): void {
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->method('request')->willThrowException(new MtlsTransportException('handshake', 'mtls_handshake_failed'));

		$this->expectException(DsoProviderException::class);
		$this->expectExceptionMessage('The DSO-LV mTLS download failed (mtls_handshake_failed): handshake');

		$this->mtlsClient(transport: $transport)->download(self::MTLS_CONFIG, self::URL, 100);

	}//end testMtlsFailureIsWrapped()

	/**
	 * Sending in mTLS mode hands the transport exactly the message options, without a Bearer header.
	 *
	 * @return void
	 */
	public function testMtlsSendHandsTransportTheMessageOptions(): void {
		$transport = $this->createMock(MtlsTransportService::class);
		$transport->expects($this->once())
			->method('request')
			->with(
				$this->anything(),
				'POST',
				'https://dso-lv.example.nl/statussen',
				[
					'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
					'json' => ['status' => 'x'],
					'http_errors' => false,
				],
				$this->isInstanceOf(MtlsCertificateBundle::class)
			)
			->willReturn(new Response(200, [], '{"ref":"R-9"}'));

		$ref = $this->mtlsClient(transport: $transport)
			->send(['baseUrl' => 'https://dso-lv.example.nl'] + self::MTLS_CONFIG, 'v-1', 'status', ['status' => 'x']);

		$this->assertSame('R-9', $ref);

	}//end testMtlsSendHandsTransportTheMessageOptions()
}//end class
