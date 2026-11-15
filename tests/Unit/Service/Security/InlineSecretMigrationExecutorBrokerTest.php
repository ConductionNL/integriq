<?php

/**
 * Tests for how InlineSecretMigrationExecutor obtains and vets the credential
 * broker from the container, and for mint results it cannot use.
 *
 * Uses the real executor (no seam overrides): the broker comes from the
 * container exactly as in production, so the container lookup, its caching
 * and the capability checks are what is under test.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\InlineSecretMigrationExecutor;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCA\Integriq\Service\Security\InlineSecretSourceRun;
use OCA\Integriq\Tests\Helpers\MigrationSimulatingObjectService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A broker that mints and resolves organisation-scoped credentials, recording calls.
 */
class ContainerBrokerDouble {

	/**
	 * The uuid the next mint returns ('' for a credential without one).
	 *
	 * @var string
	 */
	public string $mintUuid = 'cred-1';

	/**
	 * Every mint call's secret.
	 *
	 * @var array<int,?string>
	 */
	public array $mintedSecrets = [];

	/**
	 * Every resolve call's credential id.
	 *
	 * @var array<int,string>
	 */
	public array $resolvedIds = [];

	/**
	 * Mint a credential.
	 *
	 * @param string      $name         The name.
	 * @param string      $provider     The provider.
	 * @param string      $owner        The owner.
	 * @param array       $allowedApps  The allowed apps.
	 * @param string|null $secret       The secret.
	 * @param string      $scope        The scope.
	 * @param string|null $organisation The organisation.
	 *
	 * @return ObjectEntity
	 */
	public function mint(
		string $name,
		string $provider,
		string $owner,
		array $allowedApps = [],
		?string $secret = null,
		string $scope = 'personal',
		?string $organisation = null,
	): ObjectEntity {
		$this->mintedSecrets[] = $secret;
		$entity = new ObjectEntity();
		$entity->setUuid($this->mintUuid);

		return $entity;

	}//end mint()

	/**
	 * Resolve a credential: the last minted secret.
	 *
	 * @param string      $credentialId         The credential id.
	 * @param string      $appId                The app id.
	 * @param string|null $actingUserId         The acting user.
	 * @param string|null $actingOrganisationId The acting organisation.
	 *
	 * @return string|null
	 */
	public function resolveInjectable(
		string $credentialId,
		string $appId,
		?string $actingUserId = null,
		?string $actingOrganisationId = null,
	): ?string {
		$this->resolvedIds[] = $credentialId;

		return (end($this->mintedSecrets) ?: null);

	}//end resolveInjectable()
}//end class

/**
 * A broker that can mint but has no resolveInjectable() at all.
 */
class ResolvelessBrokerDouble {

	/**
	 * Mint a credential.
	 *
	 * @param string $name The name.
	 *
	 * @return ObjectEntity
	 */
	public function mint(string $name): ObjectEntity {
		return new ObjectEntity();

	}//end mint()
}//end class

/**
 * Broker lookup through the container, and unusable mint results.
 */
#[CoversClass(InlineSecretMigrationExecutor::class)]
#[UsesClass(InlineSecretMigrationPlanner::class)]
#[UsesClass(InlineSecretSourceRun::class)]
class InlineSecretMigrationExecutorBrokerTest extends TestCase {

	/**
	 * A live-looking inline secret.
	 */
	private const SECRET = 'inline-apikey-value-for-test';

	/**
	 * The source's organisation.
	 */
	private const ORG = 'org-uuid-1234';

	/**
	 * @var MigrationSimulatingObjectService
	 */
	private MigrationSimulatingObjectService $objectService;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private $container;

	/**
	 * @var InlineSecretMigrationExecutor
	 */
	private InlineSecretMigrationExecutor $executor;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = new MigrationSimulatingObjectService();
		$this->container = $this->createMock(ContainerInterface::class);
		$logger = new NullLogger();

		$this->executor = new InlineSecretMigrationExecutor(
			$this->objectService,
			new InlineSecretMigrationPlanner($this->objectService, $logger),
			$logger,
			$this->container
		);
		$this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET], 'alice', self::ORG);

	}//end setUp()

	/**
	 * The raw stored value of a field on src-1.
	 *
	 * @param string $field The field.
	 *
	 * @return mixed
	 */
	private function rawField(string $field): mixed {
		$entity = $this->objectService->find(id: 'src-1', register: 'integriq', schema: 'source', _render: false);

		return ($entity?->getObject()[$field] ?? null);

	}//end rawField()

	/**
	 * The broker is fetched from the container once and reused across runs.
	 *
	 * @return void
	 */
	public function testTheBrokerIsResolvedFromTheContainerOnce(): void {
		$broker = new ContainerBrokerDouble();
		$this->container->expects($this->once())->method('get')
			->with(InlineSecretMigrationExecutor::BROKER_CLASS)
			->willReturn($broker);

		$first = $this->executor->migrateAll();
		$second = $this->executor->migrateAll();

		$this->assertSame(1, $first['migrated']);
		$this->assertSame(0, $second['migrated']);
		$this->assertSame([self::SECRET], $broker->mintedSecrets);
		$this->assertSame(['cred-1'], $broker->resolvedIds);
		$this->assertNull($this->rawField('apikey'));

	}//end testTheBrokerIsResolvedFromTheContainerOnce()

	/**
	 * A container that cannot build the broker refuses the run and rewrites nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvableBrokerRefusesTheRun(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no such service'));

		try {
			$this->executor->migrateAll();
			$this->fail('The run must be refused.');
		} catch (RuntimeException $exception) {
			$this->assertSame(
				'The OpenRegister credential broker could not be resolved: no such service. Nothing was rewritten.',
				$exception->getMessage()
			);
		}

		$this->assertSame(self::SECRET, $this->rawField('apikey'));
		$this->assertSame([], $this->objectService->saves);

	}//end testAnUnresolvableBrokerRefusesTheRun()

	/**
	 * A broker that can mint but has no resolveInjectable() is refused before any mint.
	 *
	 * @return void
	 */
	public function testABrokerWithoutResolveInjectableIsRefused(): void {
		$this->container->method('get')->willReturn(new ResolvelessBrokerDouble());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/cannot resolve organisation-scoped credentials sessionlessly.*Nothing was rewritten\.$/s');

		try {
			$this->executor->migrateAll();
		} finally {
			$this->assertSame(self::SECRET, $this->rawField('apikey'));
			$this->assertSame([], $this->objectService->saves);
		}

	}//end testABrokerWithoutResolveInjectableIsRefused()

	/**
	 * A minted credential without a uuid fails the field, unverified and unwritten.
	 *
	 * @return void
	 */
	public function testAMintWithoutAUuidFailsTheField(): void {
		$broker = new ContainerBrokerDouble();
		$broker->mintUuid = '';
		$this->container->method('get')->willReturn($broker);

		$result = $this->executor->migrateAll();

		$this->assertSame(1, $result['failed']);
		$this->assertSame(0, $result['migrated']);
		$this->assertSame('mint-no-uuid', $result['sources'][0]['fields'][0]['reason']);
		$this->assertSame('failed', $result['sources'][0]['fields'][0]['outcome']);
		$this->assertSame([], $broker->resolvedIds);
		$this->assertSame(self::SECRET, $this->rawField('apikey'));
		$this->assertSame([], $this->objectService->saves);

	}//end testAMintWithoutAUuidFailsTheField()

	/**
	 * A non-object configuration is replaced by one holding only the credential reference.
	 *
	 * @return void
	 */
	public function testANonObjectConfigurationIsReplacedByTheReference(): void {
		$this->objectService->seed('src-1', ['name' => 'S', 'apikey' => self::SECRET, 'configuration' => 'legacy-string'], 'alice', self::ORG);
		$this->container->method('get')->willReturn(new ContainerBrokerDouble());

		$result = $this->executor->migrateAll();

		$this->assertSame(1, $result['migrated']);
		$this->assertSame(
			['authentication' => ['apikey' => ['credentialRef' => ['credentialId' => 'cred-1']]]],
			$this->rawField('configuration')
		);
		$this->assertNull($this->rawField('apikey'));

	}//end testANonObjectConfigurationIsReplacedByTheReference()

	/**
	 * A non-object authentication bag is replaced, keeping the rest of the configuration.
	 *
	 * @return void
	 */
	public function testANonObjectAuthenticationBagIsReplaced(): void {
		$this->objectService->seed(
			'src-1',
			['name' => 'S', 'apikey' => self::SECRET, 'configuration' => ['timeout' => 30, 'authentication' => 'x']],
			'alice',
			self::ORG
		);
		$this->container->method('get')->willReturn(new ContainerBrokerDouble());

		$this->executor->migrateAll();

		$this->assertSame(
			['timeout' => 30, 'authentication' => ['apikey' => ['credentialRef' => ['credentialId' => 'cred-1']]]],
			$this->rawField('configuration')
		);

	}//end testANonObjectAuthenticationBagIsReplaced()
}//end class
