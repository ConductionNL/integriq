<?php

/**
 * Edge-case tests for the browser login: an adapter that gives no request,
 * a return address that already carries a query, and a polymorphic pseudonym.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\AssertionGuard;
use OCA\Integriq\Auth\Idp\AtomicCache;
use OCA\Integriq\Auth\Idp\EnvelopeCodeStore;
use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use OCA\Integriq\Auth\Idp\IdpAdapterRegistry;
use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpConsumer;
use OCA\Integriq\Auth\Idp\IdpConsumerSecretResolver;
use OCA\Integriq\Auth\Idp\IdpLoginCallback;
use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Auth\Idp\IdpLoginStart;
use OCA\Integriq\Auth\Idp\IdpLoginStateStore;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpRegistrationService;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\SubjectEnvelope;
use OCA\Integriq\Auth\Idp\SubjectEnvelopeService;
use OCA\Integriq\Auth\Idp\SubjectPseudonymService;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Login paths the main browser-login test leaves out.
 */
#[CoversClass(IdpLoginService::class)]
#[UsesClass(AssertionGuard::class)]
#[UsesClass(AtomicCache::class)]
#[UsesClass(EnvelopeCodeStore::class)]
#[UsesClass(EnvelopeExchangeService::class)]
#[UsesClass(EnvelopeReplayGuard::class)]
#[UsesClass(IdpAdapterRegistry::class)]
#[UsesClass(IdpBrokerConfig::class)]
#[UsesClass(IdpConsumer::class)]
#[UsesClass(IdpConsumerSecretResolver::class)]
#[UsesClass(IdpLoginCallback::class)]
#[UsesClass(IdpLoginStart::class)]
#[UsesClass(IdpLoginStateStore::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(SubjectEnvelope::class)]
#[UsesClass(SubjectEnvelopeService::class)]
#[UsesClass(SubjectPseudonymService::class)]
#[UsesClass(TrustLevelMapper::class)]
#[UsesClass(IdpAssertionException::class)]
#[UsesClass(IdpSecretRefusedException::class)]
class IdpLoginServiceEdgesTest extends TestCase {

	/**
	 * A registered return address that already carries a query.
	 */
	private const RETURN_URL = 'https://portal.example.nl/cb?tenant=x';

	/**
	 * The service-provider entity id.
	 */
	private const ENTITY_ID = 'https://integriq.example.nl/sp';

	/**
	 * @var FakeMemcache
	 */
	private FakeMemcache $cache;

	/**
	 * @var array<string,string>
	 */
	private array $settings = [];

	/**
	 * Set up an enabled broker with one consumer.
	 *
	 * @return void
	 */
	/**
	 * Per-test changes to gemeente-x's registration.
	 *
	 * @var array<string, mixed>
	 */
	private array $registrationOverrides = [];

	/**
	 * The salt reads: registration, organisation, role, reference.
	 *
	 * @var list<array{0: string, 1: string, 2: string, 3: string}>
	 */
	private array $saltReads = [];

	/**
	 * Every message and context the login service logged.
	 *
	 * @var list<string>
	 */
	private array $logLines = [];

	/**
	 * Whether the secret seam refuses the salt.
	 *
	 * @var bool
	 */
	private bool $saltRefused = false;

	protected function setUp(): void {
		$this->cache = new FakeMemcache();
		$this->settings = [
			IdpBrokerConfig::KEY_ENABLED => '1',
			IdpBrokerConfig::KEY_SIGNING => 'envelope-signing-key-0123456789abcdef',
			IdpBrokerConfig::KEY_CONSUMERS => (string)json_encode(
				['portaliq' => ['enabled' => true, 'returnUrls' => [self::RETURN_URL], 'secretRef' => 'cred-1']]
			),
		];

	}//end setUp()

	/**
	 * The broker config over the in-memory settings.
	 *
	 * @return IdpBrokerConfig
	 */
	private function config(): IdpBrokerConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->settings[$key] ?? $default)
		);

		return new IdpBrokerConfig($appConfig);

	}//end config()

	/**
	 * The login service around an adapter.
	 *
	 * @param GovernmentIdpAdapterInterface $adapter The adapter.
	 *
	 * @return IdpLoginService
	 */
	private function service(GovernmentIdpAdapterInterface $adapter): IdpLoginService {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($this->cache);

		$exchange = new EnvelopeExchangeService(
			config: $this->config(),
			envelopeService: new SubjectEnvelopeService(replayGuard: new EnvelopeReplayGuard($factory)),
			codeStore: new EnvelopeCodeStore($factory),
			logger: $this->createMock(LoggerInterface::class),
			secretResolver: new IdpConsumerSecretResolver(
				$this->createMock(ContainerInterface::class),
				$this->createMock(LoggerInterface::class)
			)
		);

		return new IdpLoginService(
			config: $this->config(),
			adapters: new IdpAdapterRegistry(boundAdapter: $adapter, logger: $this->createMock(LoggerInterface::class)),
			states: new IdpLoginStateStore($factory),
			assertionGuard: new AssertionGuard(replayGuard: new EnvelopeReplayGuard($factory)),
			trustMapper: new TrustLevelMapper(),
			pseudonyms: new SubjectPseudonymService(),
			exchange: $exchange,
			logger: $this->recordingLogger(),
			registrations: $this->registrations(),
			secrets: $this->secrets()
		);

	}//end service()

	/**
	 * A DigiD adapter answering with the given subject.
	 *
	 * @param string $subject The subject.
	 * @param string $subType The subject type.
	 *
	 * @return ScriptedGovernmentIdpAdapter
	 */
	private function digid(string $subject, string $subType): ScriptedGovernmentIdpAdapter {
		return new ScriptedGovernmentIdpAdapter(
			providerId: 'digid',
			assertion: [
				'id' => '_a' . bin2hex(random_bytes(8)),
				'audience' => self::ENTITY_ID,
				'notBefore' => (time() - 10),
				'notOnOrAfter' => (time() + 300),
				'subject' => $subject,
				'subType' => $subType,
				'assuranceLevel' => 'Substantieel',
				'organisation' => '',
			]
		);

	}//end digid()

	/**
	 * The start parameters for the consumer.
	 *
	 * @return array<string,string>
	 */
	private function startParams(): array {
		return [
			'organisation' => 'gemeente-x',
			'consumer' => 'portaliq',
			'trust' => 'substantial',
			'returnUrl' => self::RETURN_URL,
			'relayState' => 'relay-7',
		];

	}//end startParams()

	/**
	 * An adapter that gives no request id is refused, and no state is kept.
	 *
	 * @return void
	 */
	public function testAnAdapterThatGivesNoRequestStartsNothing(): void {
		$adapter = new class('digid') extends ScriptedGovernmentIdpAdapter {
			/**
			 * Begin without a request id.
			 *
			 * @param array $context The context.
			 *
			 * @return array
			 */
			public function beginAuthentication(array $context): array {
				parent::beginAuthentication($context);
				return ['requestId' => ' ', 'redirectUrl' => 'https://idp.example.nl/sso'];
			}
		};

		try {
			$this->service($adapter)->start(provider: 'digid', params: $this->startParams());
			$this->fail('The start was not refused.');
		} catch (IdpAssertionException $exception) {
			$this->assertSame('The identity provider adapter gave no request to follow.', $exception->getMessage());
		}

		$this->assertSame([], $this->cache->entries);

	}//end testAnAdapterThatGivesNoRequestStartsNothing()

	/**
	 * A return address with a query gets the code appended with '&'.
	 *
	 * @return void
	 */
	public function testTheCodeIsAppendedToAnExistingQuery(): void {
		$adapter = $this->digid('999993653', 'bsn');
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		$back = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);

		$this->assertMatchesRegularExpression('#^https://portal\.example\.nl/cb\?tenant=x&code=[^&]+&relayState=relay-7$#', $back);

	}//end testTheCodeIsAppendedToAnExistingQuery()

	/**
	 * A refused callback also appends its error with '&'.
	 *
	 * @return void
	 */
	public function testARefusalIsAppendedToAnExistingQuery(): void {
		$adapter = $this->digid('999993653', 'kvk');
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		$back = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);

		$this->assertStringStartsWith(self::RETURN_URL . '&error=', $back);
		$this->assertStringEndsWith('&relayState=relay-7', $back);
		$this->assertStringNotContainsString('code=', $back);

	}//end testARefusalIsAppendedToAnExistingQuery()

	/**
	 * A polymorphic pseudonym from DigiD is accepted as delivered and earns a code.
	 *
	 * @return void
	 */
	public function testAPolymorphicPseudonymEarnsACode(): void {
		$adapter = $this->digid('pp-0123456789abcdef', 'bsn-pseudonym');
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		$back = $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);

		$this->assertMatchesRegularExpression('#^https://portal\.example\.nl/cb\?tenant=x&code=[^&]+&relayState=relay-7$#', $back);

	}//end testAPolymorphicPseudonymEarnsACode()

	/**
	 * A logger that keeps every message with its context.
	 *
	 * @return LoggerInterface
	 */
	private function recordingLogger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []): void {
					$this->logLines[] = $message . ' ' . (string) json_encode($context);
				}
			);
		}

		return $logger;

	}//end recordingLogger()

	/**
	 * Run one DigiD login for gemeente-x and answer where the browser is sent back.
	 *
	 * @param ScriptedGovernmentIdpAdapter $adapter The adapter.
	 *
	 * @return string The return address.
	 */
	private function login(ScriptedGovernmentIdpAdapter $adapter): string {
		$service = $this->service($adapter);
		$service->start(provider: 'digid', params: $this->startParams());

		return $service->callback(provider: 'digid', callback: ['inResponseTo' => $adapter->lastRequestId]);

	}//end login()

	/**
	 * An assertion naming another organisation's EntityID is refused on the
	 * audience check: the audience is this organisation's registration.
	 *
	 * @return void
	 */
	public function testAnAssertionForAnotherOrganisationsEntityIdIsRefused(): void {
		$this->registrationOverrides = ['spEntityId' => 'urn:nl:gemeente-x:sp'];

		$back = $this->login($this->digid('999993653', 'bsn'));

		$this->assertStringContainsString('error=', $back);
		$this->assertStringNotContainsString('code=', $back);
		$this->assertStringContainsString('another audience', implode("\n", $this->logLines));

	}//end testAnAssertionForAnotherOrganisationsEntityIdIsRefused()

	/**
	 * Without an enabled registration for the organisation and provider, no code is issued.
	 *
	 * @return void
	 */
	public function testWithoutAnEnabledRegistrationNoCodeIsIssued(): void {
		$this->registrationOverrides = ['enabled' => false];

		$back = $this->login($this->digid('999993653', 'bsn'));

		$this->assertStringContainsString('error=', $back);
		$this->assertStringNotContainsString('code=', $back);

	}//end testWithoutAnEnabledRegistrationNoCodeIsIssued()

	/**
	 * In `polymorphic` mode a plain BSN is refused, no salt is read, and the
	 * BSN is in no log line.
	 *
	 * @return void
	 */
	public function testAPlainBsnInPolymorphicModeIsRefusedAndNeverLogged(): void {
		$this->registrationOverrides = ['pseudonymisation' => 'polymorphic'];

		$back = $this->login($this->digid('999993653', 'bsn'));

		$this->assertStringContainsString('error=', $back);
		$this->assertStringNotContainsString('code=', $back);
		$this->assertSame([], $this->saltReads);
		$this->assertNotSame([], $this->logLines);
		$this->assertStringNotContainsString('999993653', implode("\n", $this->logLines));

	}//end testAPlainBsnInPolymorphicModeIsRefusedAndNeverLogged()

	/**
	 * In `hmac` mode the salt is read from Keepiq through the registration's
	 * own salt credential, for its own organisation, and the login earns a code.
	 *
	 * @return void
	 */
	public function testInHmacModeTheSaltComesFromTheRegistrationsCredential(): void {
		$back = $this->login($this->digid('999993653', 'bsn'));

		$this->assertStringContainsString('code=', $back);
		$this->assertSame([['reg-digid', 'gemeente-x', 'salt', 'cred-salt']], $this->saltReads);

	}//end testInHmacModeTheSaltComesFromTheRegistrationsCredential()

	/**
	 * A salt the seam refuses ends the login without a code.
	 *
	 * @return void
	 */
	public function testARefusedSaltEndsTheLogin(): void {
		$this->registrationOverrides = ['credentials' => []];
		$this->saltRefused = true;

		$back = $this->login($this->digid('999993653', 'bsn'));

		$this->assertStringContainsString('error=', $back);
		$this->assertStringNotContainsString('code=', $back);

	}//end testARefusedSaltEndsTheLogin()

	/**
	 * The identity provider's own spelling of a level is read through the
	 * registration's `assuranceMapping`; without the mapping it is refused.
	 *
	 * @return void
	 */
	public function testTheAssuranceSpellingIsMappedByTheRegistration(): void {
		$adapter = $this->digid('pp-0123456789abcdef', 'bsn-pseudonym');
		$adapter->assertion['assuranceLevel'] = 'urn:digid:substantieel';
		$this->assertStringContainsString('error=', $this->login($adapter));

		$this->registrationOverrides = ['assuranceMapping' => ['urn:digid:substantieel' => 'substantieel']];
		$adapter = $this->digid('pp-0123456789abcdef', 'bsn-pseudonym');
		$adapter->assertion['assuranceLevel'] = 'urn:digid:substantieel';
		$this->assertStringContainsString('code=', $this->login($adapter));

	}//end testTheAssuranceSpellingIsMappedByTheRegistration()

	/**
	 * The registrations: gemeente-x has an enabled `hmac` registration for DigiD
	 * and eHerkenning, with our EntityID and a salt credential.
	 *
	 * @return IdpRegistrationService
	 */
	private function registrations(): IdpRegistrationService {
		$registrations = $this->createMock(IdpRegistrationService::class);
		$registrations->method('find')->willReturnCallback(
			function (string $organisation, string $provider): ?IdpRegistration {
				if ($organisation !== 'gemeente-x' || in_array($provider, ['digid', 'eherkenning'], true) === false) {
					return null;
				}

				return new IdpRegistration(
					id: 'reg-' . $provider,
					data: $this->registrationOverrides + [
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
			function (string $registration, string $organisation, string $role, string $reference): string {
				$this->saltReads[] = [$registration, $organisation, $role, $reference];
				if ($this->saltRefused === true) {
					throw new IdpSecretRefusedException(reason: IdpSecretSeam::REASON_SECRET_MISSING, detail: 'no credential is referenced');
				}

				return str_repeat('s', 40);
			}
		);

		return $secrets;

	}//end secrets()
}//end class
