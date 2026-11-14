<?php

/**
 * The IdP stores only ever hold a distributed atomic cache.
 *
 * Pins, for the login state store, the envelope code store and the replay
 * guard, which cache each keeps: none when the factory has no cache, none
 * when the distributed cache is not an atomic IMemcache, and the distributed
 * cache under its own prefix otherwise, so the shared cache choice can move
 * without changing which instance may issue or accept a single-use value.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\EnvelopeCodeStore;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the IdP stores' cache choice.
 */
final class IdpCacheChoiceGoldenTest extends TestCase {

	/**
	 * Each store with its cache prefix.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function stores(): array {
		return [
			'login state' => [IdpLoginStateStore::class, 'integriq.idp.state.'],
			'envelope code' => [EnvelopeCodeStore::class, 'integriq.idp.code.'],
			'replay guard' => [EnvelopeReplayGuard::class, 'integriq.idp.jti.'],
		];

	}//end stores()

	/**
	 * The cache a store kept.
	 *
	 * @param object $store The store.
	 *
	 * @return mixed
	 */
	private function cacheOf(object $store): mixed {
		return (fn (): mixed => $this->cache)->call($store);

	}//end cacheOf()

	/**
	 * Without a cache backend nothing is created and nothing is kept.
	 *
	 * @param class-string $class  The store.
	 * @param string       $prefix Its prefix.
	 *
	 * @return void
	 */
	#[DataProvider('stores')]
	public function testNoBackendKeepsNoCache(string $class, string $prefix): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->expects($this->never())->method('createDistributed');

		$this->assertNull($this->cacheOf(store: new $class($factory)));
		$this->assertNotSame('', $prefix);

	}//end testNoBackendKeepsNoCache()

	/**
	 * A distributed cache that is not atomic is not kept.
	 *
	 * @param class-string $class  The store.
	 * @param string       $prefix Its prefix.
	 *
	 * @return void
	 */
	#[DataProvider('stores')]
	public function testANonAtomicCacheIsNotKept(string $class, string $prefix): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->expects($this->once())->method('createDistributed')->with($prefix)->willReturn($this->createMock(ICache::class));

		$this->assertNull($this->cacheOf(store: new $class($factory)));

	}//end testANonAtomicCacheIsNotKept()

	/**
	 * An atomic distributed cache is kept, under the store's own prefix.
	 *
	 * @param class-string $class  The store.
	 * @param string       $prefix Its prefix.
	 *
	 * @return void
	 */
	#[DataProvider('stores')]
	public function testAnAtomicCacheIsKept(string $class, string $prefix): void {
		$memcache = $this->createMock(IMemcache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->expects($this->once())->method('createDistributed')->with($prefix)->willReturn($memcache);

		$store = new $class($factory);
		$this->assertSame($memcache, $this->cacheOf(store: $store));
		if (method_exists($store, 'isUsable') === true) {
			$this->assertTrue($store->isUsable());
		}

	}//end testAnAtomicCacheIsKept()
}//end class
