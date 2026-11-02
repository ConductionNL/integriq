<?php

/**
 * Contract of the ExchangeRejectionService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Exchange
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

namespace OCA\Integriq\Service\Exchange;

use InvalidArgumentException;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see ExchangeRejectionService} that other components call.
 */
interface ExchangeRejectionServiceInterface {
	/**
	 * Store one rejected record of a run.
	 *
	 * @param string              $jobId     The job's uuid.
	 * @param string              $target    The job's target.
	 * @param array<string,mixed> $rejection `{recordId, sourceKind, errorCode, offendingFields}`.
	 * @param string              $ownerApp  The job's owning app.
	 *
	 * @return ObjectEntity The stored rejection.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function record(string $jobId, string $target, array $rejection, string $ownerApp = ''): ObjectEntity;

	/**
	 * Store one rejection carried by a migrated job's history.
	 *
	 * The owning app's former status is translated by the seeded
	 * `learniq-exchange-rejection-status` mapping.
	 *
	 * @param ObjectEntity        $job       The migrated job.
	 * @param array<string,mixed> $rejection The former rejection.
	 *
	 * @return ObjectEntity The stored rejection.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function migrate(ObjectEntity $job, array $rejection): ObjectEntity;

	/**
	 * Load an exchange rejection.
	 *
	 * @param string $rejectionId The dead letter's uuid.
	 *
	 * @return ObjectEntity|null The rejection, or null when absent or not an exchange rejection.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function find(string $rejectionId): ?ObjectEntity;

	/**
	 * Resubmit a failed rejection: a single-record job for the same target.
	 *
	 * @param string $rejectionId The rejection's uuid.
	 * @param string $actor       Who resubmitted.
	 *
	 * @return array{rejection: ObjectEntity, rejectionId: string, jobId: string} The updated rejection and the new job's id.
	 *
	 * @throws InvalidMessageStateException When the rejection is not failed, or its job is gone.
	 * @throws InvalidArgumentException     When no exchange rejection has that id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function resubmit(string $rejectionId, string $actor): array;

	/**
	 * Mark a failed rejection resubmitted.
	 *
	 * @param ObjectEntity $entry The rejection.
	 * @param string       $actor Who resubmitted.
	 *
	 * @return ObjectEntity The updated rejection.
	 *
	 * @throws InvalidMessageStateException When the rejection is not failed.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function markReplayed(ObjectEntity $entry, string $actor): ObjectEntity;

	/**
	 * Put a resubmitted rejection back to failed: the target rejected it again.
	 *
	 * @param string              $rejectionId The rejection's uuid.
	 * @param array<string,mixed> $rejection   The new `{errorCode, offendingFields}`.
	 *
	 * @return ObjectEntity|null The updated rejection, or null when it is gone.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function reopen(string $rejectionId, array $rejection): ?ObjectEntity;

	/**
	 * Waive a rejection with a reason.
	 *
	 * @param ObjectEntity $entry  The rejection.
	 * @param string       $actor  Who waived.
	 * @param string       $reason Why.
	 *
	 * @return ObjectEntity The updated rejection.
	 *
	 * @throws InvalidArgumentException     When the reason is empty.
	 * @throws InvalidMessageStateException When the rejection is not failed.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function waive(ObjectEntity $entry, string $actor, string $reason): ObjectEntity;

	/**
	 * Translate an owning app's former rejection status.
	 *
	 * @param string $legacyStatus The former status.
	 *
	 * @return string failed, replayed or discarded.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function translateStatus(string $legacyStatus): string;
}
