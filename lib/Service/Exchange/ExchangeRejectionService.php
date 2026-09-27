<?php

/**
 * Integriq exchange rejection service.
 *
 * Stores a record an exchange target rejected as a `sync_item_dead_letter`
 * row and runs its correction loop: resubmit, reopen, waive.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Exchange
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
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

use DateTime;
use InvalidArgumentException;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Exchange rejections on the native dead letter schema (design D6).
 *
 * A rejection stores the code, the field names and an opaque owner reference,
 * never the record's data or the target's free-text message: the dead letter
 * schema is readable by every account (SchemaAuthorizationRatchetTest), and
 * DUO's messages quote names and birth dates.
 *
 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
 */
class ExchangeRejectionService {

	public const SCHEMA = 'sync_item_dead_letter';

	public const STATUS_FAILED = 'failed';

	public const STATUS_REPLAYED = 'replayed';

	public const STATUS_DISCARDED = 'discarded';

	public const PHASE = 'exchange';

	/**
	 * Slug of the seeded status translation.
	 *
	 * @var string
	 */
	public const STATUS_MAPPING_SLUG = 'learniq-exchange-rejection-status';

	/**
	 * The shipped translation, used when the seeded row is not stored yet.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_FALLBACK = [
		'open' => self::STATUS_FAILED,
		'corrected' => self::STATUS_FAILED,
		'resubmitted' => self::STATUS_REPLAYED,
		'accepted' => self::STATUS_REPLAYED,
		'waived' => self::STATUS_DISCARDED,
	];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService            $objectService OpenRegister object access.
	 * @param ExchangeErrorCodeCatalogue $codes         Resolves a code to its label.
	 * @param ExchangeJobService         $jobs          Creates the resubmission job.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly ExchangeErrorCodeCatalogue $codes,
		private readonly ExchangeJobService $jobs,
	) {

	}//end __construct()

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
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function record(string $jobId, string $target, array $rejection, string $ownerApp = ''): ObjectEntity {
		$now = (new DateTime())->format('c');
		$code = (string)($rejection['errorCode'] ?? 'send-failed');

		$row = $this->buildRow(
			jobId: $jobId,
			target: $target,
			rejection: $rejection,
			code: $code,
			status: self::STATUS_FAILED,
			now: $now
		);
		$row['ownerApp'] = $ownerApp;

		return $this->save(data: $row);

	}//end record()

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
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function migrate(ObjectEntity $job, array $rejection): ObjectEntity {
		$jobData = $job->getObject();
		$code = (string)($rejection['errorCode'] ?? 'send-failed');
		$status = $this->translateStatus(legacyStatus: (string)($rejection['status'] ?? 'open'));
		$detected = (string)($rejection['detectedAt'] ?? (new DateTime())->format('c'));

		$row = $this->buildRow(
			jobId: $job->getUuid(),
			target: (string)($jobData['exchangeTarget'] ?? ''),
			rejection: $rejection,
			code: $code,
			status: $status,
			now: $detected
		);
		$row['ownerApp'] = (string)($jobData['ownerApp'] ?? '');

		foreach (['correctedBy', 'correctedAt', 'correctionDeadlineAt'] as $field) {
			if (empty($rejection[$field]) === false) {
				$row[$field] = (string)$rejection[$field];
			}
		}

		if ($status === self::STATUS_DISCARDED) {
			$row['discardedBy'] = (string)($rejection['waivedBy'] ?? '');
			$row['discardedAt'] = (string)($rejection['waivedAt'] ?? $detected);
			$row['discardReason'] = (string)($rejection['waiveReason'] ?? '');
		}

		return $this->save(data: $row);

	}//end migrate()

	/**
	 * Load an exchange rejection.
	 *
	 * @param string $rejectionId The dead letter's uuid.
	 *
	 * @return ObjectEntity|null The rejection, or null when absent or not an exchange rejection.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function find(string $rejectionId): ?ObjectEntity {
		try {
			$entry = $this->objectService->find(
				id: $rejectionId,
				register: ExchangeJobService::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($entry === null || empty($entry->getObject()['exchangeJob']) === true) {
			return null;
		}

		return $entry;

	}//end find()

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
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function resubmit(string $rejectionId, string $actor): array {
		$entry = $this->find(rejectionId: $rejectionId);
		if ($entry === null) {
			throw new InvalidArgumentException(sprintf('No exchange rejection "%s".', $rejectionId));
		}

		$data = $entry->getObject();
		$this->requireFailed(data: $data, action: 'resubmit');

		$original = $this->jobs->findJob(jobId: (string)($data['exchangeJob'] ?? ''));
		if ($original === null) {
			throw new InvalidMessageStateException(
				message: 'The exchange job of this rejection no longer exists, so it cannot be resubmitted.'
			);
		}

		$job = $this->jobs->createResubmission(
			original: $original->getObject(),
			recordId: (string)($data['originId'] ?? ''),
			ownerRef: (string)($data['ownerRef'] ?? ''),
			rejectionId: $entry->getUuid(),
			actor: $actor
		);

		return [
			'rejection' => $this->markReplayed(entry: $entry, actor: $actor),
			'rejectionId' => $rejectionId,
			'jobId' => $this->uuidOf(entity: $job),
		];

	}//end resubmit()

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
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function markReplayed(ObjectEntity $entry, string $actor): ObjectEntity {
		$data = $entry->getObject();
		$this->requireFailed(data: $data, action: 'resubmit');

		$data['status'] = self::STATUS_REPLAYED;
		$data['replayedBy'] = $actor;
		$data['replayedAt'] = (new DateTime())->format('c');

		return $this->save(data: $data, uuid: $entry->getUuid());

	}//end markReplayed()

	/**
	 * Put a resubmitted rejection back to failed: the target rejected it again.
	 *
	 * @param string              $rejectionId The rejection's uuid.
	 * @param array<string,mixed> $rejection   The new `{errorCode, offendingFields}`.
	 *
	 * @return ObjectEntity|null The updated rejection, or null when it is gone.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function reopen(string $rejectionId, array $rejection): ?ObjectEntity {
		$entry = $this->find(rejectionId: $rejectionId);
		if ($entry === null) {
			return null;
		}

		$data = $entry->getObject();
		$code = (string)($rejection['errorCode'] ?? ($data['errorCode'] ?? 'send-failed'));
		$now = (new DateTime())->format('c');
		$entryData = $this->codes->resolve(target: (string)($data['exchangeTarget'] ?? ''), code: $code);

		$data['status'] = self::STATUS_FAILED;
		$data['errorCode'] = $code;
		$data['offendingFields'] = $this->fieldNames(fields: ($rejection['offendingFields'] ?? []));
		$data['error'] = $entryData['label'];
		$data['retryCount'] = ((int)($data['retryCount'] ?? 0) + 1);
		$attempts = $data['attempts'] ?? [];
		if (is_array($attempts) === false) {
			$attempts = [];
		}

		$attempts[] = ['at' => $now, 'error' => $code];
		$data['attempts'] = $attempts;

		return $this->save(data: $data, uuid: $entry->getUuid());

	}//end reopen()

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
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public function waive(ObjectEntity $entry, string $actor, string $reason): ObjectEntity {
		if (trim($reason) === '') {
			throw new InvalidArgumentException('A rejection is only waived with a reason.');
		}

		$data = $entry->getObject();
		$this->requireFailed(data: $data, action: 'waive');

		$data['status'] = self::STATUS_DISCARDED;
		$data['discardedBy'] = $actor;
		$data['discardedAt'] = (new DateTime())->format('c');
		$data['discardReason'] = trim($reason);

		return $this->save(data: $data, uuid: $entry->getUuid());

	}//end waive()

	/**
	 * Translate an owning app's former rejection status.
	 *
	 * @param string $legacyStatus The former status.
	 *
	 * @return string failed, replayed or discarded.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function translateStatus(string $legacyStatus): string {
		$table = self::STATUS_FALLBACK;
		try {
			$matches = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => ExchangeJobService::REGISTER,
						'schema' => ExchangeJobService::SCHEMA_MAPPING,
						'slug' => self::STATUS_MAPPING_SLUG,
					],
					'limit' => 1,
				],
				_rbac: false,
				_multitenancy: false
			);
			foreach (($matches['results'] ?? []) as $row) {
				$mapping = ($row->getObject()['mapping'] ?? null);
				if (is_array($mapping) === true) {
					$table = array_merge($table, $mapping);
				}
			}
		} catch (\Throwable $exception) {
			unset($exception);
		}

		$status = (string)($table[$legacyStatus] ?? self::STATUS_FAILED);
		if (in_array($status, [self::STATUS_FAILED, self::STATUS_REPLAYED, self::STATUS_DISCARDED], true) === false) {
			return self::STATUS_FAILED;
		}

		return $status;

	}//end translateStatus()

	/**
	 * Build a rejection row.
	 *
	 * @param string              $jobId     The job's uuid.
	 * @param string              $target    The target.
	 * @param array<string,mixed> $rejection The rejection.
	 * @param string              $code      The error code.
	 * @param string              $status    The status.
	 * @param string              $now       The capture time.
	 *
	 * @return array<string,mixed> The row.
	 */
	private function buildRow(
		string $jobId,
		string $target,
		array $rejection,
		string $code,
		string $status,
		string $now
	): array {
		$recordId = (string)($rejection['recordId'] ?? '');
		$sourceKind = (string)($rejection['sourceKind'] ?? '');
		$ownerRef = (string)($rejection['ownerRef'] ?? '');
		if ($ownerRef === '' && $recordId !== '') {
			$ownerRef = trim($sourceKind . '/' . $recordId, '/');
		}

		return [
			'exchangeJob' => $jobId,
			'exchangeTarget' => $target,
			'originId' => $recordId,
			'ownerRef' => $ownerRef,
			'sourceKind' => $sourceKind,
			'phase' => self::PHASE,
			'errorCode' => $code,
			'offendingFields' => $this->fieldNames(fields: ($rejection['offendingFields'] ?? [])),
			'error' => $this->codes->resolve(target: $target, code: $code)['label'],
			'payload' => [],
			'status' => $status,
			'retryCount' => 0,
			'attempts' => [['at' => $now, 'error' => $code]],
		];

	}//end buildRow()

	/**
	 * The uuid of a stored object, empty when it has none.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return string The uuid.
	 */
	private function uuidOf(ObjectEntity $entity): string {
		$uuid = $entity->getUuid();
		if (is_string($uuid) === true) {
			return $uuid;
		}

		return '';

	}//end uuidOf()

	/**
	 * Keep field names only.
	 *
	 * @param mixed $fields The offered field list.
	 *
	 * @return array<int, string> The names.
	 */
	private function fieldNames(mixed $fields): array {
		if (is_array($fields) === false) {
			return [];
		}

		$names = [];
		foreach ($fields as $field) {
			if (is_string($field) === true && $field !== '') {
				$names[] = $field;
			}
		}

		return $names;

	}//end fieldNames()

	/**
	 * Refuse an action on a rejection that is not failed.
	 *
	 * @param array<string,mixed> $data   The rejection data.
	 * @param string              $action The action name.
	 *
	 * @return void
	 *
	 * @throws InvalidMessageStateException When not failed.
	 */
	private function requireFailed(array $data, string $action): void {
		$status = (string)($data['status'] ?? '');
		if ($status !== self::STATUS_FAILED) {
			throw new InvalidMessageStateException(
				message: sprintf('Cannot %s a rejection in state "%s"; only failed rejections can.', $action, $status)
			);
		}

	}//end requireFailed()

	/**
	 * Save a rejection row.
	 *
	 * @param array<string,mixed> $data The row.
	 * @param string|null         $uuid The uuid to update, or null to create.
	 *
	 * @return ObjectEntity The saved row.
	 */
	private function save(array $data, ?string $uuid = null): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: ExchangeJobService::REGISTER,
			schema: self::SCHEMA,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

	}//end save()
}//end class
