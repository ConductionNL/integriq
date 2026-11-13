<?php

/**
 * Unit tests for JwksResolver — the key set resolver shared by the LTI path and
 * the consumer path: the caller's cache namespace and keys, per-owner
 * isolation, the rate-limited refetch, the caller's own log label and ad-hoc
 * source, and fetching a discovery document.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Jwks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Jwks;

use Jose\Component\KeyManagement\JWKFactory;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Jwks\JwksLookup;
use OCA\Integriq\Service\Jwks\JwksResolver;
use OCA\Integriq\Service\Jwks\JwksResolverProfile;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Tests for the shared key set resolver.
 */
#[CoversClass(JwksResolver::class)]
#[UsesClass(JwksLookup::class)]
#[UsesClass(JwksResolverProfile::class)]
class JwksResolverTest extends TestCase {

	private ArrayCache $cache;

	/**
	 * The calls made: [source uuid, source object, endpoint].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The log lines: [level, message, context].
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $logs = [];

	/**
	 * What the next call answers: [status, body] or a Throwable.
	 *
	 * @var array<int,mixed>|RuntimeException
	 */
	private array|RuntimeException $answer = [200, ''];

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cache = new ArrayCache();
		$this->calls = [];
		$this->logs = [];
	}//end setUp()

	/**
	 * A resolver over the test cache, call recorder and logger.
	 *
	 * @return JwksResolver The resolver.
	 */
	private function resolver(): JwksResolver {
		$callService = $this->createMock(CallService::class);
		$callService->method('call')->willReturnCallback(
			function (ObjectEntity $source, string $endpoint): ObjectEntity {
				$this->calls[] = [$source->getUuid(), $source->getObject(), $endpoint];
				if ($this->answer instanceof RuntimeException) {
					throw $this->answer;
				}

				$log = new ObjectEntity();
				$log->setObject(['response' => ['statusCode' => $this->answer[0], 'body' => $this->answer[1]]]);

				return $log;
			}
		);

		$logger = new class($this->logs) extends AbstractLogger {
			/**
			 * @param array<int,array<int,mixed>> $logs The sink.
			 */
			public function __construct(private array &$logs) {
			}

			/**
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed>       $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message, $context];
			}
		};

		return new JwksResolver(
			cache: $this->cache,
			callService: $callService,
			logger: $logger,
			profile: new JwksResolverProfile(logLabel: 'ConsumerKeys', sourceUuidPrefix: 'consumer-jwks-', sourceName: 'Consumer JWKS (ad-hoc)')
		);
	}//end resolver()

	/**
	 * A one-key JWKS document as JSON.
	 *
	 * @param string $kid The key id.
	 *
	 * @return string The document.
	 */
	private static function jwks(string $kid): string {
		$jwk = JWKFactory::createRSAKey(2048, ['kid' => $kid, 'alg' => 'RS256', 'use' => 'sig']);

		return (string)json_encode(['keys' => [$jwk->toPublic()->jsonSerialize()]]);
	}//end jwks()

	/**
	 * A key is fetched once, cached under the owner's key in the caller's cache, and served from there.
	 *
	 * @return void
	 */
	public function testAKeyIsFetchedOnceAndCachedPerOwner(): void {
		$this->answer = [200, self::jwks(kid: 'k1')];
		$resolver = $this->resolver();
		$lookup = new JwksLookup(ownerType: 'consumer', ownerUuid: 'c-1', jwksUri: 'https://idp.example:8443/realms/x/certs?v=2', kid: 'k1');

		$this->assertSame('k1', $resolver->resolveKey(lookup: $lookup)?->get('kid'));
		$this->assertSame('k1', $resolver->resolveKey(lookup: $lookup)?->get('kid'));

		$this->assertSame(
			[['consumer-jwks-c-1', ['name' => 'Consumer JWKS (ad-hoc)', 'isEnabled' => true, 'location' => 'https://idp.example:8443'], '/realms/x/certs?v=2']],
			$this->calls
		);
		$this->assertIsString($this->cache->get('jwks:consumer:c-1'));
		$this->assertTrue($this->cache->get('jwks:refetch:c-1'));
	}//end testAKeyIsFetchedOnceAndCachedPerOwner()

	/**
	 * Two owners sharing an address each get their own cached set.
	 *
	 * @return void
	 */
	public function testOwnersSharingAnAddressDoNotShareACache(): void {
		$this->answer = [200, self::jwks(kid: 'k1')];
		$resolver = $this->resolver();

		$resolver->resolveKey(lookup: new JwksLookup(ownerType: 'consumer', ownerUuid: 'c-1', jwksUri: 'https://idp.example/certs', kid: 'k1'));
		$resolver->resolveKey(lookup: new JwksLookup(ownerType: 'consumer', ownerUuid: 'c-2', jwksUri: 'https://idp.example/certs', kid: 'k1'));

		$this->assertSame(['consumer-jwks-c-1', 'consumer-jwks-c-2'], array_column($this->calls, 0));
	}//end testOwnersSharingAnAddressDoNotShareACache()

	/**
	 * An unknown kid refetches once; inside the guard window a second miss answers null without a call, logged under the caller's label.
	 *
	 * @return void
	 */
	public function testAnUnknownKidRefetchesOnceThenFailsClosed(): void {
		$this->answer = [200, self::jwks(kid: 'k1')];
		$resolver = $this->resolver();
		$lookup = new JwksLookup(ownerType: 'consumer', ownerUuid: 'c-1', jwksUri: 'https://idp.example/certs', kid: 'other');

		$this->assertNull($resolver->resolveKey(lookup: $lookup));
		$this->assertNull($resolver->resolveKey(lookup: $lookup));

		$this->assertCount(1, $this->calls);
		$this->assertSame(
			[['info', 'ConsumerKeys: refetch guard active, rejecting unresolved kid', ['registrationType' => 'consumer', 'registrationUuid' => 'c-1', 'kid' => 'other']]],
			$this->logs
		);
	}//end testAnUnknownKidRefetchesOnceThenFailsClosed()

	/**
	 * A rotated key is found after the guard window: the old set is cached, the refetch finds the new kid.
	 *
	 * @return void
	 */
	public function testARotatedKeyIsFoundByOneRefetch(): void {
		$this->cache->set('jwks:consumer:c-1', self::jwks(kid: 'old'));
		$this->answer = [200, self::jwks(kid: 'new')];

		$key = $this->resolver()->resolveKey(lookup: new JwksLookup(ownerType: 'consumer', ownerUuid: 'c-1', jwksUri: 'https://idp.example/certs', kid: 'new'));

		$this->assertSame('new', $key?->get('kid'));
		$this->assertCount(1, $this->calls);
	}//end testARotatedKeyIsFoundByOneRefetch()

	/**
	 * Fetch failures answer null and say why under the caller's label.
	 *
	 * @return void
	 */
	public function testFetchFailuresAnswerNull(): void {
		$lookup = static fn (string $uri): JwksLookup => new JwksLookup(ownerType: 'consumer', ownerUuid: 'c-1', jwksUri: $uri, kid: 'k1');

		$this->assertNull($this->resolver()->resolveKey(lookup: $lookup('not a url')));
		$this->setUp();
		$this->answer = new RuntimeException('timeout');
		$this->assertNull($this->resolver()->resolveKey(lookup: $lookup('https://idp.example/certs')));
		$this->setUp();
		$this->answer = [503, 'down'];
		$this->assertNull($this->resolver()->resolveKey(lookup: $lookup('https://idp.example/certs')));
		$this->assertSame([['info', 'ConsumerKeys: JWKS fetch returned non-200', ['registrationUuid' => 'c-1', 'statusCode' => 503]]], $this->logs);
		$this->setUp();
		$this->answer = [200, '{"issuer":"x"}'];
		$this->assertNull($this->resolver()->resolveKey(lookup: $lookup('https://idp.example/certs')));
		$this->assertSame([['info', 'ConsumerKeys: JWKS response is not a valid JWKS document', ['registrationUuid' => 'c-1']]], $this->logs);
	}//end testFetchFailuresAnswerNull()

	/**
	 * A discovery document is fetched and decoded; a list, a non-200 or a bad address answers null.
	 *
	 * @return void
	 */
	public function testADocumentIsFetchedAsAJsonObject(): void {
		$this->answer = [200, '{"issuer":"https://login.example.nl/realms/gemeente","jwks_uri":"https://login.example.nl/certs"}'];
		$this->assertSame(
			['issuer' => 'https://login.example.nl/realms/gemeente', 'jwks_uri' => 'https://login.example.nl/certs'],
			$this->resolver()->fetchDocument(url: 'https://login.example.nl/realms/gemeente/.well-known/openid-configuration', ownerUuid: 'c-1')
		);
		$this->assertSame('/realms/gemeente/.well-known/openid-configuration', $this->calls[0][2]);

		$this->answer = [200, '[1,2]'];
		$this->assertNull($this->resolver()->fetchDocument(url: 'https://login.example.nl/x', ownerUuid: 'c-1'));
		$this->answer = [404, ''];
		$this->assertNull($this->resolver()->fetchDocument(url: 'https://login.example.nl/x', ownerUuid: 'c-1'));
		$this->assertNull($this->resolver()->fetchDocument(url: '/relative', ownerUuid: 'c-1'));
		$this->assertSame(['warning', 'ConsumerKeys: invalid document url', ['registrationUuid' => 'c-1']], end($this->logs));
	}//end testADocumentIsFetchedAsAJsonObject()
}//end class
