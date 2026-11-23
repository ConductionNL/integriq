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

use Adbar\Dot;
use JWadhams\JsonLogic;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\SynchronizationLogServiceInterface;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
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
 *   an unclaimed target item for an object without a contract, so a first run
 *   against a populated target does not create duplicates.
 *
 * The run never deletes or deactivates anything at the target. When the read
 * of the target list is incomplete (a failed page, a rate limit) it writes
 * nothing at all, because a missing item may simply be on a page that was not
 * read, and says so in the run log. Target items no contract claims are listed
 * in the run log, not created, adopted or changed.
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 */
class OutboundReconciliationService {

	/**
	 * Constructor.
	 *
	 * @param SynchronizationServiceInterface         $synchronizationService The engine: target reads and contract pushes.
	 * @param SynchronizationContractServiceInterface $contractService        The contract store.
	 * @param SynchronizationLogServiceInterface      $logService             The run log store.
	 * @param ObjectService                           $objectService          The OpenRegister object service.
	 * @param LoggerInterface                         $logger                 The logger.
	 */
	public function __construct(
		private readonly SynchronizationServiceInterface $synchronizationService,
		private readonly SynchronizationContractServiceInterface $contractService,
		private readonly SynchronizationLogServiceInterface $logService,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
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
		$result = $this->objectService->findAll(
			config: ['filters' => ['register' => 'integriq', 'schema' => 'synchronization', 'targetType' => 'api']],
			_rbac: false,
			_multitenancy: false,
		);

		$synchronizations = [];
		foreach (($result['results'] ?? $result) as $entity) {
			$synchronization = $this->toArray(entity: $entity);
			if ($this->isReconcilable(synchronization: $synchronization) === true) {
				$synchronizations[] = $synchronization;
			}
		}

		return $synchronizations;
	}//end findReconcilable()

	/**
	 * Reconcile one synchronization with its target.
	 *
	 * @param array $synchronization The synchronization; see the class doc for `targetConfig.reconcile`.
	 *
	 * @return array{complete: bool, created: string[], adopted: array<string, string>, unmatched: string[], failed: string[], message: string}
	 *               What the run did: origin ids pushed as new, origin id to adopted target id, unclaimed target ids,
	 *               origin ids whose push failed.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function reconcile(array $synchronization): array {
		$report = ['complete' => false, 'created' => [], 'adopted' => [], 'unmatched' => [], 'failed' => [], 'message' => ''];
		$log = $this->logService->createFromArray(
			object: ['synchronizationId' => ($synchronization['id'] ?? null), 'message' => 'Reconciliation']
		);

		$targetItems = $this->readTarget(synchronization: $synchronization, complete: $report['complete']);
		if ($report['complete'] === false) {
			$report['message'] = 'The read of the target was incomplete; nothing was written.';
			return $this->finish(log: $log, report: $report);
		}

		$contracts = $this->contractsByOrigin(synchronization: $synchronization);
		$claimed = [];
		foreach ($contracts as $contract) {
			$targetId = (string)($contract['targetId'] ?? '');
			if ($targetId !== '' && isset($targetItems[$targetId]) === true) {
				$claimed[$targetId] = true;
			}
		}

		foreach ($this->sourceObjects(synchronization: $synchronization) as $uuid => $object) {
			$contract = ($contracts[$uuid] ?? null);
			$targetId = (string)($contract['targetId'] ?? '');
			if (($targetId !== '' && isset($targetItems[$targetId]) === true)
				|| $this->conditionsHold(conditions: ($synchronization['conditions'] ?? []), data: $object) === false
			) {
				continue;
			}

			$adopt = $this->findMatch(synchronization: $synchronization, object: $object, targetItems: $targetItems, claimed: $claimed);
			if ($adopt !== null) {
				$claimed[$adopt] = true;
			}

			$pushed = $this->push(synchronization: $synchronization, uuid: $uuid, object: $object, contract: $contract, targetId: $adopt, log: $log);
			if ($pushed === false) {
				$report['failed'][] = $uuid;
				continue;
			}

			if ($adopt !== null) {
				$report['adopted'][$uuid] = $adopt;
				continue;
			}

			$report['created'][] = $uuid;
		}//end foreach

		$report['unmatched'] = array_values(
			array_map('strval', array_keys(array_diff_key($targetItems, $claimed)))
		);
		$report['message'] = sprintf(
			'Reconciled: %d created, %d adopted, %d failed, %d target item(s) without a match.',
			count($report['created']),
			count($report['adopted']),
			count($report['failed']),
			count($report['unmatched'])
		);

		return $this->finish(log: $log, report: $report);
	}//end reconcile()

	/**
	 * Read the target's list, keyed by item id.
	 *
	 * @param array $synchronization The synchronization.
	 * @param bool  $complete        Set to whether every page was read.
	 *
	 * @return array<string, array> The listed items by id.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	private function readTarget(array $synchronization, bool &$complete): array {
		$reconcile = $synchronization['targetConfig']['reconcile'];
		$read = [
			'id' => ($synchronization['id'] ?? null),
			'sourceId' => ($synchronization['targetId'] ?? null),
			'sourceType' => 'api',
			'sourceConfig' => array_diff_key($reconcile, ['match' => true, 'idPosition' => true]),
		];

		$fetchInfo = null;
		try {
			$items = $this->synchronizationService->getAllObjectsFromApi(synchronization: $read, fetchInfo: $fetchInfo);
		} catch (Throwable $exception) {
			$this->logger->warning('[OutboundReconciliation] target read failed: ' . $exception->getMessage());
			$complete = false;
			return [];
		}

		$complete = (($fetchInfo['complete'] ?? false) === true);
		$idPosition = (string)($reconcile['idPosition'] ?? 'id');
		$byId = [];
		foreach ($items as $item) {
			$id = (new Dot((array)$item))->get($idPosition);
			if (is_scalar($id) === true && (string)$id !== '') {
				$byId[(string)$id] = (array)$item;
			}
		}

		return $byId;
	}//end readTarget()

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
		foreach ($this->contractService->findAllObjects(filters: ['synchronizationId' => ($synchronization['id'] ?? null)]) as $entity) {
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
	 * @return array<string, array> The serialized objects.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function sourceObjects(array $synchronization): array {
		[$register, $schema] = explode(separator: '/', string: (string)$synchronization['sourceId']);
		$result = $this->objectService->findAll(
			config: ['filters' => ['register' => $register, 'schema' => $schema]],
			_rbac: false,
			_multitenancy: false,
		);

		$objects = [];
		foreach (($result['results'] ?? $result) as $entity) {
			$uuid = (string)(is_object($entity) === true ? $entity->getUuid() : ($entity['id'] ?? ''));
			if ($uuid !== '') {
				$objects[$uuid] = $this->toArray(entity: $entity);
			}
		}

		return $objects;
	}//end sourceObjects()

	/**
	 * An unclaimed target item that matches the object on one of the `match` pairs.
	 *
	 * @param array                $synchronization The synchronization.
	 * @param array                $object          The serialized source object.
	 * @param array<string, array> $targetItems     The target items by id.
	 * @param array<string, bool>  $claimed         The target ids already claimed.
	 *
	 * @return string|null The target id to adopt, or null.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function findMatch(array $synchronization, array $object, array $targetItems, array $claimed): ?string {
		$source = new Dot($object);
		foreach ((array)($synchronization['targetConfig']['reconcile']['match'] ?? []) as $pair) {
			$value = $source->get((string)($pair['source'] ?? ''));
			if (is_scalar($value) === false || (string)$value === '') {
				continue;
			}

			foreach ($targetItems as $targetId => $item) {
				if (isset($claimed[$targetId]) === true) {
					continue;
				}

				$candidate = (new Dot($item))->get((string)($pair['target'] ?? ''));
				if (is_scalar($candidate) === true && (string)$candidate === (string)$value) {
					return (string)$targetId;
				}
			}
		}

		return null;
	}//end findMatch()

	/**
	 * Push one object through the engine, creating it or updating the adopted item.
	 *
	 * @param array                 $synchronization The synchronization.
	 * @param string                $uuid            The object's uuid.
	 * @param array                 $object          The serialized object.
	 * @param array|null            $contract        Its contract, when it has one.
	 * @param string|null           $targetId        The adopted target id; null pushes it as new.
	 * @param SynchronizationRunLog $log             The run log.
	 *
	 * @return bool True when the engine answered with a contract.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function push(array $synchronization, string $uuid, array $object, ?array $contract, ?string $targetId, SynchronizationRunLog $log): bool {
		try {
			if ($contract === null) {
				$contract = $this->contractService->createFromArray(
					object: ['synchronizationId' => ($synchronization['id'] ?? null), 'originId' => $uuid]
				);
			}

			$contract['targetId'] = $targetId;
			$flowToken = new FlowToken(syncInputOriginal: $object);
			$outcome = $this->synchronizationService->synchronizeContract(
				synchronizationContract: $contract,
				flowToken: $flowToken,
				synchronization: $synchronization,
				object: $object,
				force: true,
				log: $log
			);
		} catch (Throwable $exception) {
			$this->logger->warning('[OutboundReconciliation] push of ' . $uuid . ' failed: ' . $exception->getMessage());
			return false;
		}

		if (is_array($outcome) === false || is_array($outcome['contract'] ?? null) === false) {
			return false;
		}

		$this->contractService->persist(contract: $outcome['contract'], ensureUuid: true);

		return true;
	}//end push()

	/**
	 * Write the report onto the run log and store it.
	 *
	 * @param SynchronizationRunLog $log    The run log.
	 * @param array                 $report The report.
	 *
	 * @return array The report.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function finish(SynchronizationRunLog $log, array $report): array {
		$log->setMessage($report['message']);
		$log->setResult(['reconciliation' => $report]);
		try {
			$this->logService->persist(log: $log);
		} catch (Throwable $exception) {
			$this->logger->warning('[OutboundReconciliation] run log not stored: ' . $exception->getMessage());
		}

		return $report;
	}//end finish()

	/**
	 * Whether an object meets the synchronization's conditions, as the event push reads them.
	 *
	 * A list holds when every JsonLogic group in it holds; only a literal false fails a group.
	 * An object that does not meet them is not the synchronization's to push, so it is skipped.
	 *
	 * @param mixed $conditions The conditions: [] (none), a JsonLogic object, or a list of them.
	 * @param array $data       The serialized object.
	 *
	 * @return bool True when there are no conditions or every one holds.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function conditionsHold(mixed $conditions, array $data): bool {
		if ($conditions === [] || $conditions === null) {
			return true;
		}

		if (is_array($conditions) === true && array_is_list($conditions) === true) {
			foreach ($conditions as $group) {
				if ($this->conditionsHold(conditions: $group, data: $data) === false) {
					return false;
				}
			}

			return true;
		}

		return JsonLogic::apply($conditions, $data) !== false;
	}//end conditionsHold()

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
