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
     *
     * @spec openspec/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011
     */
    public function update(SynchronizationRunLog $log): SynchronizationRunLog
    {
        // Normalise BEFORE the append-only early return, and write the result
        // back onto the log rather than onto the outbound copy alone. The
        // engine returns `$log->jsonSerialize()` to the controller, so
        // normalising only the copy headed for storage made the API response
        // and the persisted row disagree: a dry run answered with a hundred
        // null `contracts` while the stored row carried an empty list.
        // Idempotent — compacting an already-compacted result is a no-op — so
        // running it ahead of the isPersisted() check is safe.
        $log->setResult($this->normaliseResultReferences(result: $log->getResult()));

        // Append-only: never issue a second write for the same log.
        if ($log->isPersisted() === true) {
            return $log;
        }

        $object = $log->jsonSerialize();

        // If the log is successful, limit log retention to 1 hour.
        if (($object['message'] ?? null) === 'Success') {
            $object['expires'] = (new DateTime('+1 hour'))->format('c');
        }

        // INSERT only (no uuid parameter): OpenRegister treats this as a CREATE,
        // which the append-only schema permits. OR assigns the canonical object
        // identifier; the pre-generated uuid travels in the body for traceability.
        $saved = $this->orObjectService->saveObject(
            object: $this->normalize(object: $object),
            register: self::REGISTER,
            schema: self::SCHEMA
        );

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
     * Reduce one `contracts` entry to its uuid, or null when it has none.
     *
     * Entries reach the run-log either already as a uuid string or as a
     * contract object, depending on which engine path recorded them.
     *
     * @param mixed $contract A contract object, a uuid string, or anything else.
     *
     * @return string|null The uuid, or null when the entry references nothing.
     */
    private function resolveContractId(mixed $contract): ?string
    {
        if (\is_object($contract) === true) {
            if (method_exists($contract, 'getUuid') === false) {
                return null;
            }

            $uuid = $contract->getUuid();
            if (empty($uuid) === true) {
                return null;
            }

            return (string) $uuid;
        }

        if (\is_string($contract) === true) {
            // An empty string references nothing, exactly like a null entry —
            // keeping it would persist [''] and fan out a degenerate object in
            // Flow. The `logs` list drops '' for the same reason.
            if ($contract === '') {
                return null;
            }

            return $contract;
        }

        return null;

    }//end resolveContractId()

    /**
     * Compact the run-log's reference lists to the references that exist.
     *
     * `SynchronizationService` appends to `result.contracts` and `result.logs`
     * once per processed object whether or not there is anything to reference,
     * so a run that persists no contract records a null in each. A dry run
     * persists nothing by definition (synchronization-engine REQ-011), which is
     * how a 100-object test run ended up reporting a hundred nulls in each list
     * plus a hundred more under `_embed.contracts` — three times the payload of
     * the counters that carry the actual result, saying nothing the `objects`
     * tallies do not already say.
     *
     * `_embed.contracts` is compacted in lockstep with `contracts` because
     * `Flow\SynchronizationRunNode::objectsFrom()` pairs the two by position;
     * dropping from one alone would misalign every later entry.
     *
     * @param array $result The run-log result payload.
     *
     * @return array The result with its reference lists compacted.
     */
    private function normaliseResultReferences(array $result): array
    {
        $contracts = ($result['contracts'] ?? null);
        if (\is_array($contracts) === true) {
            $embedded = ($result['_embed']['contracts'] ?? null);
            $hasEmbed = \is_array($embedded);

            // Reindexed once, outside the loop: re-running array_values() per
            // contract rebuilt the whole embedded list on every iteration,
            // making compaction O(n²) in the number of contracts.
            $embeddedByPosition = [];
            if ($hasEmbed === true) {
                $embeddedByPosition = array_values($embedded);
            }

            $keptContracts = [];
            $keptEmbedded  = [];
            foreach (array_values($contracts) as $position => $contract) {
                $uuid = $this->resolveContractId(contract: $contract);
                if ($uuid === null) {
                    continue;
                }

                $keptContracts[] = $uuid;
                if ($hasEmbed === true) {
                    // Always append, defaulting to null, so the embedded list
                    // keeps exactly one entry per surviving contract even when
                    // it was shorter than `contracts` to begin with.
                    $keptEmbedded[] = ($embeddedByPosition[$position] ?? null);
                }
            }

            $result['contracts'] = $keptContracts;
            if ($hasEmbed === true) {
                $result['_embed']['contracts'] = $keptEmbedded;
            }
        }//end if

        $logs = ($result['logs'] ?? null);
        if (\is_array($logs) === true) {
            $result['logs'] = array_values(
                array_filter(
                    $logs,
                    static function ($logId) {
                        return $logId !== null && $logId !== '';
                    }
                )
            );
        }

        return $result;

    }//end normaliseResultReferences()
}//end class
