<?php

/**
 * An OIDC login's nonce and code verifier are kept under its state and read once.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp\Adapter;

use OCA\Integriq\Auth\Idp\Adapter\OidcLoginStore;
use OCA\Integriq\Auth\Idp\AtomicCache;
use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Tests\Unit\Auth\Idp\FakeMemcache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
#[CoversClass(OidcLoginStore::class)]
#[UsesClass(AtomicCache::class)]
class OidcLoginStoreTest extends TestCase {

	private function store(?FakeMemcache $cache): OidcLoginStore {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn($cache !== null);
		if ($cache !== null) {
			$factory->method('createDistributed')->willReturn($cache);
		}

		return new OidcLoginStore($factory);
	}

	public function testALoginIsTakenOnceUnderItsState(): void {
		$cache = new FakeMemcache();
		$store = $this->store(cache: $cache);
		$store->remember(state: 'state-1', nonce: 'n-1', verifier: 'v-1');

		$this->assertSame(['nonce' => 'n-1', 'verifier' => 'v-1'], $store->take(state: 'state-1'));

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('answers no outstanding login');
		$store->take(state: 'state-1');
	}

	public function testTheEntryLivesAsLongAsALoginStateAndIsKeyedByTheStatesHash(): void {
		$cache = $this->createMock(\OCP\IMemcache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		$cache->expects($this->once())->method('add')
			->with(hash('sha256', 'state-1'), '{"nonce":"n-1","verifier":"v-1"}', IdpLoginStateStore::TTL_SECONDS)
			->willReturn(true);

		(new OidcLoginStore($factory))->remember(state: 'state-1', nonce: 'n-1', verifier: 'v-1');
	}

	public function testAStateAlreadyInUseIsRefused(): void {
		$store = $this->store(cache: new FakeMemcache());
		$store->remember(state: 'state-1', nonce: 'n-1', verifier: 'v-1');

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('already in use');
		$store->remember(state: 'state-1', nonce: 'n-2', verifier: 'v-2');
	}

	public function testWithoutASharedCacheNothingIsKeptOrRead(): void {
		$store = $this->store(cache: null);

		try {
			$store->remember(state: 'state-1', nonce: 'n-1', verifier: 'v-1');
			$this->fail('A login was kept without a shared cache.');
		} catch (IdpAssertionException $refused) {
			$this->assertStringContainsString('No shared cache', $refused->getMessage());
		}

		$this->expectException(IdpAssertionException::class);
		$store->take(state: 'state-1');
	}

	public function testAnUnreadableEntryIsRefused(): void {
		$cache = new FakeMemcache();
		$cache->set(hash('sha256', 'state-1'), '{"nonce":"n-1"}');

		$this->expectException(IdpAssertionException::class);
		$this->store(cache: $cache)->take(state: 'state-1');
	}
}
