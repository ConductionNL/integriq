<?php

/**
 * Unit tests for EnvelopeReplayGuard: burning single-use identifiers.
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

use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * Tests EnvelopeReplayGuard.
 *
 * @covers \OCA\Integriq\Auth\Idp\EnvelopeReplayGuard
 * @uses \OCA\Integriq\Auth\Idp\AtomicCache
 */
class EnvelopeReplayGuardTest extends TestCase {

	/**
	 * A cache factory whose distributed cache is the given one, or none.
	 *
	 * @param IMemcache|null $cache The cache.
	 *
	 * @return ICacheFactory The factory.
	 */
	private function factory(?IMemcache $cache): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cache !== null);
		if ($cache !== null) {
			$factory->method('createDistributed')->willReturn($cache);
		}

		return $factory;
	}

	/**
	 * A blank identifier is refused before the cache is consulted, even
	 * when there is no cache.
	 *
	 * @return void
	 */
	public function testABlankIdentifierIsRefusedFirst(): void {
		$guard = new EnvelopeReplayGuard($this->factory(null));

		$this->assertFalse($guard->isUsable());
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('The artefact carries no single-use identifier, so it is refused.');
		$guard->burn(jti: '   ', ttlSeconds: 60);
	}

	/**
	 * Without a cache a real identifier is refused.
	 *
	 * @return void
	 */
	public function testWithoutACacheTheArtefactIsRefused(): void {
		$guard = new EnvelopeReplayGuard($this->factory(null));

		$this->expectExceptionMessage('No shared cache is configured, so single use cannot be guaranteed and the artefact is refused.');
		$guard->burn(jti: 'jti-1', ttlSeconds: 60);
	}

	/**
	 * The trimmed identifier is burnt under its hash for the given lifetime,
	 * and a second use is refused.
	 *
	 * @return void
	 */
	public function testAnIdentifierBurnsOnce(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->expects($this->exactly(2))
			->method('add')
			->with(hash('sha256', 'jti-1'), 1, 45)
			->willReturnOnConsecutiveCalls(true, false);
		$guard = new EnvelopeReplayGuard($this->factory($cache));

		$this->assertTrue($guard->isUsable());
		$guard->burn(jti: ' jti-1 ', ttlSeconds: 45);

		$this->expectExceptionMessage('This artefact has already been used, so it is refused.');
		$guard->burn(jti: 'jti-1', ttlSeconds: 45);
	}
}
