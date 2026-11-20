<?php

/**
 * The one-time code store: issue, single redemption, consumer binding and its refusals.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\EnvelopeCodeStore;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Auth\Idp\EnvelopeCodeStore
 * @uses   \OCA\Integriq\Auth\Idp\AtomicCache
 */
class EnvelopeCodeStoreTest extends TestCase {

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
	 * Expect an assertion exception with exactly this message.
	 *
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function expectRefusal(string $message): void {
		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(message: $message);
	}//end expectRefusal()

	/**
	 * Without a shared cache the store is not usable.
	 *
	 * @return void
	 */
	public function testWithoutACacheTheStoreIsNotUsable(): void {
		$this->assertFalse(condition: (new EnvelopeCodeStore(cacheFactory: $this->factory(cache: null)))->isUsable());
		$this->assertTrue(condition: (new EnvelopeCodeStore(cacheFactory: $this->factory(cache: new FakeMemcache())))->isUsable());
	}//end testWithoutACacheTheStoreIsNotUsable()

	/**
	 * Without a shared cache no code is issued.
	 *
	 * @return void
	 */
	public function testWithoutACacheNoCodeIsIssued(): void {
		$this->expectRefusal(message: 'No shared cache is configured, so a one-time code cannot be issued.');

		(new EnvelopeCodeStore(cacheFactory: $this->factory(cache: null)))->issue(envelopeToken: 'jws', consumer: 'portal');
	}//end testWithoutACacheNoCodeIsIssued()

	/**
	 * Without a shared cache no code is redeemed.
	 *
	 * @return void
	 */
	public function testWithoutACacheNoCodeIsRedeemed(): void {
		$this->expectRefusal(message: 'No shared cache is configured, so a one-time code cannot be redeemed.');

		(new EnvelopeCodeStore(cacheFactory: $this->factory(cache: null)))->redeem(code: 'abc', consumer: 'portal');
	}//end testWithoutACacheNoCodeIsRedeemed()

	/**
	 * A code is URL-safe, stored under its hash, and redeems once to the envelope it was issued for.
	 *
	 * @return void
	 */
	public function testACodeRedeemsOnceToItsEnvelope(): void {
		$cache = new FakeMemcache();
		$store = new EnvelopeCodeStore(cacheFactory: $this->factory(cache: $cache));

		$code = $store->issue(envelopeToken: 'the.jws.token', consumer: 'portal');

		$this->assertMatchesRegularExpression(pattern: '/^[A-Za-z0-9_-]{43}$/', string: $code);
		$this->assertSame(expected: [hash(algo: 'sha256', data: $code)], actual: array_keys($cache->entries));
		$this->assertSame(expected: 'the.jws.token', actual: $store->redeem(code: ' '.$code."\n", consumer: 'portal'));
		$this->assertSame(expected: [], actual: $cache->entries);

		$this->expectRefusal(message: 'The one-time code is unknown, expired or already redeemed, so it is refused.');
		$store->redeem(code: $code, consumer: 'portal');
	}//end testACodeRedeemsOnceToItsEnvelope()

	/**
	 * Two codes for the same envelope differ.
	 *
	 * @return void
	 */
	public function testEveryCodeIsFresh(): void {
		$store = new EnvelopeCodeStore(cacheFactory: $this->factory(cache: new FakeMemcache()));

		$this->assertNotSame(
			expected: $store->issue(envelopeToken: 'jws', consumer: 'portal'),
			actual: $store->issue(envelopeToken: 'jws', consumer: 'portal')
		);
	}//end testEveryCodeIsFresh()

	/**
	 * Another consumer cannot redeem the code, and the attempt uses it up.
	 *
	 * @return void
	 */
	public function testAnotherConsumerIsRefusedAndTheCodeIsGone(): void {
		$cache = new FakeMemcache();
		$store = new EnvelopeCodeStore(cacheFactory: $this->factory(cache: $cache));
		$code  = $store->issue(envelopeToken: 'jws', consumer: 'portal');

		try {
			$store->redeem(code: $code, consumer: 'other');
			$this->fail(message: 'Another consumer must not redeem the code.');
		} catch (IdpAssertionException $exception) {
			$this->assertSame(expected: 'The one-time code belongs to another consumer, so it is refused.', actual: $exception->getMessage());
		}

		$this->assertSame(expected: [], actual: $cache->entries);
	}//end testAnotherConsumerIsRefusedAndTheCodeIsGone()

	/**
	 * An unknown code is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownCodeIsRefused(): void {
		$this->expectRefusal(message: 'The one-time code is unknown, expired or already redeemed, so it is refused.');

		(new EnvelopeCodeStore(cacheFactory: $this->factory(cache: new FakeMemcache())))->redeem(code: 'nope', consumer: 'portal');
	}//end testAnUnknownCodeIsRefused()

	/**
	 * An entry that is not JSON is refused after it is taken.
	 *
	 * @return void
	 */
	public function testAnUnreadableEntryIsRefused(): void {
		$cache = new FakeMemcache();
		$cache->entries[hash(algo: 'sha256', data: 'code')] = 'not json';

		$this->expectRefusal(message: 'The stored code entry is unreadable, so it is refused.');

		(new EnvelopeCodeStore(cacheFactory: $this->factory(cache: $cache)))->redeem(code: 'code', consumer: 'portal');
	}//end testAnUnreadableEntryIsRefused()

	/**
	 * When the cache will not take the code, no code is handed out.
	 *
	 * @return void
	 */
	public function testACodeTheCacheRefusesIsNotIssued(): void {
		$cache = $this->createMock(originalClassName: IMemcache::class);
		$cache->method('add')->willReturn(false);

		$this->expectRefusal(message: 'The one-time code could not be stored, so no code is issued.');

		(new EnvelopeCodeStore(cacheFactory: $this->factory(cache: $cache)))->issue(envelopeToken: 'jws', consumer: 'portal');
	}//end testACodeTheCacheRefusesIsNotIssued()

	/**
	 * The code lives no longer than an envelope, and at least one second.
	 *
	 * @return void
	 */
	public function testTheLifetimeIsCapped(): void {
		$ttls  = [];
		$cache = $this->createMock(originalClassName: IMemcache::class);
		$cache->method('add')->willReturnCallback(
			static function (string $key, mixed $value, int $ttl) use (&$ttls): bool {
				$ttls[] = $ttl;
				return true;
			}
		);
		$store = new EnvelopeCodeStore(cacheFactory: $this->factory(cache: $cache));

		$store->issue(envelopeToken: 'jws', consumer: 'portal', ttlSeconds: 600);
		$store->issue(envelopeToken: 'jws', consumer: 'portal', ttlSeconds: 0);
		$store->issue(envelopeToken: 'jws', consumer: 'portal', ttlSeconds: 30);
		$store->issue(envelopeToken: 'jws', consumer: 'portal');

		$this->assertSame(expected: [60, 1, 30, 60], actual: $ttls);
	}//end testTheLifetimeIsCapped()
}//end class
