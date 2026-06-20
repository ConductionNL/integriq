<?php
/**
 * OpenConnector Synchronization Contract Log Service.
 *
 * Write path for synchronization contract logs, backed by OpenRegister.
 *
 * Post OpenRegister-cutover the `openconnector_synchronization_contract_logs`
 * table was dropped. The W5 SynchronizationService rewrite replaces the
 * `SynchronizationContractLogMapper` adapter with this service so the engine
 * no longer carries an `OCA\OpenConnector\Db\SynchronizationContractLogMapper`
 * import.
 *
 * W14 tier-2 cleanup further drops the residual
 * `OCA\OpenConnector\Db\SynchronizationContractLog` value-object import: the
 * service now operates on plain arrays end-to-end (matching the array shapes
 * the OpenRegister `synchronization_contract_log` schema persists). Callers
 * receive arrays they can mutate freely and persist via update()/insert().
 *
 * Persistence semantics are identical to the previous adapter:
 *
 *  - createFromArray() builds an in-memory array (auto-filling system fields +
 *    a stable uuid the engine can reference before persistence); no write
 *    occurs.
 *  - update() / insert() performs the single INSERT honouring the
 *    append-only schema invariant; repeated calls for an already-persisted
 *    log are a safe no-op.
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

use DateTime;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Session\Exceptions\SessionNotAvailableException;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only write path for synchronization contract logs.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SynchronizationContractLogService
{

    /**
     * The OpenRegister register the contract log lives in.
     *
     * @var string
     */
    private const REGISTER = 'openconnector';

    /**
     * The OpenRegister schema for contract log objects.
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
     * Build a new in-memory synchronization contract log handle.
     *
     * The OpenRegister `synchronization_contract_log` schema is append-only:
     * OR rejects any UPDATE/DELETE on it. The engine therefore mutates the log
     * in memory during the run and persists it exactly once at the end via
     * update()/insert(). This method only builds and returns the in-memory
     * array with system fields auto-filled (uuid, userId, sessionId,
     * synchronizationLogId default, expires default).
     *
     * @param array $object The contract log data.
     *
     * @return array The (unpersisted) contract log array.
     */
    public function createFromArray(array $object): array
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

        return $object;

    }//end createFromArray()

    /**
     * Persist a contract log to OpenRegister, write-once.
     *
     * Honours the append-only schema: the row is INSERTed exactly once (no uuid
     * parameter, so OpenRegister treats it as a CREATE). A repeated call for an
     * already-persisted log is a no-op that returns the array unchanged.
     *
     * @param array $log The contract log array to persist.
     *
     * @return array The persisted (or unchanged) array.
     */
    public function update(array $log): array
    {
        $uuid = (string) ($log['uuid'] ?? '');

        // Append-only: never issue a second write for the same log.
        if ($uuid !== '' && isset($this->persisted[$uuid]) === true) {
            return $log;
        }

        // INSERT only (no uuid parameter): OpenRegister treats this as a CREATE,
        // which the append-only schema permits.
        $saved = $this->orObjectService->saveObject(
            object: $this->normalize($log),
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        if ($uuid !== '') {
            $this->persisted[$uuid] = true;
        }

        return $saved->jsonSerialize();

    }//end update()

    /**
     * Persist a contract log to OpenRegister, write-once (alias of update()).
     *
     * @param array $log The contract log array to persist.
     *
     * @return array The persisted array.
     */
    public function insert(array $log): array
    {
        return $this->update($log);

    }//end insert()

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
}//end class
