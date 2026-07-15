<?php
/**
 * OpenConnector Sync Item Dead Letter Service.
 *
 * Capture, replay and discard machinery for per-object synchronization
 * failures ("sync_item_dead_letter"). Mirrors EventService's
 * recordFailure()/replayMessage()/discardMessage() shape (see local ADR-013)
 * adapted for manual-only replay — there is no automatic retry sweep for
 * sync-item failures (synchronization-engine spec REQ-008).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenConnector\Exception\InvalidMessageStateException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Capture/replay/discard machinery for dead-lettered synchronization items.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#req-008-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync
 */
class SyncItemDeadLetterService
{
    /**
     * Constructor.
     *
     * @param ORObjectService    $objectService      The OR ObjectService for data access.
     * @param ContainerInterface $containerInterface The PSR-11 container (lazy SynchronizationService
     *                                               resolution — see SynchronizationService's
     *                                               `$syncItemDeadLetterService` property docblock for
     *                                               the constructor-cycle rationale).
     * @param LoggerInterface    $logger             PSR logger.
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        private readonly ContainerInterface $containerInterface,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Resolve SynchronizationService lazily from the container. Resolved
     * lazily (not constructor-injected) because SynchronizationService
     * itself lazily resolves THIS class for failure capture — a direct
     * two-way constructor dependency would be circular.
     *
     * @return SynchronizationService|null
     */
    private function getSynchronizationService(): ?SynchronizationService
    {
        try {
            $service = $this->containerInterface->get(SynchronizationService::class);
        } catch (\Throwable $e) {
            return null;
        }

        if ($service instanceof SynchronizationService) {
            return $service;
        }

        return null;

    }//end getSynchronizationService()

    /**
     * Append a single attempt entry to the audit trail.
     *
     * @param array       $attempts The existing attempts array.
     * @param string      $at       ISO 8601 timestamp of the attempt.
     * @param string|null $error    Error message, or null on a successful replay.
     *
     * @return array The attempts array with the new entry appended.
     */
    private function appendAttempt(array $attempts, string $at, ?string $error): array
    {
        $attempts[] = [
            'at'    => $at,
            'error' => $error,
        ];

        return $attempts;

    }//end appendAttempt()

    /**
     * Captures a per-object synchronization failure as a `sync_item_dead_letter`
     * entry (synchronization-engine spec REQ-008).
     *
     * @param array       $synchronization The synchronization payload whose pass produced the failure.
     * @param array       $payload         The raw source object at the moment of failure.
     * @param string      $error           The exception message captured at failure time.
     * @param string|null $originId        Best-effort origin id, when resolvable before the failure.
     *
     * @return ObjectEntity The persisted `sync_item_dead_letter` entry.
     *
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/specs/synchronization-engine/spec.md#req-008-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync
     */
    public function recordFailure(
        array $synchronization,
        array $payload,
        string $error,
        ?string $originId=null,
    ): ObjectEntity {
        $nowIso = (new DateTime())->format('c');

        $entry = [
            'synchronization' => (string) ($synchronization['uuid'] ?? $synchronization['id'] ?? ''),
            'originId'        => $originId,
            'phase'           => 'item-processing',
            'payload'         => $payload,
            'error'           => $error,
            'status'          => 'failed',
            'retryCount'      => 0,
            'attempts'        => $this->appendAttempt(attempts: [], at: $nowIso, error: $error),
        ];

        return $this->objectService->saveObject(
            object: $entry,
            register: 'openconnector',
            schema: 'sync_item_dead_letter'
        );

    }//end recordFailure()

    /**
     * Replay a dead-lettered sync item back through
     * SynchronizationService::replaySynchronizationItem() (dead-letter-replay
     * spec REQ-DLR-009).
     *
     * On success: `status='replayed'`, `replayedBy`/`replayedAt` stamped,
     * the existing `attempts[]` history preserved. On renewed failure:
     * `status` stays `failed`, `retryCount` increments, a new `attempts[]`
     * entry is appended. Unlike event-message replay, this is a synchronous,
     * immediate re-attempt only — no automatic re-entry into a backoff state.
     *
     * @param string $id       The dead-letter entry UUID.
     * @param string $actorUid The acting operator's user id.
     *
     * @return ObjectEntity The updated entry.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the entry does not exist.
     * @throws InvalidMessageStateException                When the entry is not in `failed` state.
     * @throws \OCP\DB\Exception                           On persistence failure.
     *
     * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
     */
    public function replayMessage(string $id, string $actorUid): ObjectEntity
    {
        $entry  = $this->objectService->find(id: $id, register: 'openconnector', schema: 'sync_item_dead_letter');
        $data   = $entry->getObject();
        $status = ($data['status'] ?? '');

        if ($status !== 'failed') {
            throw new InvalidMessageStateException(
                message: 'Cannot replay a sync item dead letter in state "'.$status.'"; only failed entries are replayable.'
            );
        }

        $nowIso            = (new DateTime())->format('c');
        $synchronizationId = ($data['synchronization'] ?? null);
        $synchronizationSvc = $this->getSynchronizationService();
        $replaySucceeded    = false;
        $replayErrorMessage = null;

        if ($synchronizationSvc === null || empty($synchronizationId) === true) {
            $replayErrorMessage = 'Replay unavailable: SynchronizationService could not be resolved.';
        } else {
            try {
                $synchronization = $synchronizationSvc->getSynchronization(id: $synchronizationId)->jsonSerialize();
                $synchronizationSvc->replaySynchronizationItem(
                    synchronization: $synchronization,
                    payload: (array) ($data['payload'] ?? [])
                );
                $replaySucceeded = true;
            } catch (\Throwable $exception) {
                $replayErrorMessage = $exception->getMessage();
                $this->logger->warning(
                    'SyncItemDeadLetterService: replay attempt failed.',
                    ['id' => $id, 'exception' => $exception->getMessage()]
                );
            }
        }//end if

        if ($replaySucceeded === true) {
            $data['status']     = 'replayed';
            $data['replayedBy'] = $actorUid;
            $data['replayedAt'] = $nowIso;
            // The attempts[] history is deliberately preserved on a successful replay.
        } else {
            $data['retryCount'] = ((int) ($data['retryCount'] ?? 0) + 1);
            $data['attempts']   = $this->appendAttempt(attempts: (array) ($data['attempts'] ?? []), at: $nowIso, error: $replayErrorMessage);
            // Status remains 'failed'.
        }

        return $this->objectService->saveObject(
            object: $data,
            register: 'openconnector',
            schema: 'sync_item_dead_letter',
            uuid: $entry->getUuid()
        );

    }//end replayMessage()

    /**
     * Discard a dead-lettered sync item into the terminal `discarded` state
     * (dead-letter-replay spec REQ-DLR-010). Never hard-deleted; remains
     * queryable for audit.
     *
     * @param string $id       The dead-letter entry UUID.
     * @param string $actorUid The acting operator's user id.
     *
     * @return ObjectEntity The updated entry.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When the entry does not exist.
     * @throws InvalidMessageStateException                When the entry is not in `failed` state.
     * @throws \OCP\DB\Exception                           On persistence failure.
     *
     * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-discard-of-a-dead-lettered-sync-item-req-dlr-010
     */
    public function discardMessage(string $id, string $actorUid): ObjectEntity
    {
        $entry  = $this->objectService->find(id: $id, register: 'openconnector', schema: 'sync_item_dead_letter');
        $data   = $entry->getObject();
        $status = ($data['status'] ?? '');

        if ($status !== 'failed') {
            throw new InvalidMessageStateException(
                message: 'Cannot discard a sync item dead letter in state "'.$status.'"; only failed entries are discardable.'
            );
        }

        $data['status']      = 'discarded';
        $data['discardedBy'] = $actorUid;
        $data['discardedAt'] = (new DateTime())->format('c');

        return $this->objectService->saveObject(
            object: $data,
            register: 'openconnector',
            schema: 'sync_item_dead_letter',
            uuid: $entry->getUuid()
        );

    }//end discardMessage()
}//end class
