<?php

/**
 * The "Synced from" leaf held against OpenRegister's real provider contract.
 *
 * The OpenRegister classes under tests/stubs are copies of openregister
 * development (IntegrationProvider, AbstractIntegrationProvider,
 * NotImplementedException), so a method the interface gains and the provider
 * lacks fails here instead of on a live sidebar.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/or-integration-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Integration;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\Integration\SynchronizationContractProvider;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * REQ-OCIP-001..005 on the shipped provider.
 */
class SynchronizationContractProviderContractTest extends TestCase {

	/**
	 * Build the provider with the storage flag set as given.
	 *
	 * @param string $migrated The `storage_migrated` app value.
	 * @param mixed  $objectService The OR object service double.
	 *
	 * @return SynchronizationContractProvider
	 */
	private function provider(string $migrated, $objectService = null): SynchronizationContractProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')
			->willReturnCallback(static fn (string $key, string $default = '') => ($key === 'storage_migrated' ? $migrated : $default));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []) => vsprintf($text, $parameters)
		);

		return new SynchronizationContractProvider(($objectService ?? ObjectServiceMockBuilder::make($this)), $appConfig, $l10n);

	}//end provider()

	/**
	 * An OR object service double whose findAll() records its config.
	 *
	 * @param array $rows What findAll() answers.
	 * @param array $seen By-ref: register, schema and config handed over.
	 *
	 * @return mixed
	 */
	private function recordingObjectService(array $rows, array &$seen) {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('setRegister')->willReturnCallback(
			function ($register) use ($objectService, &$seen) {
				$seen['register'] = $register;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnCallback(
			function ($schema) use ($objectService, &$seen) {
				$seen['schema'] = $schema;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($rows, &$seen) {
				$seen['config'] = $config;
				return ['results' => $rows, 'total' => count($rows)];
			}
		);

		return $objectService;

	}//end recordingObjectService()

	/**
	 * REQ-OCIP-001: the provider fulfils every method OpenRegister's interface declares.
	 *
	 * @return void
	 */
	public function testTheProviderFulfilsOpenRegistersWholeContract(): void {
		$provider = $this->provider('true');
		$this->assertInstanceOf(IntegrationProvider::class, $provider);

		$class = new ReflectionClass($provider);
		foreach ((new ReflectionClass(IntegrationProvider::class))->getMethods() as $method) {
			$this->assertFalse(
				$class->getMethod($method->getName())->isAbstract(),
				'IntegrationProvider::' . $method->getName() . '() has no implementation'
			);
		}

	}//end testTheProviderFulfilsOpenRegistersWholeContract()

	/**
	 * REQ-OCIP-001 and 002: the declared metadata and the inherited read-only posture.
	 *
	 * @return void
	 */
	public function testTheLeafDeclaresItsMetadataAndAddsNoPermissionOfItsOwn(): void {
		$provider = $this->provider('true');

		$this->assertSame('sync-contract', $provider->getId());
		$this->assertSame('Synced from', $provider->getLabel());
		$this->assertSame('SyncOutline', $provider->getIcon());
		$this->assertSame('workflow', $provider->getGroup());
		$this->assertSame('integriq', $provider->getRequiredApp());
		$this->assertSame('query-time', $provider->getStorageStrategy());
		$this->assertNull($provider->getOpenConnectorSource());
		$this->assertNull($provider->requiresPermission());
		$this->assertTrue($provider->isEnabled());

	}//end testTheLeafDeclaresItsMetadataAndAddsNoPermissionOfItsOwn()

	/**
	 * The four verbs a user could call on a read-only leaf.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function mutationVerbs(): array {
		return [
			'get' => [static fn (SynchronizationContractProvider $p) => $p->get('r', 's', 'o', 'e')],
			'create' => [static fn (SynchronizationContractProvider $p) => $p->create('r', 's', 'o', [])],
			'update' => [static fn (SynchronizationContractProvider $p) => $p->update('r', 's', 'o', 'e', [])],
			'delete' => [static fn (SynchronizationContractProvider $p) => $p->delete('r', 's', 'o', 'e')],
		];

	}//end mutationVerbs()

	/**
	 * REQ-OCIP-002: every mutation verb is refused and nothing is written.
	 *
	 * @param callable $verb The verb to call.
	 *
	 * @return void
	 *
	 * @dataProvider mutationVerbs
	 */
	public function testEveryMutationVerbIsRefused(callable $verb): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->never())->method('saveObject');

		$this->expectException(NotImplementedException::class);
		$verb($this->provider('true', $objectService));

	}//end testEveryMutationVerbIsRefused()

	/**
	 * REQ-OCIP-003: register and schema go through the context setters and
	 * the filters carry the target id only.
	 *
	 * @return void
	 */
	public function testTheQueryFiltersOnTheTargetIdOnly(): void {
		$seen = [];
		$contract = ObjectServiceMockBuilder::objectEntity(
			$this,
			['synchronizationId' => 'a1b2c3d4-0000-4000-8000-000000000001', 'targetLastSynced' => '2026-09-27T10:00:00+00:00'],
			'contract-1'
		);
		$provider = $this->provider('true', $this->recordingObjectService([$contract], $seen));

		$rows = $provider->list('some-register', 'some-schema', 'object-7');

		$this->assertSame('integriq', $seen['register']);
		$this->assertSame('synchronization_contract', $seen['schema']);
		$this->assertSame(['targetId' => 'object-7'], $seen['config']['filters']);
		$this->assertSame(50, $seen['config']['limit']);
		$this->assertSame(0, $seen['config']['offset']);
		$this->assertCount(1, $rows);
		$this->assertSame('contract-1', $rows[0]['id']);
		$this->assertSame('/index.php/apps/integriq/synchronizations/a1b2c3d4-0000-4000-8000-000000000001', $rows[0]['url']);

	}//end testTheQueryFiltersOnTheTargetIdOnly()

	/**
	 * REQ-OCIP-003: a page asked with its own size starts right after the
	 * previous page of that size, not after 50 rows per earlier page.
	 *
	 * @return void
	 */
	public function testAPageOfItsOwnSizeStartsAfterThePreviousPage(): void {
		$seen = [];
		$provider = $this->provider('true', $this->recordingObjectService([], $seen));

		$provider->list('r', 's', 'object-7', ['_limit' => 10, '_page' => 3]);

		$this->assertSame(10, $seen['config']['limit']);
		$this->assertSame(20, $seen['config']['offset']);

	}//end testAPageOfItsOwnSizeStartsAfterThePreviousPage()

	/**
	 * REQ-OCIP-003: an object no synchronization wrote gets an empty list.
	 *
	 * @return void
	 */
	public function testAnObjectNoSynchronizationWroteGetsAnEmptyList(): void {
		$seen = [];
		$provider = $this->provider('true', $this->recordingObjectService([], $seen));

		$this->assertSame([], $provider->list('r', 's', 'object-8'));

	}//end testAnObjectNoSynchronizationWroteGetsAnEmptyList()

	/**
	 * REQ-OCIP-004: before the storage migration the leaf is empty and health says unavailable.
	 *
	 * @return void
	 */
	public function testBeforeTheStorageMigrationTheLeafStaysEmpty(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->never())->method('findAll');
		$provider = $this->provider('false', $objectService);

		$this->assertFalse($provider->isEnabled());
		$this->assertSame([], $provider->list('r', 's', 'object-7'));
		$health = $provider->health();
		$this->assertSame('unavailable', $health['status']);
		$this->assertNotEmpty($health['message']);

	}//end testBeforeTheStorageMigrationTheLeafStaysEmpty()

	/**
	 * Every text the leaf shows a user has a Dutch translation.
	 *
	 * @return void
	 */
	public function testEveryTextTheLeafShowsIsTranslatedInDutch(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Service/Integration/SynchronizationContractProvider.php');
		preg_match_all("/->t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*[,)]/", $source, $matches);
		$texts = array_map('stripslashes', $matches[1]);
		$this->assertCount(6, $texts, 'every ->t() call takes one literal the extractor can read');

		$dutch = json_decode((string)file_get_contents(__DIR__ . '/../../../../l10n/nl.json'), true)['translations'];
		foreach ($texts as $text) {
			$this->assertArrayHasKey($text, $dutch, 'nl.json lacks: ' . $text);
			$this->assertNotSame($text, $dutch[$text], 'nl.json keeps English for: ' . $text);
		}

	}//end testEveryTextTheLeafShowsIsTranslatedInDutch()

	/**
	 * REQ-OCIP-005: when OpenRegister cannot hand over its registry, boot
	 * logs a warning and carries on.
	 *
	 * @return void
	 */
	public function testBootCarriesOnWhenTheRegistryCannotBeResolved(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($logger) {
				if ($id === IntegrationRegistry::class) {
					throw new \RuntimeException('OpenRegister predates the registry');
				}
				return $logger;
			}
		);
		$context = $this->createMock(IBootContext::class);
		$context->method('getServerContainer')->willReturn($container);

		$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$register = new \ReflectionMethod(Application::class, 'registerIntegrationProviders');
		$register->invoke($application, $context);

	}//end testBootCarriesOnWhenTheRegistryCannotBeResolved()

}//end class
