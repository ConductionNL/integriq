<?php

/**
 * Unit tests for the callback of a Yivi login: the envelope carries exactly
 * the attributes the login asked for, its subject is a pseudonym of the
 * disclosure, a BSN attribute is pseudonymised unless the registration passes
 * it on, and a login that shared less than it asked issues no code.
 *
 * The login service, the state store, the OIDC Relying Party adapter, the
 * envelope service and the factory are the real classes; the issuer is the
 * OIDC stub. The trust of a Yivi login waits on design D5 of the change, so
 * the round trips use a mapper that gives Yivi `substantial`; the last test
 * shows the real mapper keeps a Yivi callback fail-closed until then.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\Adapter\OidcLoginStore;
use OCA\Integriq\Auth\Idp\Adapter\OidcRelyingPartyAdapter;
use OCA\Integriq\Auth\Idp\AssertionGuard;
use OCA\Integriq\Auth\Idp\AttributeEnvelopeFactory;
use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\IdpAdapterRegistry;
use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpRegistrationService;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\SubjectEnvelope;
use OCA\Integriq\Auth\Idp\SubjectEnvelopeService;
use OCA\Integriq\Auth\Idp\SubjectPseudonymService;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpDisclosureIncompleteException;
use OCA\Integriq\Tests\Fixtures\Idp\OidcStubIssuer;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the attribute half of a Yivi callback.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
 */
class YiviEnvelopeTest extends TestCase {

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
	 * The registration's salt.
	 *
	 * @var string
	 */
	private const SALT = 'yivi-registration-salt-0123456789abcdef';

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
	 * The Yivi attribute id of a BSN.
	 *
	 * @var string
	 */
	private const BSN = 'pbdf.gemeente.bsn.bsn';

	/**
	 * The shared cache.
	 *
	 * @var FakeMemcache
	 */
	private FakeMemcache $cache;

	/**
	 * The stub OIDC bridge.
	 *
	 * @var OidcStubIssuer
	 */
	private OidcStubIssuer $issuer;

	/**
	 * The envelopes the exchange was asked to issue a code for.
	 *
	 * @var list<SubjectEnvelope>
	 */
	private array $issued = [];

	/**
	 * A fresh cache, issuer and exchange record.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cache = new FakeMemcache();
		$this->issuer = new OidcStubIssuer(clientId: 'client-yivi', now: time());
		$this->issued = [];
	}//end setUp()

	/**
	 * Gemeente-x's Yivi registration over the stub issuer.
	 *
	 * @param array<string,mixed> $overrides Properties to change.
	 *
	 * @return IdpRegistration The registration.
	 */
	private function registration(array $overrides = []): IdpRegistration {
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
					'clientAuth' => 'client_secret_basic',
					'pseudonymisation' => 'hmac',
					'credentials' => ['clientSecret' => 'cred-secret', 'salt' => 'cred-salt'],
					'yiviAttributes' => [
						['id' => self::FULLNAME, 'claim' => 'fullname', 'label' => 'Naam', 'carriesBsn' => false],
						['id' => self::EMAIL, 'claim' => 'email', 'label' => 'E-mail', 'carriesBsn' => false],
						['id' => self::BSN, 'claim' => 'bsn', 'label' => 'BSN', 'carriesBsn' => true],
					],
				],
				$overrides
			)
		);
	}//end registration()

	/**
	 * A cache factory over the shared fake.
	 *
	 * @return ICacheFactory The factory.
	 */
	private function cacheFactory(): ICacheFactory {
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($this->cache);
		return $factory;
	}//end cacheFactory()

	/**
	 * The secret seam: the client secret and the salt.
	 *
	 * @return IdpSecretSeam The seam.
	 */
	private function secrets(): IdpSecretSeam {
		$secrets = $this->createMock(originalClassName: IdpSecretSeam::class);
		$secrets->method('read')->willReturnCallback(
			static fn (string $registration, string $organisation, string $role, string $reference): string => match ($role) {
				'salt' => self::SALT,
				default => 'client-secret-0123456789',
			}
		);
		return $secrets;
	}//end secrets()

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
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => ($settings[$key] ?? $default)
		);

		return new IdpBrokerConfig(appConfig: $appConfig);
	}//end config()

	/**
	 * A trust mapper that gives a Yivi login `substantial`, standing in for the D5 decision.
	 *
	 * @return TrustLevelMapper The mapper.
	 */
	private function decidedTrust(): TrustLevelMapper {
		return new class extends TrustLevelMapper {
			/**
			 * Yivi maps to substantial; every other provider as the real table says.
			 *
			 * @param string               $provider The provider.
			 * @param string               $level    The level.
			 * @param array<string,string> $aliases  The aliases.
			 *
			 * @return string The trust.
			 */
			public function map(string $provider, string $level, array $aliases = []): string {
				if ($provider === TrustLevelMapper::PROVIDER_YIVI) {
					return TrustLevelMapper::TRUST_SUBSTANTIAL;
				}

				return parent::map(provider: $provider, level: $level, aliases: $aliases);
			}
		};
	}//end decidedTrust()

	/**
	 * The login service over the real OIDC adapter of the registration.
	 *
	 * @param IdpRegistration  $registration The organisation's Yivi registration.
	 * @param TrustLevelMapper $trust        The trust mapper.
	 *
	 * @return IdpLoginService The service.
	 */
	private function service(IdpRegistration $registration, TrustLevelMapper $trust): IdpLoginService {
		$client = $this->createMock(originalClassName: IClient::class);
		$answer = function (string $method): callable {
			return function (string $uri, array $options = []) use ($method): IResponse {
				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getBody')->willReturn($this->issuer->answer(method: $method, url: $uri, options: $options));
				return $response;
			};
		};
		$client->method('get')->willReturnCallback($answer('GET'));
		$client->method('post')->willReturnCallback($answer('POST'));
		$http = $this->createMock(originalClassName: IClientService::class);
		$http->method('newClient')->willReturn($client);

		$factory = $this->cacheFactory();
		$adapter = new OidcRelyingPartyAdapter(
			registration: $registration,
			secrets: $this->secrets(),
			http: $http,
			logins: new OidcLoginStore(cacheFactory: $factory),
			trust: $trust,
			redirectUri: 'https://nc.example/apps/integriq/api/idp/yivi/callback'
		);

		$registrations = $this->createMock(originalClassName: IdpRegistrationService::class);
		$registrations->method('find')->willReturn($registration);

		$exchange = $this->createMock(originalClassName: EnvelopeExchangeService::class);
		$exchange->method('issueCode')->willReturnCallback(
			function (SubjectEnvelope $envelope): string {
				$this->issued[] = $envelope;
				return 'code-1';
			}
		);

		return new IdpLoginService(
			config: $this->config(),
			adapters: new IdpAdapterRegistry(boundAdapter: $adapter, logger: $this->createMock(originalClassName: LoggerInterface::class)),
			states: new IdpLoginStateStore(cacheFactory: $factory),
			assertionGuard: new AssertionGuard(replayGuard: new EnvelopeReplayGuard(cacheFactory: $factory)),
			trustMapper: $trust,
			pseudonyms: new SubjectPseudonymService(),
			exchange: $exchange,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			registrations: $registrations,
			secrets: $this->secrets()
		);
	}//end service()

	/**
	 * Start a Yivi login asking for some attributes, then answer the callback with some claims.
	 *
	 * @param IdpLoginService     $service The service.
	 * @param string              $asked   The attribute ids, comma-separated.
	 * @param array<string,mixed> $claims  The claims the bridge puts in the ID token.
	 *
	 * @return string Where the callback sends the browser.
	 */
	private function roundTrip(IdpLoginService $service, string $asked, array $claims): string {
		$redirect = $service->start(
			provider: 'yivi',
			params: [
				'organisation' => 'gemeente-x',
				'consumer' => 'portaliq',
				'trust' => 'substantial',
				'returnUrl' => self::RETURN_URL,
				'relayState' => 'portal-relay-42',
				'attributes' => $asked,
			]
		);
		parse_str((string)parse_url($redirect, PHP_URL_QUERY), $query);
		$this->issuer->nonce = (string)$query['nonce'];
		$this->issuer->claims = $claims;

		return $service->callback(provider: 'yivi', callback: ['code' => 'auth-code-1', 'state' => (string)$query['state']]);
	}//end roundTrip()

	/**
	 * The resident shares what the form asked: the envelope carries name and
	 * e-mail and no other claim, and both survive signing and verifying.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function testTheResidentSharesWhatTheFormAsked(): void {
		$back = $this->roundTrip(
			service: $this->service(registration: $this->registration(), trust: $this->decidedTrust()),
			asked: self::FULLNAME . ',' . self::EMAIL,
			claims: ['fullname' => 'Sanne de Vries', 'email' => 'sanne@example.nl', 'bsn' => '999993653', 'phone' => '0612345678']
		);

		$this->assertSame(expected: self::RETURN_URL . '?code=code-1&relayState=portal-relay-42', actual: $back);
		$this->assertCount(expectedCount: 1, haystack: $this->issued);
		$envelope = $this->issued[0];
		$this->assertSame(
			expected: [self::FULLNAME => 'Sanne de Vries', self::EMAIL => 'sanne@example.nl'],
			actual: $envelope->getAttributes()
		);
		$this->assertSame(expected: 'yivi', actual: $envelope->getProvider());
		$this->assertSame(expected: SubjectEnvelope::SUBTYPE_ATTRIBUTES, actual: $envelope->getSubType());
		$this->assertSame(expected: 'portaliq', actual: $envelope->getAudience());
		$this->assertSame(expected: 'gemeente-x', actual: $envelope->getOrganisation());
		$this->assertSame(expected: 'substantial', actual: $envelope->getTrust());
		$this->assertMatchesRegularExpression(pattern: '/^[0-9a-f]{64}$/', string: $envelope->getSubject());

		$service = new SubjectEnvelopeService(replayGuard: new EnvelopeReplayGuard(cacheFactory: $this->cacheFactory()));
		$verified = $service->verify(
			token: $service->mint(envelope: $envelope, signingKey: self::SIGNING_KEY),
			signingKey: self::SIGNING_KEY,
			audience: 'portaliq'
		);
		$this->assertSame(expected: $envelope->getAttributes(), actual: $verified->getAttributes());
		$this->assertSame(expected: $envelope->toClaims(), actual: $verified->toClaims());
	}//end testTheResidentSharesWhatTheFormAsked()

	/**
	 * The resident shares less than asked: integriq's own page, a way back
	 * with `error=attributes_not_disclosed`, and no code.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function testTheResidentSharesLessThanAsked(): void {
		foreach ([['fullname' => 'Sanne de Vries'], ['fullname' => 'Sanne de Vries', 'email' => '  '], ['fullname' => 'S', 'email' => ['x']]] as $claims) {
			$this->cache = new FakeMemcache();
			try {
				$this->roundTrip(
					service: $this->service(registration: $this->registration(), trust: $this->decidedTrust()),
					asked: self::FULLNAME . ',' . self::EMAIL,
					claims: $claims
				);
				$this->fail(message: 'A login that shared less than it asked was not refused.');
			} catch (IdpDisclosureIncompleteException $refusal) {
				$this->assertSame(
					expected: self::RETURN_URL . '?error=attributes_not_disclosed&relayState=portal-relay-42',
					actual: $refusal->getReturnUrl()
				);
			}
		}

		$this->assertSame(expected: [], actual: $this->issued, message: 'A code was issued for an incomplete disclosure.');
	}//end testTheResidentSharesLessThanAsked()

	/**
	 * A BSN attribute is pseudonymised as a DigiD BSN would be, unless the registration passes it on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function testABsnAttributeIsPseudonymisedUnlessThePassOnIsSet(): void {
		$factory = new AttributeEnvelopeFactory(pseudonyms: new SubjectPseudonymService());
		$attributes = [self::FULLNAME => 'Sanne de Vries', self::BSN => '999993653'];

		$kept = $factory->envelope(registration: $this->registration(), attributes: $attributes, salt: self::SALT, audience: 'portaliq', trust: 'low');
		$this->assertSame(
			expected: (new SubjectPseudonymService())->pseudonymFor(bsn: '999993653', organisation: 'gemeente-x', salt: self::SALT),
			actual: $kept->getAttributes()[self::BSN]
		);
		$this->assertSame(expected: 'Sanne de Vries', actual: $kept->getAttributes()[self::FULLNAME]);
		$this->assertStringNotContainsString(needle: '999993653', haystack: (string)json_encode($kept->toClaims()));

		$passed = $factory->envelope(
			registration: $this->registration(overrides: ['yiviBsnToConsumer' => true]),
			attributes: $attributes,
			salt: self::SALT,
			audience: 'portaliq',
			trust: 'low'
		);
		$this->assertSame(expected: $attributes, actual: $passed->getAttributes());
		$this->assertSame(expected: 'low', actual: $passed->getTrust());
	}//end testABsnAttributeIsPseudonymisedUnlessThePassOnIsSet()

	/**
	 * The same disclosure gives the same subject; another value or another organisation does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function testTheSubjectIsAStablePseudonymOfTheDisclosure(): void {
		$factory = new AttributeEnvelopeFactory(pseudonyms: new SubjectPseudonymService());
		$subject = fn (IdpRegistration $registration, array $attributes): string => $factory->envelope(
			registration: $registration,
			attributes: $attributes,
			salt: self::SALT,
			audience: 'portaliq',
			trust: 'low'
		)->getSubject();

		$one = $subject($this->registration(), [self::EMAIL => 'sanne@example.nl']);
		$this->assertSame(expected: $one, actual: $subject($this->registration(), [self::EMAIL => 'sanne@example.nl']));
		$this->assertNotSame(expected: $one, actual: $subject($this->registration(), [self::EMAIL => 'piet@example.nl']));
		$this->assertNotSame(
			expected: $one,
			actual: $subject($this->registration(overrides: ['organisation' => 'gemeente-y']), [self::EMAIL => 'sanne@example.nl'])
		);

		$this->expectException(exception: IdpAssertionException::class);
		$factory->envelope(registration: $this->registration(), attributes: [self::EMAIL => 'x'], salt: 'short', audience: 'portaliq', trust: 'low');
	}//end testTheSubjectIsAStablePseudonymOfTheDisclosure()

	/**
	 * A state without attributes, or one naming an attribute the registration no longer allows, issues nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function testAnEmptyStateOrAWithdrawnAttributeIsRefused(): void {
		$factory = new AttributeEnvelopeFactory(pseudonyms: new SubjectPseudonymService());
		$assertion = ['disclosed' => ['email' => 'sanne@example.nl', 'zipcode' => '1234AB']];

		foreach (['', ' , ', 'pbdf.gemeente.address.zipcode'] as $requested) {
			try {
				$factory->disclosed(registration: $this->registration(), requested: $requested, assertion: $assertion);
				$this->fail(message: 'The state "' . $requested . '" was not refused.');
			} catch (IdpAssertionException $refusal) {
				$this->assertNotInstanceOf(expected: IdpDisclosureIncompleteException::class, actual: $refusal);
			}
		}

		$this->expectException(exception: IdpDisclosureIncompleteException::class);
		$factory->disclosed(registration: $this->registration(), requested: self::EMAIL, assertion: ['disclosed' => 'not-a-map']);
	}//end testAnEmptyStateOrAWithdrawnAttributeIsRefused()

	/**
	 * Only an attribute envelope carries attributes, and a verified claim set keeps only string values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function testOnlyAnAttributeEnvelopeCarriesAttributes(): void {
		$digid = new SubjectEnvelope(
			subject: 'p',
			subType: 'bsn-pseudonym',
			provider: 'digid',
			audience: 'portaliq',
			organisation: 'gemeente-x',
			trust: 'substantial',
			attributes: [self::EMAIL => 'sanne@example.nl']
		);
		$this->assertSame(expected: [], actual: $digid->getAttributes());
		$this->assertArrayNotHasKey(key: 'attributes', array: $digid->toClaims());

		$rebuilt = SubjectEnvelope::fromClaims(
			claims: ['sub' => 's', 'subType' => 'attributes', 'provider' => 'yivi', 'attributes' => [self::EMAIL => 'a@b.nl', 'x' => ['y'], 'n' => 7]]
		);
		$this->assertSame(expected: [self::EMAIL => 'a@b.nl'], actual: $rebuilt->getAttributes());
		$this->assertSame(expected: [], actual: SubjectEnvelope::fromClaims(claims: ['subType' => 'attributes', 'attributes' => 'x'])->getAttributes());
	}//end testOnlyAnAttributeEnvelopeCarriesAttributes()

	/**
	 * With the real trust mapper a Yivi login still issues no code: its trust waits on D5.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-the-assurance-floor-applies-to-the-government-providers-a-yivi-login-carries-its-registrations-trust-req-yivi-004
	 */
	public function testWithoutATrustMappingAYiviLoginStaysFailClosed(): void {
		$back = $this->roundTrip(
			service: $this->service(registration: $this->registration(), trust: new TrustLevelMapper()),
			asked: self::EMAIL,
			claims: ['email' => 'sanne@example.nl']
		);

		$this->assertSame(expected: self::RETURN_URL . '?error=login_failed&relayState=portal-relay-42', actual: $back);
		$this->assertSame(expected: [], actual: $this->issued);
	}//end testWithoutATrustMappingAYiviLoginStaysFailClosed()
}//end class
