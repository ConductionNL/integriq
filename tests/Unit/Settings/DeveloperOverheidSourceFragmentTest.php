<?php

/**
 * Unit tests for the developer.overheid.nl OSS-register source seed fragment.
 *
 * Reads the real `lib/Settings/register.d/developer-overheid-source.json`, the
 * file OpenRegister imports, rather than a copy of it. Beyond the shape of the
 * fragment, the source is put through the call engine once with the broker
 * stubbed at its outer edge, so the claim "the key arrives as X-Api-Key and the
 * CallLog shows it redacted" is checked against the code that does it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * @spec openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-the-oss-register-is-a-dormant-source-that-holds-only-a-credential-reference-req-dos-001
 * @spec openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-the-key-is-sent-as-x-api-key-and-never-stored-or-logged-req-dos-002
 * @spec openspec/changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md#requirement-gitlabcom-raw-files-are-a-credential-free-source-req-dos-003
 */
class DeveloperOverheidSourceFragmentTest extends TestCase {

	/**
	 * The placeholder key every test credential resolves to.
	 *
	 * @var string
	 */
	private const KEY = 'YOUR_API_KEY_HERE';

	/**
	 * Top-level source fields that would hold a secret.
	 *
	 * @var array<int, string>
	 */
	private const SECRET_FIELDS = ['apikey', 'password', 'secret', 'jwt', 'token', 'authorizationHeader', 'authenticationConfig'];

	/**
	 * Saved CallLogs, captured from the call engine's object service.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Request options the call engine handed to the HTTP client.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * The seeded sources of a fragment, by slug.
	 *
	 * @param string $file The fragment's file name under lib/Settings/register.d.
	 *
	 * @return array<string, array<string, mixed>> The sources.
	 */
	private function sources(string $file): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/' . $file), true);
		$this->assertIsArray($data, 'The fragment must be valid JSON.');

		$bySlug = [];
		foreach ($data['components']['objects'] as $object) {
			$this->assertSame('integriq', $object['@self']['register']);
			$this->assertSame('source', $object['@self']['schema']);
			$bySlug[$object['@self']['slug']] = $object;
		}

		return $bySlug;
	}//end sources()

	/**
	 * The register's source as seeded.
	 *
	 * @return array<string, mixed> The source.
	 */
	private function register(): array {
		return $this->sources('developer-overheid-source.json')['developer-overheid-oss'];
	}//end register()

	/**
	 * The source is disabled, at the register's base URL, and asks for JSON.
	 *
	 * @return void
	 */
	public function testTheRegisterSourceIsDormant(): void {
		$source = $this->register();

		$this->assertSame('https://api.developer.overheid.nl/oss-register/v1', $source['location']);
		$this->assertFalse($source['isEnabled']);
		$this->assertSame('application/json', $source['configuration']['headers']['Accept']);
		$this->assertSame('none', $source['auth'], 'The key must not go through the source-level key fields.');

	}//end testTheRegisterSourceIsDormant()

	/**
	 * The only key material is the placeholder; the header renders it.
	 *
	 * Asserting the whole configuration is what proves no other value under it
	 * is a literal key.
	 *
	 * @return void
	 */
	public function testTheConfigurationHoldsOnlyTheReference(): void {
		$configuration = $this->register()['configuration'];

		$this->assertSame(
			['credentialRef' => ['credentialName' => 'developer-overheid-oss']],
			$configuration['authentication']['apikey']
		);
		$this->assertSame('{{ source.configuration.authentication.apikey }}', $configuration['headers']['X-Api-Key']);
		$this->assertSame(
			[
				'headers' => [
					'Accept' => 'application/json',
					'X-Api-Key' => '{{ source.configuration.authentication.apikey }}',
				],
				'authentication' => [
					'type' => 'apikey',
					'apikey' => ['credentialRef' => ['credentialName' => 'developer-overheid-oss']],
				],
			],
			$configuration
		);

		foreach (self::SECRET_FIELDS as $field) {
			$this->assertArrayNotHasKey($field, $this->register(), sprintf('The source carries a secret field "%s".', $field));
		}

	}//end testTheConfigurationHoldsOnlyTheReference()

	/**
	 * The description says where the key comes from and what to call the credential.
	 *
	 * @return void
	 */
	public function testTheDescriptionGivesTheRecipe(): void {
		$description = $this->register()['description'];

		$this->assertStringContainsString('https://apis.developer.overheid.nl/apis/key-aanvragen', $description);
		$this->assertStringContainsString('developer-overheid-oss', $description);
		$this->assertStringContainsString('generic-apikey', $description);
		$this->assertStringContainsString('openconnector', $description);

	}//end testTheDescriptionGivesTheRecipe()

	/**
	 * The catalogue files the source under Government registers, disabled.
	 *
	 * @return void
	 */
	public function testTheCatalogueListsItUnderGovernmentRegisters(): void {
		$entry = $this->catalogue()['source-template:developer-overheid-oss'];

		$this->assertSame('Government registers', $entry['category']);
		$this->assertSame('developer-overheid-oss', $entry['sourceTemplateSlug']);
		$this->assertStringContainsString('developer-overheid-oss', $entry['description']);

	}//end testTheCatalogueListsItUnderGovernmentRegisters()

	/**
	 * The placeholder selects app-side injection, not the broker proxy.
	 *
	 * A top-level credentialRef would send the call to the broker proxy, which
	 * refuses an inject-only provider such as generic-apikey (design D1).
	 *
	 * @return void
	 */
	public function testThePlaceholderTakesTheInjectionPath(): void {
		$source = $this->register();
		$broker = $this->brokeredCallService(credentials: [], resolver: new class() {
		});

		$this->assertFalse($broker->hasCredentialRef(config: $source['configuration']));
		$this->assertTrue($broker->hasInjectableCredentials(sourceData: $source));

	}//end testThePlaceholderTakesTheInjectionPath()

	/**
	 * A call carries the key as X-Api-Key and its CallLog shows the header redacted.
	 *
	 * @return void
	 */
	public function testACallCarriesTheKeyAndLogsItRedacted(): void {
		$resolver = new class(self::KEY) {
			/**
			 * Recorded resolutions.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param string $secret The secret to hand out.
			 */
			public function __construct(
				private string $secret,
			) {
			}

			/**
			 * Mirror of CredentialBrokerService::resolveInjectable().
			 *
			 * @param string $credentialId The credential.
			 * @param string $appId The calling app.
			 * @param string|null $actingUserId The acting user.
			 *
			 * @return string|null The secret.
			 */
			public function resolveInjectable(string $credentialId, string $appId, ?string $actingUserId = null): ?string {
				$this->calls[] = [$credentialId, $appId, $actingUserId];
				return $this->secret;
			}
		};

		$service = $this->callService(
			brokered: $this->brokeredCallService(credentials: [$this->credential()], resolver: $resolver)
		);
		$service->call(source: $this->enabledSource(), endpoint: '/repositories?publiccode=true&perPage=100&page=1');

		$this->assertCount(1, $this->sent, 'Exactly one request goes out.');
		$this->assertSame(self::KEY, $this->sent[0]['headers']['X-Api-Key']);
		$this->assertSame('application/json', $this->sent[0]['headers']['Accept']);
		$this->assertArrayNotHasKey('authentication', $this->sent[0], 'The authentication block never reaches the client.');
		$this->assertSame([['11111111-1111-1111-1111-111111111111', 'openconnector', null]], $resolver->calls);

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertSame('***REDACTED***', $logs[0]['request']['headers']['X-Api-Key']);
		$this->assertStringNotContainsString(self::KEY, (string)json_encode($logs[0]));

	}//end testACallCarriesTheKeyAndLogsItRedacted()

	/**
	 * Without the credential no request is sent, and the CallLog names the credential.
	 *
	 * @return void
	 */
	public function testWithoutTheCredentialNoRequestIsSent(): void {
		$service = $this->callService(
			brokered: $this->brokeredCallService(credentials: [], resolver: new class() {
			})
		);
		$service->call(source: $this->enabledSource(), endpoint: '/repositories?publiccode=true&perPage=100&page=1');

		$this->assertSame([], $this->sent, 'No request may reach the register.');

		$logs = $this->callLogs();
		$this->assertCount(1, $logs);
		$this->assertSame(409, $logs[0]['statusCode']);
		$this->assertStringContainsString('developer-overheid-oss', $logs[0]['statusMessage']);

	}//end testWithoutTheCredentialNoRequestIsSent()

	/**
	 * The gitlab.com raw source is enabled, asks for plain text and needs no credential.
	 *
	 * @return void
	 */
	public function testTheGitlabRawSourceNeedsNoCredential(): void {
		$sources = $this->sources('gitlab-source.json');
		$this->assertSame(['gitlab-raw'], array_keys($sources));

		$raw = $sources['gitlab-raw'];
		$this->assertSame('https://gitlab.com', $raw['location']);
		$this->assertTrue($raw['isEnabled']);
		$this->assertSame('none', $raw['auth']);
		$this->assertSame(['headers' => ['Accept' => 'text/plain']], $raw['configuration']);
		foreach (self::SECRET_FIELDS as $field) {
			$this->assertArrayNotHasKey($field, $raw, sprintf('gitlab-raw carries a secret field "%s".', $field));
		}

	}//end testTheGitlabRawSourceNeedsNoCredential()

	/**
	 * The catalogue files gitlab-raw under Code hosting, next to the GitHub sources.
	 *
	 * @return void
	 */
	public function testTheCatalogueListsGitlabRawUnderCodeHosting(): void {
		$catalogue = $this->catalogue();

		$this->assertSame('Code hosting', $catalogue['source-template:gitlab-raw']['category']);
		$this->assertSame('Code hosting', $catalogue['source-template:github-raw']['category']);

	}//end testTheCatalogueListsGitlabRawUnderCodeHosting()

	/**
	 * The catalogue entries by slug, built from the real fragment directory.
	 *
	 * @return array<string, array<string, mixed>> The entries.
	 */
	private function catalogue(): array {
		$service = new CatalogRegistryService(
			new IntegrationRegistry(),
			$this->createMock(ObjectService::class),
			$this->createMock(IAppConfig::class),
			new NullLogger()
		);

		return array_column($service->collect(), null, 'slug');
	}//end catalogue()

	/**
	 * The seeded source, enabled, at a host that never resolves.
	 *
	 * @return ObjectEntity The source.
	 */
	private function enabledSource(): ObjectEntity {
		$data = $this->register();
		unset($data['@self']);
		$data['isEnabled'] = true;
		$data['location'] = 'https://api.example.invalid/oss-register/v1';

		$source = new ObjectEntity();
		$source->setUuid('22222222-2222-2222-2222-222222222222');
		$source->setObject($data);

		return $source;
	}//end enabledSource()

	/**
	 * A generic-apikey credential named developer-overheid-oss, owned by the session user.
	 *
	 * @return ObjectEntity The credential's metadata object.
	 */
	private function credential(): ObjectEntity {
		$credential = new ObjectEntity();
		$credential->setUuid('11111111-1111-1111-1111-111111111111');
		$credential->setOwner('alice');
		$credential->setObject(['name' => 'developer-overheid-oss', 'provider' => 'generic-apikey', 'allowedApps' => ['openconnector']]);

		return $credential;
	}//end credential()

	/**
	 * The real BrokeredCallService over a stubbed OpenRegister.
	 *
	 * @param array<int, ObjectEntity> $credentials The credentials a name lookup finds.
	 * @param object $resolver The broker double answering resolveInjectable().
	 *
	 * @return BrokeredCallService The service.
	 */
	private function brokeredCallService(array $credentials, object $resolver): BrokeredCallService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(['results' => $credentials, 'total' => count($credentials)]);
		$objects->method('find')->willReturn(($credentials[0] ?? null));

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new class($objects, $apps, $session, $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class), $this->createMock(ContainerInterface::class), $resolver) extends BrokeredCallService {
			/**
			 * Constructor.
			 *
			 * @param ObjectService $objectService The object service.
			 * @param IAppManager $appManager The app manager.
			 * @param IUserSession $userSession The session.
			 * @param IUserManager $userManager The user manager.
			 * @param LoggerInterface $logger The logger.
			 * @param ContainerInterface $container The container.
			 * @param object $resolver The broker double.
			 */
			public function __construct(
				ObjectService $objectService,
				IAppManager $appManager,
				IUserSession $userSession,
				IUserManager $userManager,
				LoggerInterface $logger,
				ContainerInterface $container,
				private object $resolver,
			) {
				parent::__construct($objectService, $appManager, $userSession, $userManager, $logger, $container);
			}

			/**
			 * The broker class is present.
			 *
			 * @return boolean
			 */
			protected function isBrokerClassAvailable(): bool {
				return true;
			}

			/**
			 * The broker double.
			 *
			 * @return object
			 */
			protected function resolveBroker(): object {
				return $this->resolver;
			}
		};
	}//end brokeredCallService()

	/**
	 * A CallService whose object service and HTTP client record what they get.
	 *
	 * @param BrokeredCallService $brokered The brokered-call service.
	 *
	 * @return CallService The service.
	 */
	private function callService(BrokeredCallService $brokered): CallService {
		$this->saved = [];
		$this->sent = [];

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null) {
				$this->saved[] = ['object' => $object, 'schema' => $schema];
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->saved));
				$entity->setObject(is_array($object) === true ? $object : []);

				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = new CallService(
			$objects,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) {
				$this->sent[] = $options;
				return new Response(200, ['Content-Type' => 'application/json'], '[]');
			}
		);
		$property = new ReflectionProperty(CallService::class, 'client');
		$property->setAccessible(true);
		$property->setValue($service, $client);

		return $service;
	}//end callService()

	/**
	 * The CallLogs the engine saved.
	 *
	 * @return array<int, array<string, mixed>> The CallLog objects.
	 */
	private function callLogs(): array {
		$logs = [];
		foreach ($this->saved as $row) {
			if ($row['schema'] === 'call_log') {
				$logs[] = $row['object'];
			}
		}

		return $logs;
	}//end callLogs()
}//end class
