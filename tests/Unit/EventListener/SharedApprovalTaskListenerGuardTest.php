<?php

/**
 * Characterisation tests for SharedApprovalTaskListener::handle()'s guards:
 * which events and tasks are ever looked at, and what a failing lookup logs.
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
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every guard of handle() (allowed and refused), the decision table of
 * user outcomes, and the warning a failed resolution logs.
 */
class SharedApprovalTaskListenerGuardTest extends TestCase {

	/**
	 * Every approval request looked up.
	 *
	 * @var list<string>
	 */
	private array $lookups = [];

	/**
	 * Every decision taken, as "approve"/"reject".
	 *
	 * @var list<string>
	 */
	private array $decisions = [];

	/**
	 * Every warning, as [message, context].
	 *
	 * @var list<array>
	 */
	private array $warnings = [];

	/**
	 * The listener over recording doubles; find() fails for "broken".
	 *
	 * @return SharedApprovalTaskListener
	 */
	private function listener(): SharedApprovalTaskListener {
		$record = new ObjectEntity();
		$record->setUuid('approval-1');
		$record->setObject(['status' => 'pending', 'taskUuid' => 'task-1']);
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('find')->willReturnCallback(
			function (string $id) use ($record): ObjectEntity {
				$this->lookups[] = $id;
				if ($id === 'broken') {
					throw new RuntimeException('store down');
				}

				return $record;
			}
		);
		$approvals->method('isAuthorizedApprover')->willReturn(true);
		$decisions = $this->createMock(ApprovalDecisionService::class);
		foreach (['approve', 'reject'] as $decision) {
			$decisions->method($decision)->willReturnCallback(
				function () use ($decision): ObjectEntity {
					$this->decisions[] = $decision;
					return new ObjectEntity();
				}
			);
		}

		$alice = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($alice);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new SharedApprovalTaskListener($approvals, $decisions, $this->createMock(ActionAuthService::class), $users, $l10n, $logger);

	}//end listener()

	/**
	 * A terminal task.
	 *
	 * @param mixed  $metadata The metadata.
	 * @param string $appId    The app id.
	 * @param string $outcome  The outcome.
	 *
	 * @return Task
	 */
	private function task(mixed $metadata, string $appId = 'integriq', string $outcome = 'approved'): Task {
		$task = new Task();
		$task->setUuid('task-1');
		$task->setAppId($appId);
		$task->setOutcome($outcome);
		$task->setCompletedBy('alice');
		$task->setMetadata($metadata);

		return $task;

	}//end task()

	/**
	 * Only a committed terminal event of an integriq approval mirror with a
	 * request id is looked up; every other shape is refused untouched.
	 *
	 * @return void
	 */
	public function testOnlyACommittedApprovalMirrorIsLookedUp(): void {
		$mirror = ['kind' => 'approval_request', 'approvalRequestId' => 'approval-1'];
		$listener = $this->listener();

		$listener->handle(new Event());
		$listener->handle(new TaskTerminalEvent($this->task($mirror), false));
		$listener->handle(new TaskTerminalEvent($this->task($mirror, appId: 'dossiq')));
		$listener->handle(new TaskTerminalEvent($this->task(null)));
		$listener->handle(new TaskTerminalEvent($this->task(['kind' => 'checklist', 'approvalRequestId' => 'approval-1'])));
		$listener->handle(new TaskTerminalEvent($this->task(['kind' => 'approval_request', 'approvalRequestId' => ''])));
		$listener->handle(new TaskTerminalEvent($this->task(['kind' => 'approval_request'])));
		$this->assertSame([], $this->lookups);

		$listener->handle(new TaskTerminalEvent($this->task($mirror)));
		$listener->handle(new TaskTerminalEvent($this->task(['kind' => 'approval_request', 'approvalRequestId' => 7])));
		$this->assertSame(['approval-1', '7'], $this->lookups);
		$this->assertSame(['approve', 'approve'], $this->decisions);

	}//end testOnlyACommittedApprovalMirrorIsLookedUp()

	/**
	 * A failing lookup is logged with the task and request ids and decides nothing.
	 *
	 * @return void
	 */
	public function testAFailedResolutionIsLogged(): void {
		$this->listener()->handle(new TaskTerminalEvent($this->task(['kind' => 'approval_request', 'approvalRequestId' => 'broken'])));

		$this->assertSame([], $this->decisions);
		$this->assertSame(
			[[
				'SharedApprovalTaskListener: the mirrored task could not resolve its approval request: store down',
				['taskUuid' => 'task-1', 'approvalRequestId' => 'broken'],
			]],
			$this->warnings
		);

	}//end testAFailedResolutionIsLogged()

	/**
	 * Which user outcomes approve, reject or decide nothing.
	 *
	 * @return void
	 */
	public function testTheUserOutcomeDecides(): void {
		$listener = $this->listener();
		foreach (['approved', 'Approved ', 'rejected', 'dead_letter', 'cancelled', 'completed'] as $outcome) {
			$listener->handle(new TaskTerminalEvent($this->task(['kind' => 'approval_request', 'approvalRequestId' => 'approval-1'], outcome: $outcome)));
		}

		$this->assertSame(['approve', 'approve', 'reject', 'reject'], $this->decisions);

	}//end testTheUserOutcomeDecides()

}//end class
