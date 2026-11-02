<?php

/**
 * Contract of the SynchronizationContractService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Public operations of {@see SynchronizationContractService} that other components call.
 *
 * The method signatures repeat those of SynchronizationContractService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface SynchronizationContractServiceInterface {
	/**
	 * Find a single contract object by id/uuid.
	 *
	 * @param string|int $id The OpenRegister id/uuid of the contract.
	 *
	 * @return ObjectEntity|null The OR contract object or null when not found.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-the-contract-is-persisted-before-the-after-rules-run-req-021
	 */
	public function findObject(string|int $id): ?ObjectEntity;

	/**
	 * Find all contract objects matching the supplied filters.
	 *
	 * @param array $filters Additional `filters` payload (register+schema injected).
	 *
	 * @return array<ObjectEntity> The matching contract objects.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationContractService::findAllObjects()
	 */
	public function findAllObjects(array $filters = []): array;

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
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationContractService::find()
	 */
	public function find(string|int $id): array;

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
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationContractService::findBySyncAndOrigin()
	 */
	public function findBySyncAndOrigin(
		string $synchronizationId,
		string $originId,
		?bool $justByOriginId = false,
	): ?array;

	/**
	 * Find a contract by origin id (single match).
	 *
	 * Replaces the legacy `SynchronizationContractMapper::findByOriginId()`.
	 *
	 * @param string $originId The origin id.
	 *
	 * @return array|null The contract payload array or null when not found.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationContractService::findByOriginId()
	 */
	public function findByOriginId(string $originId): ?array;

	/**
	 * Find the targetId for a contract addressed by originId.
	 *
	 * Replaces the legacy
	 * `SynchronizationContractMapper::findTargetIdByOriginId()`.
	 *
	 * @param string $originId The origin id.
	 *
	 * @return string|null The target id when present, otherwise null.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationContractService::findTargetIdByOriginId()
	 */
	public function findTargetIdByOriginId(string $originId): ?string;

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
	public function contractIdentity(array $object): ?string;

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
	public function persist(array $contract, bool $ensureUuid = false): array;

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
	public function persistBulk(array $contracts): array;

	/**
	 * Persist a contract from array data, auto-filling uuid + version.
	 *
	 * Replaces the legacy `SynchronizationContractMapper::createFromArray()`.
	 *
	 * @param array $object Array of contract data.
	 *
	 * @return array The persisted contract payload array.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationContractService::createFromArray()
	 */
	public function createFromArray(array $object): array;

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
	public function updateFromArray(string|int $id, array $object): array;
}
