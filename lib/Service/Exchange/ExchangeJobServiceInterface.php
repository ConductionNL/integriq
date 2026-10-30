<?php

/**
 * Contract of the ExchangeJobService.
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

use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see ExchangeJobService} that other components call.
 */
interface ExchangeJobServiceInterface {
	/**
	 * Take an ExchangeJobRequestedEvent: create the job, or refuse.
	 *
	 * A migrated request whose legacy id was already migrated answers with the
	 * existing job and returns null, so its rejections are not written twice.
	 *
	 * @param ExchangeJobRequestedEvent $event The request.
	 *
	 * @return ObjectEntity|null The job created by this call, or null when none was.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function handleRequest(ExchangeJobRequestedEvent $event): ?ObjectEntity;

	/**
	 * Take an ExchangeMappingRequestedEvent: upsert the mapping by slug.
	 *
	 * @param ExchangeMappingRequestedEvent $event The request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function handleMappingRequest(ExchangeMappingRequestedEvent $event): void;

	/**
	 * Create a single-record resubmission job for a rejection.
	 *
	 * @param array<string,mixed> $original The rejected record's job data.
	 * @param string              $recordId The rejected record's id in the owning app.
	 * @param string              $ownerRef The rejected record's reference.
	 * @param string              $rejectionId The rejection's uuid.
	 * @param string              $actor    Who resubmitted.
	 *
	 * @return ObjectEntity The new job.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function createResubmission(
		array $original,
		string $recordId,
		string $ownerRef,
		string $rejectionId,
		string $actor
	): ObjectEntity;

	/**
	 * Load an exchange job by uuid.
	 *
	 * @param string $jobId The uuid.
	 *
	 * @return ObjectEntity|null The job, or null when absent.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function findJob(string $jobId): ?ObjectEntity;

	/**
	 * Save an exchange job's data.
	 *
	 * @param array<string,mixed> $data The job data.
	 * @param string|null         $uuid The uuid to update, or null to create.
	 *
	 * @return ObjectEntity The saved job.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function saveJob(array $data, ?string $uuid = null): ObjectEntity;

	/**
	 * Find a mapping row by slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return ObjectEntity|null The mapping, or null when absent.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-004-the-jobs-mapping-transforms-each-allowed-record
	 */
	public function findMapping(string $slug): ?ObjectEntity;
}
