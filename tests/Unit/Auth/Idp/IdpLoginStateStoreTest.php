<?php

/**
 * Unit tests for IdpLoginStateStore: the signed, single-use login state.
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

use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * Tests IdpLoginStateStore.
 *
 * @covers \OCA\Integriq\Auth\Idp\IdpLoginStateStore
 * @uses \OCA\Integriq\Auth\Idp\AtomicCache
 */
class IdpLoginStateStoreTest extends TestCase {

	/**
	 * A signing key.
	 */
	private const KEY = 'login-state-signing-key-0123456789';

	/**
	 * A fixed clock.
	 */
	private const NOW = 1_800_000_000;

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
	 * Assert that the callable throws the refusal with the given message.
	 *
	 * @param string   $message  The expected message.
	 * @param callable $callable The call.
	 *
	 * @return void
	 */
	private function assertRefused(string $message, callable $callable): void {
		try {
			$callable();
			$this->fail('Expected a refusal: ' . $message);
		} catch (IdpAssertionException $exception) {
			$this->assertSame($message, $exception->getMessage());
		}
	}

	/**
	 * A nonce is 32 random bytes, URL-safe and unpadded.
	 *
	 * @return void
	 */
	public function testANonceIsUrlSafeAndFresh(): void {
		$store = new IdpLoginStateStore($this->factory(null));

		$nonce = $store->newNonce();

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $nonce);
		$this->assertNotSame($nonce, $store->newNonce());
	}

	/**
	 * Without a shared cache no login state is kept, and none is consumed.
	 *
	 * @return void
	 */
	public function testWithoutACacheNoLoginStarts(): void {
		$store = new IdpLoginStateStore($this->factory(null));

		$this->assertRefused(
			'No shared cache is configured, so a login state cannot be kept and no login starts.',
			fn () => $store->store(requestId: 'r-1', state: [], signingKey: self::KEY)
		);
		$this->assertRefused(
			'The response answers no login this broker started.',
			fn () => $store->consume(requestId: 'r-1', signingKey: self::KEY)
		);
	}

	/**
	 * A blank request id or an empty key is refused before anything is stored.
	 *
	 * @return void
	 */
	public function testAStateNeedsARequestIdAndAKey(): void {
		$cache = new FakeMemcache();
		$store = new IdpLoginStateStore($this->factory($cache));

		$this->assertRefused(
			'A login state needs a request id and a signing key.',
			fn () => $store->store(requestId: '  ', state: [], signingKey: self::KEY)
		);
		$this->assertRefused(
			'A login state needs a request id and a signing key.',
			fn () => $store->store(requestId: 'r-1', state: [], signingKey: '')
		);
		$this->assertSame([], $cache->entries);
	}

	/**
	 * A stored state is narrowed to the known fields, expires five minutes
	 * later, and comes back once.
	 *
	 * @return void
	 */
	public function testAStateRoundTripsOnceNarrowedToItsFields(): void {
		$cache = new FakeMemcache();
		$store = new IdpLoginStateStore($this->factory($cache));

		$store->store(
			requestId: 'r-1',
			state: ['nonce' => 'n', 'provider' => 'digid', 'trust' => 'substantial', 'injected' => 'x', 'expiresAt' => '1'],
			signingKey: self::KEY,
			now: self::NOW
		);

		$this->assertSame([hash('sha256', 'r-1')], array_keys($cache->entries));
		$this->assertSame(
			[
				'nonce' => 'n',
				'organisation' => '',
				'consumer' => '',
				'provider' => 'digid',
				'trust' => 'substantial',
				'returnUrl' => '',
				'relayState' => '',
				'expiresAt' => (string)(self::NOW + 300),
			],
			$store->consume(requestId: ' r-1 ', signingKey: self::KEY, now: self::NOW + 299)
		);
		$this->assertRefused(
			'The response answers no login this broker started.',
			fn () => $store->consume(requestId: 'r-1', signingKey: self::KEY, now: self::NOW)
		);
	}

	/**
	 * A second state under the same request id is refused.
	 *
	 * @return void
	 */
	public function testAFailedStoreStartsNoLogin(): void {
		$store = new IdpLoginStateStore($this->factory(new FakeMemcache()));
		$store->store(requestId: 'r-1', state: [], signingKey: self::KEY);

		$this->assertRefused(
			'The login state could not be stored, so no login starts.',
			fn () => $store->store(requestId: 'r-1', state: [], signingKey: self::KEY)
		);
	}

	/**
	 * A state read at or after its expiry is refused.
	 *
	 * @return void
	 */
	public function testAnExpiredStateIsRefused(): void {
		$store = new IdpLoginStateStore($this->factory(new FakeMemcache()));
		$store->store(requestId: 'r-1', state: [], signingKey: self::KEY, now: self::NOW);

		$this->assertRefused(
			'The login state has expired, so it is refused.',
			fn () => $store->consume(requestId: 'r-1', signingKey: self::KEY, now: self::NOW + 300)
		);
	}

	/**
	 * A state checked under another key, or with no key, does not verify.
	 *
	 * @return void
	 */
	public function testAStateUnderAnotherKeyDoesNotVerify(): void {
		$store = new IdpLoginStateStore($this->factory(new FakeMemcache()));
		$store->store(requestId: 'r-1', state: [], signingKey: self::KEY, now: self::NOW);
		$store->store(requestId: 'r-2', state: [], signingKey: self::KEY, now: self::NOW);

		$this->assertRefused(
			'The login state does not verify, so it is refused.',
			fn () => $store->consume(requestId: 'r-1', signingKey: 'another-key-0123456789abcdefghij', now: self::NOW)
		);
		$this->assertRefused(
			'The login state does not verify, so it is refused.',
			fn () => $store->consume(requestId: 'r-2', signingKey: '', now: self::NOW)
		);
	}

	/**
	 * A correctly signed body that is not a JSON object is refused.
	 *
	 * @return void
	 */
	public function testASignedButUnreadableStateIsRefused(): void {
		$cache = new FakeMemcache();
		$body = 'not json';
		$sig = hash_hmac('sha256', 'integriq-idp-login-state' . "\0" . $body, self::KEY);
		$cache->entries[hash('sha256', 'r-1')] = (string)json_encode(['state' => $body, 'sig' => $sig]);
		$store = new IdpLoginStateStore($this->factory($cache));

		$this->assertRefused(
			'The login state is unreadable, so it is refused.',
			fn () => $store->consume(requestId: 'r-1', signingKey: self::KEY, now: self::NOW)
		);
	}
}
