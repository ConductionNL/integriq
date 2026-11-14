<?php

/**
 * Integriq exchange job service.
 *
 * Creates, finds and saves the `job` rows that carry another app's data
 * exchange jobs, and stores an app's own exchange mappings.
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
use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
use OCA\Integriq\Service\Job\ExchangeJobAction;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;

/**
 * Store access for exchange jobs and mappings (design D1, D7).
 *
 * Writes run without a user session (listeners, the scheduler), so every
 * OpenRegister call here passes `_rbac: false, _multitenancy: false`; the
 * callers are the in-process event listeners and the runner, never a request.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExchangeJobService implements ExchangeJobServiceInterface {

	public const REGISTER = 'integriq';

	public const SCHEMA_JOB = 'job';

	public const SCHEMA_MAPPING = 'mapping';

	public const STATUS_QUEUED = 'queued';

	public const STATUS_RUNNING = 'running';

	public const STATUS_SUCCEEDED = 'succeeded';

	public const STATUS_PARTIAL = 'partial';

	public const STATUS_FAILED = 'failed';

	public const STATUS_REFUSED = 'refused';

	/**
	 * Statuses after which a job does not run again by itself.
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL = [self::STATUS_SUCCEEDED, self::STATUS_PARTIAL, self::STATUS_FAILED, self::STATUS_REFUSED];

	/**
	 * The mapping row a job gets when its owning app names none, keyed by
	 * owner, then `target:direction`, then the scope's `berichtsoort` (`*`
	 * for any other). An explicit slug always wins.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private const DEFAULT_MAPPINGS = [
		'learniq' => [
			'bron-rod:export' => [
				'schooladvies' => 'learniq-bron-rod-export-schooladvies',
				'*' => 'learniq-bron-rod-export-learner',
			],
		],
	];

	/**
	 * Legacy job statuses a migrated job may keep; anything else becomes queued.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_STATUS = [
		'succeeded' => self::STATUS_SUCCEEDED,
		'partial' => self::STATUS_PARTIAL,
		'failed' => self::STATUS_FAILED,
		'running' => self::STATUS_FAILED,
	];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService         $objectService OpenRegister object access.
	 * @param ExchangeTargetCatalogue $targets       The target vocabulary.
	 * @param LoggerInterface         $logger        Logger.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly ExchangeTargetCatalogue $targets,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

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
	public function handleRequest(ExchangeJobRequestedEvent $event): ?ObjectEntity {
		$request = ExchangeJobRequest::fromEvent(event: $event);
		if ($this->refuseInvalid(event: $event, request: $request) === true || $this->answerMigrated(event: $event) === true) {
			return null;
		}

		$job     = $this->buildJob(request: $request);
		$history = $event->getHistory();
		if ($history !== null) {
			$job = $this->applyHistory(job: $job, history: $history);
		}

		return $this->storeRequestedJob(event: $event, job: $job);

	}//end handleRequest()

	/**
	 * Refuse a request the vocabulary does not allow.
	 *
	 * @param ExchangeJobRequestedEvent $event   The request event.
	 * @param ExchangeJobRequest        $request The request it carries.
	 *
	 * @return bool True when the request was refused.
	 */
	private function refuseInvalid(ExchangeJobRequestedEvent $event, ExchangeJobRequest $request): bool {
		$refusal = $this->validate(request: $request);
		if ($refusal === null) {
			return false;
		}

		$event->refuse(code: $refusal['code'], reason: $refusal['reason']);
		return true;

	}//end refuseInvalid()

	/**
	 * Answer a migrated request whose legacy id was already migrated with the
	 * existing job.
	 *
	 * @param ExchangeJobRequestedEvent $event The request event.
	 *
	 * @return bool True when the request was answered with an existing job.
	 */
	private function answerMigrated(ExchangeJobRequestedEvent $event): bool {
		$legacyId = (string)($event->getHistory()['legacyId'] ?? '');
		if ($legacyId === '') {
			return false;
		}

		$existing = $this->findMigrated(ownerApp: $event->getOwnerApp(), legacyId: $legacyId);
		if ($existing === null) {
			return false;
		}

		$event->setJobId($existing->getUuid());
		return true;

	}//end answerMigrated()

	/**
	 * Store a requested job and hand its id to the event, or refuse it when
	 * the store fails.
	 *
	 * @param ExchangeJobRequestedEvent $event The request event.
	 * @param array<string,mixed>       $job   The job data.
	 *
	 * @return ObjectEntity|null The stored job, or null when the store failed.
	 */
	private function storeRequestedJob(ExchangeJobRequestedEvent $event, array $job): ?ObjectEntity {
		try {
			$saved = $this->saveJob(data: $job);
		} catch (\Throwable $exception) {
			$this->logger->error('[ExchangeJobService] could not store an exchange job: ' . $exception->getMessage());
			$event->refuse(code: 'store-failed', reason: 'Integriq could not store the job: ' . $exception->getMessage());
			return null;
		}

		$event->setJobId($saved->getUuid());
		return $saved;

	}//end storeRequestedJob()

	/**
	 * Take an ExchangeMappingRequestedEvent: upsert the mapping by slug.
	 *
	 * @param ExchangeMappingRequestedEvent $event The request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function handleMappingRequest(ExchangeMappingRequestedEvent $event): void {
		$ownerApp = $event->getOwnerApp();
		$slug = $event->getSlug();
		if ($ownerApp === '' || str_starts_with($slug, $ownerApp . '-') === false) {
			$event->refuse(
				code: 'slug-foreign',
				reason: sprintf('The mapping slug "%s" must start with "%s-".', $slug, $ownerApp)
			);
			return;
		}

		if ($event->getMapping() === []) {
			$event->refuse(code: 'mapping-empty', reason: 'The mapping has no rules.');
			return;
		}

		$data = [
			'name' => $event->getName(),
			'description' => $event->getDescription(),
			'reference' => $slug,
			'slug' => $slug,
			'mapping' => $event->getMapping(),
			'cast' => $event->getCast(),
			'unset' => $event->getUnset(),
			'passThrough' => $event->isPassThrough(),
			'version' => '1.0.0',
		];

		try {
			$existing = $this->findMapping(slug: $slug);
			$saved = $this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_MAPPING,
				uuid: $existing?->getUuid(),
				_rbac: false,
				// Unscoped: in-process listener, no user session (see the class note).
				_multitenancy: false
			);
		} catch (\Throwable $exception) {
			$event->refuse(code: 'store-failed', reason: 'Integriq could not store the mapping: ' . $exception->getMessage());
			return;
		}

		$event->setMappingId($saved->getUuid());

	}//end handleMappingRequest()

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
	): ObjectEntity {
		$resubmitted = $this->resubmissionData(original: $original, recordId: $recordId);
		$resubmitted['ownerRef']    = $ownerRef;
		$resubmitted['requestedBy'] = $actor;

		$job = $this->buildJob(request: ExchangeJobRequest::fromJobData(data: $resubmitted));
		$job['resubmissionOf'] = $rejectionId;

		return $this->saveJob(data: $job);

	}//end createResubmission()

	/**
	 * The rejected job's data with its scope narrowed to one record and its
	 * name marked as a resubmission.
	 *
	 * @param array<string,mixed> $original The rejected record's job data.
	 * @param string              $recordId The rejected record's id in the owning app.
	 *
	 * @return array<string,mixed> The job data of the resubmission.
	 */
	private function resubmissionData(array $original, string $recordId): array {
		$scope = $original['exchangeScope'] ?? [];
		if (is_array($scope) === false) {
			$scope = [];
		}

		$scope['recordIds']        = [$recordId];
		$original['exchangeScope'] = $scope;
		$original['name']          = 'Resubmission: ' . (string)($original['name'] ?? '');
		return $original;

	}//end resubmissionData()

	/**
	 * Load an exchange job by uuid.
	 *
	 * @param string $jobId The uuid.
	 *
	 * @return ObjectEntity|null The job, or null when absent.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function findJob(string $jobId): ?ObjectEntity {
		if ($jobId === '') {
			return null;
		}

		try {
			return $this->objectService->find(
				id: $jobId,
				register: self::REGISTER,
				schema: self::SCHEMA_JOB,
				_rbac: false,
				// Unscoped: exchange jobs are written by listeners and the runner without a session; read by id for them.
				_multitenancy: false
			);
		} catch (\Throwable $exception) {
			unset($exception);
			return null;
		}

	}//end findJob()

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
	public function saveJob(array $data, ?string $uuid = null): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_JOB,
			uuid: $uuid,
			_rbac: false,
			// Unscoped: system write from a listener or the runner, no user session.
			_multitenancy: false
		);

	}//end saveJob()

	/**
	 * Find a mapping row by slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return ObjectEntity|null The mapping, or null when absent.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-004-the-jobs-mapping-transforms-each-allowed-record
	 */
	public function findMapping(string $slug): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA_MAPPING, 'slug' => $slug],
				'limit' => 1,
			],
			_rbac: false,
			// Unscoped: an app's own exchange mapping, read for a listener or the runner without a session.
			_multitenancy: false
		);

		foreach (($matches['results'] ?? $matches) as $row) {
			if ($row instanceof ObjectEntity) {
				return $row;
			}
		}

		return null;

	}//end findMapping()

	/**
	 * Check a request against the vocabulary.
	 *
	 * @param ExchangeJobRequest $request The request.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null when valid.
	 */
	private function validate(ExchangeJobRequest $request): ?array {
		return ($this->routeRefusal(request: $request) ?? $this->mappingRefusal(request: $request));

	}//end validate()

	/**
	 * Check the owner, target and direction of a request.
	 *
	 * @param ExchangeJobRequest $request The request.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null when valid.
	 */
	private function routeRefusal(ExchangeJobRequest $request): ?array {
		if ($request->ownerApp === '') {
			return ['code' => 'owner-missing', 'reason' => 'The request names no owning app.'];
		}

		if ($this->targets->has(target: $request->target) === false) {
			return ['code' => 'target-unknown', 'reason' => sprintf('Integriq knows no exchange target "%s".', $request->target)];
		}

		if ($this->targets->supportsDirection(target: $request->target, direction: $request->direction) === false) {
			return [
				'code' => 'direction-unsupported',
				'reason' => sprintf('The exchange target "%s" does not support direction "%s".', $request->target, $request->direction),
			];
		}

		return null;

	}//end routeRefusal()

	/**
	 * Check that the mapping a request names exists.
	 *
	 * @param ExchangeJobRequest $request The request.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null when valid.
	 */
	private function mappingRefusal(ExchangeJobRequest $request): ?array {
		$slug = $request->mappingSlug;
		if ($slug === null || $slug === '' || $this->findMapping(slug: $slug) !== null) {
			return null;
		}

		return ['code' => 'mapping-missing', 'reason' => sprintf('No mapping "%s" exists in integriq.', $slug)];

	}//end mappingRefusal()

	/**
	 * Build the data of a new exchange job.
	 *
	 * @param ExchangeJobRequest $request The request.
	 *
	 * @return array<string,mixed> The job data.
	 */
	private function buildJob(ExchangeJobRequest $request): array {
		$job         = array_merge($this->jobDefinition(request: $request), $this->exchangeFields(request: $request));
		$mappingSlug = $this->resolveMapping(request: $request);
		if ($mappingSlug !== null) {
			$job['exchangeMapping'] = $mappingSlug;
		}

		return $job;

	}//end buildJob()

	/**
	 * The native job fields of a new exchange job: a single run of the
	 * exchange action, named after the target and direction by default.
	 *
	 * @param ExchangeJobRequest $request The request.
	 *
	 * @return array<string,mixed> The job fields.
	 */
	private function jobDefinition(ExchangeJobRequest $request): array {
		$name = $request->name;
		if ($name === '') {
			$name = $this->targets->label(target: $request->target) . ' ' . $request->direction;
		}

		return [
			'name' => $name,
			'description' => sprintf('Data exchange job of %s for target %s.', $request->ownerApp, $request->target),
			'jobClass' => ExchangeJobAction::class,
			'arguments' => [],
			'interval' => 0,
			'isEnabled' => true,
			'singleRun' => true,
		];

	}//end jobDefinition()

	/**
	 * The exchange fields of a new, queued exchange job.
	 *
	 * @param ExchangeJobRequest $request The request.
	 *
	 * @return array<string,mixed> The exchange fields.
	 */
	private function exchangeFields(ExchangeJobRequest $request): array {
		return [
			'exchangeTarget' => $request->target,
			'exchangeDirection' => $request->direction,
			'ownerApp' => $request->ownerApp,
			'ownerRef' => $request->ownerRef,
			'exchangeScope' => $request->scope,
			'exchangeStatus' => self::STATUS_QUEUED,
			'requestedBy' => $request->requestedBy,
			'requestedAt' => (new DateTime())->format('c'),
		];

	}//end exchangeFields()

	/**
	 * The mapping slug a job gets: the one its owner named, else the default.
	 *
	 * @param ExchangeJobRequest $request The request.
	 *
	 * @return string|null The mapping slug, or null when there is none.
	 */
	private function resolveMapping(ExchangeJobRequest $request): ?string {
		if ($request->mappingSlug === null || $request->mappingSlug === '') {
			return $this->defaultMapping(request: $request);
		}

		return $request->mappingSlug;

	}//end resolveMapping()

	/**
	 * The mapping row for a job whose owning app named none.
	 *
	 * A learniq `bron-rod` export with scope `berichtsoort: schooladvies`
	 * gets the school advice row; any other learniq `bron-rod` export gets
	 * the learner row.
	 *
	 * @param ExchangeJobRequest $request The request.
	 *
	 * @return string|null The mapping slug, or null when there is no default.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-default-mapping-for-a-schooladvies-job
	 */
	private function defaultMapping(ExchangeJobRequest $request): ?string {
		$byKind = (self::DEFAULT_MAPPINGS[$request->ownerApp][$request->target.':'.$request->direction] ?? null);
		if ($byKind === null) {
			return null;
		}

		$kind = $request->scope['berichtsoort'] ?? '';
		if (is_string($kind) === false) {
			$kind = '';
		}

		return ($byKind[$kind] ?? $byKind['*']);

	}//end defaultMapping()

	/**
	 * Fold a migrated job's history into its data: disabled, never runs.
	 *
	 * @param array<string,mixed> $job     The job data.
	 * @param array<string,mixed> $history The history.
	 *
	 * @return array<string,mixed> The job data.
	 */
	private function applyHistory(array $job, array $history): array {
		$legacyStatus = (string)($history['status'] ?? '');
		$status = (self::LEGACY_STATUS[$legacyStatus] ?? self::STATUS_QUEUED);

		$job['migratedFrom'] = (string)($history['legacyId'] ?? '');
		$job['exchangeStatus'] = $status;
		foreach (['requestedAt', 'startedAt', 'finishedAt'] as $field) {
			if (empty($history[$field]) === false) {
				$job[$field] = (string)$history[$field];
			}
		}

		if (is_array($history['result'] ?? null) === true) {
			$job['exchangeResult'] = $history['result'];
		}

		if (empty($history['errorMessage']) === false) {
			$job['exchangeError'] = (string)$history['errorMessage'];
		}

		// A job that was waiting (queued, pending review) stays runnable: its
		// gate decides again. A finished one is history and never runs.
		$job['isEnabled'] = ($status === self::STATUS_QUEUED);

		return $job;

	}//end applyHistory()

	/**
	 * Find a job already migrated from a legacy id.
	 *
	 * @param string $ownerApp The owning app.
	 * @param string $legacyId The legacy id.
	 *
	 * @return ObjectEntity|null The job, or null.
	 */
	private function findMigrated(string $ownerApp, string $legacyId): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_JOB,
					'ownerApp' => $ownerApp,
					'migratedFrom' => $legacyId,
				],
				'limit' => 1,
			],
			_rbac: false,
			// Unscoped: migration lookup run as the system, no user session.
			_multitenancy: false
		);

		foreach (($matches['results'] ?? $matches) as $row) {
			if ($row instanceof ObjectEntity) {
				return $row;
			}
		}

		return null;

	}//end findMigrated()
}//end class
