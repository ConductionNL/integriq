<?php

/**
 * Golden test pinning StufZknClient's transmit, exchange and send outcomes and the request it posts.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-secure-transport
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\StufZkn\StufZknClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the transmit() result for every path (refused before sending, transport failures,
 * answers), the posted method, URL, headers and options, and the logged lines.
 */
class StufZknClientTransmitGoldenTest extends TestCase {

	/** @var array<int, array> Guzzle history. */
	private array $history = [];

	/** @var array<int, array> Log lines: [level, message, context]. */
	private array $logs = [];

	/**
	 * A client answering with the queued responses or exceptions.
	 *
	 * @param array $queue The MockHandler queue.
	 *
	 * @return StufZknClient
	 */
	private function client(array $queue): StufZknClient {
		$this->history = [];
		$this->logs = [];
		$stack = HandlerStack::create(new MockHandler($queue));
		$stack->push(Middleware::history($this->history));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('raw-token-value');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array $logs The shared log line list.
			 */
			public function __construct(private array &$logs) {
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message, $context];
			}
		};

		return new StufZknClient(
			new Client(['handler' => $stack]),
			$crypto,
			$l,
			$logger,
			new MtlsConfigResolver($crypto),
			new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger)
		);

	}//end client()

	/**
	 * The posted request: method, URL, the SOAP and auth headers, body and timeout option.
	 *
	 * @return array
	 */
	private function posted(): array {
		$entry = $this->history[0];

		return [
			$entry['request']->getMethod(),
			(string)$entry['request']->getUri(),
			$entry['request']->getHeaderLine('Content-Type'),
			$entry['request']->getHeaderLine('SOAPAction'),
			$entry['request']->getHeaderLine('Accept'),
			$entry['request']->getHeaderLine('Authorization'),
			(string)$entry['request']->getBody(),
			($entry['options']['timeout'] ?? null),
		];

	}//end posted()

	/**
	 * A transmit result with the measured duration masked (it may round to 0 against a mock).
	 *
	 * @param array $result The result.
	 *
	 * @return array
	 */
	private function masked(array $result): array {
		$this->assertIsInt($result['durationMs']);
		$this->assertGreaterThanOrEqual(0, $result['durationMs']);
		$result['durationMs'] = 'D';

		return $result;

	}//end masked()

	/**
	 * Every transmit() path.
	 *
	 * @return void
	 */
	public function testTransmitOutcomes(): void {
		$token = ['baseUrl' => 'https://zs.example.nl/zds', 'authentication' => ['encryptedToken' => 'c']];
		$fout = fn (string $code, string $omschrijving, string $details, string $kind) => ['code' => $code, 'omschrijving' => $omschrijving, 'details' => $details, 'kind' => $kind];

		$this->assertSame(
			['httpStatus' => 0, 'responseXml' => '', 'durationMs' => 0, 'fout' => $fout('TRANSPORT_NON_HTTPS', 'Endpoint URL is not HTTPS', '', 'permanent')],
			$this->client([])->transmit($token, '"a"', '<e/>', ' http://zs.example.nl ')
		);
		$this->assertSame(
			['httpStatus' => 0, 'responseXml' => '', 'durationMs' => 0, 'fout' => $fout('TRANSPORT_NON_HTTPS', 'Endpoint URL is not HTTPS', '', 'permanent')],
			$this->client([])->transmit(['authentication' => []], '"a"', '<e/>')
		);
		$this->assertSame(
			['httpStatus' => 0, 'responseXml' => '', 'durationMs' => 0, 'fout' => $fout('TRANSPORT_CONFIGURATION', 'The source cannot send', 'StUF-ZKN consumer base URL missing: `configuration.baseUrl` is required.', 'permanent')],
			$this->client([])->transmit(['authentication' => []], '"a"', '<e/>', 'https://other.example.nl/x')
		);
		$noToken = $this->client([])->transmit(['baseUrl' => 'https://zs.example.nl', 'authentication' => []], '"a"', '<e/>');
		$this->assertSame('TRANSPORT_CONFIGURATION', $noToken['fout']['code']);
		$this->assertSame('permanent', $noToken['fout']['kind']);
		$this->assertSame(0, $noToken['durationMs']);

		$this->assertSame(
			['httpStatus' => 0, 'responseXml' => '', 'durationMs' => 'D', 'fout' => $fout('TIMEOUT', 'Transport error', 'The StUF-ZKN request failed unexpectedly: Connection timed out', 'transient')],
			$this->masked($this->client([new ConnectException('Connection timed out', new Request('POST', 'x'))])->transmit($token, '"a"', '<e/>'))
		);
		$this->assertSame(
			[['warning', '[StufZknClient] unexpected transport failure', ['exception' => 'Connection timed out']]],
			$this->logs
		);
		$this->assertSame('TIMEOUT', $this->client([new ConnectException('cURL TIMEOUT 28', new Request('POST', 'x'))])->transmit($token, '"a"', '<e/>')['fout']['code']);
		$this->assertSame(
			$fout('NETWORK', 'Transport error', 'The StUF-ZKN request failed unexpectedly: refused', 'transient'),
			$this->client([new RequestException('refused', new Request('POST', 'x'))])->transmit($token, '"a"', '<e/>')['fout']
		);

		$answer = $this->masked($this->client([new Response(500, [], '<fault/>')])->transmit($token, '"zakLk01"', '<env/>', 'https://zs.example.nl/other', 7));
		$this->assertSame(['httpStatus' => 500, 'responseXml' => '<fault/>', 'durationMs' => $answer['durationMs'], 'fout' => null], $answer);
		$this->assertSame(
			['POST', 'https://zs.example.nl/other', 'text/xml; charset=utf-8', '"zakLk01"', 'text/xml, application/soap+xml', 'Bearer raw-token-value', '<env/>', 7],
			$this->posted()
		);

		$this->client([new Response(200, [], 'ok')])->transmit(['baseUrl' => 'https://zs.example.nl/zds', 'authentication' => ['mode' => 'wsse']], '"a"', '<e/>');
		$this->assertSame(['POST', 'https://zs.example.nl/zds', 'text/xml; charset=utf-8', '"a"', 'text/xml, application/soap+xml', '', '<e/>', 30], $this->posted());

	}//end testTransmitOutcomes()

	/**
	 * exchange() and send() through the same post.
	 *
	 * @return void
	 */
	public function testExchangeAndSend(): void {
		$token = ['baseUrl' => 'https://zs.example.nl/zds', 'authentication' => ['encryptedToken' => 'c', 'scheme' => 'Token']];

		$this->assertSame('<ok/>', $this->client([new Response(201, [], '<ok/>')])->exchange($token, '"x"', '<e/>'));
		$this->assertSame(['POST', 'https://zs.example.nl/zds', 'text/xml; charset=utf-8', '"x"', 'text/xml, application/soap+xml', 'Token raw-token-value', '<e/>', null], $this->posted());

		try {
			$this->client([new Response(400, [], 'garbage')])->exchange($token, '"x"', '<e/>', 'https://zs.example.nl/b');
			$this->fail('no refusal');
		} catch (StufZknProviderException $exception) {
			$this->assertSame('The case system answered HTTP 400: no fault details', $exception->getMessage());
		}

		$this->assertSame('https://zs.example.nl/b', $this->posted()[1]);

		$this->client([new Response(200, [], '')])->send($token, 'REF-1', '<e/>');
		$this->assertSame(['POST', 'https://zs.example.nl/zds', 'text/xml; charset=utf-8', '""', 'text/xml, application/soap+xml', 'Token raw-token-value', '<e/>', null], $this->posted());

		try {
			$this->client([new RequestException('down', new Request('POST', 'x'))])->send($token, 'REF-1', '<e/>');
			$this->fail('no refusal');
		} catch (StufZknProviderException $exception) {
			$this->assertSame('The StUF-ZKN request failed unexpectedly: down', $exception->getMessage());
			$this->assertInstanceOf(RequestException::class, $exception->getPrevious());
		}

		try {
			$mtls = ['baseUrl' => 'https://zs.example.nl', 'authentication' => ['mode' => 'mtls', 'mtls' => ['encryptedCertificate' => 'x']]];
			$this->client([])->send($mtls, 'REF-1', '<e/>');
			$this->fail('no refusal');
		} catch (StufZknProviderException $exception) {
			$this->assertStringStartsWith('The StUF-ZKN mTLS request failed (', $exception->getMessage());
			$this->assertSame('[StufZknClient] mTLS request failed', $this->logs[0][1]);
			$this->assertSame(['exception', 'errorCode'], array_keys($this->logs[0][2]));
		}

	}//end testExchangeAndSend()
}//end class
