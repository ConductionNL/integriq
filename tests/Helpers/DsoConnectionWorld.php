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

use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
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
	 * Other stored objects (sources) by uuid, with their schema.
	 *
	 * @var array<string, array{schema: string, entity: ObjectEntity}>
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
	 * @param string               $schema The schema slug.
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return void
	 */
	protected function addOther(string $schema, string $uuid, array $data): void {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		$this->worldOthers[$uuid] = ['schema' => $schema, 'entity' => $entity];
	}//end addOther()

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
			schemaMapper: $schemaMapper,
			container: $container,
			logger: $logger
		);
	}//end buildWorldConnection()

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

		return in_array($action, ($this->worldGrants[$uid] ?? []), true);
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

		foreach ($this->worldOthers as $other) {
			if ($other['schema'] !== $schema) {
				continue;
			}

			// Sources are admin-only too.
			if ($rbac === true) {
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
		if ($uuid !== null && isset($this->worldVerzoeken[$uuid]) === true) {
			$action = 'update';
		}

		if ($rbac === true && $this->worldGrants($uid, $action) === false) {
			throw new RuntimeException(
				"User '" . ($uid ?? 'Anonymous') . "' does not have permission to '" . $action . "' objects in schema '" . $schema . "'"
			);
		}

		$resolved = ($uuid ?? 'verzoek-' . (++$this->worldUuidCounter));
		$entity = new ObjectEntity();
		$entity->setUuid($resolved);
		$entity->setObject($object);

		if ($schema === 'dso_verzoek') {
			$this->worldVerzoeken[$resolved] = $entity;
		}

		$this->worldWrites[] = ['schema' => $schema, 'action' => $action, 'uid' => $uid, 'object' => $object, 'rbac' => $rbac];

		return $entity;
	}//end worldSave()
}//end trait
