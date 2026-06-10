<?php
/**
 * OpenConnector SynchronizationContract mapper (OpenRegister-backed adapter).
 *
 * Post OpenRegister-cutover the `openconnector_synchronization_contracts` table
 * was dropped. This mapper is no longer a QBMapper: it is a thin adapter over
 * `\OCA\OpenRegister\Service\ObjectService` (register `openconnector`, schema
 * `synchronization_contract`). It keeps the original public surface so the
 * existing call sites in the engine keep working, but every read/write now flows
 * through the OpenRegister object API and returns hydrated `SynchronizationContract`
 * value objects.
 *
 * KNOWN CONSTRAINT: OpenRegister's object search applies an in-request visibility
 * limit (a just-written object may not be returned by `findAll` within the same
 * request). The engine therefore mostly addresses contracts by their canonical
 * uuid (find) immediately after a write, and only relies on the filtered finders
 * for previously-committed contracts. This limit is being addressed separately in
 * OpenRegister.
 *
 * @category Db
 * @package  OCA\OpenConnector\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Db;

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Uid\Uuid;

/**
 * OpenRegister-backed adapter for synchronization contract objects.
 *
 * @package OCA\OpenConnector\Db
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
class SynchronizationContractMapper
{

    /**
     * The OpenRegister register synchronization contracts live in.
     *
     * @var string
     */
    private const REGISTER = 'openconnector';

    /**
     * The OpenRegister schema for synchronization contract objects.
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
        private readonly OrObjectService $orObjectService
    ) {

    }//end __construct()

    /**
     * Find a synchronization contract by id/uuid.
     *
     * @param int|string $id The id/uuid of the contract to find.
     *
     * @return SynchronizationContract The found contract value object.
     *
     * @throws DoesNotExistException When the contract is not found.
     */
    public function find(int | string $id): SynchronizationContract
    {
        $object = $this->orObjectService->find(
            id: (string) $id,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        if ($object === null) {
            throw new DoesNotExistException('The synchronization contract you are looking for does not exist');
        }

        return $this->toContract($object->jsonSerialize());

    }//end find()

    /**
     * Find a synchronization contract by synchronization ID and origin ID.
     *
     * @param string    $synchronizationId The synchronization ID.
     * @param string    $originId          The origin ID.
     * @param bool|null $justByOriginId    When true, match on origin ID only.
     *
     * @return SynchronizationContract|null The found contract or null if not found.
     */
    public function findSyncContractByOriginId(string $synchronizationId, string $originId, ?bool $justByOriginId=false): ?SynchronizationContract
    {
        if ($justByOriginId === true) {
            $filters = ['originId' => $originId];
        } else {
            $filters = ['synchronizationId' => $synchronizationId, 'originId' => $originId];
        }

        $matches = $this->searchObjects(filters: $filters, limit: 1);
        if (empty($matches) === true) {
            return null;
        }

        return $this->toContract($matches[0]->jsonSerialize());

    }//end findSyncContractByOriginId()

    /**
     * Find the target_id for a given origin_id.
     *
     * @param string $originId The origin ID to search for.
     *
     * @return string|null The target_id if found, or null if not found.
     */
    public function findTargetIdByOriginId(string $originId): ?string
    {
        $matches = $this->searchObjects(filters: ['originId' => $originId], limit: 1);
        if (empty($matches) === true) {
            return null;
        }

        $targetId = $this->toContract($matches[0]->jsonSerialize())->getTargetId();

        return ($targetId !== null && $targetId !== '') ? $targetId : null;

    }//end findTargetIdByOriginId()

    /**
     * Find a synchronization contract by synchronization ID and target ID.
     *
     * @param string $synchronization The synchronization ID.
     * @param string $targetId        The target ID.
     *
     * @return SynchronizationContract|bool|null The found contract, false, or null if not found.
     */
    public function findOnTarget(string $synchronization, string $targetId): SynchronizationContract | bool | null
    {
        $matches = $this->searchObjects(
            filters: ['synchronizationId' => $synchronization, 'targetId' => $targetId],
            limit: 1
        );
        if (empty($matches) === true) {
            return null;
        }

        return $this->toContract($matches[0]->jsonSerialize());

    }//end findOnTarget()

    /**
     * Find a synchronization contract by origin ID and target ID.
     *
     * @param string $originId The origin ID.
     * @param string $targetId The target ID.
     *
     * @return SynchronizationContract|bool|null The found contract, false, or null if not found.
     */
    public function findByOriginAndTarget(string $originId, string $targetId): SynchronizationContract | bool | null
    {
        $matches = $this->searchObjects(
            filters: ['originId' => $originId, 'targetId' => $targetId],
            limit: 1
        );
        if (empty($matches) === true) {
            return null;
        }

        return $this->toContract($matches[0]->jsonSerialize());

    }//end findByOriginAndTarget()

    /**
     * Find all contracts by synchronization ID whose target object has the given schema.
     *
     * The legacy mapper joined the contracts table to `openregister_objects` to
     * filter by the target object's schema. With OpenRegister-stored contracts we
     * fetch the contracts by synchronization ID and then keep those whose target
     * object resolves to the requested schema.
     *
     * @param string $synchronizationId The synchronization ID.
     * @param string $schemaId          The schema ID the target object must carry.
     *
     * @return array<SynchronizationContract> The matching contracts.
     */
    public function findAllBySynchronizationAndSchema(string $synchronizationId, string $schemaId): array
    {
        $matches   = $this->searchObjects(filters: ['synchronizationId' => $synchronizationId]);
        $contracts = [];

        foreach ($matches as $object) {
            $contract = $this->toContract($object->jsonSerialize());
            $targetId = $contract->getTargetId();
            if ($targetId === null || $targetId === '') {
                continue;
            }

            // Resolve the target object to compare its schema. Misses (deleted
            // targets) are skipped rather than dropped from the cleanup set.
            try {
                $target = $this->orObjectService->find(id: $targetId);
            } catch (\Throwable $exception) {
                $target = null;
            }

            if ($target === null) {
                continue;
            }

            $targetSchema = $target->getSchema();
            if ((string) $targetSchema === (string) $schemaId) {
                $contracts[] = $contract;
            }
        }

        return $contracts;

    }//end findAllBySynchronizationAndSchema()

    /**
     * Find all synchronization contracts with optional filtering and pagination.
     *
     * @param int|null   $limit            Maximum number of results to return.
     * @param int|null   $offset           Number of results to skip.
     * @param array|null $filters          Associative array of field => value filters.
     * @param array|null $searchConditions Unused (kept for signature compatibility).
     * @param array|null $searchParams     Unused (kept for signature compatibility).
     *
     * @return array<SynchronizationContract> Array of found contracts.
     */
    public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[], ?array $searchConditions=[], ?array $searchParams=[]): array
    {
        $objects = $this->searchObjects(filters: ($filters ?? []), limit: $limit, offset: $offset);

        return array_map(
            fn ($object): SynchronizationContract => $this->toContract($object->jsonSerialize()),
            $objects
        );

    }//end findAll()

    /**
     * Create a new synchronization contract from array data.
     *
     * @param array $object Array of contract data.
     *
     * @return SynchronizationContract The created contract value object.
     */
    public function createFromArray(array $object): SynchronizationContract
    {
        if (empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        if (empty($object['version']) === true) {
            $object['version'] = '0.0.1';
        }

        return $this->persist($object);

    }//end createFromArray()

    /**
     * Update an existing synchronization contract from array data.
     *
     * @param int|string $id     ID/uuid of the contract to update.
     * @param array      $object Array of updated contract data.
     *
     * @return SynchronizationContract The updated contract value object.
     *
     * @throws DoesNotExistException When the contract does not exist.
     */
    public function updateFromArray(int | string $id, array $object): SynchronizationContract
    {
        $existing = $this->find($id);

        if (empty($existing->getVersion()) === true) {
            $object['version'] = '0.0.1';
        } else if (empty($object['version']) === true) {
            $version = explode('.', $existing->getVersion());
            if (isset($version[2]) === true) {
                $version[2]        = ((int) $version[2] + 1);
                $object['version'] = implode('.', $version);
            }
        }

        $merged = array_merge($existing->jsonSerialize(), $object);

        return $this->persist($merged);

    }//end updateFromArray()

    /**
     * Persist a contract value object (INSERT).
     *
     * Mirrors the retired QBMapper::insert() semantics so existing call sites that
     * hand in a typed contract keep working.
     *
     * @param SynchronizationContract $entity The contract to insert.
     *
     * @return SynchronizationContract The persisted contract value object.
     */
    public function insert(SynchronizationContract $entity): SynchronizationContract
    {
        $object = $entity->jsonSerialize();
        if (empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        return $this->persist($object);

    }//end insert()

    /**
     * Persist a contract value object (UPDATE/UPSERT).
     *
     * Mirrors the retired QBMapper::update() semantics.
     *
     * @param SynchronizationContract $entity The contract to update.
     *
     * @return SynchronizationContract The persisted contract value object.
     */
    public function update(SynchronizationContract $entity): SynchronizationContract
    {
        return $this->persist($entity->jsonSerialize());

    }//end update()

    /**
     * Persist a contract value object (INSERT or UPDATE).
     *
     * Mirrors the retired QBMapper::insertOrUpdate() semantics.
     *
     * @param SynchronizationContract $entity The contract to persist.
     *
     * @return SynchronizationContract The persisted contract value object.
     */
    public function insertOrUpdate(SynchronizationContract $entity): SynchronizationContract
    {
        $object = $entity->jsonSerialize();
        if (empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        return $this->persist($object);

    }//end insertOrUpdate()

    /**
     * Delete a synchronization contract.
     *
     * @param SynchronizationContract $entity The contract to delete.
     *
     * @return SynchronizationContract The deleted contract value object.
     */
    public function delete(SynchronizationContract $entity): SynchronizationContract
    {
        $uuid = ($entity->getUuid() ?? (string) $entity->getId());
        if ($uuid !== null && $uuid !== '') {
            $this->orObjectService->deleteObject(
                uuid: (string) $uuid,
                register: self::REGISTER,
                schema: self::SCHEMA
            );
        }

        return $entity;

    }//end delete()

    /**
     * Find a synchronization contract by origin ID.
     *
     * @param string $originId The origin ID to search for.
     *
     * @return SynchronizationContract|null The matching contract or null if not found.
     */
    public function findByOriginId(string $originId): ?SynchronizationContract
    {
        $matches = $this->searchObjects(filters: ['originId' => $originId], limit: 1);
        if (empty($matches) === true) {
            return null;
        }

        return $this->toContract($matches[0]->jsonSerialize());

    }//end findByOriginId()

    /**
     * Find synchronization contracts by target ID.
     *
     * @param string $targetId The target ID to search for.
     *
     * @return SynchronizationContract[] The matching contracts.
     */
    public function findByTargetId(string $targetId): array
    {
        return $this->findAll(filters: ['targetId' => $targetId]);

    }//end findByTargetId()

    /**
     * Get total count of synchronization contracts.
     *
     * @return int Total number of contracts.
     */
    public function getTotalCallCount(): int
    {
        return count($this->searchObjects());

    }//end getTotalCallCount()

    /**
     * Get total count of synchronization contracts with optional filters.
     *
     * @param array $filters Optional filters to apply.
     *
     * @return int Total number of contracts matching filters.
     */
    public function getTotalCount(array $filters=[]): int
    {
        return count($this->searchObjects(filters: $filters));

    }//end getTotalCount()

    /**
     * Handle object removal by updating or removing associated contracts.
     *
     * @param string $objectIdentifier The ID of the removed object.
     *
     * @return array The contracts that were touched.
     */
    public function handleObjectRemoval(string $objectIdentifier): array
    {
        $byOrigin = $this->searchObjects(filters: ['originId' => $objectIdentifier]);
        $byTarget = $this->searchObjects(filters: ['targetId' => $objectIdentifier]);

        // De-duplicate by uuid (an object could in theory match both buckets).
        $objects = [];
        foreach (array_merge($byOrigin, $byTarget) as $object) {
            $objects[(string) $object->getUuid()] = $object;
        }

        $contracts = [];
        foreach ($objects as $object) {
            $contract  = $this->toContract($object->jsonSerialize());
            $contracts[] = $contract;

            if ($contract->getOriginId() === $objectIdentifier) {
                $contract->setOriginId(null);
                $contract->setOriginHash(null);
            }

            if ($contract->getTargetId() === $objectIdentifier) {
                $contract->setTargetId(null);
                $contract->setTargetHash(null);
            }

            // Delete the contract entirely when no associations remain.
            if ($contract->getOriginId() === null && $contract->getTargetId() === null) {
                $this->delete($contract);
                continue;
            }

            $this->update($contract);
        }

        return $contracts;

    }//end handleObjectRemoval()

    /**
     * Persist a contract array to OpenRegister, addressing it by uuid when present.
     *
     * @param array $object The contract payload.
     *
     * @return SynchronizationContract The persisted contract value object.
     */
    private function persist(array $object): SynchronizationContract
    {
        $uuid = ($object['uuid'] ?? null);

        // OpenRegister owns object identity (it keys on the `uuid` parameter); the
        // value object's legacy int `id` is not an OpenRegister identifier and
        // would break OR's `trim($object['id'])` upsert probe, so drop it.
        unset($object['id']);

        $saved = $this->orObjectService->saveObject(
            object: $object,
            register: self::REGISTER,
            schema: self::SCHEMA,
            uuid: ($uuid !== null && $uuid !== '' ? (string) $uuid : null)
        );

        return $this->toContract($saved->jsonSerialize());

    }//end persist()

    /**
     * Hydrate an OpenRegister object array into a contract value object.
     *
     * @param array $object The serialised OpenRegister object.
     *
     * @return SynchronizationContract The hydrated contract.
     */
    private function toContract(array $object): SynchronizationContract
    {
        return (new SynchronizationContract())->hydrate($object);

    }//end toContract()

    /**
     * Run an OpenRegister object search scoped to the contract register/schema.
     *
     * @param array    $filters Field filters keyed by contract property.
     * @param int|null $limit   Optional result limit.
     * @param int|null $offset  Optional result offset.
     *
     * @return array<\OCA\OpenRegister\Db\ObjectEntity> The matched OpenRegister objects.
     */
    private function searchObjects(array $filters=[], ?int $limit=null, ?int $offset=null): array
    {
        $config = [
            'filters' => array_merge(
                ['register' => self::REGISTER, 'schema' => self::SCHEMA],
                $filters
            ),
        ];

        if ($limit !== null) {
            $config['limit'] = $limit;
        }

        if ($offset !== null) {
            $config['offset'] = $offset;
        }

        $matches = $this->orObjectService->findAll(config: $config);

        return array_values(($matches['results'] ?? $matches));

    }//end searchObjects()
}//end class
