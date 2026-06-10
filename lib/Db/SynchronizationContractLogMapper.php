<?php
/**
 * OpenConnector SynchronizationContractLog mapper (OpenRegister-backed adapter).
 *
 * Post OpenRegister-cutover the `openconnector_synchronization_contract_logs`
 * table was dropped. This mapper is no longer a QBMapper: it is a thin adapter
 * over `\OCA\OpenRegister\Service\ObjectService` (register `openconnector`,
 * schema `synchronization_contract_log`).
 *
 * The OpenRegister `synchronization_contract_log` schema is APPEND-ONLY
 * (write-once): OR rejects any UPDATE/DELETE on it with an AppendOnlyException.
 * The engine builds a contract log in memory via createFromArray(), mutates it
 * during the run (setTarget / setTargetResult / setExpires / ...), and then
 * persists it exactly once via update(). To honour the append-only schema this
 * adapter therefore DEFERS the write: createFromArray() only builds the in-memory
 * value object (auto-filling system fields + a stable uuid), and the first
 * update()/insert() performs the single INSERT. Any subsequent update() for an
 * already-persisted log is a no-op.
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

use DateTime;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Session\Exceptions\SessionNotAvailableException;
use Symfony\Component\Uid\Uuid;

/**
 * OpenRegister-backed, append-only adapter for synchronization contract log objects.
 *
 * @package OCA\OpenConnector\Db
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SynchronizationContractLogMapper
{

    /**
     * The OpenRegister register contract logs live in.
     *
     * @var string
     */
    private const REGISTER = 'openconnector';

    /**
     * The OpenRegister schema for synchronization contract log objects.
     *
     * @var string
     */
    private const SCHEMA = 'synchronization_contract_log';

    /**
     * Tracks the uuids of contract logs already persisted in this request so a
     * repeated update() on an append-only log is a safe no-op.
     *
     * @var array<string,bool>
     */
    private array $persisted = [];

    /**
     * Constructor.
     *
     * @param OrObjectService $orObjectService The OpenRegister object service.
     * @param IUserSession    $userSession     The current user session.
     * @param ISession        $session         The PHP session.
     */
    public function __construct(
        private readonly OrObjectService $orObjectService,
        private readonly IUserSession $userSession,
        private readonly ISession $session
    ) {

    }//end __construct()

    /**
     * Find a contract log by id/uuid.
     *
     * @param int|string $id The id/uuid of the contract log to find.
     *
     * @return SynchronizationContractLog The found contract log value object.
     *
     * @throws DoesNotExistException When the contract log is not found.
     */
    public function find(int | string $id): SynchronizationContractLog
    {
        $object = $this->orObjectService->find(
            id: (string) $id,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        if ($object === null) {
            throw new DoesNotExistException('The synchronization contract log you are looking for does not exist');
        }

        return (new SynchronizationContractLog())->hydrate($object->jsonSerialize());

    }//end find()

    /**
     * Find a contract log by synchronization ID.
     *
     * @param string $synchronizationId The synchronization ID.
     *
     * @return SynchronizationContractLog|null The found contract log or null.
     */
    public function findOnSynchronizationId(string $synchronizationId): ?SynchronizationContractLog
    {
        $matches = $this->searchObjects(filters: ['synchronizationId' => $synchronizationId], limit: 1);
        if (empty($matches) === true) {
            return null;
        }

        return (new SynchronizationContractLog())->hydrate($matches[0]->jsonSerialize());

    }//end findOnSynchronizationId()

    /**
     * Find all contract logs with optional filtering and pagination.
     *
     * @param int|null   $limit            Maximum number of results to return.
     * @param int|null   $offset           Number of results to skip.
     * @param array|null $filters          Associative array of field => value filters.
     * @param array|null $searchConditions Unused (kept for signature compatibility).
     * @param array|null $searchParams     Unused (kept for signature compatibility).
     *
     * @return array<SynchronizationContractLog> Array of found contract logs.
     */
    public function findAll(?int $limit=null, ?int $offset=null, ?array $filters=[], ?array $searchConditions=[], ?array $searchParams=[]): array
    {
        $objects = $this->searchObjects(filters: ($filters ?? []), limit: $limit, offset: $offset);

        return array_map(
            fn ($object): SynchronizationContractLog => (new SynchronizationContractLog())->hydrate($object->jsonSerialize()),
            $objects
        );

    }//end findAll()

    /**
     * Build a new in-memory contract log handle (does NOT write yet).
     *
     * The append-only schema means the row must be written exactly once, in its
     * final state. The engine mutates the returned handle during the run and then
     * calls update()/insert() to perform the single INSERT.
     *
     * @param array $object The contract log data.
     *
     * @return SynchronizationContractLog The (unpersisted) contract log value object.
     */
    public function createFromArray(array $object): SynchronizationContractLog
    {
        // Auto-fill a stable uuid the engine can reference before persistence.
        if (empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        // Auto-fill userId from the current user session.
        if (empty($object['userId']) === true && $this->userSession->getUser() !== null) {
            $object['userId'] = $this->userSession->getUser()->getUID();
        }

        // Auto-fill sessionId from the current session (guarded for job context).
        if (isset($object['sessionId']) === false) {
            try {
                $object['sessionId'] = $this->session->getId();
            } catch (SessionNotAvailableException $exception) {
                $object['sessionId'] = null;
            }
        }

        // Default the linked run-log id when running a contract directly.
        if (empty($object['synchronizationLogId']) === true) {
            $object['synchronizationLogId'] = 'n.a.';
        }

        // Default expiry to +3 days unless the caller provided one.
        if (isset($object['expires']) === false) {
            $object['expires'] = (new DateTime('+3 days'))->format('c');
        }

        return (new SynchronizationContractLog())->hydrate($object);

    }//end createFromArray()

    /**
     * Persist a contract log to OpenRegister, write-once.
     *
     * Honours the append-only schema: the row is INSERTed exactly once (no uuid
     * parameter, so OpenRegister treats it as a CREATE). A repeated call for an
     * already-persisted log is a no-op that returns the handle unchanged.
     *
     * @param SynchronizationContractLog $log The contract log value object to persist.
     *
     * @return SynchronizationContractLog The persisted (or unchanged) value object.
     */
    public function update(SynchronizationContractLog $log): SynchronizationContractLog
    {
        $object = $log->jsonSerialize();
        $uuid   = (string) ($object['uuid'] ?? '');

        // Append-only: never issue a second write for the same log.
        if ($uuid !== '' && isset($this->persisted[$uuid]) === true) {
            return $log;
        }

        // INSERT only (no uuid parameter): OpenRegister treats this as a CREATE,
        // which the append-only schema permits.
        $saved = $this->orObjectService->saveObject(
            object: $this->normalize($object),
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        if ($uuid !== '') {
            $this->persisted[$uuid] = true;
        }

        return (new SynchronizationContractLog())->hydrate($saved->jsonSerialize());

    }//end update()

    /**
     * Persist a contract log to OpenRegister, write-once (alias of update()).
     *
     * @param SynchronizationContractLog $log The contract log value object to persist.
     *
     * @return SynchronizationContractLog The persisted value object.
     */
    public function insert(SynchronizationContractLog $log): SynchronizationContractLog
    {
        return $this->update($log);

    }//end insert()

    /**
     * Update an existing contract log from array data (write-once create).
     *
     * @param int|string $id     Ignored for the append-only schema (kept for compatibility).
     * @param array      $object Array of contract log data.
     *
     * @return SynchronizationContractLog The persisted value object.
     */
    public function updateFromArray(int | string $id, array $object): SynchronizationContractLog
    {
        return $this->update($this->createFromArray($object));

    }//end updateFromArray()

    /**
     * Strip null/system keys OpenRegister manages itself before saving.
     *
     * @param array $object The contract log data.
     *
     * @return array The cleaned payload for OpenRegister.
     */
    private function normalize(array $object): array
    {
        unset($object['id'], $object['created']);

        return array_filter(
            $object,
            static function ($value) {
                return $value !== null;
            }
        );

    }//end normalize()

    /**
     * Run an OpenRegister object search scoped to the contract-log register/schema.
     *
     * @param array    $filters Field filters keyed by contract-log property.
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
