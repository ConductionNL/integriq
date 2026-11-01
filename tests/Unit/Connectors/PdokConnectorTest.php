<?php

/**
 * Integriq PDOK Connector Test
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Connectors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Connectors;

use OCA\Integriq\Connectors\PdokConnector;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PdokConnector — normalisation, cache behaviour, breaker logic.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PdokConnectorTest extends TestCase {
	/**
	 * @var IClientService|MockObject
	 */
	private $clientService;

	/**
	 * @var ICacheFactory|MockObject
	 */
	private $cacheFactory;

	/**
	 * @var ICache|MockObject
	 */
	private $cache;

	/**
	 * @var LoggerInterface|MockObject
	 */
	private $logger;

	/**
	 * @var ContainerInterface|MockObject
	 */
	private $container;

	/**
	 * Set up mocks shared across tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->clientService = $this->createMock(IClientService::class);
		$this->cache = $this->createMock(ICache::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('createDistributed')->willReturn($this->cache);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));

	}//end setUp()

	/**
	 * `normalize` maps every documented PDOK field to the canonical shape on a full address.
	 *
	 * @return void
	 */
	public function testNormalizeFullAddress(): void {
		$fixture = $this->loadFixture('lauriergracht');
		$connector = $this->makeConnector();

		$result = $connector->normalize($fixture['response']['docs'][0]);

		$this->assertSame('adr-0363200000218908', $result['pdokId']);
		$this->assertSame('Lauriergracht', $result['streetAddress']);
		$this->assertSame('14h', $result['houseNumber']);
		$this->assertSame('1016RD', $result['postalCode']);
		$this->assertSame('Amsterdam', $result['addressLocality']);
		$this->assertSame('Noord-Holland', $result['addressRegion']);
		$this->assertSame('NL', $result['addressCountry']);
		$this->assertSame('0363200000218908', $result['bagAddressId']);
		$this->assertSame('pdok', $result['source']);
		$this->assertIsArray($result['location']);
		$this->assertSame('Point', $result['location']['type']);
		$this->assertSame([4.8825, 52.371], $result['location']['coordinates']);

	}//end testNormalizeFullAddress()

	/**
	 * Woonplaats-only fixture maps optional fields to null (never absent).
	 *
	 * @return void
	 */
	public function testNormalizeWoonplaatsHasNullFieldsPresent(): void {
		$fixture = $this->loadFixture('woonplaats-tilburg');
		$connector = $this->makeConnector();

		$result = $connector->normalize($fixture['response']['docs'][0]);

		$this->assertArrayHasKey('postalCode', $result);
		$this->assertNull($result['postalCode']);
		$this->assertArrayHasKey('houseNumber', $result);
		$this->assertNull($result['houseNumber']);
		$this->assertArrayHasKey('streetAddress', $result);
		$this->assertNull($result['streetAddress']);
		$this->assertSame('Tilburg', $result['addressLocality']);
		$this->assertSame('pdok', $result['source']);

	}//end testNormalizeWoonplaatsHasNullFieldsPresent()

	/**
	 * Stadhuisplein fixture: number without huisletter formatting.
	 *
	 * @return void
	 */
	public function testNormalizeWithoutHuisletter(): void {
		$fixture = $this->loadFixture('stadhuisplein-tilburg');
		$connector = $this->makeConnector();

		$result = $connector->normalize($fixture['response']['docs'][0]);

		$this->assertSame('1', $result['houseNumber']);
		$this->assertSame('5038TC', $result['postalCode']);
		$this->assertSame('Tilburg', $result['addressLocality']);

	}//end testNormalizeWithoutHuisletter()

	/**
	 * Cache hit on `suggest` skips the HTTP call entirely.
	 *
	 * @return void
	 */
	public function testSuggestCacheHitSkipsUpstream(): void {
		$cached = ['docs' => [['pdokId' => 'cached']], 'numFound' => 1];
		$this->cache->method('get')->willReturn($cached);
		$this->clientService->expects($this->never())->method('newClient');

		$result = $this->makeConnector()->suggest('lauriergracht');
		$this->assertSame($cached, $result);

	}//end testSuggestCacheHitSkipsUpstream()

	/**
	 * Open circuit returns the stale-flagged envelope without calling upstream.
	 *
	 * @return void
	 */
	public function testOpenCircuitShortCircuitsUpstream(): void {
		$this->cache->method('get')->willReturnCallback(function ($key) {
			if (str_contains($key, '::circuit')) {
				return ['state' => 'open', 'failures' => 5, 'opened_at' => time()];
			}
			return null;
		});
		$this->clientService->expects($this->never())->method('newClient');

		$result = $this->makeConnector()->lookup('adr-0363200000218908');

		$this->assertTrue($result['stale']);
		$this->assertSame(0, $result['numFound']);

	}//end testOpenCircuitShortCircuitsUpstream()

	/**
	 * Empty query parameter short-circuits the upstream call.
	 *
	 * @return void
	 */
	public function testEmptyQueryReturnsEmpty(): void {
		$this->clientService->expects($this->never())->method('newClient');
		$result = $this->makeConnector()->suggest('   ');
		$this->assertSame(0, $result['numFound']);
		$this->assertSame([], $result['docs']);

	}//end testEmptyQueryReturnsEmpty()

	/**
	 * A successful 200 response is decoded, normalised and cached.
	 *
	 * @return void
	 */
	public function testSuccessfulFreeTextSearch(): void {
		$fixture = $this->loadFixture('lauriergracht');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode($fixture));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);
		$this->cache->method('get')->willReturn(null);

		$result = $this->makeConnector()->free('Lauriergracht');

		$this->assertSame(1, $result['numFound']);
		$this->assertSame('Lauriergracht', $result['docs'][0]['streetAddress']);

	}//end testSuccessfulFreeTextSearch()

	/**
	 * Build a fresh connector with the shared mocks.
	 *
	 * @return PdokConnector
	 */
	private function makeConnector(): PdokConnector {
		return new PdokConnector(
			$this->clientService,
			$this->cacheFactory,
			$this->logger,
			$this->container
		);

	}//end makeConnector()

	/**
	 * Load a raw PDOK fixture by name.
	 *
	 * @param string $name The fixture file stem.
	 *
	 * @return array Decoded fixture payload.
	 */
	private function loadFixture(string $name): array {
		$path = (__DIR__ . '/../../fixtures/pdok/fixture-' . $name . '.json');
		return json_decode((string)file_get_contents($path), true);
	}//end loadFixture()

	/**
	 * Every fetch path matches the golden file recorded before fetch() was
	 * split: the cache, the stored-address lookup, the open circuit, upstream
	 * failures and success with write-through, as results, cache writes,
	 * register calls and the one observability log line per call.
	 *
	 * Regenerate with PDOK_FETCH_GOLDEN=1.
	 *
	 * @return void
	 */
	public function testEveryFetchPathMatchesTheGoldenFile(): void {
		$fixture = $this->loadFixture('lauriergracht');
		$fresh = gmdate('c');
		$cases = [
			'suggest-cache-hit' => ['suggest', ['cache' => ['docs' => [['pdokId' => 'c']], 'numFound' => 1]]],
			'suggest-upstream-200' => ['suggest', ['status' => 200]],
			'free-upstream-200' => ['free', ['status' => 200]],
			'free-upstream-500' => ['free', ['status' => 500]],
			'free-upstream-throws' => ['free', ['status' => 0]],
			'lookup-open-circuit' => ['lookup', ['circuit' => 'open']],
			'lookup-stored-fresh' => ['lookup', ['stored' => [['pdokId' => 'adr-1', 'fetchedAt' => $fresh]]]],
			'lookup-stored-stale' => ['lookup', ['stored' => [['pdokId' => 'adr-1', 'fetchedAt' => '2001-01-01T00:00:00Z']], 'status' => 200]],
			'lookup-no-register' => ['lookup', ['noRegister' => true, 'status' => 200]],
			'reverse-stored-empty-upstream-200' => ['reverse', ['stored' => [], 'status' => 200]],
			'reverse-write-fails' => ['reverse', ['stored' => [], 'status' => 200, 'saveFails' => true]],
		];

		$actual = [];
		foreach ($cases as $name => [$method, $case]) {
			$this->setUp();
			$calls = [];
			$store = [];
			if (isset($case['cache']) === true) {
				$store['PLACEHOLDER'] = $case['cache'];
			}

			$this->cache->method('get')->willReturnCallback(
				function (string $key) use (&$store, $case) {
					if (str_contains($key, '::circuit') === true) {
						return (($case['circuit'] ?? null) === 'open' ? ['state' => 'open', 'failures' => 5, 'opened_at' => time()] : ($store[$key] ?? null));
					}

					return ($store[$key] ?? ($store['PLACEHOLDER'] ?? null));
				}
			);
			$this->cache->method('set')->willReturnCallback(
				function (string $key, $value, int $ttl = 0) use (&$store, &$calls) {
					$store[$key] = $value;
					$calls[] = ['cache.set', (str_contains($key, '::circuit') === true ? 'circuit' : 'answer'), $ttl, (str_contains($key, '::circuit') === true ? ($value['state'] ?? null) : $value)];
					return true;
				}
			);

			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn(($case['status'] ?? 200));
			$response->method('getBody')->willReturn(json_encode($fixture));
			$client = $this->createMock(IClient::class);
			$client->method('get')->willReturnCallback(
				function (string $url, array $options) use ($response, $case, &$calls) {
					$calls[] = ['http.get', $url, $options];
					if (($case['status'] ?? 200) === 0) {
						throw new \RuntimeException('dns failure');
					}

					return $response;
				}
			);
			$this->clientService->method('newClient')->willReturn($client);

			foreach (['debug', 'warning', 'error'] as $level) {
				$this->logger->method($level)->willReturnCallback(
					function (string $message, array $context = []) use ($level, &$calls) {
						if (array_key_exists('upstream_latency_ms', $context) === true && $context['upstream_latency_ms'] !== null) {
							$context['upstream_latency_ms'] = '(ms)';
						}

						$calls[] = ['log.' . $level, $message, $context];
					}
				);
			}

			if (isset($case['stored']) === true || ($case['noRegister'] ?? false) === true) {
				$register = new class($case, $calls) {
					/**
					 * @param array $case  The case.
					 * @param array $calls The call log, by reference.
					 */
					public function __construct(private array $case, private array &$calls) {
					}

					/**
					 * @param mixed ...$args Ignored.
					 *
					 * @return object
					 */
					public function getMapper(...$args): object {
						$this->calls[] = ['or.getMapper', $args];
						$stored = ($this->case['stored'] ?? []);
						return new class($stored) {
							/**
							 * @param array $stored The stored rows.
							 */
							public function __construct(private array $stored) {
							}

							/**
							 * @param array $config The query.
							 *
							 * @return array
							 */
							public function findAll(array $config): array {
								return $this->stored;
							}
						};
					}

					/**
					 * @param mixed ...$args The save arguments.
					 *
					 * @return void
					 */
					public function saveObject(...$args): void {
						$this->calls[] = ['or.saveObject', $args];
						if (($this->case['saveFails'] ?? false) === true) {
							throw new \RuntimeException('register refused');
						}
					}
				};
				$this->container = $this->createMock(ContainerInterface::class);
				if (($case['noRegister'] ?? false) === true) {
					$this->container->method('get')->willThrowException(new \RuntimeException('no OR'));
				} else {
					$this->container->method('get')->willReturn($register);
				}
			}//end if

			$connector = $this->makeConnector();
			$result = match ($method) {
				'suggest' => $connector->suggest('lauriergracht'),
				'free' => $connector->free('Lauriergracht', 5, 2),
				'lookup' => $connector->lookup('adr-1'),
				'reverse' => $connector->reverse(52.37, 4.88),
			};

			$actual[$name] = ['result' => $result, 'calls' => $calls];
		}//end foreach

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		$encoded = str_replace($fresh, '(fresh)', $encoded);
		$encoded = (string)preg_replace('/"fetchedAt": "(?!2001)[^"]+Z"/', '"fetchedAt": "(clock)"', $encoded);
		$golden = __DIR__ . '/../../fixtures/connectors/pdok-fetch.json';
		if (getenv('PDOK_FETCH_GOLDEN') === '1') {
			if (is_dir(dirname($golden)) === false) {
				mkdir(dirname($golden), 0777, true);
			}

			file_put_contents($golden, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents($golden), true), json_decode($encoded, true));
	}//end testEveryFetchPathMatchesTheGoldenFile()
}//end class
