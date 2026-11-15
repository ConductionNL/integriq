<?php

/**
 * The iStandaarden client refuses a JSON answer without a usable reference
 * and a token it cannot decrypt, sending nothing in the second case.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/iwmo-ijw-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\IwmoIjw;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\IwmoIjwProviderException;
use OCA\Integriq\Service\IwmoIjw\IStandardsClient;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\IwmoIjw\IStandardsClient
 * @uses   \OCA\Integriq\Exception\IwmoIjwProviderException
 * @uses   \OCA\Integriq\Service\IwmoIjw\BerichtRequest
 * @uses   \OCA\Integriq\Service\Mtls\MtlsConfigResolver
 * @uses   \OCA\Integriq\Service\Mtls\MtlsTransportService
 * @uses   \OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder
 */
class IStandardsClientRefusalsTest extends TestCase {

	private const CONFIGURATION = [
		'baseUrl' => 'https://istandaarden.example.nl/api/v1',
		'authentication' => ['encryptedToken' => 'ciphertext-blob'],
	];

	/**
	 * The requests the client sent.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $history = [];

	/**
	 * A client over queued responses and the given crypto.
	 *
	 * @param array<int,Response> $responses The responses.
	 * @param ICrypto             $crypto    The crypto.
	 *
	 * @return IStandardsClient
	 */
	private function client(array $responses, ICrypto $crypto): IStandardsClient {
		$stack = HandlerStack::create(handler: new MockHandler(queue: $responses));
		$this->history = [];
		$stack->push(middleware: Middleware::history(container: $this->history));
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new IStandardsClient(
			httpClient: new Client(config: ['handler' => $stack]),
			crypto: $crypto,
			l: $l10n,
			logger: $logger,
			mtlsConfigResolver: new MtlsConfigResolver(crypto: $crypto),
			mtlsTransport: new MtlsTransportService(optionsBuilder: new MtlsTransportOptionsBuilder(), logger: $logger)
		);
	}//end client()

	/**
	 * A crypto that decrypts to a fixed token.
	 *
	 * @return ICrypto
	 */
	private function crypto(): ICrypto {
		$crypto = $this->createMock(originalClassName: ICrypto::class);
		$crypto->method('decrypt')->willReturn('raw-token-value');

		return $crypto;
	}//end crypto()

	/**
	 * A JSON answer without ref or referentienummer, or with an empty or non-string one, is refused.
	 *
	 * @return void
	 */
	public function testAJsonAnswerWithoutAUsableReferenceIsRefused(): void {
		$bodies = ['{"status":"ok"}', '{"ref":""}', '{"referentienummer":42}', '[]'];
		foreach ($bodies as $body) {
			try {
				$this->client(responses: [new Response(status: 200, body: $body)], crypto: $this->crypto())
					->send(sourceConfiguration: self::CONFIGURATION, berichttype: 'Wmo303', envelopeXml: '<Bericht/>');
				$this->fail(message: $body.' must be refused');
			} catch (IwmoIjwProviderException $exception) {
				$this->assertSame(
					expected: 'The iStandaarden endpoint returned a JSON response with no usable ref/referentienummer.',
					actual: $exception->getMessage(),
					message: $body
				);
			}
		}
	}//end testAJsonAnswerWithoutAUsableReferenceIsRefused()

	/**
	 * When both are present, ref is the reference.
	 *
	 * @return void
	 */
	public function testRefTakesPrecedenceOverReferentienummer(): void {
		$client = $this->client(
			responses: [new Response(status: 200, body: '{"ref":"R-1","referentienummer":"N-1"}')],
			crypto: $this->crypto()
		);

		$ref = $client->send(sourceConfiguration: self::CONFIGURATION, berichttype: 'Wmo303', envelopeXml: '<Bericht/>');

		$this->assertSame(expected: 'R-1', actual: $ref);
	}//end testRefTakesPrecedenceOverReferentienummer()

	/**
	 * A token that cannot be decrypted is refused before anything is sent, naming the cause.
	 *
	 * @return void
	 */
	public function testAnUndecryptableTokenIsRefusedBeforeSending(): void {
		$crypto = $this->createMock(originalClassName: ICrypto::class);
		$crypto->method('decrypt')->willThrowException(new RuntimeException('HMAC does not match.'));
		$client = $this->client(responses: [new Response(status: 200, body: 'IWMO-1')], crypto: $crypto);

		try {
			$client->send(sourceConfiguration: self::CONFIGURATION, berichttype: 'Wmo303', envelopeXml: '<Bericht/>');
			$this->fail(message: 'An undecryptable token must be refused.');
		} catch (IwmoIjwProviderException $exception) {
			$this->assertSame(
				expected: 'The stored iStandaarden API token could not be decrypted: HMAC does not match.',
				actual: $exception->getMessage()
			);
		}

		$this->assertSame(expected: [], actual: $this->history);
	}//end testAnUndecryptableTokenIsRefusedBeforeSending()
}//end class
