<?php

/**
 * Unit tests for AtomicCache, the distributed cache the single-use stores share.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\AtomicCache;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * Only a distributed cache with an atomic add is handed out.
 *
 * @covers \OCA\Integriq\Auth\Idp\AtomicCache
 */
class AtomicCacheTest extends TestCase {

	/**
	 * Without a cache backend nothing is created and nothing is returned.
	 *
	 * @return void
	 */
	public function testNoBackendYieldsNoCache(): void {
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(false);
		$factory->expects($this->never())->method('createDistributed');

		$this->assertNull(actual: AtomicCache::distributed(cacheFactory: $factory, prefix: 'integriq.idp.state'));
	}//end testNoBackendYieldsNoCache()

	/**
	 * A distributed cache that is an IMemcache is returned, created under the
	 * prefix followed by a dot.
	 *
	 * @return void
	 */
	public function testAMemcacheIsReturnedUnderTheDottedPrefix(): void {
		$memcache = $this->createMock(originalClassName: IMemcache::class);
		$factory  = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->expects($this->once())
			->method('createDistributed')
			->with('integriq.idp.codes.')
			->willReturn($memcache);

		$this->assertSame(expected: $memcache, actual: AtomicCache::distributed(cacheFactory: $factory, prefix: 'integriq.idp.codes'));
	}//end testAMemcacheIsReturnedUnderTheDottedPrefix()

	/**
	 * A distributed cache without an atomic add is refused: a single-use store
	 * on it could let two racing requests both pass.
	 *
	 * @return void
	 */
	public function testACacheWithoutAtomicAddIsRefused(): void {
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($this->createMock(originalClassName: ICache::class));

		$this->assertNull(actual: AtomicCache::distributed(cacheFactory: $factory, prefix: 'integriq.idp.replay'));
	}//end testACacheWithoutAtomicAddIsRefused()
}//end class
