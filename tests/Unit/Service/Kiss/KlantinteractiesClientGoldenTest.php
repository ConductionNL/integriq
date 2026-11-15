<?php

/**
 * Golden test pinning KlantinteractiesClient's list reading and request dispatch.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Service\Kiss\KlantinteractiesClient;
use OCP\IL10N;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the listed items and cursor for every response shape, the requests sent, and every
 * refusal's exact message with its log line.
 */
class KlantinteractiesClientGoldenTest extends TestCase {

	/** @var array<int, array> Guzzle history. */
	private array $history = [];

	/** @var array<int, array> Log lines: [level, message, context]. */
	private array $logs = [];

	/** @var array The source configuration. */
	private array $configuration = ['baseUrl' => 'https://kiss.example.nl/api/v1/', 'authentication' => ['encryptedToken' => 'c']];

	/**
	 * A client answering with the queued responses or exceptions.
	 *
	 * @param array $queue The MockHandler queue.
	 *
	 * @return KlantinteractiesClient
	 */
	private function client(array $queue): KlantinteractiesClient {
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

		return new KlantinteractiesClient(new Client(['handler' => $stack]), $crypto, $l, $logger);

	}//end client()

	/**
	 * The sent request: method, URL with query, Authorization and body.
	 *
	 * @param int $index Which request.
	 *
	 * @return array
	 */
	private function sent(int $index = 0): array {
		$request = $this->history[$index]['request'];

		return [$request->getMethod(), (string)$request->getUri(), $request->getHeaderLine('Authorization'), $request->getHeaderLine('Content-Type'), (string)$request->getBody()];

	}//end sent()

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
	 * Every response shape of the klantcontacten list.
	 *
	 * @return void
	 */
	public function testListShapes(): void {
		$paged = '{"results":[{"uuid":"a","registratiedatum":"2026-01-02"},{"uuid":"b"},{"uuid":"c","registratiedatum":"2026-03-04"},{"uuid":"d","registratiedatum":"2026-02-01"}]}';
		$this->assertSame(
			['items' => [['uuid' => 'a', 'registratiedatum' => '2026-01-02'], ['uuid' => 'b'], ['uuid' => 'c', 'registratiedatum' => '2026-03-04'], ['uuid' => 'd', 'registratiedatum' => '2026-02-01']], 'nextCursor' => '2026-03-04'],
			$this->client([new Response(200, [], $paged)])->listCustomerContacts($this->configuration, '2026-01-01', 25)
		);
		$this->assertSame(
			['GET', 'https://kiss.example.nl/api/v1/klantcontacten?expand=betrokkenen%2Conderwerpobjecten&sorteer=registratiedatum&pageSize=25&registratiedatum__gte=2026-01-01', 'Token tok', 'application/json', ''],
			$this->sent()
		);

		$this->assertSame(
			['items' => [['uuid' => 'x']], 'nextCursor' => null],
			$this->client([new Response(200, [], '{"k":{"uuid":"x"}}')])->listCustomerContacts($this->configuration, '', 5)
		);
		$this->assertStringNotContainsString('registratiedatum__gte', $this->sent()[1]);
		$this->assertSame(['items' => [], 'nextCursor' => null], $this->client([new Response(200, [], 'not json')])->listCustomerContacts($this->configuration, null, 5));
		$this->assertSame(
			['KissProviderException', 'KISS returned a non-JSON-array response for the klantcontacten list.', null],
			$this->thrown(fn () => $this->client([new Response(200, [], '{"results":"x"}')])->listCustomerContacts($this->configuration, null, 5))
		);

	}//end testListShapes()

	/**
	 * Every dispatch refusal, and a create with its betrokkene.
	 *
	 * @return void
	 */
	public function testDispatchRefusalsAndCreate(): void {
		$this->assertSame(
			['KissProviderException', 'KISS base URL missing: `configuration.baseUrl` is required.', null],
			$this->thrown(fn () => $this->client([])->listCustomerContacts(['baseUrl' => '/'], null, 5))
		);
		$this->assertSame(
			['KissProviderException', 'The KISS request failed unexpectedly: down', RequestException::class],
			$this->thrown(fn () => $this->client([new RequestException('down', new Request('GET', 'x'))])->listCustomerContacts($this->configuration, null, 5))
		);
		$this->assertSame([['warning', '[KlantinteractiesClient] unexpected transport failure', ['exception' => 'down']]], $this->logs);
		$this->assertSame(
			['KissProviderException', 'KISS responded with HTTP 404.', null],
			$this->thrown(fn () => $this->client([new Response(404, [], '{}')])->listCustomerContacts($this->configuration, null, 5))
		);

		$client = $this->client([new Response(201, [], '{"uuid":"kc-1"}'), new Response(201, [], '{"uuid":"b-1"}')]);
		$this->assertSame('kc-1', $client->createCustomerContact($this->configuration, ['tekst' => 'x', 'involvedParty' => ['rol' => 'klant']]));
		$this->assertSame(['POST', 'https://kiss.example.nl/api/v1/klantcontacten', 'Token tok', 'application/json', '{"tekst":"x"}'], $this->sent(0));
		$this->assertSame(['POST', 'https://kiss.example.nl/api/v1/betrokkenen', 'Token tok', 'application/json', '{"rol":"klant","klantcontact":{"uuid":"kc-1"}}'], $this->sent(1));

	}//end testDispatchRefusalsAndCreate()
}//end class
