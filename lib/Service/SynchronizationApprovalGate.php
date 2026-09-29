<?php

/**
 * Integriq Synchronization Approval Gate.
 *
 * The synchronization half of the human-in-the-loop approval workflow: the
 * `approval_request` that pauses a gated synchronization run, the lookup of
 * an approved request that has not authorised a run yet, and the two
 * outcome writes (consumed, superseded). The state machine, authorization,
 * notification and shared-task mirror stay in {@see ApprovalService}; this
 * class only writes the synchronization-specific shapes and hands a new
 * request to ApprovalService::announce().
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateInterval;
use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IUserSession;

/**
 * Persistence of the approval_request that gates a synchronization run.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
class SynchronizationApprovalGate {

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for approval_request persistence.
	 * @param IUserSession $userSession Resolves the requesting NC user.
	 * @param ApprovalService $approvalService Reads requests and announces a new one (shared-task mirror + approver notification).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly IUserSession $userSession,
		private readonly ApprovalService $approvalService,
	) {
	}//end __construct()

	/**
	 * Read one approval_request by id.
	 *
	 * @param string $id The approval_request uuid.
	 *
	 * @return ObjectEntity The request.
	 *
	 * @throws \OCA\Integriq\Exception\ApprovalStateException When it does not exist (404).
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function find(string $id): ObjectEntity {
		return $this->approvalService->find(id: $id);

	}//end find()

	/**
	 * Create the single `approval_request` gating a Synchronization batch
	 * run (synchronization-engine REQ-015). Unlike the endpoint-rule case
	 * there is no FlowToken snapshot to persist — resume re-runs
	 * `synchronize()` rather than replaying a payload (design.md Decision 6).
	 *
	 * @param string $synchronizationId The gated synchronization's id.
	 * @param string $approverGroup The configured approver group.
	 * @param string $onReject Outcome on reject.
	 * @param string $onTimeout Outcome on timeout.
	 * @param integer $ttlSeconds TTL in seconds before expiry.
	 * @param array $changeSet What the run would create, change and remove
	 *                         (ChangeSetBuilder::build()); stored as
	 *                         `snapshot.changeSet` with its `fingerprint`.
	 *
	 * @return ObjectEntity The created, `pending` approval_request.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
	 */
	public function suspendForSynchronization(
		string $synchronizationId,
		string $approverGroup,
		string $onReject,
		string $onTimeout,
		int $ttlSeconds,
		array $changeSet = [],
	): ObjectEntity {
		$now = new DateTime();
		$expiresAt = (clone $now)->add(new DateInterval('PT' . max($ttlSeconds, 1) . 'S'));

		$object = [
			'status' => 'pending',
			'synchronizationId' => $synchronizationId,
			'timing' => 'before',
			'snapshot' => [],
			'approverGroup' => $approverGroup,
			'onReject' => $onReject,
			'onTimeout' => $onTimeout,
			'createdAt' => $now->format('c'),
			'expiresAt' => $expiresAt->format('c'),
		];
		// A scheduled run has no session user; the register refuses a null
		// string, so the key is left out rather than written empty.
		$requesterUserId = $this->userSession->getUser()?->getUID();
		if ($requesterUserId !== null) {
			$object['requesterUserId'] = $requesterUserId;
		}

		if ($changeSet !== []) {
			$object['snapshot'] = ['changeSet' => $changeSet];
			$object['fingerprint'] = (string)($changeSet['fingerprint'] ?? '');
		}

		$record = $this->objectService->saveObject(
			object: $object,
			register: ApprovalService::REGISTER,
			schema: ApprovalService::SCHEMA
		);

		return $this->approvalService->announce(approvalRequest: $record);
	}//end suspendForSynchronization()

	/**
	 * Find an approved, not-yet-consumed approval_request for a
	 * synchronization (the batch-gate's "has this run already been
	 * approved" check).
	 *
	 * @param string $synchronizationId The synchronization id.
	 *
	 * @return ObjectEntity|null The approved, unconsumed request, or null.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function findApprovedUnconsumedForSynchronization(string $synchronizationId): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => ApprovalService::REGISTER,
					'schema' => ApprovalService::SCHEMA,
					'synchronizationId' => $synchronizationId,
					'status' => 'approved',
				],
				'limit' => 10,
			]
		);
		$results = ($matches['results'] ?? $matches);

		foreach ($results as $candidate) {
			$data = $candidate->getObject();
			if (empty($data['consumedAt']) === true) {
				return $candidate;
			}
		}

		return null;
	}//end findApprovedUnconsumedForSynchronization()

	/**
	 * Mark a Synchronization batch-gate approval_request consumed once its
	 * gated write phase has completed, so it cannot re-authorize a later run.
	 *
	 * @param ObjectEntity $approvalRequest The approved approval_request.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function markConsumed(ObjectEntity $approvalRequest): void {
		$data = $approvalRequest->getObject();
		$data['consumedAt'] = (new DateTime())->format('c');

		$this->objectService->saveObject(
			object: $data,
			register: ApprovalService::REGISTER,
			schema: ApprovalService::SCHEMA,
			uuid: $approvalRequest->getUuid()
		);

	}//end markConsumed()

	/**
	 * Close an approved request whose source changed after its preview.
	 *
	 * The request is consumed, so it can never authorize a later run, and
	 * names the new request that carries the new change set.
	 *
	 * @param ObjectEntity $approvalRequest The approved request whose fingerprint no longer matches.
	 * @param string $supersededBy The new request's id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-accepting-writes-the-previewed-change-set-or-asks-again-req-inav-004
	 */
	public function markSuperseded(ObjectEntity $approvalRequest, string $supersededBy): void {
		$data = $approvalRequest->getObject();
		$data['consumedAt'] = (new DateTime())->format('c');
		$data['resumeResult'] = 'superseded';
		$data['supersededBy'] = $supersededBy;

		$this->objectService->saveObject(
			object: $data,
			register: ApprovalService::REGISTER,
			schema: ApprovalService::SCHEMA,
			uuid: $approvalRequest->getUuid()
		);

	}//end markSuperseded()

}//end class
