<?php

/**
 * Unit tests for the browser half of a government login: start and callback.
 *
 * Every class here is the real one except the identity provider (scripted),
 * the shared cache (an in-memory IMemcache) and app config (an array). The
 * refusals come first, because for a login broker they are the feature.
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

use OCA\Integriq\Auth\Idp\AssertionGuard;
use OCA\Integriq\Auth\Idp\EnvelopeCodeStore;
use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use OCA\Integriq\Auth\Idp\IdpAdapterRegistry;
use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpConsumerSecretResolver;
use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpRegistrationService;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\SubjectEnvelopeService;
use OCA\Integriq\Auth\Idp\SubjectPseudonymService;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpAssuranceBelowFloorException;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the start, the callback and the round trip to the exchange.
 */
class IdpBrowserLoginTest extends TestCase {

	/**
	 * The portal's registered return address.
	 *
	 * @var string
	 */
	public const RETURN_URL = 'https://portal.example.nl/portal/api/session/broker/callback';

	/**
	 * The envelope signing key.
	 *
	 * @var string
	 */
	private const SIGNING_KEY = 'envelope-signing-key-0123456789abcdef';

	/**
	 * The portal's exchange secret.
	 *
	 * @var string
	 */
	private const SECRET = 'portaliq-exchange-secret-0123456789';

	/**
	 * The SP EntityID the assertions name.
	 *
	 * @var string
	 */
	private const ENTITY_ID = 'https://integriq.example.nl/sp';

	/**
	 * The shared cache.
	 *
	 * @var FakeMemcache
	 */
	private FakeMemcache $cache;

	/**
	 * App config, as an array.
	 *
	 * @var array<string,string>
	 */
	private array $settings = [];

	/**
	 * A fresh cache and a broker that is on, keyed, and knows portaliq.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cache = new FakeMemcache();
		$this->settings = [
			IdpBrokerConfig::KEY_ENABLED => '1',
			IdpBrokerConfig::KEY_SIGNING => self::SIGNING_KEY,
			IdpBrokerConfig::KEY_CONSUMERS => (string)json_encode(
				[
					'portaliq' => ['enabled' => true, 'returnUrls' => [self::RETURN_URL], 'secretRef' => 'cred-1', 'secretOrganisation' => ''],
					'legacyapp' => 'legacy-exchange-secret-0123456789abc',
					'dossiq' => ['enabled' => false, 'returnUrls' => ['https://dossiq.example.nl/cb'], 'secretRef' => ''],
				]
			),
		];
	}//end setUp()

	/**
	 * The broker config over the settings array.
	 *
	 * @return IdpBrokerConfig The config.
	 */
	private function config(): IdpBrokerConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->settings[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->settings[$key] = $value;
				return true;
			}
		);

		return new IdpBrokerConfig($appConfig);
	}//end config()

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
	 * The exchange, with a broker double that answers the portal's secret for `cred-1`.
	 *
	 * @return EnvelopeExchangeService The exchange.
	 */
	private function exchange(): EnvelopeExchangeService {
		$resolver = new class(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		) extends IdpConsumerSecretResolver {
			/**
			 * A broker that holds one credential.
			 *
			 * @return object|null The broker.
			 */
			protected function resolveBroker(): ?object {
				return new class {
					/**
					 * The one credential.
					 *
					 * @param string $credentialId The reference.
					 *
					 * @return string|null The secret.
					 */
					public function resolveInjectable(string $credentialId): ?string {
						if ($credentialId !== 'cred-1') {
							throw new \RuntimeException('denied');
						}

						return 'portaliq-exchange-secret-0123456789';
					}
				};
			}
		};

		$factory = $this->cacheFactory();
		return new EnvelopeExchangeService(
			config: $this->config(),
			envelopeService: new SubjectEnvelopeService(replayGuard: new EnvelopeReplayGuard($factory)),
			codeStore: new EnvelopeCodeStore($factory),
			logger: $this->createMock(LoggerInterface::class),
			secretResolver: $resolver
		);
	}//end exchange()

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
			exchange: $this->exchange(),
			logger: $this->createMock(LoggerInterface::class),
			registrations: $this->registrations(),
			secrets: $this->secrets()
		);
	}//end service()

	/**
	 * A DigiD adapter answering a valid BSN assertion at `Substantieel`.
	 *
	 * @param array<string,mixed> $overrides Assertion fields to change.
	 *
	 * @return ScriptedGovernmentIdpAdapter The adapter.
	 */
	private function digid(array $overrides = []): ScriptedGovernmentIdpAdapter {
		return new ScriptedGovernmentIdpAdapter(
			providerId: 'digid',
			assertion: array_merge(
				[
					'id' => '_a' . bin2hex(random_bytes(8)),
					'audience' => self::ENTITY_ID,
					'notBefore' => (time() - 10),
					'notOnOrAfter' => (time() + 300),
					'subject' => '999993653',
					'subType' => 'bsn',
					'assuranceLevel' => 'Substantieel',
					'organisation' => '',
				],
				$overrides
			)
		);
	}//end digid()

	/**
	 * The start parameters the portal sends.
	 *
	 * @param array<string,string> $overrides Parameters to change.
	 *
	 * @return array<string,string> The parameters.
	 */
	private function startParams(array $overrides = []): array {
		return array_merge(
			[
				'organisation' => 'gemeente-x',
				'consumer' => 'portaliq',
				'trust' => 'substantial',
				'returnUrl' => self::RETURN_URL,
				'relayState' => 'portal-relay-42',
			],
			$overrides
		);
	}//end startParams()

	/**
	 * The query of a redirect address.
	 *
	 * @param string $url The address.
	 *
	 * @return array<string,string> The query.
	 */
	private function queryOf(string $url): array {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
		return $query;
	}//end queryOf()

	/**
	 * Assert the start refuses and neither stores a state nor reaches the provider.
	 *
	 * @param ScriptedGovernmentIdpAdapter $adapter The adapter.
	 * @param string $provider The provider.
	 * @param array<string,string> $params The parameters.
	 *
	 * @return void
	 */
	private function assertStartRefused(ScriptedGovernmentIdpAdapter $adapter, string $provider, array $params): void {
		try {
			$this->service($adapter)->start(provider: $provider, params: $params);
			$this->fail('The start was not refused.');
		} catch (IdpAssertionException $exception) {
			$this->assertNotSame('', $exception->getMessage());
		}

		$this->assertSame([], $adapter->begun, 'The browser was sent to the identity provider.');
		$this->assertSame([], $this->cache->entries, 'A state was stored for a refused start.');
	}//end assertStartRefused()

	/**
	 * A return address that is not registered is refused, even one that
	 * starts with the registered one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testAnUnregisteredReturnAddressIsRefused(): void {
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams(['returnUrl' => 'https://evil.example/steal']));
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams(['returnUrl' => self::RETURN_URL . '/../x']));
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams(['returnUrl' => '']));
	}//end testAnUnregisteredReturnAddressIsRefused()

	/**
	 * An unknown consumer, a disabled one and one in the older form cannot start.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function testUnknownDisabledAndOlderFormConsumersCannotStart(): void {
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams(['consumer' => 'nobody']));
		$this->assertStartRefused(
			$this->digid(),
			'digid',
			$this->startParams(['consumer' => 'dossiq', 'returnUrl' => 'https://dossiq.example.nl/cb'])
		);
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams(['consumer' => 'legacyapp']));
	}//end testUnknownDisabledAndOlderFormConsumersCannotStart()

	/**
	 * A broker that is off, or keyless, starts nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testADisabledOrKeylessBrokerStartsNothing(): void {
		$this->settings[IdpBrokerConfig::KEY_ENABLED] = '0';
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams());

		$this->settings[IdpBrokerConfig::KEY_ENABLED] = '1';
		$this->settings[IdpBrokerConfig::KEY_SIGNING] = 'short';
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams());
	}//end testADisabledOrKeylessBrokerStartsNothing()

	/**
	 * A provider with no live adapter, and a provider nobody knows, start nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testAnUnconfiguredOrUnknownProviderStartsNothing(): void {
		// The bound adapter is DigiD, so eHerkenning gets the dormant one.
		$this->assertStartRefused($this->digid(), 'eherkenning', $this->startParams());
		$this->assertStartRefused($this->digid(), 'facebook', $this->startParams());
		$this->assertStartRefused(
			new ScriptedGovernmentIdpAdapter(providerId: 'digid', configured: false),
			'digid',
			$this->startParams()
		);
	}//end testAnUnconfiguredOrUnknownProviderStartsNothing()

	/**
	 * A trust level nobody knows, a missing organisation and an oversized relay state are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testMalformedStartParametersAreRefused(): void {
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams(['trust' => 'medium']));
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams(['organisation' => '']));
		$this->assertStartRefused($this->digid(), 'digid', $this->startParams(['relayState' => str_repeat('r', 1025)]));
	}//end testMalformedStartParametersAreRefused()

	/**
	 * An assertion that answers no stored state is refused and issues no code.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 */
	public function testAnIdentityProviderInitiatedResponseIsRefused(): void {
		$adapter = $this->digid();

		foreach (['', '_never-sent'] as $inResponseTo) {
			try {
				$this->service($adapter)->callback(provider: 'digid', callback: ['inResponseTo' => $inResponseTo]);
				$this->fail('An unsolicited response was accepted.');
			} catch (IdpAssertionException $exception) {
				$this->assertStringContainsString('answers no login', $exception->getMessage());
			}
		}

		$this->assertSame([], $this->cache->entries, 'A code was issued, or an assertion id burnt, for an unsolicited response.');
	}//end testAnIdentityProviderInitiatedResponseIsRefused()

	/**
	 * A state answers one callback: the second one finds nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testAStateAnswersOneCallback(): void {
		$adapter = $this->digid();
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		$service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);

		$this->expectException(IdpAssertionException::class);
		$service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);
	}//end testAStateAnswersOneCallback()

	/**
	 * A state past its five minutes is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testAnExpiredStateIsRefused(): void {
		$adapter = $this->digid();
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('expired');
		$service->callback(
			provider: 'digid',
			callback: ['inResponseTo' => $adapter->lastRequestId],
			now: (time() + IdpLoginStateStore::TTL_SECONDS + 1)
		);
	}//end testAnExpiredStateIsRefused()

	/**
	 * A state whose stored body was changed in the cache does not verify.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 */
	public function testATamperedStateIsRefused(): void {
		$adapter = $this->digid();
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		foreach ($this->cache->entries as $key => $entry) {
			$this->cache->entries[$key] = str_replace('portal.example.nl', 'evil.example.nl', (string)$entry);
		}

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('does not verify');
		$service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);
	}//end testATamperedStateIsRefused()

	/**
	 * Every refusal after the state is found goes back to the consumer as one
	 * generic error with the relay state, and never with a code.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 */
	public function testAFailedCallbackReturnsOneGenericError(): void {
		$cases = [
			'wrong audience' => ['audience' => 'https://other.example/sp'],
			'unknown level' => ['assuranceLevel' => 'urn:unknown'],
			'expired assertion' => ['notOnOrAfter' => (time() - 1)],
			'wrong subject type' => ['subType' => 'kvk'],
			'another organisation' => ['organisation' => 'gemeente-y'],
		];

		foreach ($cases as $name => $overrides) {
			$this->setUp();
			$adapter = $this->digid($overrides);
			$service = $this->service($adapter);
			$service->start(provider: 'digid', params: $this->startParams());

			$redirect = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);

			$this->assertStringStartsWith(self::RETURN_URL . '?', $redirect, $name);
			$this->assertSame(
				['error' => 'login_failed', 'relayState' => 'portal-relay-42'],
				$this->queryOf($redirect),
				$name
			);
		}

		// Above the floor but below what the login asked for stays generic.
		$this->setUp();
		$adapter = $this->digid(['assuranceLevel' => 'Substantieel']);
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams(['trust' => 'high']));
		$redirect = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);
		$this->assertSame(['error' => 'login_failed', 'relayState' => 'portal-relay-42'], $this->queryOf($redirect));
	}//end testAFailedCallbackReturnsOneGenericError()

	/**
	 * A consumer disabled between start and callback gets no code.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function testAConsumerDisabledMidLoginGetsNoCode(): void {
		$adapter = $this->digid();
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = (string)json_encode(
			['portaliq' => ['enabled' => false, 'returnUrls' => [self::RETURN_URL], 'secretRef' => 'cred-1']]
		);

		$redirect = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);
		$this->assertSame('login_failed', ($this->queryOf($redirect)['error'] ?? null));
	}//end testAConsumerDisabledMidLoginGetsNoCode()

	/**
	 * The resident comes back signed in: the round trip from start through
	 * callback to the exchange, with the consumer's secret read by broker
	 * reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 */
	public function testTheResidentComesBackSignedIn(): void {
		$adapter = $this->digid();
		$service = $this->service($adapter);

		$toIdp = $service->start(provider: 'digid', params: $this->startParams());

		$this->assertStringStartsWith('https://idp.example.nl/sso', $toIdp);
		$this->assertCount(1, $adapter->begun);
		$this->assertNotSame('portal-relay-42', $adapter->begun[0]['relayState'], 'The consumer relay state reached the identity provider.');

		$back = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);

		$this->assertStringStartsWith(self::RETURN_URL . '?', $back);
		$query = $this->queryOf($back);
		$this->assertSame('portal-relay-42', $query['relayState']);
		$this->assertArrayNotHasKey('error', $query);

		$token = $this->exchange()->redeemCode(code: $query['code'], consumer: 'portaliq', presentedSecret: self::SECRET);
		$claims = json_decode((string)base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

		$this->assertSame('portaliq', $claims['audience']);
		$this->assertSame('gemeente-x', $claims['organisation']);
		$this->assertSame('bsn-pseudonym', $claims['subType']);
		$this->assertSame('substantial', $claims['trust']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $claims['sub']);
		$this->assertStringNotContainsString('999993653', $token);
		$this->assertArrayNotHasKey('branch', $claims);

		$this->expectException(IdpAssertionException::class);
		$this->exchange()->redeemCode(code: $query['code'], consumer: 'portaliq', presentedSecret: self::SECRET);
	}//end testTheResidentComesBackSignedIn()

	/**
	 * An employee signing in for one branch gets an envelope carrying it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-an-eherkenning-envelope-carries-the-branch-the-login-was-restricted-to-req-idp-004
	 */
	public function testAnEherkenningLoginCarriesItsBranch(): void {
		$adapter = new ScriptedGovernmentIdpAdapter(
			providerId: 'eherkenning',
			assertion: [
				'id' => '_eh1',
				'audience' => self::ENTITY_ID,
				'notBefore' => (time() - 10),
				'notOnOrAfter' => (time() + 300),
				'subject' => '12345678',
				'subType' => 'kvk',
				'assuranceLevel' => 'urn:etoegang:core:assurance-class:loa3',
				'branch' => '000012345678',
			]
		);
		$service = $this->service($adapter);
		$service->start(provider: 'eherkenning', params: $this->startParams());

		$query = $this->queryOf($service->callback(provider: 'eherkenning', callback: ['inResponseTo' => $adapter->lastRequestId]));
		$token = $this->exchange()->redeemCode(code: $query['code'], consumer: 'portaliq', presentedSecret: self::SECRET);
		$claims = json_decode((string)base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

		$this->assertSame('12345678', $claims['sub']);
		$this->assertSame('kvk', $claims['subType']);
		$this->assertSame('000012345678', $claims['branch']);
	}//end testAnEherkenningLoginCarriesItsBranch()

	/**
	 * A branch that is not a vestigingsnummer ends the login rather than
	 * being dropped, which would widen it to the whole company.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-an-eherkenning-envelope-carries-the-branch-the-login-was-restricted-to-req-idp-004
	 */
	public function testAMalformedBranchEndsTheLogin(): void {
		$adapter = new ScriptedGovernmentIdpAdapter(
			providerId: 'eherkenning',
			assertion: [
				'id' => '_eh2',
				'audience' => self::ENTITY_ID,
				'notBefore' => (time() - 10),
				'notOnOrAfter' => (time() + 300),
				'subject' => '12345678',
				'subType' => 'kvk',
				'assuranceLevel' => 'EH3',
				'branch' => 'branch-7',
			]
		);
		$service = $this->service($adapter);
		$service->start(provider: 'eherkenning', params: $this->startParams());

		$query = $this->queryOf($service->callback(provider: 'eherkenning', callback: ['inResponseTo' => $adapter->lastRequestId]));
		$this->assertSame('login_failed', ($query['error'] ?? null));
	}//end testAMalformedBranchEndsTheLogin()

	/**
	 * A DigiD assertion that carries a branch anyway does not put it on the envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-an-eherkenning-envelope-carries-the-branch-the-login-was-restricted-to-req-idp-004
	 */
	public function testADigidAssertionNeverCarriesABranch(): void {
		$adapter = $this->digid(['branch' => '000012345678']);
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		$query = $this->queryOf($service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]));
		$token = $this->exchange()->redeemCode(code: $query['code'], consumer: 'portaliq', presentedSecret: self::SECRET);

		$this->assertStringNotContainsString('branch', (string)base64_decode(strtr(explode('.', $token)[1], '-_', '+/')));
	}//end testADigidAssertionNeverCarriesABranch()

	/**
	 * A consumer in the older form still redeems with its inline secret.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function testAnOlderFormConsumerStillRedeems(): void {
		$exchange = $this->exchange();
		$code = $exchange->issueCode(
			envelope: new \OCA\Integriq\Auth\Idp\SubjectEnvelope('p', 'bsn-pseudonym', 'digid', 'legacyapp', 'gemeente-x', 'low')
		);

		$token = $exchange->redeemCode(code: $code, consumer: 'legacyapp', presentedSecret: 'legacy-exchange-secret-0123456789abc');
		$this->assertCount(3, explode('.', $token));
	}//end testAnOlderFormConsumerStillRedeems()

	/**
	 * A disabled consumer, and one whose broker reference the broker refuses, cannot redeem.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function testADisabledOrUnresolvableConsumerCannotRedeem(): void {
		$this->settings[IdpBrokerConfig::KEY_CONSUMERS] = (string)json_encode(
			[
				'portaliq' => ['enabled' => false, 'returnUrls' => [self::RETURN_URL], 'secretRef' => 'cred-1'],
				'other' => ['enabled' => true, 'returnUrls' => [], 'secretRef' => 'cred-unknown'],
			]
		);

		foreach (['portaliq' => self::SECRET, 'other' => ''] as $consumer => $secret) {
			$exchange = $this->exchange();
			$code = $exchange->issueCode(
				envelope: new \OCA\Integriq\Auth\Idp\SubjectEnvelope('p', 'bsn-pseudonym', 'digid', $consumer, 'gemeente-x', 'low')
			);

			try {
				$exchange->redeemCode(code: $code, consumer: $consumer, presentedSecret: $secret);
				$this->fail($consumer . ' redeemed.');
			} catch (IdpAssertionException $exception) {
				$this->assertSame('The exchange request is refused.', $exception->getMessage());
			}
		}
	}//end testADisabledOrUnresolvableConsumerCannotRedeem()

	/**
	 * The service with a logger that records every warning.
	 *
	 * @param GovernmentIdpAdapterInterface $adapter The adapter.
	 * @param \ArrayObject<int, array{0: string, 1: array<string, mixed>}> $warnings Where warnings go.
	 *
	 * @return IdpLoginService The service.
	 */
	private function recordingService(GovernmentIdpAdapterInterface $adapter, \ArrayObject $warnings): IdpLoginService {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use ($warnings): void {
				$warnings->append([$message, $context]);
			}
		);
		$factory = $this->cacheFactory();
		return new IdpLoginService(
			config: $this->config(),
			adapters: new IdpAdapterRegistry(boundAdapter: $adapter, logger: $this->createMock(LoggerInterface::class)),
			states: new IdpLoginStateStore($factory),
			assertionGuard: new AssertionGuard(replayGuard: new EnvelopeReplayGuard($factory)),
			trustMapper: new TrustLevelMapper(),
			pseudonyms: new SubjectPseudonymService(),
			exchange: $this->exchange(),
			logger: $logger,
			registrations: $this->registrations(),
			secrets: $this->secrets()
		);
	}//end recordingService()

	/**
	 * Each refusal at the callback logs its own reason, and a start hands the
	 * adapter exactly the organisation, consumer, trust and a fresh nonce.
	 *
	 * @return void
	 */
	public function testEachCallbackRefusalLogsItsReason(): void {
		$cases = [
			'trust below the request' => [['assuranceLevel' => 'Substantieel'], 'The assurance level is below the trust the login asked for.', ' High '],
			'wrong subject type' => [['subType' => 'kvk'], 'The assertion carries no subject this provider may deliver.'],
			'empty subject' => [['subject' => '  '], 'The assertion carries no subject this provider may deliver.'],
			'another organisation' => [['organisation' => 'Gemeente-Y'], 'The assertion names another organisation than the login started for.'],
		];

		foreach ($cases as $name => $case) {
			[$overrides, $reason] = $case;
			$trust = ($case[2] ?? ' Substantial ');
			$this->setUp();
			$warnings = new \ArrayObject();
			$adapter = $this->digid($overrides);
			$service = $this->recordingService($adapter, $warnings);
			$service->start(provider: 'digid', params: $this->startParams(['organisation' => ' Gemeente-X ', 'trust' => $trust]));

			$this->assertCount(1, $adapter->begun, $name);
			$begun = $adapter->begun[0];
			$this->assertSame(['gemeente-x', 'portaliq', strtolower(trim($trust))], [$begun['organisation'], $begun['consumer'], $begun['trust']], $name);
			$this->assertNotSame('portal-relay-42', $begun['relayState'], $name);

			$redirect = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);

			$this->assertSame(['error' => 'login_failed', 'relayState' => 'portal-relay-42'], $this->queryOf($redirect), $name);
			$this->assertSame(
				[['Integriq idp-broker: a login was refused at the callback: ' . $reason, ['provider' => 'digid', 'consumer' => 'portaliq']]],
				$warnings->getArrayCopy(),
				$name
			);
		}

		$this->setUp();
		$warnings = new \ArrayObject();
		$adapter = $this->digid(['organisation' => 'GEMEENTE-X']);
		$service = $this->recordingService($adapter, $warnings);
		$service->start(provider: 'digid', params: $this->startParams());
		$redirect = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);
		$this->assertSame(['code', 'relayState'], array_keys($this->queryOf($redirect)));
		$this->assertSame([], $warnings->getArrayCopy());
	}//end testEachCallbackRefusalLogsItsReason()

	/**
	 * An adapter for any provider answering one assurance level.
	 *
	 * @param string $provider The provider.
	 * @param string $level    The assurance level the assertion carries.
	 *
	 * @return ScriptedGovernmentIdpAdapter The adapter.
	 */
	private function adapterAt(string $provider, string $level): ScriptedGovernmentIdpAdapter {
		$subjects = [
			'digid' => ['999993653', 'bsn'],
			'eherkenning' => ['12345678', 'kvk'],
			'eidas' => ['NL/DE/1234', 'eidas-person-identifier'],
		];
		return new ScriptedGovernmentIdpAdapter(
			providerId: $provider,
			assertion: [
				'id' => '_a' . bin2hex(random_bytes(8)),
				'audience' => self::ENTITY_ID,
				'notBefore' => (time() - 10),
				'notOnOrAfter' => (time() + 300),
				'subject' => $subjects[$provider][0],
				'subType' => $subjects[$provider][1],
				'assuranceLevel' => $level,
				'organisation' => '',
			]
		);
	}//end adapterAt()

	/**
	 * A start that asks for less than substantial is refused before anything
	 * reaches the identity provider.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function testAStartBelowTheFloorIsRefused(): void {
		$this->assertSame('substantial', TrustLevelMapper::FLOOR);

		$adapter = $this->digid();
		try {
			$this->service($adapter)->start(provider: 'digid', params: $this->startParams(['trust' => ' LOW ']));
			$this->fail('A start asking for low was not refused.');
		} catch (IdpAssertionException $exception) {
			$this->assertSame('The login asks for a trust level below the floor of substantial, so no login starts.', $exception->getMessage());
		}

		$this->assertSame([], $adapter->begun);
		$this->assertSame([], $this->cache->entries);

		$this->service($this->digid())->start(provider: 'digid', params: $this->startParams(['trust' => 'high']));
		$this->assertNotSame([], $this->cache->entries, 'A start asking for high must be allowed.');
	}//end testAStartBelowTheFloorIsRefused()

	/**
	 * Every level below the floor is refused at the callback, with no code,
	 * and the refusal carries the level used and the way back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function testEveryLevelBelowTheFloorIsRefusedWithTheWayBack(): void {
		$cases = [
			['digid', 'Basis', 'basis'],
			['digid', 'Midden', 'midden'],
			['eherkenning', 'EH2', 'eh2'],
			['eherkenning', 'EH2+', 'eh2+'],
			['eherkenning', 'urn:etoegang:core:assurance-class:loa2plus', 'urn:etoegang:core:assurance-class:loa2plus'],
			['eidas', 'low', 'low'],
		];

		foreach ($cases as [$provider, $level, $canonical]) {
			$this->setUp();
			$adapter = $this->adapterAt(provider: $provider, level: $level);
			$service = $this->service($adapter);
			$service->start(provider: $provider, params: $this->startParams());
			$codesBefore = $this->cache->entries;

			try {
				$service->callback(provider: $provider, callback: ['inResponseTo' => $adapter->lastRequestId]);
				$this->fail($provider . ' ' . $level . ' was not refused.');
			} catch (IdpAssuranceBelowFloorException $refusal) {
				$this->assertSame($provider, $refusal->getProvider(), $level);
				$this->assertSame($canonical, $refusal->getLevelUsed(), $level);
				$this->assertStringStartsWith(self::RETURN_URL . '?', $refusal->getReturnUrl(), $level);
				$this->assertSame(
					['error' => 'assurance_below_floor', 'relayState' => 'portal-relay-42'],
					$this->queryOf($refusal->getReturnUrl()),
					$level
				);
			}

			// The state is consumed and the assertion id burnt; no code is stored on top.
			$this->assertCount(count($codesBefore), $this->cache->entries, $level . ': a code was issued.');
		}
	}//end testEveryLevelBelowTheFloorIsRefusedWithTheWayBack()

	/**
	 * Every level at or above the floor logs in when it meets the trust asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function testEveryLevelAtOrAboveTheFloorIsAccepted(): void {
		$cases = [
			['digid', 'Substantieel', 'substantial'],
			['digid', 'Hoog', 'high'],
			['eherkenning', 'EH3', 'substantial'],
			['eherkenning', 'EH4', 'high'],
			['eidas', 'substantial', 'substantial'],
			['eidas', 'http://eidas.europa.eu/LoA/high', 'high'],
		];

		foreach ($cases as [$provider, $level, $trust]) {
			$this->setUp();
			$adapter = $this->adapterAt(provider: $provider, level: $level);
			$service = $this->service($adapter);
			$service->start(provider: $provider, params: $this->startParams(['trust' => $trust]));

			$redirect = $service->callback(provider: $provider, callback: ['inResponseTo' => $adapter->lastRequestId]);

			$this->assertSame(['code', 'relayState'], array_keys($this->queryOf($redirect)), $provider . ' ' . $level);
		}
	}//end testEveryLevelAtOrAboveTheFloorIsAccepted()

	/**
	 * A refusal below the floor is logged with the level and without the subject.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	public function testARefusalBelowTheFloorIsLoggedWithoutTheSubject(): void {
		$warnings = new \ArrayObject();
		$adapter = $this->digid(['assuranceLevel' => 'Midden']);
		$service = $this->recordingService($adapter, $warnings);
		$service->start(provider: 'digid', params: $this->startParams());

		try {
			$service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);
			$this->fail('Midden was not refused.');
		} catch (IdpAssuranceBelowFloorException $refusal) {
			$this->assertSame('midden', $refusal->getLevelUsed());
		}

		$this->assertSame(
			[[
				'Integriq idp-broker: a login was refused at the callback: The assurance level is below the floor of substantial.',
				['provider' => 'digid', 'consumer' => 'portaliq', 'level' => 'midden'],
			]],
			$warnings->getArrayCopy()
		);
		$this->assertStringNotContainsString('999993653', serialize($warnings->getArrayCopy()));
	}//end testARefusalBelowTheFloorIsLoggedWithoutTheSubject()

	/**
	 * The registrations: gemeente-x has an enabled `hmac` registration for DigiD,
	 * eHerkenning and eIDAS, with our EntityID and a salt credential.
	 *
	 * @return IdpRegistrationService
	 */
	private function registrations(): IdpRegistrationService {
		$registrations = $this->createMock(IdpRegistrationService::class);
		$registrations->method('find')->willReturnCallback(
			static function (string $organisation, string $provider): ?IdpRegistration {
				if ($organisation !== 'gemeente-x' || in_array($provider, ['digid', 'eherkenning', 'eidas'], true) === false) {
					return null;
				}

				return new IdpRegistration(
					id: 'reg-' . $provider,
					data: [
						'organisation' => $organisation,
						'provider' => $provider,
						'enabled' => true,
						'spEntityId' => self::ENTITY_ID,
						'pseudonymisation' => 'hmac',
						'credentials' => ['salt' => 'cred-salt'],
					]
				);
			}
		);

		return $registrations;

	}//end registrations()

	/**
	 * The secret seam, answering the salt credential.
	 *
	 * @return IdpSecretSeam
	 */
	private function secrets(): IdpSecretSeam {
		$secrets = $this->createMock(IdpSecretSeam::class);
		$secrets->method('read')->willReturnCallback(
			static fn (string $registration, string $organisation, string $role, string $reference): string => str_repeat('s', 40)
		);

		return $secrets;

	}//end secrets()
}//end class
