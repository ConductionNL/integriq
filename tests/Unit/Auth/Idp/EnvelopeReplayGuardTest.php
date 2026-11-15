<?php

/**
 * The replay guard: an identifier is used once, and without one or without a cache nothing passes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Auth\Idp\EnvelopeReplayGuard
 * @uses   \OCA\Integriq\Auth\Idp\AtomicCache
 */
class EnvelopeReplayGuardTest extends TestCase {

	/**
	 * A cache factory handing out the given cache, or none.
	 *
	 * @param IMemcache|null $cache The cache.
	 *
	 * @return ICacheFactory
	 */
	private function factory(?IMemcache $cache): ICacheFactory {
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cache !== null);
		if ($cache !== null) {
			$factory->method('createDistributed')->willReturn($cache);
		}

		return $factory;
	}//end factory()

	/**
	 * An identifier passes once, stored under its hash for the given time, and is refused after.
	 *
	 * @return void
	 */
	public function testAnIdentifierPassesOnce(): void {
		$cache = $this->createMock(originalClassName: IMemcache::class);
		$cache->expects($this->exactly(count: 2))
			->method('add')
			->with(hash(algo: 'sha256', data: 'jti-1'), 1, 45)
			->willReturnOnConsecutiveCalls(true, false);
		$guard = new EnvelopeReplayGuard(cacheFactory: $this->factory(cache: $cache));

		$guard->burn(jti: ' jti-1 ', ttlSeconds: 45);

		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(message: 'This artefact has already been used, so it is refused.');
		$guard->burn(jti: 'jti-1', ttlSeconds: 45);
	}//end testAnIdentifierPassesOnce()

	/**
	 * An artefact without an identifier is refused before the cache is asked.
	 *
	 * @return void
	 */
	public function testAnEmptyIdentifierIsRefused(): void {
		$cache = $this->createMock(originalClassName: IMemcache::class);
		$cache->expects($this->never())->method('add');
		$guard = new EnvelopeReplayGuard(cacheFactory: $this->factory(cache: $cache));

		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(message: 'The artefact carries no single-use identifier, so it is refused.');
		$guard->burn(jti: "  \t", ttlSeconds: 60);
	}//end testAnEmptyIdentifierIsRefused()

	/**
	 * Without a shared cache every artefact is refused: single use cannot be promised.
	 *
	 * @return void
	 */
	public function testWithoutACacheEverythingIsRefused(): void {
		$guard = new EnvelopeReplayGuard(cacheFactory: $this->factory(cache: null));

		$this->assertFalse(condition: $guard->isUsable());
		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(
			message: 'No shared cache is configured, so single use cannot be guaranteed and the artefact is refused.'
		);
		$guard->burn(jti: 'jti-1', ttlSeconds: 60);
	}//end testWithoutACacheEverythingIsRefused()
}//end class
