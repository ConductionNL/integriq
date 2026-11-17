<?php

/**
 * The factory builds a registration's OIDC adapter with its provider's callback as redirect URI.
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
use OCA\Integriq\Auth\Idp\Adapter\OidcRelyingPartyAdapter;
use OCA\Integriq\Auth\Idp\Adapter\OidcRelyingPartyAdapterFactory;
use OCA\Integriq\Auth\Idp\AtomicCache;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Tests\Unit\Auth\Idp\FakeMemcache;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
#[CoversClass(OidcRelyingPartyAdapterFactory::class)]
#[UsesClass(OidcRelyingPartyAdapter::class)]
#[UsesClass(OidcLoginStore::class)]
#[UsesClass(AtomicCache::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(TrustLevelMapper::class)]
class OidcRelyingPartyAdapterFactoryTest extends TestCase {

	public function testTheAdapterRedirectsToItsProvidersCallback(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('linkToRouteAbsolute')
			->with('integriq.idpBroker.callback', ['provider' => 'eidas'])
			->willReturn('https://nc.example/apps/integriq/api/idp/eidas/callback');
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(
			json_encode(
				[
					'issuer' => 'https://issuer.example',
					'authorization_endpoint' => 'https://issuer.example/authorize?tenant=x',
					'token_endpoint' => 'https://issuer.example/token',
					'jwks_uri' => 'https://issuer.example/jwks',
				]
			)
		);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('isAvailable')->willReturn(true);
		$caches->method('createDistributed')->willReturn(new FakeMemcache());
		$factory = new OidcRelyingPartyAdapterFactory(
			secrets: $this->createMock(IdpSecretSeam::class),
			http: $http,
			logins: new OidcLoginStore($caches),
			trust: new TrustLevelMapper(),
			urlGenerator: $urls
		);

		$adapter = $factory->build(
			new IdpRegistration(id: 'reg-1', data: ['provider' => 'eidas', 'issuer' => 'https://issuer.example', 'clientId' => 'c-1'])
		);
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$this->assertSame('eidas', $adapter->getProviderId());
		$this->assertStringStartsWith('https://issuer.example/authorize?tenant=x&response_type=code', $begun['redirectUrl']);
		$this->assertStringContainsString(
			'redirect_uri='.rawurlencode('https://nc.example/apps/integriq/api/idp/eidas/callback'),
			$begun['redirectUrl']
		);
	}
}
