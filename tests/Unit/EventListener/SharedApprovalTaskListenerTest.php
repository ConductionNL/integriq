<?php

/**
 * Unit tests for SharedApprovalTaskListener (hitl-on-shared-tasks 2.1 and
 * 2.2): a decision taken on the mirrored task in the shared inbox resolves
 * the approval_request through the same decision path Integriq's own
 * screen uses, with the same two authorization layers, and the shared
 * sweep's expiry resolves the record.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\SharedApprovalTaskListener;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\ApprovalDecisionService;
use OCA\Integriq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the task-first decision listener.
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md
 */
class SharedApprovalTaskListenerTest extends TestCase {

	/**
	 * @var ApprovalService|MockObject
	 */
	private $approvals;

	/**
	 * @var ApprovalDecisionService|MockObject
	 */
	private $decisions;

	/**
	 * @var ActionAuthService|MockObject
	 */
	private $actionAuth;

	/**
	 * @var IUserManager|MockObject
	 */
	private $users;

	private SharedApprovalTaskListener $listener;

	private ObjectEntity $record;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->approvals = $this->createMock(ApprovalService::class);
		$this->decisions = $this->createMock(ApprovalDecisionService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->users = $this->createMock(IUserManager::class);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$this->users->method('get')->willReturnCallback(static fn (string $uid) => $uid === 'alice' ? $alice : null);

		$this->record = new ObjectEntity();
		$this->record->setUuid('approval-1');
		$this->record->setObject(['status' => 'pending', 'approverGroup' => 'woo-approvers', 'taskUuid' => 'task-1']);
		$this->approvals->method('find')->willReturnCallback(fn (string $id) => $this->record);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->listener = new SharedApprovalTaskListener(
			$this->approvals,
			$this->decisions,
			$this->actionAuth,
			$this->users,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * A terminal mirror task, built through the real entity accessors.
	 *
	 * @param string $outcome The recorded outcome.
	 * @param string|null $completedBy Who completed it.
	 * @param string $appId The owning app.
	 * @param string|null $comment The completion comment.
	 *
	 * @return Task
	 */
	private function task(string $outcome, ?string $completedBy = 'alice', string $appId = 'integriq', ?string $comment = null): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setAppId($appId);
		$task->setOutcome($outcome);
		$task->setCompletedBy($completedBy);
		$task->setComment($comment);
		$task->setMetadata(['kind' => 'approval_request', 'approvalRequestId' => 'approval-1']);

		return $task;
	}//end task()

	/**
	 * Approved in the shared inbox by a member of the approver group: the
	 * run resumes through the decision service, attributed to that member.
	 *
	 * @return void
	 */
	public function testAnApprovalInTheSharedInboxResumesTheRun(): void {
		$this->approvals->method('isAuthorizedApprover')->willReturn(true);
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->anything(), 'approval.approve');
		$this->decisions->expects($this->once())->method('approve')
			->with($this->record, $this->callback(static fn (IUser $u) => $u->getUID() === 'alice'), 'looks right')
			->willReturn(new JSONResponse([]));

		$this->listener->handle(new TaskTerminalEvent($this->task('approved', comment: 'looks right')));

	}//end testAnApprovalInTheSharedInboxResumesTheRun()

	/**
	 * Rejected in the shared inbox: the record is rejected with the task's
	 * comment, and with a fixed reason when the task carries none.
	 *
	 * @return void
	 */
	public function testARejectionInTheSharedInboxCarriesTheCommentOrAFixedReason(): void {
		$this->approvals->method('isAuthorizedApprover')->willReturn(true);
		$comments = [];
		$this->decisions->expects($this->exactly(2))->method('reject')->willReturnCallback(
			function (ObjectEntity $record, IUser $user, string $comment) use (&$comments): ObjectEntity {
				$comments[] = $comment;

				return $record;
			}
		);

		$this->listener->handle(new TaskTerminalEvent($this->task('rejected', comment: 'wrong case')));
		$this->listener->handle(new TaskTerminalEvent($this->task('rejected')));

		$this->assertSame(['wrong case', 'Rejected in the shared task inbox'], $comments);

	}//end testARejectionInTheSharedInboxCarriesTheCommentOrAFixedReason()

	/**
	 * Fail closed: a completer outside the approver group, a completer the
	 * action matrix refuses, or a completer that is no user, changes
	 * nothing.
	 *
	 * @return void
	 */
	public function testAnUnauthorizedCompleterChangesNothing(): void {
		$this->approvals->method('isAuthorizedApprover')->willReturnOnConsecutiveCalls(false, true);
		$this->actionAuth->method('requireAction')->willReturnCallback(
			static function (IUser $user, string $action): void {
				static $calls = 0;
				$calls++;
				if ($calls === 2) {
					throw new OCSForbiddenException('not in the matrix');
				}
			}
		);
		$this->decisions->expects($this->never())->method('approve');

		$this->listener->handle(new TaskTerminalEvent($this->task('approved')));
		$this->listener->handle(new TaskTerminalEvent($this->task('approved')));
		$this->listener->handle(new TaskTerminalEvent($this->task('approved', completedBy: 'integriq:bob')));

	}//end testAnUnauthorizedCompleterChangesNothing()

	/**
	 * Idempotent: a record that is no longer pending (Integriq's own
	 * decision closed the mirror, or the local sweep ran) is left alone.
	 *
	 * @return void
	 */
	public function testARecordThatIsNoLongerPendingIsLeftAlone(): void {
		$this->record->setObject(['status' => 'approved', 'taskUuid' => 'task-1']);
		$this->decisions->expects($this->never())->method('approve');
		$this->approvals->expects($this->never())->method('expireFromSharedTask');

		$this->listener->handle(new TaskTerminalEvent($this->task('approved')));
		$this->listener->handle(new TaskTerminalEvent($this->task('dead_letter', completedBy: 'timer')));

	}//end testARecordThatIsNoLongerPendingIsLeftAlone()

	/**
	 * Another app's task, a task that is not an approval mirror, and the
	 * uncommitted in-transaction dispatch are all ignored.
	 *
	 * @return void
	 */
	public function testForeignAndUncommittedTasksAreIgnored(): void {
		$this->approvals->expects($this->never())->method('find');

		$this->listener->handle(new TaskTerminalEvent($this->task('approved', appId: 'dossiq')));
		$this->listener->handle(new TaskTerminalEvent($this->task('approved'), false));

		$plain = $this->task('approved');
		$plain->setMetadata(['kind' => 'checklist']);
		$this->listener->handle(new TaskTerminalEvent($plain));

	}//end testForeignAndUncommittedTasksAreIgnored()

	/**
	 * The shared sweep closed the mirror with its declared behaviour: the
	 * record is resolved as expired. A cancelled mirror decides nothing.
	 *
	 * @return void
	 */
	public function testTheSharedSweepsExpiryResolvesTheRecordAndACancelDecidesNothing(): void {
		$this->approvals->expects($this->exactly(3))->method('expireFromSharedTask')->with($this->record);
		$this->decisions->expects($this->never())->method('approve');
		$this->decisions->expects($this->never())->method('reject');

		foreach (['skipped', 'failed', 'dead_letter', 'cancelled'] as $outcome) {
			$this->listener->handle(new TaskTerminalEvent($this->task($outcome, completedBy: 'timer')));
		}

	}//end testTheSharedSweepsExpiryResolvesTheRecordAndACancelDecidesNothing()
}//end class
