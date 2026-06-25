<?php
/**
 * OpenConnector Synchronization Log Service
 *
 * Write path for synchronization run-logs, backed by OpenRegister.
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
 * Write path for synchronization run-logs, backed by OpenRegister.
 *
 * Post OpenRegister-cutover the `SynchronizationLog` entity and its QBMapper were
 * deleted (commit 7df241bc) but the write path was left wired to the orphaned
 * mapper, so every `POST /api/synchronizations/{id}/run` 500'd. This service
 * restores the write path on top of OpenRegister (register `openconnector`,
 * schema `synchronization_log`) — the exact same register/schema the read path
 * (LogsController / SynchronizationsController) already uses — and returns a
 * SynchronizationRunLog value object the engine mutates in memory.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SynchronizationLogService
{

    /**
     * The OpenRegister register the synchronization log lives in.
     *
     * @var string
     */
    private const REGISTER = 'openconnector';

    /**
     * The OpenRegister schema for synchronization log objects.
     *
     * @var string
     */
    private const SCHEMA = 'synchronization_log';

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
     * Build a new in-memory synchronization run-log handle.
     *
     * The OpenRegister `synchronization_log` schema is immutable / append-only
     * (ADR-003): OR rejects any `saveObject(uuid: ...)` UPDATE after the initial
     * INSERT with an AppendOnlyException. The engine therefore writes the row
     * exactly once, with its final state, at the end of the run — so this method
     * only builds and returns the in-memory value object (auto-filling the system
     * fields and a stable pre-generated uuid the engine can reference for
     * contract-log linkage). The row is persisted later via update()/persist().
     *
     * @param array $object The log data.
     *
     * @return SynchronizationRunLog The (unpersisted) log value object.
     */
    public function createFromArray(array $object): SynchronizationRunLog
    {
        // Auto-fill system fields, including a stable uuid the engine can hand to
        // contract logs before the row is actually persisted at finalize time.
        $object['uuid']   = ($object['uuid'] ?? (string) Uuid::v4());
        $object['id']     = ($object['id'] ?? $object['uuid']);
        $object['userId'] = ($object['userId'] ?? $this->userSession->getUser()?->getUID());

        // Catch error from session, because when running from a Job this might
        // cause an error preventing the Job from being ran.
        try {
            $object['sessionId'] = ($object['sessionId'] ?? $this->session->getId());
        } catch (SessionNotAvailableException $exception) {
            $object['sessionId'] = null;
        }

        $object['test']  = ($object['test'] ?? false);
        $object['force'] = ($object['force'] ?? false);

        return (new SynchronizationRunLog())->hydrate(object: $object);

    }//end createFromArray()

    /**
     * Persist the in-memory run-log to OpenRegister, write-once.
     *
     * Mirrors the retired mapper's update() semantics (the 1-hour retention
     * shortening for successful runs) but honours the append-only schema: the row
     * is INSERTed exactly once via saveObject() WITHOUT a uuid parameter (a CREATE
     * for OpenRegister). Because the schema rejects updates, any subsequent call
     * for an already-persisted log is a no-op that returns the handle unchanged.
     * Callers therefore invoke this once, at the end of the run, with the log in
     * its final state.
     *
     * @param SynchronizationRunLog $log The log value object to persist.
     *
     * @return SynchronizationRunLog The persisted (or unchanged) log value object.
     *
     * @throws \Exception When the OpenRegister save fails.
     */
    public function update(SynchronizationRunLog $log): SynchronizationRunLog
    {
        // Append-only: never issue a second write for the same log.
        if ($log->isPersisted() === true) {
            return $log;
        }

        $object = $log->jsonSerialize();

        // If the log is successful, limit log retention to 1 hour.
        if (($object['message'] ?? null) === 'Success') {
            $object['expires'] = (new DateTime('+1 hour'))->format('c');
        }

        // Process contracts in results if they exist.
        if (isset($object['result']['contracts']) === true && is_array($object['result']['contracts']) === true) {
            $object['result']['contracts'] = $this->processContracts(contracts: $object['result']['contracts']);
        }

        // INSERT only (no uuid parameter): OpenRegister treats this as a CREATE,
        // which the append-only schema permits. OR assigns the canonical object
        // identifier; the pre-generated uuid travels in the body for traceability.
        $saved = $this->orObjectService->saveObject($this->normalize(object: $object), [], self::REGISTER, self::SCHEMA);

        $log->markPersisted(saved: $saved->jsonSerialize());

        return $log;

    }//end update()

    /**
     * Persist a run-log to OpenRegister, write-once (alias of update()).
     *
     * Provided for call sites that conceptually "finalize" rather than "update".
     *
     * @param SynchronizationRunLog $log The log value object to persist.
     *
     * @return SynchronizationRunLog The persisted log value object.
     *
     * @throws \Exception When the OpenRegister save fails.
     */
    public function persist(SynchronizationRunLog $log): SynchronizationRunLog
    {
        return $this->update(log: $log);

    }//end persist()

    /**
     * Strip null/system keys OpenRegister manages itself before saving.
     *
     * The OpenRegister object service owns `id`, `created` and `updated`; passing
     * them through (especially a null `id` on create) only adds noise, so drop
     * them and any other null values from the payload.
     *
     * @param array $object The log data.
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
     * Process contracts array to ensure it only contains valid UUIDs.
     *
     * @param array $contracts Array of contracts or contract objects.
     *
     * @return array Processed array containing only valid UUIDs.
     */
    private function processContracts(array $contracts): array
    {
        return array_values(
            array_filter(
                array_map(
                    static function ($contract) {
                        if (is_object($contract) === true) {
                            // If it's an object with getUuid method, use that.
                            if (method_exists($contract, 'getUuid') === true) {
                                $uuid = $contract->getUuid();
                                if (empty($uuid) === true) {
                                    return null;
                                }

                                return $uuid;
                            }

                            return null;
                        }

                        // If it's already a string (UUID), return it.
                        if (is_string($contract) === true) {
                            return $contract;
                        }

                        return null;
                    },
                    $contracts
                )
            )
        );

    }//end processContracts()
}//end class
