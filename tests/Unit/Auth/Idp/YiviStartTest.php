<?php

/**
 * Unit tests for the start of a Yivi login: the attributes a consuming app
 * names are checked against the organisation's allowlist, recorded in the
 * signed state, and asked of the bridge as essential claims.
 *
 * The login service, the state store, the trust mapper and the OIDC Relying
 * Party adapter are the real classes; the issuer is the OIDC stub, the cache
 * an in-memory IMemcache and app config an array.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\Adapter\OidcLoginStore;
use OCA\Integriq\Auth\Idp\Adapter\OidcRelyingPartyAdapter;
use OCA\Integriq\Auth\Idp\AssertionGuard;
use OCA\Integriq\Auth\Idp\AttributeDisclosureRequest;
use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use OCA\Integriq\Auth\Idp\IdpAdapterRegistry;
use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpRegistrationService;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\SubjectPseudonymService;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Tests\Fixtures\Idp\OidcStubIssuer;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the attribute half of a Yivi start.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
 */
class YiviStartTest extends TestCase {

	/**
	 * The portal's registered return address.
	 *
	 * @var string
	 */
	private const RETURN_URL = 'https://portal.example.nl/portal/api/session/broker/callback';

	/**
	 * The envelope signing key.
	 *
	 * @var string
	 */
	private const SIGNING_KEY = 'envelope-signing-key-0123456789abcdef';

	/**
	 * The Yivi attribute id of a full name.
	 *
	 * @var string
	 */
	private const FULLNAME = 'pbdf.gemeente.personalData.fullname';

	/**
	 * The Yivi attribute id of an e-mail address.
	 *
	 * @var string
	 */
	private const EMAIL = 'pbdf.sidn-pbdf.email.email';

	/**
	 * The Yivi attribute id of a postal code.
	 *
	 * @var string
	 */
	private const ZIPCODE = 'pbdf.gemeente.address.zipcode';

	/**
	 * The shared cache.
	 *
	 * @var FakeMemcache
	 */
	private FakeMemcache $cache;

	/**
	 * The organisation's Yivi registration, or null for none.
	 *
	 * @var IdpRegistration|null
	 */
	private ?IdpRegistration $registration = null;

	/**
	 * A fresh cache and a Yivi registration for gemeente-x that allows three attributes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cache = new FakeMemcache();
		$this->registration = $this->yiviRegistration();
	}//end setUp()

	/**
	 * Gemeente-x's Yivi registration over the stub issuer.
	 *
	 * @param array<string,mixed> $overrides Properties to change.
	 *
	 * @return IdpRegistration The registration.
	 */
	private function yiviRegistration(array $overrides = []): IdpRegistration {
		return new IdpRegistration(
			id: 'reg-yivi',
			data: array_merge(
				[
					'organisation' => 'gemeente-x',
					'provider' => 'yivi',
					'kind' => 'oidc-rp',
					'enabled' => true,
					'issuer' => OidcStubIssuer::ISSUER,
					'clientId' => 'client-yivi',
					'clientAuth' => 'private_key_jwt',
					'yiviAttributes' => [
						['id' => self::FULLNAME, 'claim' => 'fullname', 'label' => 'Naam', 'carriesBsn' => false],
						['id' => self::EMAIL, 'claim' => 'email', 'label' => 'E-mail', 'carriesBsn' => false],
						['id' => self::ZIPCODE, 'claim' => 'zipcode', 'label' => 'Postcode', 'carriesBsn' => false],
						['id' => 'pbdf.broken.noclaim', 'label' => 'No claim name'],
					],
				],
				$overrides
			)
		);
	}//end yiviRegistration()

	/**
	 * A cache factory over the shared fake.
	 *
	 * @return ICacheFactory The factory.
	 */
	private function cacheFactory(): ICacheFactory {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($this->cache);
		return $factory;
	}//end cacheFactory()

	/**
	 * The broker config: on, keyed, and knowing portaliq.
	 *
	 * @return IdpBrokerConfig The config.
	 */
	private function config(): IdpBrokerConfig {
		$settings = [
			IdpBrokerConfig::KEY_ENABLED => '1',
			IdpBrokerConfig::KEY_SIGNING => self::SIGNING_KEY,
			IdpBrokerConfig::KEY_CONSUMERS => (string)json_encode(
				['portaliq' => ['enabled' => true, 'returnUrls' => [self::RETURN_URL], 'secretRef' => 'cred-1', 'secretOrganisation' => '']]
			),
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($settings[$key] ?? $default)
		);

		return new IdpBrokerConfig($appConfig);
	}//end config()

	/**
	 * The registrations, answering the Yivi registration for gemeente-x and a DigiD one.
	 *
	 * @return IdpRegistrationService The registrations.
	 */
	private function registrations(): IdpRegistrationService {
		$registrations = $this->createMock(IdpRegistrationService::class);
		$registrations->method('find')->willReturnCallback(
			function (string $organisation, string $provider): ?IdpRegistration {
				if ($organisation !== 'gemeente-x') {
					return null;
				}

				if ($provider === 'yivi') {
					return $this->registration;
				}

				return new IdpRegistration(id: 'reg-' . $provider, data: ['organisation' => $organisation, 'provider' => $provider, 'enabled' => true]);
			}
		);

		return $registrations;
	}//end registrations()

	/**
	 * The login service over one bound adapter.
	 *
	 * @param GovernmentIdpAdapterInterface $adapter The adapter the container binds.
	 *
	 * @return IdpLoginService The service.
	 */
	private function service(GovernmentIdpAdapterInterface $adapter): IdpLoginService {
		$factory = $this->cacheFactory();
		return new IdpLoginService(
			config: $this->config(),
			adapters: new IdpAdapterRegistry(boundAdapter: $adapter, logger: $this->createMock(LoggerInterface::class)),
			states: new IdpLoginStateStore($factory),
			assertionGuard: new AssertionGuard(replayGuard: new EnvelopeReplayGuard($factory)),
			trustMapper: new TrustLevelMapper(),
			pseudonyms: new SubjectPseudonymService(),
			exchange: $this->createMock(EnvelopeExchangeService::class),
			logger: $this->createMock(LoggerInterface::class),
			registrations: $this->registrations(),
			secrets: $this->createMock(IdpSecretSeam::class)
		);
	}//end service()

	/**
	 * The real OIDC Relying Party adapter of the Yivi registration, over the stub issuer.
	 *
	 * @return OidcRelyingPartyAdapter The adapter.
	 */
	private function oidcAdapter(): OidcRelyingPartyAdapter {
		$issuer = new OidcStubIssuer(clientId: 'client-yivi', now: time());
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri, array $options = []) use ($issuer): IResponse {
				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($issuer->answer(method: 'GET', url: $uri, options: $options));
				return $response;
			}
		);
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		return new OidcRelyingPartyAdapter(
			registration: $this->registration,
			secrets: $this->createMock(IdpSecretSeam::class),
			http: $http,
			logins: new OidcLoginStore($this->cacheFactory()),
			trust: new TrustLevelMapper(),
			redirectUri: 'https://nc.example/apps/integriq/api/idp/yivi/callback'
		);
	}//end oidcAdapter()

	/**
	 * The start parameters the portal sends for a Yivi login.
	 *
	 * @param string $attributes The attribute ids, comma-separated.
	 *
	 * @return array<string,string> The parameters.
	 */
	private function startParams(string $attributes): array {
		return [
			'organisation' => 'gemeente-x',
			'consumer' => 'portaliq',
			'trust' => 'substantial',
			'returnUrl' => self::RETURN_URL,
			'relayState' => 'portal-relay-42',
			'attributes' => $attributes,
		];
	}//end startParams()

	/**
	 * Assert a start is refused, reaching neither the bridge nor the state store.
	 *
	 * @param array<string,string> $params The parameters.
	 *
	 * @return void
	 */
	private function assertStartRefused(array $params): void {
		$adapter = new ScriptedGovernmentIdpAdapter(providerId: 'yivi');
		try {
			$this->service($adapter)->start(provider: 'yivi', params: $params);
			$this->fail('The start was not refused.');
		} catch (IdpAssertionException $exception) {
			$this->assertStringContainsString('no login starts', $exception->getMessage());
		}

		$this->assertSame([], $adapter->begun, 'The browser was sent to the bridge.');
		$this->assertSame([], $this->cache->entries, 'A state was stored for a refused start.');
	}//end assertStartRefused()

	/**
	 * A form asks for name and e-mail: the bridge is asked for exactly those
	 * two as essential claims, and the signed state records the two ids.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function testAFormAskingForNameAndEmailAsksTheBridgeForExactlyThoseTwo(): void {
		$redirect = $this->service($this->oidcAdapter())->start(
			provider: 'yivi',
			params: $this->startParams(self::FULLNAME . ', ' . self::EMAIL)
		);

		$this->assertStringStartsWith(OidcStubIssuer::AUTHORIZE . '?', $redirect);
		parse_str((string)parse_url($redirect, PHP_URL_QUERY), $query);
		$this->assertSame(
			['id_token' => ['fullname' => ['essential' => true], 'email' => ['essential' => true]]],
			json_decode((string)$query['claims'], true)
		);

		$state = (new IdpLoginStateStore($this->cacheFactory()))->consume(requestId: (string)$query['nonce'], signingKey: self::SIGNING_KEY);
		$this->assertSame(self::FULLNAME . ',' . self::EMAIL, $state['attributes']);
		$this->assertSame('yivi', $state['provider']);
		$this->assertSame(self::RETURN_URL, $state['returnUrl']);
	}//end testAFormAskingForNameAndEmailAsksTheBridgeForExactlyThoseTwo()

	/**
	 * The login service hands the adapter claim names only, in the order asked, without repeats.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function testTheAdapterIsHandedTheClaimNamesOnly(): void {
		$adapter = new ScriptedGovernmentIdpAdapter(providerId: 'yivi');
		$this->service($adapter)->start(provider: 'yivi', params: $this->startParams(self::ZIPCODE . ',' . self::FULLNAME . ',' . self::ZIPCODE));

		$this->assertCount(1, $adapter->begun);
		$this->assertSame(['zipcode', 'fullname'], $adapter->begun[0]['claims']);

		$state = (new IdpLoginStateStore($this->cacheFactory()))->consume(requestId: $adapter->lastRequestId, signingKey: self::SIGNING_KEY);
		$this->assertSame(self::ZIPCODE . ',' . self::FULLNAME, $state['attributes']);
	}//end testTheAdapterIsHandedTheClaimNamesOnly()

	/**
	 * An attribute the organisation did not allow, an empty list, and an
	 * allowlist entry without a claim name all end on integriq's error page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function testAnAttributeOutsideTheAllowlistOrNoneAtAllIsRefused(): void {
		$this->assertStartRefused($this->startParams(self::FULLNAME . ',pbdf.gemeente.bsn.bsn'));
		$this->assertStartRefused($this->startParams(''));
		$this->assertStartRefused($this->startParams(' , ,'));
		$this->assertStartRefused($this->startParams('pbdf.broken.noclaim'));
		$this->assertStartRefused(array_diff_key($this->startParams(self::EMAIL), ['attributes' => true]));
	}//end testAnAttributeOutsideTheAllowlistOrNoneAtAllIsRefused()

	/**
	 * More attributes than one login may ask for is refused, even when each is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function testTooManyAttributesAreRefused(): void {
		$entries = [];
		$ids = [];
		for ($i = 0; $i <= AttributeDisclosureRequest::MAX_ATTRIBUTES; $i++) {
			$entries[] = ['id' => 'attr.' . $i, 'claim' => 'c' . $i];
			$ids[] = 'attr.' . $i;
		}

		$this->registration = $this->yiviRegistration(['yiviAttributes' => $entries]);
		$this->assertStartRefused($this->startParams(implode(',', $ids)));

		$this->registration = $this->yiviRegistration(['yiviAttributes' => array_slice($entries, 0, AttributeDisclosureRequest::MAX_ATTRIBUTES)]);
		$adapter = new ScriptedGovernmentIdpAdapter(providerId: 'yivi');
		$this->service($adapter)->start(provider: 'yivi', params: $this->startParams(implode(',', array_slice($ids, 0, AttributeDisclosureRequest::MAX_ATTRIBUTES))));
		$this->assertCount(AttributeDisclosureRequest::MAX_ATTRIBUTES, $adapter->begun[0]['claims']);
	}//end testTooManyAttributesAreRefused()

	/**
	 * Without an enabled Yivi registration, or with an allowlist that is not a list, no Yivi login starts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function testNoEnabledRegistrationOrNoUsableAllowlistStartsNothing(): void {
		$this->registration = null;
		$this->assertStartRefused($this->startParams(self::EMAIL));

		$this->registration = $this->yiviRegistration(['enabled' => false]);
		$this->assertStartRefused($this->startParams(self::EMAIL));

		$this->registration = $this->yiviRegistration(['yiviAttributes' => 'pbdf.sidn-pbdf.email.email']);
		$this->assertStartRefused($this->startParams(self::EMAIL));

		$this->registration = $this->yiviRegistration(['yiviAttributes' => ['not-an-entry', ['id' => ['x'], 'claim' => 'email']]]);
		$this->assertStartRefused($this->startParams(self::EMAIL));
	}//end testNoEnabledRegistrationOrNoUsableAllowlistStartsNothing()

	/**
	 * A DigiD login ignores an `attributes` parameter: no claims are asked and the state records none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function testAnotherProviderIgnoresTheAttributesParameter(): void {
		$adapter = new ScriptedGovernmentIdpAdapter(providerId: 'digid');
		$this->service($adapter)->start(provider: 'digid', params: $this->startParams(self::EMAIL));

		$this->assertArrayNotHasKey('claims', $adapter->begun[0]);
		$state = (new IdpLoginStateStore($this->cacheFactory()))->consume(requestId: $adapter->lastRequestId, signingKey: self::SIGNING_KEY);
		$this->assertSame('', $state['attributes']);
	}//end testAnotherProviderIgnoresTheAttributesParameter()

	/**
	 * The OIDC adapter adds no `claims` parameter when no usable claim name is handed in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function testTheOidcAdapterAsksNoClaimsWithoutClaimNames(): void {
		foreach ([null, 'email', [], ['', 7]] as $claims) {
			$context = ['organisation' => 'gemeente-x', 'relayState' => 'state-' . bin2hex(random_bytes(4))];
			if ($claims !== null) {
				$context['claims'] = $claims;
			}

			$begun = $this->oidcAdapter()->beginAuthentication($context);
			parse_str((string)parse_url($begun['redirectUrl'], PHP_URL_QUERY), $query);
			$this->assertArrayNotHasKey('claims', $query);
		}
	}//end testTheOidcAdapterAsksNoClaimsWithoutClaimNames()
}//end class
