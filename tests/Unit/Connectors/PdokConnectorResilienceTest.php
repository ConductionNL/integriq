<?php
/**
 * PDOK connector resilience: no cache, empty queries, 429 retries, bad bodies,
 * the circuit breaker's threshold and half-open state, and stale stored rows.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Connectors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/pdok-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Connectors;

use OCA\Integriq\Connectors\PdokConnector;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What the connector does when its cache, the upstream or the stored copy misbehave.
 *
 * @covers \OCA\Integriq\Connectors\PdokConnector
 * @uses   \OCA\Integriq\Connectors\PdokQuery
 * @uses   \OCA\Integriq\Connectors\PdokUpstreamException
 */
class PdokConnectorResilienceTest extends TestCase {

	private const CIRCUIT_KEY = 'pdok_connector::circuit';

	private const DOC = [
		'id' => 'adr-1',
		'type' => 'adres',
		'straatnaam' => 'Lauriergracht',
		'woonplaatsnaam' => 'Amsterdam',
		'centroide_ll' => 'POINT(4.8825 52.371)',
	];

	/**
	 * @var IClient&MockObject
	 */
	private IClient&MockObject $client;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * In-memory cache contents, or null for no cache at all.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cacheStore = [];

	/**
	 * The OpenRegister object service the container hands out, if any.
	 *
	 * @var ObjectService|null
	 */
	private ?ObjectService $objectService = null;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->client = $this->createMock(originalClassName: IClient::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The connector over the current doubles.
	 *
	 * @return PdokConnector
	 */
	private function connector(): PdokConnector {
		$clients = $this->createMock(originalClassName: IClientService::class);
		$clients->method('newClient')->willReturn($this->client);

		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		if ($this->cacheStore === null) {
			$factory->method('createDistributed')->willThrowException(new RuntimeException(message: 'no memcache'));
		} else {
			$cache = $this->createMock(originalClassName: ICache::class);
			$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cacheStore[$key] ?? null);
			$cache->method('set')->willReturnCallback(
				function (string $key, mixed $value, int $ttl = 0): bool {
					$this->cacheStore[$key] = $value;
					return true;
				}
			);
			$factory->method('createDistributed')->willReturn($cache);
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($this->objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException(message: 'no OR'));
		} else {
			$container->method('get')->willReturn($this->objectService);
		}

		return new PdokConnector(clientService: $clients, cacheFactory: $factory, logger: $this->logger, container: $container);
	}//end connector()

	/**
	 * A response with the given status and body.
	 *
	 * @param integer $status The HTTP status.
	 * @param string  $body   The raw body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body = ''): IResponse {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()

	/**
	 * A PDOK success body holding the one test document.
	 *
	 * @return string
	 */
	private function okBody(): string {
		return (string)json_encode(value: ['response' => ['numFound' => 1, 'docs' => [self::DOC]]]);
	}//end okBody()

	/**
	 * Without a cache factory the connector warns once and still answers from upstream.
	 *
	 * @return void
	 */
	public function testItWorksWithoutACache(): void {
		$this->cacheStore = null;
		$this->logger->expects($this->once())->method('warning')
			->with('PdokConnector: cache factory unavailable, proceeding uncached', ['exception' => 'no memcache']);
		$this->client->expects($this->exactly(count: 2))->method('get')
			->willReturnOnConsecutiveCalls($this->response(status: 200, body: $this->okBody()), $this->response(status: 500));
		$connector = $this->connector();

		$result = $connector->free(query: 'Lauriergracht');
		$this->assertSame(expected: 1, actual: $result['numFound']);
		$this->assertSame(expected: 'adr-1', actual: $result['docs'][0]['pdokId']);

		$this->assertSame(expected: ['docs' => [], 'numFound' => 0, 'stale' => true], actual: $connector->free(query: 'Lauriergracht'));
	}//end testItWorksWithoutACache()

	/**
	 * Blank lookups and free searches answer empty without calling upstream.
	 *
	 * @return void
	 */
	public function testBlankQueriesDoNotCallUpstream(): void {
		$this->client->expects($this->never())->method('get');
		$connector = $this->connector();

		$this->assertSame(expected: ['docs' => [], 'numFound' => 0], actual: $connector->lookup(id: '  '));
		$this->assertSame(expected: ['docs' => [], 'numFound' => 0], actual: $connector->free(query: ''));
	}//end testBlankQueriesDoNotCallUpstream()

	/**
	 * A 429 is retried with backoff and a later success is returned and closes the breaker.
	 *
	 * @return void
	 */
	public function testA429IsRetried(): void {
		$this->cacheStore = [self::CIRCUIT_KEY => ['state' => 'closed', 'failures' => 2, 'opened_at' => 0]];
		$this->client->expects($this->exactly(count: 2))->method('get')
			->willReturnOnConsecutiveCalls($this->response(status: 429), $this->response(status: 200, body: $this->okBody()));

		$result = $this->connector()->free(query: 'gracht');

		$this->assertSame(expected: 1, actual: $result['numFound']);
		$this->assertSame(expected: ['state' => 'closed', 'failures' => 0, 'opened_at' => 0], actual: $this->cacheStore[self::CIRCUIT_KEY]);
	}//end testA429IsRetried()

	/**
	 * Exhausted 429 retries answer stale, count a failure and are logged as an error.
	 *
	 * @return void
	 */
	public function testExhausted429RetriesAnswerStale(): void {
		$this->client->expects($this->exactly(count: 3))->method('get')->willReturn($this->response(status: 429));
		$this->logger->expects($this->once())->method('error')
			->with('PdokConnector upstream call', $this->callback(callback: static fn (array $context): bool => $context['http_status'] === 503));

		$this->assertSame(expected: ['docs' => [], 'numFound' => 0, 'stale' => true], actual: $this->connector()->free(query: 'gracht'));
		$this->assertSame(expected: 1, actual: $this->cacheStore[self::CIRCUIT_KEY]['failures']);
	}//end testExhausted429RetriesAnswerStale()

	/**
	 * A success status with a non-JSON body answers stale with a 502.
	 *
	 * @return void
	 */
	public function testANonJsonBodyAnswersStale(): void {
		$this->client->method('get')->willReturn($this->response(status: 200, body: '<html>'));
		$this->logger->expects($this->once())->method('error')
			->with('PdokConnector upstream call', $this->callback(callback: static fn (array $context): bool => $context['http_status'] === 502));

		$this->assertTrue(condition: $this->connector()->free(query: 'gracht')['stale']);
	}//end testANonJsonBodyAnswersStale()

	/**
	 * The fifth consecutive failure opens the breaker, which then short-circuits.
	 *
	 * @return void
	 */
	public function testTheBreakerOpensAtTheThreshold(): void {
		$this->cacheStore = [self::CIRCUIT_KEY => ['state' => 'closed', 'failures' => 4, 'opened_at' => 0]];
		$this->client->expects($this->once())->method('get')->willReturn($this->response(status: 503));
		$connector = $this->connector();

		$connector->free(query: 'gracht');
		$state = $this->cacheStore[self::CIRCUIT_KEY];
		$this->assertSame(expected: 'open', actual: $state['state']);
		$this->assertSame(expected: 5, actual: $state['failures']);
		$this->assertGreaterThan(expected: time() - 5, actual: $state['opened_at']);

		$this->assertSame(expected: ['docs' => [], 'numFound' => 0, 'stale' => true], actual: $connector->free(query: 'other'));
	}//end testTheBreakerOpensAtTheThreshold()

	/**
	 * An open breaker older than its open window lets a call through (half-open).
	 *
	 * @return void
	 */
	public function testAnExpiredOpenBreakerIsHalfOpen(): void {
		$this->cacheStore = [self::CIRCUIT_KEY => ['state' => 'open', 'failures' => 5, 'opened_at' => time() - 31]];
		$this->client->expects($this->once())->method('get')->willReturn($this->response(status: 200, body: $this->okBody()));

		$this->assertSame(expected: 1, actual: $this->connector()->free(query: 'gracht')['numFound']);
		$this->assertSame(expected: 'closed', actual: $this->cacheStore[self::CIRCUIT_KEY]['state']);
	}//end testAnExpiredOpenBreakerIsHalfOpen()

	/**
	 * A stored address with an unreadable fetch time is not trusted; upstream answers instead.
	 *
	 * @return void
	 */
	public function testAStoredRowWithAnUnreadableFetchTimeIsIgnored(): void {
		$this->objectService = new class extends ObjectService {
			/**
			 * No constructor dependencies.
			 */
			public function __construct() {
			}

			/**
			 * A mapper answering one row with a garbage fetch time.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return object
			 */
			public function getMapper(...$args): object {
				return new class {
					/**
					 * One stale row.
					 *
					 * @param array<string,mixed> $config The query.
					 *
					 * @return array<int,array<string,mixed>>
					 */
					public function findAll(array $config): array {
						return [['pdokId' => 'adr-1', 'fetchedAt' => 'not a date']];
					}
				};
			}
		};
		$this->client->expects($this->once())->method('get')->willReturn($this->response(status: 200, body: $this->okBody()));

		$this->assertSame(expected: 'adr-1', actual: $this->connector()->lookup(id: 'adr-1')['docs'][0]['pdokId']);
	}//end testAStoredRowWithAnUnreadableFetchTimeIsIgnored()

	/**
	 * A missing or unparseable centroid leaves the location null.
	 *
	 * @return void
	 */
	public function testAMissingOrBadCentroidHasNoLocation(): void {
		$connector = $this->connector();

		$this->assertNull(actual: $connector->normalize(pdokDoc: ['id' => 'a'])['location']);
		$this->assertNull(actual: $connector->normalize(pdokDoc: ['id' => 'a', 'centroide_ll' => 'LINESTRING(1 2, 3 4)'])['location']);
	}//end testAMissingOrBadCentroidHasNoLocation()
}//end class
