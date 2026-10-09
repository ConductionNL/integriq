<?php

/**
 * Unit tests for the task-first half of hitl-on-shared-tasks (follow-ups
 * 2.1, 2.3 and 2.4): the mirror is offered to the approver pool so
 * OpenRegister notifies it, the imperative notification only runs when that
 * offer did not happen, the mirror's text is translated, and the app-local
 * sweep leaves mirrored rows to the shared sweep.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
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

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskService as ORTaskService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IAction;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the task-first mirror behaviour.
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md
 */
class ApprovalServiceTaskFirstTest extends TestCase {

	/**
	 * @var MockObject
	 */
	private $objectService;

	/**
	 * @var ORTaskService|MockObject
	 */
	private $taskService;

	/**
	 * @var INotificationManager|MockObject
	 */
	private $notifications;

	private ApprovalService $service;

	private SynchronizationApprovalGate $gate;

	/**
	 * Every saveObject payload, in order.
	 *
	 * @var array<int, array>
	 */
	private array $saved = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->taskService = $this->createMock(ORTaskService::class);
		$this->notifications = $this->createMock(INotificationManager::class);

		$this->saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('approval-created');
				$entity->setObject($object);

				return $entity;
			}
		);

		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('rita');
		$userSession->method('getUser')->willReturn($user);

		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('alice');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$member]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink', 'addAction'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setLink')->willReturnSelf();
		$notification->method('createAction')->willReturn($action);
		$this->notifications->method('createNotification')->willReturn($notification);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL: ' . $text);

		$this->service = new ApprovalService(
			$this->objectService,
			$userSession,
			$groups,
			$this->notifications,
			$this->createMock(IURLGenerator::class),
			$this->createMock(LoggerInterface::class),
			null,
			$this->taskService,
			$l10n,
		);

		$this->gate = new SynchronizationApprovalGate($this->objectService, $userSession, $this->service);

	}//end setUp()

	/**
	 * A task entity with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return Task
	 */
	private function task(string $uuid): Task {
		$task = new Task();
		$task->setUuid($uuid);

		return $task;
	}//end task()

	/**
	 * Suspend a synchronization behind the woo-approvers group.
	 *
	 * @return void
	 */
	private function suspend(): void {
		$this->gate->suspendForSynchronization(
			synchronizationId: 'sync-1',
			approverGroup: 'woo-approvers',
			onReject: 'error',
			onTimeout: 'dead_letter',
			ttlSeconds: 3600,
		);
	}//end suspend()

	/**
	 * The mirror is offered to the approver group by the requester, and the
	 * imperative notification does not run: OpenRegister's pool rule
	 * notifies the group instead.
	 *
	 * @return void
	 */
	public function testTheMirrorIsOfferedToThePoolAndTheImperativeNotificationIsSkipped(): void {
		$this->taskService->method('import')->willReturn($this->task('task-9'));
		$offered = null;
		$this->taskService->expects($this->once())->method('offer')->willReturnCallback(
			function (string $uuid, array $pool, ?string $actor) use (&$offered): Task {
				$offered = [$uuid, $pool, $actor];

				return $this->task($uuid);
			}
		);
		$this->notifications->expects($this->never())->method('notify');

		$this->suspend();

		$this->assertSame(['task-9', ['candidateGroups' => ['woo-approvers']], 'rita'], $offered);

	}//end testTheMirrorIsOfferedToThePoolAndTheImperativeNotificationIsSkipped()

	/**
	 * A refused offer falls back to the imperative notification, so the
	 * approvers are never left unnotified.
	 *
	 * @return void
	 */
	public function testARefusedOfferFallsBackToTheImperativeNotification(): void {
		$this->taskService->method('import')->willReturn($this->task('task-9'));
		$this->taskService->method('offer')->willThrowException(new RuntimeException('offer refused'));
		$this->notifications->expects($this->once())->method('notify');

		$this->suspend();

	}//end testARefusedOfferFallsBackToTheImperativeNotification()

	/**
	 * Without a mirror there is nothing to offer: the imperative
	 * notification runs.
	 *
	 * @return void
	 */
	public function testAFailedMirrorStillNotifiesImperatively(): void {
		$this->taskService->method('import')->willThrowException(new RuntimeException('peer app down'));
		$this->taskService->expects($this->never())->method('offer');
		$this->notifications->expects($this->once())->method('notify');

		$this->suspend();

	}//end testAFailedMirrorStillNotifiesImperatively()

	/**
	 * The mirror's title and description go through the translator.
	 *
	 * @return void
	 */
	public function testTheMirrorTextIsTranslated(): void {
		$imported = null;
		$this->taskService->method('import')->willReturnCallback(
			function (array $data, ?string $actor) use (&$imported): Task {
				$imported = $data;

				return $this->task('task-9');
			}
		);

		$this->suspend();

		$this->assertSame('NL: Approval request', $imported['title']);
		$this->assertStringStartsWith('NL: Approve or reject this request in Integriq.', $imported['description']);

	}//end testTheMirrorTextIsTranslated()

	/**
	 * The app-local sweep leaves a mirrored row whose expiry the shared
	 * sweep owns, and still expires an unmirrored row and a mirrored row
	 * whose behaviour stayed app-local.
	 *
	 * @return void
	 */
	public function testTheLocalSweepLeavesMirroredRowsToTheSharedSweep(): void {
		$past = (new \DateTime('-1 hour'))->format('c');
		$rows = [];
		foreach ([
			'mirrored' => ['taskUuid' => 'task-1', 'onTimeout' => 'error'],
			'unmirrored' => ['onTimeout' => 'error'],
			'local-behaviour' => ['taskUuid' => 'task-2', 'onTimeout' => 'explode'],
		] as $uuid => $extra) {
			$row = new ObjectEntity();
			$row->setUuid($uuid);
			$row->setObject(array_merge(['status' => 'pending', 'expiresAt' => $past, 'tag' => $uuid], $extra));
			$rows[] = $row;
		}

		$this->objectService->method('findAll')->willReturn(['results' => $rows]);

		$result = $this->service->sweepExpired();

		$this->assertSame(2, $result['swept']);
		$this->assertSame(['unmirrored', 'local-behaviour'], array_column($this->saved, 'tag'));

	}//end testTheLocalSweepLeavesMirroredRowsToTheSharedSweep()

	/**
	 * A mirror the shared sweep closed resolves the record the way the
	 * local sweep would: expired, or dead-lettered when onTimeout says so.
	 *
	 * @return void
	 */
	public function testExpireFromSharedTaskResolvesTheRecordLikeTheLocalSweep(): void {
		$record = new ObjectEntity();
		$record->setUuid('approval-1');
		$record->setObject(['status' => 'pending', 'onTimeout' => 'dead_letter', 'taskUuid' => 'task-1']);

		$saved = $this->service->expireFromSharedTask(approvalRequest: $record);

		$this->assertSame('dead_letter', $saved->getObject()['status']);

		$plain = new ObjectEntity();
		$plain->setUuid('approval-2');
		$plain->setObject(['status' => 'pending', 'onTimeout' => 'error', 'taskUuid' => 'task-2']);

		$this->assertSame('expired', $this->service->expireFromSharedTask(approvalRequest: $plain)->getObject()['status']);

	}//end testExpireFromSharedTaskResolvesTheRecordLikeTheLocalSweep()
}//end class
