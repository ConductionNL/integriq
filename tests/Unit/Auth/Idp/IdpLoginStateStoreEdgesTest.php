<?php

/**
 * The login state store: no cache, missing input, a refused write and an unreadable state.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Auth\Idp\IdpLoginStateStore
 * @uses   \OCA\Integriq\Auth\Idp\AtomicCache
 */
class IdpLoginStateStoreEdgesTest extends TestCase {

	private const KEY = 'state-signing-key';

	private const NOW = 1790000000;

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
	 * A stored state comes back once, narrowed to its fields, with its expiry five minutes on.
	 *
	 * @return void
	 */
	public function testAStateComesBackOnceNarrowed(): void {
		$cache = new FakeMemcache();
		$store = new IdpLoginStateStore(cacheFactory: $this->factory(cache: $cache));

		$store->store(requestId: 'req-1', state: ['nonce' => 'n', 'consumer' => 'portal', 'extra' => 'dropped'], signingKey: self::KEY, now: self::NOW);
		$state = $store->consume(requestId: ' req-1 ', signingKey: self::KEY, now: (self::NOW + 299));

		$this->assertSame(expected: IdpLoginStateStore::FIELDS, actual: array_keys($state));
		$this->assertSame(expected: 'n', actual: $state['nonce']);
		$this->assertSame(expected: 'portal', actual: $state['consumer']);
		$this->assertSame(expected: '', actual: $state['provider']);
		$this->assertSame(expected: (string) (self::NOW + 300), actual: $state['expiresAt']);
		$this->assertSame(expected: [], actual: $cache->entries);
	}//end testAStateComesBackOnceNarrowed()

	/**
	 * Without a shared cache no login starts.
	 *
	 * @return void
	 */
	public function testWithoutACacheNoStateIsStored(): void {
		$this->expectRefusal(message: 'No shared cache is configured, so a login state cannot be kept and no login starts.');

		(new IdpLoginStateStore(cacheFactory: $this->factory(cache: null)))->store(requestId: 'req-1', state: [], signingKey: self::KEY);
	}//end testWithoutACacheNoStateIsStored()

	/**
	 * Without a cache a response answers no login.
	 *
	 * @return void
	 */
	public function testWithoutACacheNothingIsConsumed(): void {
		$this->expectRefusal(message: 'The response answers no login this broker started.');

		(new IdpLoginStateStore(cacheFactory: $this->factory(cache: null)))->consume(requestId: 'req-1', signingKey: self::KEY);
	}//end testWithoutACacheNothingIsConsumed()

	/**
	 * A state needs a request id and a signing key.
	 *
	 * @return void
	 */
	public function testAStateNeedsARequestIdAndAKey(): void {
		$store = new IdpLoginStateStore(cacheFactory: $this->factory(cache: new FakeMemcache()));

		foreach ([['  ', self::KEY], ['req-1', '']] as [$requestId, $key]) {
			try {
				$store->store(requestId: $requestId, state: [], signingKey: $key);
				$this->fail(message: 'A state without a request id or key must not be stored.');
			} catch (IdpAssertionException $exception) {
				$this->assertSame(expected: 'A login state needs a request id and a signing key.', actual: $exception->getMessage());
			}
		}
	}//end testAStateNeedsARequestIdAndAKey()

	/**
	 * When the cache will not take the state, no login starts.
	 *
	 * @return void
	 */
	public function testAStateTheCacheRefusesIsNotStored(): void {
		$cache = $this->createMock(originalClassName: IMemcache::class);
		$cache->expects($this->once())
			->method('add')
			->with(hash(algo: 'sha256', data: 'req-1'), $this->isType(type: 'string'), IdpLoginStateStore::TTL_SECONDS)
			->willReturn(false);

		$this->expectRefusal(message: 'The login state could not be stored, so no login starts.');

		(new IdpLoginStateStore(cacheFactory: $this->factory(cache: $cache)))->store(requestId: 'req-1', state: [], signingKey: self::KEY);
	}//end testAStateTheCacheRefusesIsNotStored()

	/**
	 * A correctly signed entry whose state is not JSON is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableStateIsRefused(): void {
		$body  = 'not json';
		$cache = new FakeMemcache();
		$cache->entries[hash(algo: 'sha256', data: 'req-1')] = (string) json_encode(
			value: ['state' => $body, 'sig' => hash_hmac(algo: 'sha256', data: 'integriq-idp-login-state'."\0".$body, key: self::KEY)]
		);

		$this->expectRefusal(message: 'The login state is unreadable, so it is refused.');

		(new IdpLoginStateStore(cacheFactory: $this->factory(cache: $cache)))->consume(requestId: 'req-1', signingKey: self::KEY);
	}//end testAnUnreadableStateIsRefused()
}//end class
