<?php

/**
 * EndpointResponseCache unit tests.
 *
 * The response cache keeps a source endpoint's answers in the distributed
 * cache: per consumer unless shared, only for safe methods and the configured
 * status codes, never an upstream `private` or `no-store` answer, shortened
 * by a lower upstream `max-age`, and cleared by moving the endpoint to a new
 * generation.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use OCA\Integriq\Service\Endpoint\EndpointResponseCache;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the endpoint response cache.
 */
#[CoversClass(EndpointResponseCache::class)]
class EndpointResponseCacheTest extends TestCase {

	/**
	 * What the distributed cache holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	/**
	 * The TTL of each write, by key.
	 *
	 * @var array<string, int>
	 */
	private array $ttls = [];

	/**
	 * The prefix the distributed cache was created with.
	 *
	 * @var string|null
	 */
	private ?string $prefix = null;

	/**
	 * The service under test.
	 *
	 * @var EndpointResponseCache
	 */
	private EndpointResponseCache $cache;

	/**
	 * An in-memory distributed cache behind the factory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->stored = [];
		$this->ttls = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->stored[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->stored[$key] = $value;
				$this->ttls[$key] = $ttl;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturnCallback(
			function (string $prefix) use ($cache): ICache {
				$this->prefix = $prefix;
				return $cache;
			}
		);

		$this->cache = new EndpointResponseCache(cacheFactory: $factory);
	}//end setUp()

	/**
	 * Settings with the given cache block.
	 *
	 * @param array<string, mixed> $cache The endpoint's cache block.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(array $cache = []): array {
		$settings = $this->cache->settings(endpointData: ['cache' => ($cache + ['enabled' => true])]);
		$this->assertNotNull($settings);

		return $settings;
	}//end settings()

	/**
	 * A request of one consumer.
	 *
	 * @param string               $consumer The consumer uuid.
	 * @param array<string, mixed> $query    The query.
	 * @param string               $method   The method.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(string $consumer = 'consumer-a', array $query = ['q' => 'kerk'], string $method = 'GET'): array {
		return ['method' => $method, 'path' => '/lookup', 'query' => $query, 'headers' => ['accept-language' => 'nl'], 'consumer' => $consumer];
	}//end request()

	/**
	 * An upstream answer.
	 *
	 * @param array<string, mixed> $headers Its headers.
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(array $headers = ['Content-Type' => 'application/json']): array {
		return ['body' => '{"hits":3}', 'headers' => $headers];
	}//end answer()

	/**
	 * An endpoint without a cache block, or with it off, caches nothing.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutAnEnabledCacheHasNoSettings(): void {
		$this->assertNull($this->cache->settings(endpointData: []));
		$this->assertNull($this->cache->settings(endpointData: ['cache' => ['enabled' => false, 'ttlSeconds' => 60]]));
		$this->assertNull($this->cache->settings(endpointData: ['cache' => ['enabled' => 'true']]));
	}//end testAnEndpointWithoutAnEnabledCacheHasNoSettings()

	/**
	 * The defaults are a 60 second lifetime, GET and HEAD, status 200, per consumer.
	 *
	 * @return void
	 */
	public function testTheDefaultsAreSixtySecondsGetAndHeadStatus200PerConsumer(): void {
		$this->assertSame(
			['ttlSeconds' => 60, 'methods' => ['GET', 'HEAD'], 'statusCodes' => [200], 'varyHeaders' => [], 'sharedAcrossConsumers' => false, 'ignoreUpstreamMaxAge' => false],
			$this->settings()
		);
	}//end testTheDefaultsAreSixtySecondsGetAndHeadStatus200PerConsumer()

	/**
	 * Only safe methods are ever cached, whatever the endpoint lists, and the lifetime is bounded.
	 *
	 * @return void
	 */
	public function testOnlySafeMethodsAreCachedAndTheLifetimeIsBounded(): void {
		$settings = $this->settings(['methods' => ['get', 'POST', 'DELETE'], 'ttlSeconds' => 999999, 'varyHeaders' => ['Accept-Language', ''], 'statusCodes' => [200, '203', 'x']]);

		$this->assertSame(['GET'], $settings['methods']);
		$this->assertSame(EndpointResponseCache::MAX_TTL, $settings['ttlSeconds']);
		$this->assertSame(['accept-language'], $settings['varyHeaders']);
		$this->assertSame([200, 203], $settings['statusCodes']);
		$this->assertFalse($this->cache->store(endpointId: 'ep-1', settings: $settings, request: self::request(method: 'POST'), status: 200, answer: self::answer()));
		$this->assertSame([], $this->stored);
	}//end testOnlySafeMethodsAreCachedAndTheLifetimeIsBounded()

	/**
	 * A kept answer is found again by the same consumer, in the distributed cache, with the endpoint's lifetime.
	 *
	 * @return void
	 */
	public function testAKeptAnswerIsFoundAgainByTheSameConsumer(): void {
		$settings = $this->settings(['ttlSeconds' => 45]);

		$this->assertNull($this->cache->lookup(endpointId: 'ep-1', settings: $settings, request: self::request()));
		$this->assertTrue($this->cache->store(endpointId: 'ep-1', settings: $settings, request: self::request(), status: 200, answer: self::answer()));

		$this->assertSame(['status' => 200, 'answer' => self::answer()], $this->cache->lookup(endpointId: 'ep-1', settings: $settings, request: self::request()));
		$this->assertSame(EndpointResponseCache::PREFIX, $this->prefix);
		$answerKey = $this->cache->key(endpointId: 'ep-1', settings: $settings, request: self::request());
		$this->assertSame(45, $this->ttls[$answerKey]);
	}//end testAKeptAnswerIsFoundAgainByTheSameConsumer()

	/**
	 * The key holds the consumer, so another consumer misses, unless the endpoint shares its answers.
	 *
	 * @return void
	 */
	public function testAnotherConsumerMissesUnlessAnswersAreShared(): void {
		$perConsumer = $this->settings();
		$this->cache->store(endpointId: 'ep-1', settings: $perConsumer, request: self::request(), status: 200, answer: self::answer());
		$this->assertNull($this->cache->lookup(endpointId: 'ep-1', settings: $perConsumer, request: self::request(consumer: 'consumer-b')));

		$shared = $this->settings(['sharedAcrossConsumers' => true]);
		$this->cache->store(endpointId: 'ep-1', settings: $shared, request: self::request(), status: 200, answer: self::answer());
		$this->assertNotNull($this->cache->lookup(endpointId: 'ep-1', settings: $shared, request: self::request(consumer: 'consumer-b')));
	}//end testAnotherConsumerMissesUnlessAnswersAreShared()

	/**
	 * The query order does not matter, its values and the vary headers do.
	 *
	 * @return void
	 */
	public function testTheKeyIgnoresQueryOrderButNotValuesOrVaryHeaders(): void {
		$settings = $this->settings(['varyHeaders' => ['Accept-Language']]);
		$base = $this->cache->key(endpointId: 'ep-1', settings: $settings, request: self::request(query: ['a' => '1', 'b' => ['y' => 2, 'x' => 1]]));

		$this->assertSame($base, $this->cache->key(endpointId: 'ep-1', settings: $settings, request: self::request(query: ['b' => ['x' => 1, 'y' => 2], 'a' => '1'])));
		$this->assertNotSame($base, $this->cache->key(endpointId: 'ep-1', settings: $settings, request: self::request(query: ['a' => '2', 'b' => ['y' => 2, 'x' => 1]])));

		$english = self::request(query: ['a' => '1', 'b' => ['y' => 2, 'x' => 1]]);
		$english['headers'] = ['accept-language' => 'en'];
		$this->assertNotSame($base, $this->cache->key(endpointId: 'ep-1', settings: $settings, request: $english));
		$this->assertNotSame($base, $this->cache->key(endpointId: 'ep-2', settings: $settings, request: self::request(query: ['a' => '1', 'b' => ['y' => 2, 'x' => 1]])));
	}//end testTheKeyIgnoresQueryOrderButNotValuesOrVaryHeaders()

	/**
	 * An upstream answer marked private or no-store is never kept.
	 *
	 * @return void
	 */
	public function testAPrivateOrNoStoreAnswerIsNeverKept(): void {
		$settings = $this->settings(['ignoreUpstreamMaxAge' => true]);

		$this->assertFalse($this->cache->store(endpointId: 'ep-1', settings: $settings, request: self::request(), status: 200, answer: self::answer(['Cache-Control' => 'Private, max-age=600'])));
		$this->assertFalse($this->cache->store(endpointId: 'ep-1', settings: $settings, request: self::request(), status: 200, answer: self::answer(['cache-control' => ['public', 'no-store']])));
		$this->assertNull($this->cache->lookup(endpointId: 'ep-1', settings: $settings, request: self::request()));
	}//end testAPrivateOrNoStoreAnswerIsNeverKept()

	/**
	 * A lower upstream max-age shortens the lifetime, a higher one does not lengthen it, zero keeps nothing.
	 *
	 * @return void
	 */
	public function testALowerUpstreamMaxAgeShortensTheLifetime(): void {
		$settings = $this->settings(['ttlSeconds' => 60]);

		$this->assertSame(20, $this->cache->lifetime(settings: $settings, headers: ['Cache-Control' => 'public, max-age=20']));
		$this->assertSame(60, $this->cache->lifetime(settings: $settings, headers: ['Cache-Control' => 'max-age=3600']));
		$this->assertNull($this->cache->lifetime(settings: $settings, headers: ['Cache-Control' => 'max-age=0']));
		$this->assertSame(60, $this->cache->lifetime(settings: $this->settings(['ttlSeconds' => 60, 'ignoreUpstreamMaxAge' => true]), headers: ['Cache-Control' => 'max-age=5']));
	}//end testALowerUpstreamMaxAgeShortensTheLifetime()

	/**
	 * Only the configured status codes are kept.
	 *
	 * @return void
	 */
	public function testOnlyTheConfiguredStatusCodesAreKept(): void {
		$settings = $this->settings();

		$this->assertFalse($this->cache->store(endpointId: 'ep-1', settings: $settings, request: self::request(), status: 500, answer: self::answer()));
		$this->assertFalse($this->cache->store(endpointId: 'ep-1', settings: $settings, request: self::request(), status: 404, answer: self::answer()));
		$this->assertTrue($this->cache->store(endpointId: 'ep-1', settings: $this->settings(['statusCodes' => [404]]), request: self::request(), status: 404, answer: self::answer()));
	}//end testOnlyTheConfiguredStatusCodesAreKept()

	/**
	 * A very large answer is served but not kept.
	 *
	 * @return void
	 */
	public function testAnAnswerAboveTheSizeLimitIsNotKept(): void {
		$large = ['body' => str_repeat('x', EndpointResponseCache::MAX_ENTRY_BYTES), 'headers' => []];

		$this->assertFalse($this->cache->store(endpointId: 'ep-1', settings: $this->settings(), request: self::request(), status: 200, answer: $large));
	}//end testAnAnswerAboveTheSizeLimitIsNotKept()

	/**
	 * Clearing an endpoint forgets its answers, and no other endpoint's.
	 *
	 * @return void
	 */
	public function testClearingAnEndpointForgetsOnlyItsAnswers(): void {
		$settings = $this->settings();
		$this->cache->store(endpointId: 'ep-1', settings: $settings, request: self::request(), status: 200, answer: self::answer());
		$this->cache->store(endpointId: 'ep-2', settings: $settings, request: self::request(), status: 200, answer: self::answer());

		$this->cache->clear(endpointId: 'ep-1');

		$this->assertNull($this->cache->lookup(endpointId: 'ep-1', settings: $settings, request: self::request()));
		$this->assertNotNull($this->cache->lookup(endpointId: 'ep-2', settings: $settings, request: self::request()));
	}//end testClearingAnEndpointForgetsOnlyItsAnswers()

	/**
	 * A generation the cache lost is drawn afresh, so older answers never come back.
	 *
	 * @return void
	 */
	public function testALostGenerationNeverBringsOlderAnswersBack(): void {
		$settings = $this->settings();
		$this->cache->store(endpointId: 'ep-1', settings: $settings, request: self::request(), status: 200, answer: self::answer());

		foreach (array_keys($this->stored) as $key) {
			if (str_starts_with($key, 'g:') === true) {
				unset($this->stored[$key]);
			}
		}

		$this->assertNull($this->cache->lookup(endpointId: 'ep-1', settings: $settings, request: self::request()));
	}//end testALostGenerationNeverBringsOlderAnswersBack()

	/**
	 * A corrupt entry is a miss, not an answer.
	 *
	 * @return void
	 */
	public function testACorruptEntryIsAMiss(): void {
		$settings = $this->settings();
		$this->stored[$this->cache->key(endpointId: 'ep-1', settings: $settings, request: self::request())] = '{"status":"200"}';

		$this->assertNull($this->cache->lookup(endpointId: 'ep-1', settings: $settings, request: self::request()));
	}//end testACorruptEntryIsAMiss()
}//end class
