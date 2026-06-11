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
 */
class SynchronizationContractService
{

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
        private readonly OrObjectService $orObjectService
    ) {

    }//end __construct()


    /**
     * Find a single contract object by id/uuid.
     *
     * @param string|int $id The OpenRegister id/uuid of the contract.
     *
     * @return ObjectEntity|null The OR contract object or null when not found.
     */
    public function findObject(string|int $id): ?ObjectEntity
    {
        return $this->orObjectService->find(
            id: (string) $id,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

    }//end findObject()


    /**
     * Find all contract objects matching the supplied filters.
     *
     * @param array $filters Additional `filters` payload (register+schema injected).
     *
     * @return array<ObjectEntity> The matching contract objects.
     */
    public function findAllObjects(array $filters=[]): array
    {
        $config  = [
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
    public function find(string|int $id): array
    {
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
     * @param string    $synchronizationId The synchronization id.
     * @param string    $originId          The origin id.
     * @param bool|null $justByOriginId    When true, match on origin id only.
     *
     * @return array|null The contract payload array or null when not found.
     */
    public function findBySyncAndOrigin(
        string $synchronizationId,
        string $originId,
        ?bool $justByOriginId=false
    ): ?array {
        if ($justByOriginId === true) {
            $filters = ['originId' => $originId];
        } else {
            $filters = [
                'synchronizationId' => $synchronizationId,
                'originId'          => $originId,
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
    public function findByOriginId(string $originId): ?array
    {
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
    public function findTargetIdByOriginId(string $originId): ?string
    {
        $contract = $this->findByOriginId(originId: $originId);
        if ($contract === null) {
            return null;
        }

        $targetId = ($contract['targetId'] ?? null);
        if ($targetId === null || $targetId === '') {
            return null;
        }

        return (string) $targetId;

    }//end findTargetIdByOriginId()


    /**
     * Persist a contract payload array to OpenRegister.
     *
     * Mirrors the previous SynchronizationContractMapper::persist() semantics:
     * keyed on `uuid` for upsert, dropping the legacy int `id` so OpenRegister's
     * upsert probe does not get confused.
     *
     * @param array $contract   The contract payload array to persist.
     * @param bool  $ensureUuid When true, auto-assign a uuid if absent.
     *
     * @return array The persisted contract payload array.
     */
    public function persist(array $contract, bool $ensureUuid=false): array
    {
        $object = $contract;

        if ($ensureUuid === true && empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        $uuid = ($object['uuid'] ?? null);

        // OpenRegister owns object identity (it keys on the `uuid` parameter);
        // the payload's legacy int `id` is not an OpenRegister identifier and
        // would break OR's `trim($object['id'])` upsert probe, so drop it.
        unset($object['id']);

        $saved = $this->orObjectService->saveObject(
            object: $object,
            register: self::REGISTER,
            schema: self::SCHEMA,
            uuid: ($uuid !== null && $uuid !== '' ? (string) $uuid : null)
        );

        return $saved->jsonSerialize();

    }//end persist()


    /**
     * Persist a contract from array data, auto-filling uuid + version.
     *
     * Replaces the legacy `SynchronizationContractMapper::createFromArray()`.
     *
     * @param array $object Array of contract data.
     *
     * @return array The persisted contract payload array.
     */
    public function createFromArray(array $object): array
    {
        if (empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        if (empty($object['version']) === true) {
            $object['version'] = '0.0.1';
        }

        unset($object['id']);

        $uuid  = $object['uuid'];
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
     * @param string|int $id     The contract id/uuid.
     * @param array      $object Array of updated contract data.
     *
     * @return array The persisted contract payload array.
     *
     * @throws DoesNotExistException When the contract does not exist.
     */
    public function updateFromArray(string|int $id, array $object): array
    {
        $existing = $this->find(id: $id);

        $existingVersion = ($existing['version'] ?? null);
        if (empty($existingVersion) === true) {
            $object['version'] = '0.0.1';
        } else if (empty($object['version']) === true) {
            $version = explode('.', (string) $existingVersion);
            if (isset($version[2]) === true) {
                $version[2]        = ((int) $version[2] + 1);
                $object['version'] = implode('.', $version);
            }
        }

        $merged = array_merge($existing, $object);
        unset($merged['id']);

        $uuid  = ($merged['uuid'] ?? null);
        $saved = $this->orObjectService->saveObject(
            object: $merged,
            register: self::REGISTER,
            schema: self::SCHEMA,
            uuid: ($uuid !== null && $uuid !== '' ? (string) $uuid : null)
        );

        return $saved->jsonSerialize();

    }//end updateFromArray()


}//end class
