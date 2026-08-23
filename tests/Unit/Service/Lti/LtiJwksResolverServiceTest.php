<?php

/**
 * Unit tests for LtiJwksResolverService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use Jose\Component\KeyManagement\JWKFactory;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Lti\LtiJwksResolverService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for external JWKS resolution: cache hit, cache miss + refetch,
 * refetch-guard rate limiting, and per-registration cache namespacing.
 */
class LtiJwksResolverServiceTest extends TestCase {

	/**
	 * Build a JWKS document `{"keys":[...]}` containing one public RSA JWK
	 * under the given kid.
	 *
	 * @param string $kid The kid to publish.
	 *
	 * @return array
	 */
	private function buildJwksDocument(string $kid): array {
		$jwk = JWKFactory::createRSAKey(2048, ['kid' => $kid, 'alg' => 'RS256', 'use' => 'sig']);
		return ['keys' => [$jwk->toPublic()->jsonSerialize()]];
	}//end buildJwksDocument()

	/**
	 * Build a resolver whose CallService always returns the given JWKS
	 * document body, counting how many outbound calls were made.
	 *
	 * @param array $jwksDocument The document CallService::call() returns.
	 * @param integer[] $callCount Byref counter incremented on every call.
	 *
	 * @return LtiJwksResolverService
	 */
	private function makeResolver(array $jwksDocument, array &$callCount): LtiJwksResolverService {
		$callService = $this->createMock(CallService::class);
		$callService->method('call')->willReturnCallback(
			function () use ($jwksDocument, &$callCount) {
				$callCount[0]++;

				$entity = new ObjectEntity();
				$entity->setUuid('call-log-' . $callCount[0]);
				$entity->setObject(
					[
						'statusCode' => 200,
						'response' => [
							'statusCode' => 200,
							'body' => json_encode($jwksDocument),
							'encoding' => 'UTF-8',
						],
					]
				);
				return $entity;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new \OCA\Integriq\Tests\Helpers\ArrayCache());

		return new LtiJwksResolverService($cacheFactory, $callService, new NullLogger());
	}//end makeResolver()

	/**
	 * A fresh resolve fetches once and returns the matching kid.
	 *
	 * @return void
	 */
	public function testResolveKeyFetchesOnCacheMiss(): void {
		$callCount = [0];
		$doc = $this->buildJwksDocument('kid-1');
		$resolver = $this->makeResolver($doc, $callCount);

		$jwk = $resolver->resolveKey('lti_platform', 'plat-1', 'https://platform.example/.well-known/jwks.json', 'kid-1');

		$this->assertNotNull($jwk);
		$this->assertSame('kid-1', $jwk->get('kid'));
		$this->assertSame(1, $callCount[0]);

	}//end testResolveKeyFetchesOnCacheMiss()

	/**
	 * A second resolve for a kid already present in the cached set does NOT refetch.
	 *
	 * @return void
	 */
	public function testResolveKeyUsesCacheOnHit(): void {
		$callCount = [0];
		$doc = $this->buildJwksDocument('kid-1');
		$resolver = $this->makeResolver($doc, $callCount);

		$resolver->resolveKey('lti_platform', 'plat-1', 'https://platform.example/jwks.json', 'kid-1');
		$resolver->resolveKey('lti_platform', 'plat-1', 'https://platform.example/jwks.json', 'kid-1');

		$this->assertSame(1, $callCount[0], 'a cache hit must not trigger a second outbound fetch');

	}//end testResolveKeyUsesCacheOnHit()

	/**
	 * Three requests for the same unknown kid within the guard window
	 * trigger exactly one outbound refetch, and all three resolve to null.
	 *
	 * @return void
	 */
	public function testUnknownKidTriggersExactlyOneRefetchWithinGuardWindow(): void {
		$callCount = [0];
		$doc = $this->buildJwksDocument('known-kid');
		$resolver = $this->makeResolver($doc, $callCount);

		$r1 = $resolver->resolveKey('lti_platform', 'plat-1', 'https://platform.example/jwks.json', 'unknown-kid');
		$r2 = $resolver->resolveKey('lti_platform', 'plat-1', 'https://platform.example/jwks.json', 'unknown-kid');
		$r3 = $resolver->resolveKey('lti_platform', 'plat-1', 'https://platform.example/jwks.json', 'unknown-kid');

		$this->assertNull($r1);
		$this->assertNull($r2);
		$this->assertNull($r3);
		$this->assertSame(1, $callCount[0], 'the refetch guard must limit to exactly one outbound fetch per 60s per registration');

	}//end testUnknownKidTriggersExactlyOneRefetchWithinGuardWindow()

	/**
	 * Two registrations sharing the same jwks_uri do not share a cache entry.
	 *
	 * @return void
	 */
	public function testTwoRegistrationsSharingJwksUriDoNotSharesCacheEntry(): void {
		$callCount = [0];
		$doc = $this->buildJwksDocument('shared-kid');
		$resolver = $this->makeResolver($doc, $callCount);

		$sharedUri = 'https://shared-host.example/jwks.json';

		$resolver->resolveKey('lti_platform', 'plat-A', $sharedUri, 'shared-kid');
		$this->assertSame(1, $callCount[0]);

		// Registration B has never been resolved — its cache entry MUST be a
		// separate miss (namespaced by registration id, not jwks_uri), so it
		// fetches independently rather than reusing A's cached set.
		$resolver->resolveKey('lti_platform', 'plat-B', $sharedUri, 'shared-kid');
		$this->assertSame(2, $callCount[0], 'registration B must not reuse registration A\'s cache entry');

	}//end testTwoRegistrationsSharingJwksUriDoNotSharesCacheEntry()

	/**
	 * An invalid jwks_uri resolves to null rather than throwing.
	 *
	 * @return void
	 */
	public function testInvalidJwksUriResolvesToNull(): void {
		$callCount = [0];
		$doc = $this->buildJwksDocument('kid-1');
		$resolver = $this->makeResolver($doc, $callCount);

		$jwk = $resolver->resolveKey('lti_platform', 'plat-1', 'not-a-valid-uri', 'kid-1');

		$this->assertNull($jwk);
		$this->assertSame(0, $callCount[0], 'an invalid URI must never reach the outbound call machinery');

	}//end testInvalidJwksUriResolvesToNull()
}//end class
