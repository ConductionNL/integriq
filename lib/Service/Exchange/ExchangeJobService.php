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
use OCA\Integriq\Action\ExchangeJobAction;
use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
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
class ExchangeJobService {

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
		$refusal = $this->validate(
			ownerApp: $event->getOwnerApp(),
			target: $event->getTarget(),
			direction: $event->getDirection(),
			mappingSlug: $event->getMappingSlug()
		);
		if ($refusal !== null) {
			$event->refuse(code: $refusal['code'], reason: $refusal['reason']);
			return null;
		}

		$history = $event->getHistory();
		$legacyId = (string)($history['legacyId'] ?? '');
		if ($legacyId !== '') {
			$existing = $this->findMigrated(ownerApp: $event->getOwnerApp(), legacyId: $legacyId);
			if ($existing !== null) {
				$event->setJobId($existing->getUuid());
				return null;
			}
		}

		$job = $this->buildJob(
			ownerApp: $event->getOwnerApp(),
			target: $event->getTarget(),
			direction: $event->getDirection(),
			ownerRef: $event->getOwnerRef(),
			scope: $event->getScope(),
			mappingSlug: $event->getMappingSlug(),
			requestedBy: $event->getRequestedBy(),
			name: $event->getName()
		);
		if ($history !== null) {
			$job = $this->applyHistory(job: $job, history: $history);
		}

		try {
			$saved = $this->saveJob(data: $job);
		} catch (\Throwable $exception) {
			$this->logger->error('[ExchangeJobService] could not store an exchange job: ' . $exception->getMessage());
			$event->refuse(code: 'store-failed', reason: 'Integriq could not store the job: ' . $exception->getMessage());
			return null;
		}

		$event->setJobId($saved->getUuid());
		return $saved;

	}//end handleRequest()

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
		$scope = $original['exchangeScope'] ?? [];
		if (is_array($scope) === false) {
			$scope = [];
		}

		$scope['recordIds'] = [$recordId];

		$job = $this->buildJob(
			ownerApp: (string)($original['ownerApp'] ?? ''),
			target: (string)($original['exchangeTarget'] ?? ''),
			direction: (string)($original['exchangeDirection'] ?? ''),
			ownerRef: $ownerRef,
			scope: $scope,
			mappingSlug: ($original['exchangeMapping'] ?? null),
			requestedBy: $actor,
			name: 'Resubmission: ' . (string)($original['name'] ?? '')
		);
		$job['resubmissionOf'] = $rejectionId;

		return $this->saveJob(data: $job);

	}//end createResubmission()

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
	 * @param string      $ownerApp    The owning app.
	 * @param string      $target      The target.
	 * @param string      $direction   The direction.
	 * @param string|null $mappingSlug The mapping slug.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null when valid.
	 */
	private function validate(string $ownerApp, string $target, string $direction, ?string $mappingSlug): ?array {
		if ($ownerApp === '') {
			return ['code' => 'owner-missing', 'reason' => 'The request names no owning app.'];
		}

		if ($this->targets->has(target: $target) === false) {
			return ['code' => 'target-unknown', 'reason' => sprintf('Integriq knows no exchange target "%s".', $target)];
		}

		if ($this->targets->supportsDirection(target: $target, direction: $direction) === false) {
			return [
				'code' => 'direction-unsupported',
				'reason' => sprintf('The exchange target "%s" does not support direction "%s".', $target, $direction),
			];
		}

		if ($mappingSlug !== null && $mappingSlug !== '' && $this->findMapping(slug: $mappingSlug) === null) {
			return ['code' => 'mapping-missing', 'reason' => sprintf('No mapping "%s" exists in integriq.', $mappingSlug)];
		}

		return null;

	}//end validate()

	/**
	 * Build the data of a new exchange job.
	 *
	 * @param string              $ownerApp    The owning app.
	 * @param string              $target      The target.
	 * @param string              $direction   The direction.
	 * @param string              $ownerRef    The owner reference.
	 * @param array<string,mixed> $scope       The scope.
	 * @param mixed               $mappingSlug The mapping slug or null.
	 * @param string              $requestedBy The requester.
	 * @param string              $name        The label.
	 *
	 * @return array<string,mixed> The job data.
	 */
	private function buildJob(
		string $ownerApp,
		string $target,
		string $direction,
		string $ownerRef,
		array $scope,
		mixed $mappingSlug,
		string $requestedBy,
		string $name
	): array {
		if ($name === '') {
			$name = $this->targets->label(target: $target) . ' ' . $direction;
		}

		$job = [
			'name' => $name,
			'description' => sprintf('Data exchange job of %s for target %s.', $ownerApp, $target),
			'jobClass' => ExchangeJobAction::class,
			'arguments' => [],
			'interval' => 0,
			'isEnabled' => true,
			'singleRun' => true,
			'exchangeTarget' => $target,
			'exchangeDirection' => $direction,
			'ownerApp' => $ownerApp,
			'ownerRef' => $ownerRef,
			'exchangeScope' => $scope,
			'exchangeStatus' => self::STATUS_QUEUED,
			'requestedBy' => $requestedBy,
			'requestedAt' => (new DateTime())->format('c'),
		];
		if (is_string($mappingSlug) === false || $mappingSlug === '') {
			$mappingSlug = $this->defaultMapping(ownerApp: $ownerApp, target: $target, direction: $direction, scope: $scope);
		}

		if ($mappingSlug !== null) {
			$job['exchangeMapping'] = $mappingSlug;
		}

		return $job;

	}//end buildJob()

	/**
	 * The mapping row for a job whose owning app named none.
	 *
	 * A learniq `bron-rod` export with scope `berichtsoort: schooladvies`
	 * gets the school advice row; any other learniq `bron-rod` export gets
	 * the learner row.
	 *
	 * @param string              $ownerApp  The owning app.
	 * @param string              $target    The target.
	 * @param string              $direction The direction.
	 * @param array<string,mixed> $scope     The scope.
	 *
	 * @return string|null The mapping slug, or null when there is no default.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#scenario-default-mapping-for-a-schooladvies-job
	 */
	private function defaultMapping(string $ownerApp, string $target, string $direction, array $scope): ?string {
		$byKind = (self::DEFAULT_MAPPINGS[$ownerApp][$target.':'.$direction] ?? null);
		if ($byKind === null) {
			return null;
		}

		$kind = $scope['berichtsoort'] ?? '';
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
