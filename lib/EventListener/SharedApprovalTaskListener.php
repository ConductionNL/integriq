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
 * as expired. Only the record's own mirror (its `taskUuid`) counts: any
 * signed-in user can create a task that claims to be Integriq's.
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
use OCA\OpenRegister\Db\Task;
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
	 * The outcomes OpenRegister counts as a rejection
	 * (`TaskState::REJECTING_OUTCOMES`): each one closes the mirror, so each
	 * one has to resolve the record.
	 *
	 * @var array<int, string>
	 */
	private const REJECTING_OUTCOMES = ['rejected', 'returned', 'declined', 'denied'];

	/**
	 * The actor prefix OpenRegister's timer sweep records as `completedBy`
	 * on a `skip` outcome. A Nextcloud uid cannot contain a colon.
	 *
	 * @var string
	 */
	private const TIMER_ACTOR_PREFIX = 'flow-timer:';

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
			if ($this->isPendingOn(record: $record, task: $task) === true) {
				$this->resolve(record: $record, task: $task);
			}
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
	 * A timer outcome expires the record only when the timer recorded it;
	 * the same outcome from a user is either a rejection OpenRegister
	 * rerouted (`onReject: dead_letter`) or decides nothing.
	 *
	 * @param ObjectEntity $record The pending approval_request.
	 * @param Task $task The terminal mirror.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
	 */
	private function resolve(ObjectEntity $record, Task $task): void {
		$outcome = (string)$task->getOutcome();
		$completedBy = (string)$task->getCompletedBy();
		$comment = trim((string)$task->getComment());

		$byTimer = ($completedBy === '' || str_starts_with($completedBy, self::TIMER_ACTOR_PREFIX) === true);
		if (in_array($outcome, self::TIMER_OUTCOMES, true) === true && $byTimer === true) {
			$this->expireWhenDue(record: $record);
			return;
		}

		$action = $this->decision(outcome: $outcome, byTimer: $byTimer);
		if ($action === null) {
			// A cancelled, terminated or otherwise ended mirror decides
			// nothing: the record stays pending for Integriq's own screen,
			// and the local sweep expires it once the mirror is closed.
			return;
		}

		$user = $this->authorizedCompleter(record: $record, completedBy: $completedBy, action: $action);
		if ($user === null) {
			return;
		}

		$this->approvalService->assertActionable(approvalRequest: $record);

		if ($action === 'approval.approve') {
			$this->decisionService->approve(approvalRequest: $record, user: $user, comment: $comment);
			return;
		}

		if ($comment === '') {
			$comment = $this->l10n->t('Rejected in the shared task inbox');
		}

		$this->decisionService->reject(approvalRequest: $record, user: $user, comment: $comment);

	}//end resolve()

	/**
	 * Whether the record is still pending and the task is its own mirror.
	 * A record no longer pending is left alone (idempotent: Integriq's own
	 * decision closed this mirror, or the record was resolved another way).
	 * appId and metadata come from the task body, so any signed-in user can
	 * forge them; only the `taskUuid` Integriq wrote back on the record
	 * identifies the mirror.
	 *
	 * @param ObjectEntity $record The approval_request.
	 * @param Task $task The terminal task.
	 *
	 * @return bool True when the task may resolve the record.
	 */
	private function isPendingOn(ObjectEntity $record, Task $task): bool {
		if (($record->getObject()['status'] ?? null) !== 'pending') {
			return false;
		}

		$taskUuid = (string)($record->getObject()['taskUuid'] ?? '');
		if ($taskUuid !== '' && (string)$task->getUuid() === $taskUuid) {
			return true;
		}

		$this->logger->warning(
			'SharedApprovalTaskListener: a task that is not the approval request\'s mirror was ignored',
			['taskUuid' => $task->getUuid(), 'approvalRequestId' => $record->getUuid()]
		);

		return false;

	}//end isPendingOn()

	/**
	 * Expire the record for a timer outcome, but only once its own expiry
	 * has passed, so the outcome is the real timeout and not one recorded
	 * early.
	 *
	 * @param ObjectEntity $record The pending approval_request.
	 *
	 * @return void
	 */
	private function expireWhenDue(ObjectEntity $record): void {
		$expiresAt = strtotime((string)($record->getObject()['expiresAt'] ?? ''));
		if ($expiresAt !== false && $expiresAt <= time()) {
			$this->approvalService->expireFromSharedTask(approvalRequest: $record);
		}

	}//end expireWhenDue()

	/**
	 * The action-matrix action a user's outcome asks for: approve, reject
	 * for every outcome OpenRegister counts as a rejection and for a
	 * rejection it rerouted to `dead_letter`, or null when the outcome
	 * decides nothing.
	 *
	 * @param string $outcome The task's recorded outcome.
	 * @param bool $byTimer Whether the timer, not a user, recorded it.
	 *
	 * @return string|null `approval.approve`, `approval.reject` or null.
	 */
	private function decision(string $outcome, bool $byTimer): ?string {
		if ($outcome === 'approved') {
			return 'approval.approve';
		}

		if (in_array($outcome, self::REJECTING_OUTCOMES, true) === true || ($outcome === 'dead_letter' && $byTimer === false)) {
			return 'approval.reject';
		}

		return null;

	}//end decision()

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
