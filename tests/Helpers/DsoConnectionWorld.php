<?php

/**
 * A small OpenRegister world for the DSO intake identity tests.
 *
 * It holds the `dso-stam` consumers, the Nextcloud accounts and their rights
 * on `dso_verzoek`, and an ObjectService whose `runAs()` is OpenRegister's own
 * (copied into the stub) over a real active-user slot. `saveObject()` refuses
 * the way OpenRegister does: no active user, or an active user without the
 * right for that write, throws. Every accepted write records the uid that was
 * active when it ran, so a test asserts WHO wrote, not only that a write
 * happened.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\Integriq\Service\Dso\DsoAccountRights;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Service\Intake\IntakeGroups;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

/**
 * Fixture trait for TestCases that exercise the DSO identity path.
 */
trait DsoConnectionWorld {

	/**
	 * Consumers by uuid, as raw data.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected array $worldConsumers = [];

	/**
	 * Accounts by uid: whether each is enabled.
	 *
	 * @var array<string, bool>
	 */
	protected array $worldAccounts = [];

	/**
	 * Rights on dso_verzoek by uid.
	 *
	 * @var array<string, list<string>>
	 */
	protected array $worldGrants = [];

	/**
	 * Whether OpenRegister's PermissionHandler resolves.
	 *
	 * @var bool
	 */
	protected bool $worldHasPermissionHandler = true;

	/**
	 * Stored dso_verzoek objects by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	protected array $worldVerzoeken = [];

	/**
	 * Stored openformulieren_submission objects by uuid, with the uid that created them.
	 *
	 * @var array<string, array{entity: ObjectEntity, owner: string|null}>
	 */
	protected array $worldSubmissions = [];

	/**
	 * Nextcloud group members: group id => list of uids.
	 *
	 * @var array<string, list<string>>
	 */
	protected array $worldGroupMembers = [];

	/**
	 * What membership of a group grants on the intake schemas: group id => actions.
	 *
	 * @var array<string, list<string>>
	 */
	protected array $worldGroupGrants = [];

	/**
	 * When true, every write to a submission is refused as OpenRegister would.
	 *
	 * @var bool
	 */
	protected bool $worldRefuseSubmissionWrites = false;

	/**
	 * Other stored objects (sources, form mappings) by uuid, with their schema
	 * and whether only an engine read sees them.
	 *
	 * @var array<string, array{schema: string, entity: ObjectEntity, adminOnly: bool}>
	 */
	protected array $worldOthers = [];

	/**
	 * Every accepted write: schema, action, the uid active at that moment.
	 *
	 * @var list<array{schema: string, action: string, uid: string|null, object: array<string, mixed>}>
	 */
	protected array $worldWrites = [];

	/**
	 * Every read: schema and whether it skipped RBAC.
	 *
	 * @var list<array{schema: string|null, rbac: bool, uid: string|null}>
	 */
	protected array $worldReads = [];

	/**
	 * The active-user slot runAs() swaps.
	 *
	 * @var ActiveUserSession
	 */
	protected ActiveUserSession $worldSession;

	/**
	 * Counter for generated uuids.
	 *
	 * @var int
	 */
	private int $worldUuidCounter = 0;

	/**
	 * The HMAC secret the default consumer trusts.
	 *
	 * @var string
	 */
	protected string $worldSecret = 'dso-test-secret';

	/**
	 * Reset the world. Call from setUp().
	 *
	 * @return void
	 */
	protected function resetWorld(): void {
		$this->worldConsumers = [];
		$this->worldAccounts = [];
		$this->worldGrants = [];
		$this->worldHasPermissionHandler = true;
		$this->worldVerzoeken = [];
		$this->worldSubmissions = [];
		$this->worldGroupMembers = [];
		$this->worldGroupGrants = [];
		$this->worldRefuseSubmissionWrites = false;
		$this->worldOthers = [];
		$this->worldWrites = [];
		$this->worldReads = [];
		$this->worldSession = new ActiveUserSession();
		$this->worldUuidCounter = 0;
	}//end resetWorld()

	/**
	 * Add an account with rights on dso_verzoek.
	 *
	 * @param string       $uid     The uid.
	 * @param list<string> $grants  The actions it holds.
	 * @param bool         $enabled Whether it is enabled.
	 *
	 * @return void
	 */
	protected function addAccount(string $uid, array $grants = ['create', 'read', 'update'], bool $enabled = true): void {
		$this->worldAccounts[$uid] = $enabled;
		$this->worldGrants[$uid] = $grants;
	}//end addAccount()

	/**
	 * Add a dso-stam consumer in HMAC mode.
	 *
	 * @param string $userId The account it acts as.
	 * @param string $uuid   The consumer uuid.
	 *
	 * @return void
	 */
	protected function addDsoConsumer(string $userId, string $uuid = 'consumer-dso'): void {
		$this->worldConsumers[$uuid] = [
			'name' => 'DSO-LV (STAM)',
			'authorizationType' => 'dso-stam',
			'authorizationConfiguration' => ['mode' => 'hmac', 'hmacSecret' => $this->worldSecret],
			'userId' => $userId,
		];
	}//end addDsoConsumer()

	/**
	 * Store an object outside dso_verzoek, for example the DSO source.
	 *
	 * @param string               $schema    The schema slug.
	 * @param string               $uuid      The uuid.
	 * @param array<string, mixed> $data      The object data.
	 * @param bool                 $adminOnly True when only an engine read sees it (sources).
	 *
	 * @return void
	 */
	protected function addOther(string $schema, string $uuid, array $data, bool $adminOnly = true): void {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		$this->worldOthers[$uuid] = ['schema' => $schema, 'entity' => $entity, 'adminOnly' => $adminOnly];
	}//end addOther()

	/**
	 * Add an `open-formulieren` consumer with the world's secret.
	 *
	 * @param string $userId The account it acts as.
	 * @param string $uuid   The consumer uuid.
	 *
	 * @return void
	 */
	protected function addOpenFormulierenConsumer(string $userId, string $uuid = 'consumer-of'): void {
		$this->worldConsumers[$uuid] = [
			'name' => 'Open Formulieren',
			'authorizationType' => 'open-formulieren',
			'authorizationConfiguration' => [
				'scheme' => 'openconnector',
				'secret' => $this->worldSecret,
				'header' => 'X-OpenFormulieren-Signature',
				'toleranceSeconds' => 300,
			],
			'userId' => $userId,
		];
	}//end addOpenFormulierenConsumer()

	/**
	 * Sign a body the way Open Formulieren does (timestamped HMAC).
	 *
	 * @param string $body The raw body.
	 *
	 * @return string The X-OpenFormulieren-Signature header value.
	 */
	protected function signOpenFormulieren(string $body): string {
		$timestamp = time();

		return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->worldSecret);
	}//end signOpenFormulieren()

	/**
	 * Sign a body the way DSO-LV does in pre-production.
	 *
	 * @param string $body The raw body.
	 *
	 * @return string The X-DSO-Signature header value.
	 */
	protected function signBody(string $body): string {
		return 'sha256=' . hash_hmac('sha256', $body, $this->worldSecret);
	}//end signBody()

	/**
	 * An IUser double for a uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The user.
	 */
	protected function worldUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn(($this->worldAccounts[$uid] ?? false));

		return $user;
	}//end worldUser()

	/**
	 * The uids of the writes, in order.
	 *
	 * @return list<string|null>
	 */
	protected function writerUids(): array {
		return array_map(static fn (array $write): ?string => $write['uid'], $this->worldWrites);
	}//end writerUids()

	/**
	 * An ObjectService with OpenRegister's own runAs() over the world's session.
	 *
	 * @return ORObjectService&MockObject
	 */
	protected function buildWorldObjectService(): ORObjectService {
		// The callbacks follow the parameter order of the ObjectService stub in
		// tests/stubs (find: id, register, schema, _rbac; saveObject: object,
		// register, schema, uuid, _rbac). The code under test calls them by name.
		$service = $this->getMockBuilder(ORObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findAll', 'saveObject'])
			->getMock();

		$property = new ReflectionProperty(ORObjectService::class, 'userSession');
		$property->setValue($service, $this->worldSession);

		$service->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$schema = ($config['filters']['schema'] ?? null);
				$this->worldReads[] = ['schema' => $schema, 'rbac' => $_rbac, 'uid' => $this->worldSession->getUser()?->getUID()];

				return ['results' => $this->worldFind(schema: $schema, filters: (array)($config['filters'] ?? []), rbac: $_rbac)];
			}
		);

		$service->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null, bool $_rbac = true): ?ObjectEntity {
				$this->worldReads[] = ['schema' => $schema, 'rbac' => $_rbac, 'uid' => $this->worldSession->getUser()?->getUID()];
				foreach ($this->worldFind(schema: $schema, filters: [], rbac: $_rbac) as $entity) {
					if ($entity->getUuid() === (string)$id) {
						return $entity;
					}
				}

				return null;
			}
		);

		$service->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntity {
				return $this->worldSave(object: (array)$object, schema: (string)$schema, uuid: $uuid, rbac: $_rbac);
			}
		);

		return $service;
	}//end buildWorldObjectService()

	/**
	 * A real DsoConnection over the world.
	 *
	 * @param ORObjectService $objectService The world's ObjectService.
	 *
	 * @return DsoConnection The connection.
	 */
	protected function buildWorldConnection(ORObjectService $objectService): DsoConnection {
		$logger = new NullLogger();

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => (array_key_exists($uid, $this->worldAccounts) === true ? $this->worldUser($uid) : null)
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn((object)['slug' => 'dso_verzoek']);

		$world = $this;
		$handler = new class($world) {
			/**
			 * Constructor.
			 *
			 * @param object $world The test case.
			 */
			public function __construct(private readonly object $world) {
			}

			/**
			 * OpenRegister's call shape: hasPermission(schema:, action:, userId:).
			 *
			 * @param object      $schema The schema.
			 * @param string      $action The action.
			 * @param string|null $userId The uid.
			 *
			 * @return bool
			 */
			public function hasPermission(object $schema, string $action, ?string $userId = null): bool {
				return $this->world->worldGrants($userId, $action);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($handler): object {
				if ($this->worldHasPermissionHandler === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $handler;
			}
		);

		return new DsoConnection(
			objectService: $objectService,
			signatureVerifier: new DSOSignatureVerifierService(
				webhookSignatureService: new WebhookSignatureService($logger),
				logger: $logger
			),
			userManager: $userManager,
			rights: new DsoAccountRights(schemaMapper: $schemaMapper, container: $container, logger: $logger),
			logger: $logger
		);
	}//end buildWorldConnection()

	/**
	 * The real IntakeGroups over the world's groups.
	 *
	 * @return IntakeGroups The groups service.
	 */
	protected function buildWorldIntakeGroups(): IntakeGroups {
		$groupOf = function (string $groupId): IGroup {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($groupId);
			$group->method('count')->willReturnCallback(
				fn (): int => count($this->worldGroupMembers[$groupId] ?? [])
			);
			$group->method('inGroup')->willReturnCallback(
				fn (IUser $user): bool => in_array($user->getUID(), ($this->worldGroupMembers[$groupId] ?? []), true)
			);
			$group->method('addUser')->willReturnCallback(
				function (IUser $user) use ($groupId): void {
					$this->worldGroupMembers[$groupId][] = $user->getUID();
				}
			);
			$group->method('removeUser')->willReturnCallback(
				function (IUser $user) use ($groupId): void {
					$this->worldGroupMembers[$groupId] = array_values(
						array_diff(($this->worldGroupMembers[$groupId] ?? []), [$user->getUID()])
					);
				}
			);

			return $group;
		};

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			fn (string $groupId): ?IGroup => (array_key_exists($groupId, $this->worldGroupMembers) === true ? $groupOf($groupId) : null)
		);
		$groupManager->method('createGroup')->willReturnCallback(
			function (string $groupId) use ($groupOf): IGroup {
				$this->worldGroupMembers[$groupId] = ($this->worldGroupMembers[$groupId] ?? []);
				return $groupOf($groupId);
			}
		);
		$groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $groupId): bool => in_array($uid, ($this->worldGroupMembers[$groupId] ?? []), true)
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => (array_key_exists($uid, $this->worldAccounts) === true ? $this->worldUser($uid) : null)
		);

		return new IntakeGroups(groupManager: $groupManager, userManager: $userManager, logger: new NullLogger());
	}//end buildWorldIntakeGroups()

	/**
	 * The real Open Formulieren connection over the world.
	 *
	 * @param ORObjectService $objectService The world's ObjectService.
	 *
	 * @return OpenFormulierenConnection The connection.
	 */
	protected function buildWorldOpenFormulierenConnection(ORObjectService $objectService): OpenFormulierenConnection {
		$logger = new NullLogger();
		$dso = $this->buildWorldConnection(objectService: $objectService);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => (array_key_exists($uid, $this->worldAccounts) === true ? $this->worldUser($uid) : null)
		);

		$property = new ReflectionProperty(DsoConnection::class, 'rights');

		return new OpenFormulierenConnection(
			consumers: $dso,
			objectService: $objectService,
			signatureService: new WebhookSignatureService($logger),
			userManager: $userManager,
			rights: $property->getValue($dso)
		);
	}//end buildWorldOpenFormulierenConnection()

	/**
	 * Whether a uid holds an action on dso_verzoek. Public for the handler double.
	 *
	 * @param string|null $uid    The uid.
	 * @param string      $action The action.
	 *
	 * @return bool
	 */
	public function worldGrants(?string $uid, string $action): bool {
		if ($uid === null) {
			return false;
		}

		if (in_array($action, ($this->worldGrants[$uid] ?? []), true) === true) {
			return true;
		}

		foreach ($this->worldGroupGrants as $groupId => $actions) {
			if (in_array($uid, ($this->worldGroupMembers[$groupId] ?? []), true) === true && in_array($action, $actions, true) === true) {
				return true;
			}
		}

		return false;
	}//end worldGrants()

	/**
	 * Find objects of a schema, honouring RBAC for the active user.
	 *
	 * @param mixed                $schema  The schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 * @param bool                 $rbac    Whether RBAC applies.
	 *
	 * @return list<ObjectEntity>
	 */
	private function worldFind(mixed $schema, array $filters, bool $rbac): array {
		$uid = $this->worldSession->getUser()?->getUID();
		$results = [];

		if ($schema === 'consumer') {
			// Consumers are admin-only: only an engine read sees them.
			if ($rbac === true) {
				return [];
			}

			foreach ($this->worldConsumers as $uuid => $data) {
				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($data);
				$results[] = $entity;
			}

			return $results;
		}

		if ($schema === 'dso_verzoek') {
			if ($rbac === true && $this->worldGrants($uid, 'read') === false) {
				return [];
			}

			foreach ($this->worldVerzoeken as $entity) {
				$data = $entity->getObject();
				$match = true;
				foreach ($filters as $key => $value) {
					if (in_array($key, ['register', 'schema'], true) === true) {
						continue;
					}

					if (($data[$key] ?? null) !== $value) {
						$match = false;
					}
				}

				if ($match === true) {
					$results[] = $entity;
				}
			}

			return $results;
		}

		if ($schema === 'openformulieren_submission') {
			foreach ($this->worldSubmissions as $stored) {
				// OpenRegister admits the owner, and a reader through its groups.
				if ($rbac === true && $stored['owner'] !== $uid && $this->worldGrants($uid, 'read') === false) {
					continue;
				}

				$data = $stored['entity']->getObject();
				$match = true;
				foreach ($filters as $key => $value) {
					if (in_array($key, ['register', 'schema'], true) === false && ($data[$key] ?? null) !== $value) {
						$match = false;
					}
				}

				if ($match === true) {
					$results[] = $stored['entity'];
				}
			}

			return $results;
		}

		foreach ($this->worldOthers as $other) {
			if ($other['schema'] !== $schema) {
				continue;
			}

			// Sources are admin-only too.
			if ($rbac === true && ($other['adminOnly'] ?? true) === true) {
				continue;
			}

			$data = $other['entity']->getObject();
			$match = true;
			foreach ($filters as $key => $value) {
				if (in_array($key, ['register', 'schema'], true) === true) {
					continue;
				}

				if (($data[$key] ?? null) !== $value) {
					$match = false;
				}
			}

			if ($match === true) {
				$results[] = $other['entity'];
			}
		}

		return $results;
	}//end worldFind()

	/**
	 * Save an object the way OpenRegister decides: refuse without the right.
	 *
	 * @param array<string, mixed> $object The data.
	 * @param string               $schema The schema slug.
	 * @param string|null          $uuid   The uuid on update.
	 * @param bool                 $rbac   Whether RBAC applies.
	 *
	 * @return ObjectEntity The saved entity.
	 */
	private function worldSave(array $object, string $schema, ?string $uuid, bool $rbac): ObjectEntity {
		$uid = $this->worldSession->getUser()?->getUID();
		$action = 'create';
		if ($uuid !== null && (isset($this->worldVerzoeken[$uuid]) === true || isset($this->worldConsumers[$uuid]) === true || isset($this->worldSubmissions[$uuid]) === true)) {
			$action = 'update';
		}

		$system = \OCA\OpenRegister\Service\SystemOperationContext::isActive();
		if ($schema === 'openformulieren_submission' && $this->worldRefuseSubmissionWrites === true) {
			throw new RuntimeException("User '" . ($uid ?? 'Anonymous') . "' does not have permission to '" . $action . "' objects in schema '" . $schema . "'");
		}

		if ($rbac === true && $system === false && $this->worldGrants($uid, $action) === false) {
			throw new RuntimeException(
				"User '" . ($uid ?? 'Anonymous') . "' does not have permission to '" . $action . "' objects in schema '" . $schema . "'"
			);
		}

		$resolved = ($uuid ?? $schema . '-' . (++$this->worldUuidCounter));
		$entity = new ObjectEntity();
		$entity->setUuid($resolved);
		$entity->setObject($object);

		if ($schema === 'dso_verzoek') {
			$this->worldVerzoeken[$resolved] = $entity;
		}

		if ($schema === 'consumer') {
			$this->worldConsumers[$resolved] = $object;
		}

		if ($schema === 'openformulieren_submission') {
			$this->worldSubmissions[$resolved] = [
				'entity' => $entity,
				'owner' => ($this->worldSubmissions[$resolved]['owner'] ?? $uid),
			];
		}

		$this->worldWrites[] = ['schema' => $schema, 'action' => $action, 'uid' => $uid, 'object' => $object, 'rbac' => $rbac, 'system' => $system];

		return $entity;
	}//end worldSave()
}//end trait
