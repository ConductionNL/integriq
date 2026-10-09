<?php

/**
 * Integriq approval task mirror.
 *
 * The shared OpenRegister task that mirrors one approval_request
 * (hitl-on-shared-tasks): creating it on the trusted path, offering it to
 * the approver group so OpenRegister announces it, and closing it after a
 * decision. Every call is best effort: a failure is logged and the approval
 * flow, which stays the system of record, carries on (design D-5).
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
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-every-suspension-mirrors-one-shared-task
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Service\Task\TaskService as ORTaskService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates, offers and closes the shared task mirroring an approval request.
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-every-suspension-mirrors-one-shared-task
 */
class ApprovalTaskMirror {

	/**
	 * The expiry and rejection behaviours OpenRegister's task service
	 * understands; anything else stays an app-local behaviour.
	 *
	 * @var array<int, string>
	 */
	public const SHARED_BEHAVIOURS = ['skip', 'error', 'dead_letter'];

	/**
	 * Constructor.
	 *
	 * @param ORTaskService|null $taskService OpenRegister's shared task service; absent, nothing is mirrored.
	 * @param IL10N|null $l10n Translates the mirror's title and description (hitl-on-shared-tasks 2.4); absent, English.
	 * @param LoggerInterface $logger Logger for mirror failures.
	 */
	public function __construct(
		private readonly ?ORTaskService $taskService,
		private readonly ?IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether a behaviour travels with the mirror.
	 *
	 * @param string $behaviour An `onTimeout` or `onReject` value.
	 *
	 * @return bool True when OpenRegister understands it.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-the-shared-sweep-owns-the-mirrors-expiry
	 */
	public function isShared(string $behaviour): bool {
		return in_array($behaviour, self::SHARED_BEHAVIOURS, true);

	}//end isShared()

	/**
	 * Create the mirror for a pending approval_request on the trusted path
	 * (design D-1/D-2), acting as the requester when known.
	 *
	 * @param array $data The approval_request object data.
	 * @param string $approvalRequestId The record uuid the task links back to.
	 *
	 * @return string|null The task uuid, or null when nothing was mirrored.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-every-suspension-mirrors-one-shared-task
	 */
	public function create(array $data, string $approvalRequestId): ?string {
		if ($this->taskService === null) {
			return null;
		}

		$actor = (string)($data['requesterUserId'] ?? '');
		if ($actor === '') {
			$actor = 'integriq';
		}

		try {
			$task = $this->taskService->import(
				data: $this->payload(data: $data, approvalRequestId: $approvalRequestId),
				actor: $actor
			);

			return (string)$task->getUuid();
		} catch (Throwable $e) {
			$this->logger->warning(
				'ApprovalTaskMirror: could not mirror the approval into the shared task service: ' . $e->getMessage(),
				['approvalRequest' => $approvalRequestId]
			);

			return null;
		}

	}//end create()

	/**
	 * Offer the mirror to the approver group, by the requester, so
	 * OpenRegister's pool rule notifies the group (hitl-on-shared-tasks
	 * 2.3). Without a mirror, a group or a requester there is nothing to
	 * offer; a refused offer is logged and answered with false, so the
	 * caller notifies the group itself.
	 *
	 * @param array $data The approval_request object data, after mirroring.
	 *
	 * @return bool True when the shared service now announces the request.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-the-approver-group-is-notified-once
	 */
	public function offer(array $data): bool {
		$taskUuid = (string)($data['taskUuid'] ?? '');
		$group = (string)($data['approverGroup'] ?? '');
		$requester = (string)($data['requesterUserId'] ?? '');
		if ($this->taskService === null || $taskUuid === '' || $group === '' || $requester === '') {
			return false;
		}

		try {
			$this->taskService->offer(uuid: $taskUuid, pool: ['candidateGroups' => [$group]], actor: $requester);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ApprovalTaskMirror: the shared task service refused to offer the mirror; notifying the group directly: ' . $e->getMessage(),
				['taskUuid' => $taskUuid]
			);

			return false;
		}

		return true;

	}//end offer()

	/**
	 * Close the mirror after a decision resolved the record (design D-4),
	 * through the shared outcome path: the decision was already authorized
	 * by Integriq's own two-layer model, and the mirror has no assignee for
	 * a completion check to pass. A missing mirror and one the shared sweep
	 * already closed are both fine.
	 *
	 * @param array $data The resolved approval_request object data.
	 * @param string $outcome The shared outcome (`transition:approved`, `transition:rejected` or `dead_letter`).
	 * @param string $actorUid The deciding user's uid, recorded as the source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-closes-the-mirrored-task
	 */
	public function close(array $data, string $outcome, string $actorUid): void {
		$taskUuid = (string)($data['taskUuid'] ?? '');
		if ($this->taskService === null || $taskUuid === '') {
			return;
		}

		try {
			$this->taskService->applyTimerOutcome(
				uuid: $taskUuid,
				outcome: $outcome,
				source: 'integriq:' . $actorUid,
				reason: sprintf("Approval request resolved as '%s'.", (string)($data['status'] ?? ''))
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ApprovalTaskMirror: could not close the mirrored shared task: ' . $e->getMessage(),
				['taskUuid' => $taskUuid]
			);
		}

	}//end close()

	/**
	 * The task payload a pending approval_request mirrors to.
	 * `onTimeout`/`onReject` travel only when they are in the shared
	 * vocabulary; anything else stays app-local and the mirror carries none.
	 *
	 * @param array $data The approval_request object data.
	 * @param string $approvalRequestId The record uuid the task links back to.
	 *
	 * @return array<string, mixed> The task creation payload.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-every-suspension-mirrors-one-shared-task
	 */
	private function payload(array $data, string $approvalRequestId): array {
		$payload = [
			'state' => 'enabled',
			'title' => ($this->l10n?->t('Approval request') ?? 'Approval request'),
			'description' => ($this->l10n?->t('Approve or reject this request in Integriq. Your decision resumes the suspended run.')
				?? 'Approve or reject this request in Integriq. Your decision resumes the suspended run.'),
			'performerType' => 'user',
			'appId' => 'integriq',
			'metadata' => [
				'kind' => 'approval_request',
				'approvalRequestId' => $approvalRequestId,
			],
		];

		if ((string)($data['approverGroup'] ?? '') !== '') {
			$payload['candidateGroups'] = [(string)$data['approverGroup']];
		}

		if ((string)($data['requesterUserId'] ?? '') !== '') {
			$payload['requester'] = (string)$data['requesterUserId'];
		}

		if ((string)($data['expiresAt'] ?? '') !== '') {
			$payload['expiresAt'] = (string)$data['expiresAt'];
			if ($this->isShared(behaviour: (string)($data['onTimeout'] ?? '')) === true) {
				$payload['onTimeout'] = (string)$data['onTimeout'];
			}
		}

		if ($this->isShared(behaviour: (string)($data['onReject'] ?? '')) === true) {
			$payload['onReject'] = (string)$data['onReject'];
		}

		return $payload;

	}//end payload()
}//end class
