<?php

/**
 * Unit tests for EnvelopeCodeStore: issuing and redeeming one-time codes.
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

use OCA\Integriq\Auth\Idp\EnvelopeCodeStore;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * Tests EnvelopeCodeStore.
 *
 * @covers \OCA\Integriq\Auth\Idp\EnvelopeCodeStore
 * @uses \OCA\Integriq\Auth\Idp\AtomicCache
 */
class EnvelopeCodeStoreBehaviourTest extends TestCase {

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
	 * Without a shared cache the store is unusable and refuses to issue.
	 *
	 * @return void
	 */
	public function testWithoutACacheNoCodeIsIssued(): void {
		$store = new EnvelopeCodeStore($this->factory(null));

		$this->assertFalse($store->isUsable());
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('No shared cache is configured, so a one-time code cannot be issued.');
		$store->issue(envelopeToken: 'token', consumer: 'portaliq');
	}

	/**
	 * Without a shared cache no code is redeemed.
	 *
	 * @return void
	 */
	public function testWithoutACacheNoCodeIsRedeemed(): void {
		$store = new EnvelopeCodeStore($this->factory(null));

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('No shared cache is configured, so a one-time code cannot be redeemed.');
		$store->redeem(code: 'abc', consumer: 'portaliq');
	}

	/**
	 * The code is URL-safe, stored under its hash with the capped lifetime,
	 * and redeems once for its consumer to the envelope.
	 *
	 * @return void
	 */
	public function testAnIssuedCodeIsStoredHashedAndRedeemsOnce(): void {
		$cache = new FakeMemcache();
		$store = new EnvelopeCodeStore($this->factory($cache));

		$code = $store->issue(envelopeToken: 'env.token', consumer: 'portaliq', ttlSeconds: 3600);

		$this->assertTrue($store->isUsable());
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $code);
		$this->assertSame([hash('sha256', $code)], array_keys($cache->entries));
		$this->assertSame('{"envelope":"env.token","consumer":"portaliq"}', $cache->entries[hash('sha256', $code)]);

		$this->assertSame('env.token', $store->redeem(code: ' ' . $code . ' ', consumer: 'portaliq'));
		$this->assertSame([], $cache->entries);
	}

	/**
	 * The lifetime is capped at the envelope maximum and raised to one second.
	 *
	 * @return void
	 */
	public function testTheLifetimeIsClampedToOneToSixtySeconds(): void {
		$cache = $this->createMock(IMemcache::class);
		$ttls = [];
		$cache->method('add')->willReturnCallback(
			function (string $key, string $value, int $ttl) use (&$ttls): bool {
				$ttls[] = $ttl;
				return true;
			}
		);
		$store = new EnvelopeCodeStore($this->factory($cache));

		$store->issue(envelopeToken: 't', consumer: 'c', ttlSeconds: 0);
		$store->issue(envelopeToken: 't', consumer: 'c', ttlSeconds: -5);
		$store->issue(envelopeToken: 't', consumer: 'c', ttlSeconds: 30);
		$store->issue(envelopeToken: 't', consumer: 'c', ttlSeconds: 600);

		$this->assertSame([1, 1, 30, 60], $ttls);
	}

	/**
	 * A cache that will not take the entry means no code is issued.
	 *
	 * @return void
	 */
	public function testAFailedStoreIssuesNoCode(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('add')->willReturn(false);
		$store = new EnvelopeCodeStore($this->factory($cache));

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('The one-time code could not be stored, so no code is issued.');
		$store->issue(envelopeToken: 't', consumer: 'c');
	}

	/**
	 * An unreadable stored entry is burnt and refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableEntryIsRefused(): void {
		$cache = new FakeMemcache();
		$cache->entries[hash('sha256', 'code-1')] = 'not json';
		$store = new EnvelopeCodeStore($this->factory($cache));

		try {
			$store->redeem(code: 'code-1', consumer: 'portaliq');
			$this->fail('An unreadable entry must be refused.');
		} catch (IdpAssertionException $exception) {
			$this->assertSame('The stored code entry is unreadable, so it is refused.', $exception->getMessage());
		}

		$this->assertSame([], $cache->entries);
	}

	/**
	 * A code redeemed by another consumer is refused and burnt.
	 *
	 * @return void
	 */
	public function testAnotherConsumersCodeIsRefusedAndBurnt(): void {
		$cache = new FakeMemcache();
		$store = new EnvelopeCodeStore($this->factory($cache));
		$code = $store->issue(envelopeToken: 'env', consumer: 'portaliq');

		try {
			$store->redeem(code: $code, consumer: 'other');
			$this->fail('Another consumer must be refused.');
		} catch (IdpAssertionException $exception) {
			$this->assertSame('The one-time code belongs to another consumer, so it is refused.', $exception->getMessage());
		}

		$this->expectExceptionMessage('The one-time code is unknown, expired or already redeemed, so it is refused.');
		$store->redeem(code: $code, consumer: 'portaliq');
	}
}
