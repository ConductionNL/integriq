<?php

/**
 * Integriq shared approval task listener.
 *
 * Resolves an approval_request from its mirrored OpenRegister task
 * (hitl-on-shared-tasks 2.1 and 2.2). A member of the approver group can
 * approve or reject in the shared task inbox; the decision then resumes or
 * stops the suspended run through the same decision service Integriq's own
 * Pending Approvals screen uses, behind the same two authorization layers.
 * When OpenRegister's timer sweep closes the mirror, the record is resolved
 * as expired.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\ApprovalDecisionService;
use OCA\Integriq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a terminal mirror task into a decision on its approval_request.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
 */
class SharedApprovalTaskListener implements IEventListener {

	/**
	 * The outcomes OpenRegister's timer sweep records for the shared
	 * behaviours skip, error and dead_letter.
	 *
	 * @var array<int, string>
	 */
	private const TIMER_OUTCOMES = ['skipped', 'failed', 'dead_letter'];

	/**
	 * Constructor.
	 *
	 * @param ApprovalService $approvalService Finds, authorizes and expires approval_request records.
	 * @param ApprovalDecisionService $decisionService Resumes or stops the suspended run.
	 * @param ActionAuthService $actionAuth The action matrix, the first authorization layer.
	 * @param IUserManager $userManager Resolves the completing user.
	 * @param IL10N $l10n Translates the fixed rejection reason.
	 * @param LoggerInterface $logger Logger for refused and failed decisions.
	 */
	public function __construct(
		private readonly ApprovalService $approvalService,
		private readonly ApprovalDecisionService $decisionService,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserManager $userManager,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a terminal task: only Integriq's committed approval mirrors.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
	 */
	public function handle(Event $event): void {
		if ($event instanceof TaskTerminalEvent === false || $event->isCommitted() === false) {
			return;
		}

		$task = $event->getTask();
		$metadata = $task->getMetadata();
		if ($task->getAppId() !== 'integriq'
			|| is_array($metadata) === false
			|| ($metadata['kind'] ?? null) !== 'approval_request'
			|| (string)($metadata['approvalRequestId'] ?? '') === ''
		) {
			return;
		}

		try {
			$record = $this->approvalService->find(id: (string)$metadata['approvalRequestId']);
			// Idempotent: Integriq's own decision closed this mirror, or the
			// record was already resolved another way.
			if (($record->getObject()['status'] ?? null) !== 'pending') {
				return;
			}

			$this->resolve(
				record: $record,
				outcome: (string)$task->getOutcome(),
				completedBy: (string)$task->getCompletedBy(),
				comment: trim((string)$task->getComment())
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SharedApprovalTaskListener: the mirrored task could not resolve its approval request: ' . $e->getMessage(),
				['taskUuid' => $task->getUuid(), 'approvalRequestId' => $metadata['approvalRequestId']]
			);
		}

	}//end handle()

	/**
	 * Map the task's outcome onto the record.
	 *
	 * @param ObjectEntity $record The pending approval_request.
	 * @param string $outcome The task's recorded outcome.
	 * @param string $completedBy Who completed the task.
	 * @param string $comment The completion comment, trimmed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
	 */
	private function resolve(ObjectEntity $record, string $outcome, string $completedBy, string $comment): void {
		if (in_array($outcome, self::TIMER_OUTCOMES, true) === true) {
			$this->approvalService->expireFromSharedTask(approvalRequest: $record);
			return;
		}

		if ($outcome !== 'approved' && $outcome !== 'rejected') {
			// A cancelled or otherwise ended mirror decides nothing: the
			// record stays pending for Integriq's own screen and sweep.
			return;
		}

		$action = 'approval.reject';
		if ($outcome === 'approved') {
			$action = 'approval.approve';
		}

		$user = $this->authorizedCompleter(record: $record, completedBy: $completedBy, action: $action);
		if ($user === null) {
			return;
		}

		$this->approvalService->assertActionable(approvalRequest: $record);

		if ($outcome === 'approved') {
			$this->decisionService->approve(approvalRequest: $record, user: $user, comment: $comment);
			return;
		}

		if ($comment === '') {
			$comment = $this->l10n->t('Rejected in the shared task inbox');
		}

		$this->decisionService->reject(approvalRequest: $record, user: $user, comment: $comment);

	}//end resolve()

	/**
	 * The completing user, when both authorization layers admit them; null
	 * (logged) otherwise, so an unauthorized completion changes nothing.
	 *
	 * @param ObjectEntity $record The pending approval_request.
	 * @param string $completedBy The task's completedBy uid.
	 * @param string $action The action-matrix action to require.
	 *
	 * @return IUser|null The authorized user.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
	 */
	private function authorizedCompleter(ObjectEntity $record, string $completedBy, string $action): ?IUser {
		$user = null;
		if ($completedBy !== '') {
			$user = $this->userManager->get($completedBy);
		}

		if ($user === null) {
			$this->logger->warning(
				'SharedApprovalTaskListener: the mirrored task was completed by no known user; the approval request stays pending',
				['approvalRequestId' => $record->getUuid(), 'completedBy' => $completedBy]
			);
			return null;
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: $action);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SharedApprovalTaskListener: the action matrix refuses the completer; the approval request stays pending',
				['approvalRequestId' => $record->getUuid(), 'completedBy' => $completedBy, 'action' => $action]
			);
			return null;
		}

		if ($this->approvalService->isAuthorizedApprover(approvalRequest: $record, user: $user) === false) {
			$this->logger->warning(
				'SharedApprovalTaskListener: the completer is not in the approver group; the approval request stays pending',
				['approvalRequestId' => $record->getUuid(), 'completedBy' => $completedBy]
			);
			return null;
		}

		return $user;

	}//end authorizedCompleter()
}//end class
