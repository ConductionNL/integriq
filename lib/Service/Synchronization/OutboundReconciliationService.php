<?php

/**
 * Repairs drift between register objects and what an outbound target holds.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\SynchronizationLogServiceInterface;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Compares a register-to-api synchronization's objects with the target's own list.
 *
 * An outbound synchronization pushes on object events, so whatever happened
 * while integriq was off or the target was down never reaches the target. A
 * synchronization opts in to a repair run with `targetConfig.reconcile`:
 *
 * - `endpoint`: the target's list endpoint, read with the engine's own paging
 *   (`resultsPosition`, `paginationType` and the other source-fetch keys apply).
 * - `idPosition`: where a listed item carries its id, `id` by default.
 * - `match`: pairs `{target, source}` of dot paths, tried in order, that adopt
 *   an unclaimed target item for an object without one, so a first run
 *   against a populated target does not create duplicates.
 *
 * The run never deletes or deactivates anything at the target. When the read
 * of the target list is incomplete (a failed page, a rate limit) it writes
 * nothing at all, because a missing item may simply be on a page that was not
 * read, and says so in the run log. Target items nothing claims are listed in
 * the run log and left alone. Objects that fail the synchronization's
 * conditions are not pushed.
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 */
class OutboundReconciliationService {

	/**
	 * The index, match and conditions helpers.
	 *
	 * @var ReconciliationMatcher
	 */
	private readonly ReconciliationMatcher $matcher;

	/**
	 * Constructor.
	 *
	 * @param SynchronizationServiceInterface         $engine    The engine: target reads and contract pushes.
	 * @param SynchronizationContractServiceInterface $contracts The contract store.
	 * @param SynchronizationLogServiceInterface      $logs      The run log store.
	 * @param ObjectService                           $objects   The OpenRegister object service.
	 */
	public function __construct(
		private readonly SynchronizationServiceInterface $engine,
		private readonly SynchronizationContractServiceInterface $contracts,
		private readonly SynchronizationLogServiceInterface $logs,
		private readonly ObjectService $objects,
	) {
		$this->matcher = new ReconciliationMatcher();
	}//end __construct()

	/**
	 * Whether a synchronization takes part in reconciliation.
	 *
	 * @param array $synchronization The synchronization.
	 *
	 * @return bool True for a register-to-api synchronization with a `targetConfig.reconcile.endpoint`.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function isReconcilable(array $synchronization): bool {
		$endpoint = ($synchronization['targetConfig']['reconcile']['endpoint'] ?? null);

		return ($synchronization['sourceType'] ?? null) === 'register/schema'
			&& ($synchronization['targetType'] ?? null) === 'api'
			&& is_string($endpoint) === true
			&& $endpoint !== ''
			&& count(explode(separator: '/', string: (string)($synchronization['sourceId'] ?? ''))) === 2;
	}//end isReconcilable()

	/**
	 * The synchronizations that opted in to reconciliation.
	 *
	 * @return array<int, array> The reconcilable synchronizations, as arrays.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function findReconcilable(): array {
		$found = [];
		$filters = ['register' => 'integriq', 'schema' => 'synchronization', 'targetType' => 'api'];
		foreach ($this->findAll(filters: $filters) as $entity) {
			$synchronization = $this->toArray(entity: $entity);
			if ($this->isReconcilable(synchronization: $synchronization) === true) {
				$found[] = $synchronization;
			}
		}

		return $found;
	}//end findReconcilable()

	/**
	 * Reconcile one synchronization with its target.
	 *
	 * @param array $synchronization The synchronization; see the class doc for `targetConfig.reconcile`.
	 *
	 * @return array The report: `complete`, `created` (origin ids), `adopted` (origin id to target id),
	 *               `unmatched` (target ids), `failed` (origin id to reason) and `message`.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function reconcile(array $synchronization): array {
		$run = new ReconciliationRun(
			synchronization: $synchronization,
			log: $this->logs->createFromArray(object: ['synchronizationId' => ($synchronization['id'] ?? null), 'message' => 'Reconciliation'])
		);

		$this->readTarget(run: $run);
		if ($run->report['complete'] === false) {
			return $this->finish(run: $run);
		}

		$contracts = $this->contractsByOrigin(synchronization: $synchronization);
		foreach ($contracts as $contract) {
			if ($run->isListed(targetId: (string)($contract['targetId'] ?? '')) === true) {
				$run->claimed[(string)$contract['targetId']] = true;
			}
		}

		foreach ($this->sourceObjects(synchronization: $synchronization) as $uuid => $object) {
			$this->repair(run: $run, uuid: (string)$uuid, object: $object, contract: ($contracts[$uuid] ?? null));
		}

		$run->report['unmatched'] = $run->unclaimed();
		$run->report['message'] = sprintf(
			'Reconciled: %d created, %d adopted, %d failed, %d target item(s) without a match.',
			count($run->report['created']),
			count($run->report['adopted']),
			count($run->report['failed']),
			count($run->report['unmatched'])
		);

		return $this->finish(run: $run);
	}//end reconcile()

	/**
	 * Push one object the target lacks: adopt a matching item, or create it.
	 *
	 * @param ReconciliationRun $run      The run.
	 * @param string            $uuid     The object's uuid.
	 * @param array             $object   The serialized object.
	 * @param array|null        $contract Its contract, when it has one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function repair(ReconciliationRun $run, string $uuid, array $object, ?array $contract): void {
		$conditions = ($run->synchronization['conditions'] ?? []);
		if ($run->isListed(targetId: (string)($contract['targetId'] ?? '')) === true
			|| $this->matcher->conditionsHold(conditions: $conditions, data: $object) === false
		) {
			return;
		}

		$adopt = $this->matcher->findMatch(
			pairs: (array)($run->synchronization['targetConfig']['reconcile']['match'] ?? []),
			object: $object,
			items: $run->targetItems,
			claimed: $run->claimed
		);
		if ($adopt !== null) {
			$run->claimed[$adopt] = true;
		}

		$contract = ($contract ?? ['synchronizationId' => ($run->synchronization['id'] ?? null), 'originId' => $uuid]);
		$contract['targetId'] = $adopt;
		$failure = $this->push(run: $run, contract: $contract, object: $object);
		if ($failure !== null) {
			$run->report['failed'][$uuid] = $failure;
			return;
		}

		if ($adopt !== null) {
			$run->report['adopted'][$uuid] = $adopt;
			return;
		}

		$run->report['created'][] = $uuid;
	}//end repair()

	/**
	 * Read the target's list into the run, and whether every page was read.
	 *
	 * @param ReconciliationRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function readTarget(ReconciliationRun $run): void {
		$reconcile = $run->synchronization['targetConfig']['reconcile'];
		$read = [
			'id' => ($run->synchronization['id'] ?? null),
			'sourceId' => ($run->synchronization['targetId'] ?? null),
			'sourceType' => 'api',
			'sourceConfig' => array_diff_key($reconcile, ['match' => true, 'idPosition' => true]),
		];

		$fetchInfo = null;
		try {
			$items = $this->engine->getAllObjectsFromApi(synchronization: $read, fetchInfo: $fetchInfo);
		} catch (Throwable $exception) {
			$run->report['message'] = 'The read of the target was incomplete (' . $exception->getMessage() . '); nothing was written.';
			return;
		}

		$run->report['complete'] = (($fetchInfo['complete'] ?? false) === true);
		if ($run->report['complete'] === false) {
			$run->report['message'] = 'The read of the target was incomplete ('
				. (string)($fetchInfo['failureReason'] ?? 'unknown') . '); nothing was written.';
			return;
		}

		$run->targetItems = $this->matcher->indexById(items: $items, idPosition: (string)($reconcile['idPosition'] ?? 'id'));
	}//end readTarget()

	/**
	 * Push one contract through the engine, forced, and store the contract it answers.
	 *
	 * @param ReconciliationRun $run      The run.
	 * @param array             $contract The contract, with the target id to update or null to create.
	 * @param array             $object   The serialized object.
	 *
	 * @return string|null Null on success, otherwise why it failed.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function push(ReconciliationRun $run, array $contract, array $object): ?string {
		try {
			if (isset($contract['id']) === false) {
				$targetId = $contract['targetId'];
				$contract = $this->contracts->createFromArray(object: $contract);
				$contract['targetId'] = $targetId;
			}

			$flowToken = new FlowToken(syncInputOriginal: $object);
			$outcome = $this->engine->synchronizeContract(
				synchronizationContract: $contract,
				flowToken: $flowToken,
				synchronization: $run->synchronization,
				object: $object,
				force: true,
				log: $run->log
			);
			if (is_array($outcome) === false || is_array($outcome['contract'] ?? null) === false) {
				return 'the engine answered no contract';
			}

			$this->contracts->persist(contract: $outcome['contract'], ensureUuid: true);
		} catch (Throwable $exception) {
			return $exception->getMessage();
		}

		return null;
	}//end push()

	/**
	 * The synchronization's contracts, keyed by origin id.
	 *
	 * @param array $synchronization The synchronization.
	 *
	 * @return array<string, array> The contracts.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function contractsByOrigin(array $synchronization): array {
		$byOrigin = [];
		foreach ($this->contracts->findAllObjects(filters: ['synchronizationId' => ($synchronization['id'] ?? null)]) as $entity) {
			$contract = $this->toArray(entity: $entity);
			$origin = (string)($contract['originId'] ?? '');
			if ($origin !== '') {
				$byOrigin[$origin] = $contract;
			}
		}

		return $byOrigin;
	}//end contractsByOrigin()

	/**
	 * The register objects the synchronization pushes, keyed by uuid.
	 *
	 * @param array $synchronization The synchronization.
	 *
	 * @return array<string, array> The serialized objects, each with its `id`.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function sourceObjects(array $synchronization): array {
		[$register, $schema] = explode(separator: '/', string: (string)$synchronization['sourceId']);

		$objects = [];
		foreach ($this->findAll(filters: ['register' => $register, 'schema' => $schema]) as $entity) {
			$object = $this->toArray(entity: $entity);
			$uuid = (string)($object['id'] ?? '');
			if ($uuid !== '') {
				$objects[$uuid] = $object;
			}
		}

		return $objects;
	}//end sourceObjects()

	/**
	 * Every object matching the filters, across organisations: a system run.
	 *
	 * @param array $filters The filters, with register and schema.
	 *
	 * @return iterable The objects.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function findAll(array $filters): iterable {
		$result = $this->objects->findAll(config: ['filters' => $filters], _rbac: false, _multitenancy: false);

		return ($result['results'] ?? $result);
	}//end findAll()

	/**
	 * Write the report onto the run log, store it, and answer the report.
	 *
	 * A run log that cannot be stored does not undo what the run did; the report says so.
	 *
	 * @param ReconciliationRun $run The run.
	 *
	 * @return array The report.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function finish(ReconciliationRun $run): array {
		$run->log->setMessage(message: $run->report['message']);
		$run->log->setResult(result: ['reconciliation' => $run->report]);
		try {
			$this->logs->persist(log: $run->log);
		} catch (Throwable $exception) {
			$run->report['message'] .= ' The run log was not stored: ' . $exception->getMessage();
		}

		return $run->report;
	}//end finish()

	/**
	 * An OpenRegister object or a plain array as an array with its id.
	 *
	 * @param mixed $entity The object.
	 *
	 * @return array The array.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function toArray(mixed $entity): array {
		if (is_array($entity) === true) {
			return $entity;
		}

		$array = (array)$entity->jsonSerialize();
		$array['id'] = ($array['id'] ?? $entity->getUuid());

		return $array;
	}//end toArray()
}//end class
