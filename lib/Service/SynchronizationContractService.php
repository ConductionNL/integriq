<?php

/**
 * OpenConnector Synchronization Contract Service.
 *
 * Encapsulates the read/write lifecycle of synchronization contracts so the
 * SynchronizationService engine does not have to interleave OpenRegister
 * persistence concerns with sync-orchestration logic. Extracted from
 * SynchronizationService in W14 Tier 2.
 *
 * All operations target the OpenRegister `synchronization_contract` schema in
 * register `openconnector`. Contracts are addressed by their OpenRegister
 * uuid; the legacy int `id` is stripped on every write because OpenRegister
 * owns object identity (it keys on the `uuid` parameter).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Uid\Uuid;

/**
 * Read/write lifecycle for synchronization contracts.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) 11 against a threshold of 10, and the
 *   eleventh is not a design smell: this class is the contract's CRUD surface, so its
 *   public methods are one-per-lifecycle-operation rather than a grab bag. Splitting it
 *   to satisfy the count would put half the lifecycle behind a second collaborator that
 *   every caller of the first also needs, which is a worse shape than the number.
 */
class SynchronizationContractService {

	/**
	 * The OpenRegister register the contract lives in.
	 *
	 * @var string
	 */
	private const REGISTER = 'openconnector';

	/**
	 * The OpenRegister schema for contract objects.
	 *
	 * @var string
	 */
	private const SCHEMA = 'synchronization_contract';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService The OpenRegister object service.
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
	) {

	}//end __construct()

	/**
	 * Find a single contract object by id/uuid.
	 *
	 * @param string|int $id The OpenRegister id/uuid of the contract.
	 *
	 * @return ObjectEntity|null The OR contract object or null when not found.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-the-contract-is-persisted-before-the-after-rules-run-req-021
	 */
	public function findObject(string|int $id): ?ObjectEntity {
		// _audit: false — a synchronization contract is the engine's own
		// bookkeeping, and reading it is machine-to-machine inside one operation,
		// not a person opening a record. Auditing these reads put 2.8 MILLION
		// `read` rows into this instance's audit table, 91% of everything in it,
		// and every one of those inserts maintained nine indexes.
		return $this->orObjectService->find(
			id: (string)$id,
			register: self::REGISTER,
			schema: self::SCHEMA,
			_audit: false
		);

	}//end findObject()

	/**
	 * Find all contract objects matching the supplied filters.
	 *
	 * @param array $filters Additional `filters` payload (register+schema injected).
	 *
	 * @return array<ObjectEntity> The matching contract objects.
	 */
	public function findAllObjects(array $filters = []): array {
		$config = [
			'filters' => array_merge(
				['register' => self::REGISTER, 'schema' => self::SCHEMA],
				$filters
			),
		];
		$matches = $this->orObjectService->findAll(config: $config);

		return array_values(($matches['results'] ?? $matches));
	}//end findAllObjects()

	/**
	 * Find a single contract by id/uuid and return its payload array.
	 *
	 * Replaces the legacy `SynchronizationContractMapper::find($id)`.
	 *
	 * @param string|int $id The OpenRegister id/uuid of the contract.
	 *
	 * @return array The contract payload array.
	 *
	 * @throws DoesNotExistException When no contract matches the id.
	 */
	public function find(string|int $id): array {
		$object = $this->findObject(id: $id);
		if ($object === null) {
			throw new DoesNotExistException(
				'The synchronization contract you are looking for does not exist'
			);
		}

		return $object->jsonSerialize();
	}//end find()

	/**
	 * Find a contract by synchronizationId + originId (or just originId).
	 *
	 * Replaces the legacy
	 * `SynchronizationContractMapper::findSyncContractByOriginId()`.
	 *
	 * @param string $synchronizationId The synchronization id.
	 * @param string $originId The origin id.
	 * @param bool|null $justByOriginId When true, match on origin id only.
	 *
	 * @return array|null The contract payload array or null when not found.
	 */
	public function findBySyncAndOrigin(
		string $synchronizationId,
		string $originId,
		?bool $justByOriginId = false,
	): ?array {
		if ($justByOriginId === true) {
			$filters = ['originId' => $originId];
		} else {
			$filters = [
				'synchronizationId' => $synchronizationId,
				'originId' => $originId,
			];
		}

		$matches = $this->findAllObjects(filters: $filters);
		if (empty($matches) === true) {
			return null;
		}

		return $matches[0]->jsonSerialize();
	}//end findBySyncAndOrigin()

	/**
	 * Find a contract by origin id (single match).
	 *
	 * Replaces the legacy `SynchronizationContractMapper::findByOriginId()`.
	 *
	 * @param string $originId The origin id.
	 *
	 * @return array|null The contract payload array or null when not found.
	 */
	public function findByOriginId(string $originId): ?array {
		$matches = $this->findAllObjects(filters: ['originId' => $originId]);
		if (empty($matches) === true) {
			return null;
		}

		return $matches[0]->jsonSerialize();
	}//end findByOriginId()

	/**
	 * Find the targetId for a contract addressed by originId.
	 *
	 * Replaces the legacy
	 * `SynchronizationContractMapper::findTargetIdByOriginId()`.
	 *
	 * @param string $originId The origin id.
	 *
	 * @return string|null The target id when present, otherwise null.
	 */
	public function findTargetIdByOriginId(string $originId): ?string {
		$contract = $this->findByOriginId(originId: $originId);
		if ($contract === null) {
			return null;
		}

		$targetId = ($contract['targetId'] ?? null);
		if ($targetId === null || $targetId === '') {
			return null;
		}

		return (string)$targetId;
	}//end findTargetIdByOriginId()

	/**
	 * The OpenRegister identifier to upsert a contract on.
	 *
	 * WHY THIS EXISTS. `persist()` used to read `$object['uuid']` alone and then
	 * drop `$object['id']`, on the reasoning that `id` was a legacy *integer* and
	 * not an OpenRegister identifier. A contract payload carries no `uuid`
	 * property at all — its identity comes back from OpenRegister AS `id`, and
	 * that `id` is a uuid string. So the upsert key was always null and every
	 * save CREATED: four distinct contract objects for one
	 * (synchronizationId, originId), one per run, each carrying an identical
	 * `originHash`. `synchronization_contract` grew without bound — 528,656 rows
	 * on the dev instance.
	 *
	 * A numeric `id` is still refused, which is what the original comment was
	 * actually protecting against.
	 *
	 * @param array $object The contract payload.
	 *
	 * @return string|null The uuid to upsert on, or null to create.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	public function contractIdentity(array $object): ?string {
		$uuid = ($object['uuid'] ?? null);
		if ($uuid !== null && (string)$uuid !== '') {
			return (string)$uuid;
		}

		$id = ($object['id'] ?? null);
		if ($id === null || is_numeric($id) === true) {
			return null;
		}

		$id = (string)$id;
		if ($id === '') {
			return null;
		}

		return $id;
	}//end contractIdentity()

	/**
	 * Persist a contract payload array to OpenRegister.
	 *
	 * Keyed for upsert on the contract's own identity — see
	 * {@see self::contractIdentity()} for why reading `uuid` alone minted a new
	 * contract on every run.
	 *
	 * @param array $contract The contract payload array to persist.
	 * @param bool $ensureUuid When true, auto-assign a uuid if absent.
	 *
	 * @return array The persisted contract payload array.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-the-contract-is-persisted-before-the-after-rules-run-req-021
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	public function persist(array $contract, bool $ensureUuid = false): array {
		$object = $contract;

		// Read the identity BEFORE dropping `id`: a contract loaded back from
		// OpenRegister carries no `uuid` property at all — its identity comes
		// back AS `id`, and that `id` is a uuid string.
		$uuidParam = $this->contractIdentity(object: $object);

		// `ensureUuid` mints an identity for a contract that HAS none. It used to
		// test `empty($object['uuid'])`, which is true for every contract ever
		// loaded from OpenRegister — they carry `id`, never `uuid` — so it minted
		// a fresh uuid on top of a perfectly good identity and the save created
		// instead of updating. Both persists at the end of synchronizeContract
		// pass `ensureUuid: true`, so that was EVERY update: measured live, a run
		// that skipped 1903 of 2000 and updated 97 still added exactly 97 rows.
		if ($ensureUuid === true && $uuidParam === null) {
			$uuidParam = (string)Uuid::v4();
			$object['uuid'] = $uuidParam;
		}

		// A legacy int `id` is not an OpenRegister identifier and would break OR's
		// `trim($object['id'])` upsert probe, so drop it either way.
		unset($object['id']);

		// Wrapped in SystemOperationContext because `silent` is NOT enough on its
		// own: it gates the audit row and inverse-relation work inside SaveObject,
		// while the ObjectCreated/Updated dispatch lives a layer lower in
		// MagicMapper, which checks this context instead. With `silent` set but
		// no context, mm:EVENT-DISPATCH was still the single largest remaining
		// cost of a 374-record sync — 6,866ms — spent dispatching events for rows
		// nothing subscribes to.
		$saved = \OCA\OpenRegister\Service\SystemOperationContext::run(
			fn (): mixed => $this->orObjectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuidParam,
			// A contract is the engine's own bookkeeping: its shape is generated
			// here, not authored, and nothing subscribes to its lifecycle. So it
			// pays for neither.
			//
			// `silent` drops the audit row and the lifecycle event. Auditing
			// contract WRITES is the same argument already applied to contract
			// READS, which were 91% of this instance's audit table — a mapping
			// row the engine wrote for itself is not the thing an audit trail
			// exists to record.
			//
			// `_validation: false` skips re-validating that shape against the
			// contract schema on every record. Unlike a target object, whose
			// content comes from an external source and is exactly what
			// validation is for, this payload never leaves the engine's hands.
			silent: true,
			_validation: false
			)
		);

		return $saved->jsonSerialize();
	}//end persist()

	/**
	 * Persist a batch of contract payloads in one bulk write.
	 *
	 * Same semantics as {@see persist()} — uuid-keyed upsert, no audit row, no
	 * lifecycle event, no re-validation — applied to a whole page of contracts
	 * at once. A synchronization writes exactly one contract per source record,
	 * so on a large run this is the single most repeated write in the engine:
	 * one round trip each, all of them identical in shape.
	 *
	 * OpenRegister's bulk path takes each row's identity from `id`, where the
	 * single-object path takes it from the `uuid` parameter. The legacy int `id`
	 * a contract payload may carry is not an OpenRegister identifier, so it is
	 * overwritten with the uuid rather than merely dropped.
	 *
	 * @param array<int, array> $contracts The contract payload arrays to persist.
	 *
	 * @return array The OpenRegister bulk-save result, or an empty array for an empty batch.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-the-contract-is-persisted-before-the-after-rules-run-req-021
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	public function persistBulk(array $contracts): array {
		$rows = [];
		foreach ($contracts as $contract) {
			$object = $contract;

			// Same defect the single-write path carried, and this is the path the
			// engine actually takes for a buffered target write: it tested
			// `empty($object['uuid'])`, true for EVERY contract loaded back from
			// OpenRegister — they carry `id`, never `uuid` — so it minted a fresh
			// uuid, overwrote `id` with it, and the batch created a duplicate
			// instead of updating.
			//
			// Measured live after the single-write path was fixed: a run that
			// skipped 1903 of 2000 and updated 97 still added exactly 97 contract
			// rows, twice in a row. The rows it added carried no `uuid` and no
			// `version`, which is what ruled out both createFromArray() and an
			// ensureUuid mint and pointed here.
			$identity = $this->contractIdentity(object: $object);
			if ($identity === null) {
				$identity = (string)Uuid::v4();
			}

			$object['uuid'] = $identity;
			$object['id'] = $identity;
			$rows[] = $object;
		}

		if ($rows === []) {
			return [];
		}

		return \OCA\OpenRegister\Service\SystemOperationContext::run(
			fn (): array => $this->orObjectService->saveObjects(
				objects: $rows,
				register: self::REGISTER,
				schema: self::SCHEMA,
				validation: false,
				events: false,
				_audit: false
			)
		);
	}//end persistBulk()

	/**
	 * Persist a contract from array data, auto-filling uuid + version.
	 *
	 * Replaces the legacy `SynchronizationContractMapper::createFromArray()`.
	 *
	 * @param array $object Array of contract data.
	 *
	 * @return array The persisted contract payload array.
	 */
	public function createFromArray(array $object): array {
		if (empty($object['uuid']) === true) {
			$object['uuid'] = (string)Uuid::v4();
		}

		if (empty($object['version']) === true) {
			$object['version'] = '0.0.1';
		}

		unset($object['id']);

		$uuid = $object['uuid'];
		$saved = $this->orObjectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid
		);

		return $saved->jsonSerialize();
	}//end createFromArray()

	/**
	 * Update an existing contract from array data, bumping the patch version.
	 *
	 * Replaces the legacy `SynchronizationContractMapper::updateFromArray()`.
	 *
	 * @param string|int $id The contract id/uuid.
	 * @param array $object Array of updated contract data.
	 *
	 * @return array The persisted contract payload array.
	 *
	 * @throws DoesNotExistException When the contract does not exist.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	public function updateFromArray(string|int $id, array $object): array {
		$existing = $this->find(id: $id);

		$existingVersion = ($existing['version'] ?? null);
		if (empty($existingVersion) === true) {
			$object['version'] = '0.0.1';
		} elseif (empty($object['version']) === true) {
			$version = explode('.', (string)$existingVersion);
			if (isset($version[2]) === true) {
				$version[2] = ((int)$version[2] + 1);
				$object['version'] = implode('.', $version);
			}
		}

		$merged = array_merge($existing, $object);

		// Identity BEFORE dropping `id` — see contractIdentity().
		$uuidParam = $this->contractIdentity(object: $merged);
		unset($merged['id']);

		$saved = $this->orObjectService->saveObject(
			object: $merged,
			register: self::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuidParam
		);

		return $saved->jsonSerialize();
	}//end updateFromArray()
}//end class
