<?php

/**
 * Contract of the SyncItemDeadLetterService.
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

use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see SyncItemDeadLetterService} that other components call.
 */
interface SyncItemDeadLetterServiceInterface {
	/**
	 * Captures a per-object synchronization failure as a `sync_item_dead_letter`
	 * entry (synchronization-engine spec REQ-008).
	 *
	 * @param array $synchronization The synchronization payload whose pass produced the failure.
	 * @param array $payload The raw source object at the moment of failure.
	 * @param string $error The exception message captured at failure time.
	 * @param string|null $originId Best-effort origin id, when resolvable before the failure.
	 *
	 * @return ObjectEntity The persisted `sync_item_dead_letter` entry.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
	 */
	public function recordFailure(
		array $synchronization,
		array $payload,
		string $error,
		?string $originId = null,
	): ObjectEntity;

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
	 * @param string $id The dead-letter entry UUID.
	 * @param string $actorUid The acting operator's user id.
	 *
	 * @return ObjectEntity The updated entry.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the entry does not exist.
	 * @throws InvalidMessageStateException When the entry is not in `failed` state.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
	 */
	public function replayMessage(string $id, string $actorUid): ObjectEntity;

	/**
	 * Discard a dead-lettered sync item into the terminal `discarded` state
	 * (dead-letter-replay spec REQ-DLR-010). Never hard-deleted; remains
	 * queryable for audit.
	 *
	 * @param string $id The dead-letter entry UUID.
	 * @param string $actorUid The acting operator's user id.
	 *
	 * @return ObjectEntity The updated entry.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the entry does not exist.
	 * @throws InvalidMessageStateException When the entry is not in `failed` state.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-discard-of-a-dead-lettered-sync-item-req-dlr-010
	 */
	public function discardMessage(string $id, string $actorUid): ObjectEntity;
}
