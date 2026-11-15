<?php

/**
 * Golden test pinning every outcome of FscDirectoryClient's directory lookup and call.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Fsc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Fsc;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Service\Fsc\FscDirectoryClient;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsTransportOptionsBuilder;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the exception class and exact message of every refusal, the logged lines, the request
 * sent, and the returned resolution and call result.
 */
class FscDirectoryClientGoldenTest extends TestCase {

	/** @var array<int, array> Guzzle history. */
	private array $history = [];

	/** @var array<int, array> Log lines: [level, message, context]. */
	private array $logs = [];

	/**
	 * A client answering with the queued responses or exceptions.
	 *
	 * @param array $queue The MockHandler queue.
	 *
	 * @return FscDirectoryClient
	 */
	private function client(array $queue): FscDirectoryClient {
		$this->history = [];
		$this->logs = [];
		$stack = HandlerStack::create(new MockHandler($queue));
		$stack->push(Middleware::history($this->history));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn('tok');
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

		return new FscDirectoryClient(
			new Client(['handler' => $stack]),
			$crypto,
			$l,
			$logger,
			new MtlsConfigResolver($crypto),
			new MtlsTransportService(new MtlsTransportOptionsBuilder(), $logger)
		);

	}//end client()

	/**
	 * Describe what a call threw.
	 *
	 * @param callable $call The call.
	 *
	 * @return array [class, message, previous class or null]
	 */
	private function thrown(callable $call): array {
		try {
			$call();
		} catch (\Exception $exception) {
			$previous = $exception->getPrevious();
			return [(new \ReflectionClass($exception))->getShortName(), $exception->getMessage(), ($previous === null ? null : $previous::class)];
		}

		return ['none'];

	}//end thrown()

	/**
	 * Every resolveService() outcome.
	 *
	 * @return void
	 */
	public function testResolveServiceOutcomes(): void {
		$config = ['directoryUrl' => 'https://dir.example.nl/'];
		$resolve = fn (array $queue, array $directory = ['directoryUrl' => 'https://dir.example.nl/']) => $this->client($queue)->resolveService($directory, 'org 1', 'svc/a');
		$quoted = 'organisation "org 1" and service "svc/a"';

		$this->assertSame(
			['FscConnectivityException', 'FSC directory URL missing: `configuration.directory.directoryUrl` is required.', null],
			$this->thrown(fn () => $resolve([], ['directoryUrl' => '//']))
		);
		$this->assertSame(
			['FscConnectivityException', 'The FSC directory lookup failed unexpectedly: down', RequestException::class],
			$this->thrown(fn () => $resolve([new RequestException('down', new Request('GET', 'x'))]))
		);
		$this->assertSame([['warning', '[FscDirectoryClient] directory lookup failed unexpectedly', ['exception' => 'down']]], $this->logs);
		$this->assertSame(
			['FscDirectoryException', 'Unknown organisation or service: no FSC directory entry for ' . $quoted . '.', null],
			$this->thrown(fn () => $resolve([new Response(404, [], '{"error":"service"}')]))
		);
		$this->assertSame(['FscConnectivityException', 'FSC directory responded with HTTP 503.', null], $this->thrown(fn () => $resolve([new Response(503)])));
		$this->assertSame(
			['FscConnectivityException', 'The FSC directory returned a non-JSON response for ' . $quoted . '.', null],
			$this->thrown(fn () => $resolve([new Response(200, [], 'nope')]))
		);
		$this->assertSame(
			['FscConnectivityException', 'The FSC directory response for ' . $quoted . ' carried no usable endpoint.', null],
			$this->thrown(fn () => $resolve([new Response(200, [], '{"endpoint":""}')]))
		);

		$client = $this->client([new Response(200, [], '{"endpoint":"https://svc.example.nl","grantRequired":1,"publicKeyFingerprint":"ab"}')]);
		$this->assertSame(
			['organisation' => 'org 1', 'service' => 'svc/a', 'endpoint' => 'https://svc.example.nl', 'grantRequired' => true, 'authContext' => ['publicKeyFingerprint' => 'ab']],
			$client->resolveService($config, 'org 1', 'svc/a')
		);
		$request = $this->history[0]['request'];
		$this->assertSame(['GET', 'https://dir.example.nl/organisations/org%201/services/svc%2Fa', 'application/json'], [$request->getMethod(), (string)$request->getUri(), $request->getHeaderLine('Accept')]);

		$this->assertSame(
			['organisation' => 'o', 'service' => 's', 'endpoint' => 'e', 'grantRequired' => false, 'authContext' => ['publicKeyFingerprint' => null]],
			$this->client([new Response(204, [], '{"endpoint":"e"}')])->resolveService($config, 'o', 's')
		);

	}//end testResolveServiceOutcomes()

	/**
	 * Every call() outcome.
	 *
	 * @return void
	 */
	public function testCallOutcomes(): void {
		$config = ['authentication' => ['encryptedToken' => 'c', 'scheme' => 'Token']];
		$resolution = ['endpoint' => 'https://svc.example.nl/x'];

		$this->assertSame(
			['FscConnectivityException', 'FSC call resolution missing an endpoint: cannot dispatch without one.', null],
			$this->thrown(fn () => $this->client([])->call($config, [], 'post', []))
		);
		$this->assertSame(
			['FscConnectivityException', 'The FSC call failed unexpectedly: down', RequestException::class],
			$this->thrown(fn () => $this->client([new RequestException('down', new Request('GET', 'x'))])->call($config, $resolution, 'post', []))
		);
		$this->assertSame([['warning', '[FscDirectoryClient] unexpected transport failure', ['exception' => 'down']]], $this->logs);
		$this->assertSame(
			['FscConnectivityException', 'FSC endpoint responded with HTTP 302.', null],
			$this->thrown(fn () => $this->client([new Response(302)])->call($config, $resolution, 'post', []))
		);
		$mtls = $this->thrown(fn () => $this->client([])->call(['authentication' => ['mode' => 'mtls', 'mtls' => ['encryptedCertificate' => 'x']]], $resolution, 'get', []));
		$this->assertSame('FscConnectivityException', $mtls[0]);
		$this->assertStringStartsWith('The FSC mTLS call failed (', $mtls[1]);
		$this->assertSame('[FscDirectoryClient] mTLS call failed', $this->logs[0][1]);

		$client = $this->client([new Response(201, ['X-FSC-Reference' => ' r-1 '], '{"a":1}')]);
		$this->assertSame(['ref' => 'r-1', 'statusCode' => 201, 'body' => ['a' => 1]], $client->call($config, $resolution, 'put', ['k' => 'v']));
		$request = $this->history[0]['request'];
		$this->assertSame(
			['PUT', 'https://svc.example.nl/x', 'application/json', 'application/json', 'Token tok', '{"k":"v"}'],
			[$request->getMethod(), (string)$request->getUri(), $request->getHeaderLine('Content-Type'), $request->getHeaderLine('Accept'), $request->getHeaderLine('Authorization'), (string)$request->getBody()]
		);
		$this->assertSame(['ref' => 'x-9', 'statusCode' => 200, 'body' => 'plain'], $this->client([new Response(200, ['X-FSC-Reference' => 'x-9'], 'plain')])->call($config, $resolution, 'get', []));

	}//end testCallOutcomes()
}//end class
