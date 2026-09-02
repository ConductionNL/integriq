<?php

/**
 * Unit tests for the shared-task mirror seam (hitl-on-shared-tasks): every
 * suspension mirrors one OpenRegister task, every decision closes it, and
 * a failing mirror never gates the approval flow.
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
 * @spec openspec/changes/hitl-on-shared-tasks/specs/hitl-on-shared-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\TaskService as ORTaskService;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the shared-task mirror seam.
 *
 * @spec openspec/changes/hitl-on-shared-tasks/specs/hitl-on-shared-tasks/spec.md
 */
class ApprovalServiceSharedTaskTest extends TestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var ORTaskService|MockObject
	 */
	private $taskService;

	/**
	 * @var LoggerInterface|MockObject
	 */
	private $logger;

	/**
	 * @var ApprovalService
	 */
	private ApprovalService $service;

	/**
	 * Every saveObject call's payload, in order.
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
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
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

		$this->service = new ApprovalService(
			$this->objectService,
			$userSession,
			$this->createMock(IGroupManager::class),
			$this->createMock(INotificationManager::class),
			$this->createMock(IURLGenerator::class),
			$this->logger,
			null,
			$this->taskService,
		);

	}//end setUp()

	/**
	 * A shared task entity carrying a uuid, via the real Entity accessors.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return Task
	 */
	private function task(string $uuid): Task {
		$task = new Task();
		$task->setUuid($uuid);

		return $task;
	}//end task()

	/**
	 * A resolved-enough approval_request entity.
	 *
	 * @param array $body The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $body): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('approval-1');
		$entity->setObject($body);

		return $entity;
	}//end entity()

	/**
	 * The deciding user.
	 *
	 * @return IUser|MockObject
	 */
	private function approver() {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}//end approver()

	/**
	 * A suspension creates ONE shared task through the trusted path,
	 * carrying group, expiry, behaviours and the record link, and writes
	 * the task uuid back onto the record.
	 *
	 * @return void
	 */
	public function testSuspendForSynchronizationMirrorsOneLinkedSharedTask(): void {
		$imported = null;
		$this->taskService->expects($this->once())->method('import')->willReturnCallback(
			function (array $data, ?string $actor) use (&$imported): Task {
				$imported = ['data' => $data, 'actor' => $actor];

				return $this->task('task-9');
			}
		);

		$this->service->suspendForSynchronization(
			synchronizationId: 'sync-1',
			approverGroup: 'woo-approvers',
			onReject: 'error',
			onTimeout: 'dead_letter',
			ttlSeconds: 3600,
		);

		$this->assertSame(['woo-approvers'], $imported['data']['candidateGroups']);
		$this->assertSame('dead_letter', $imported['data']['onTimeout']);
		$this->assertSame('error', $imported['data']['onReject']);
		$this->assertNotEmpty($imported['data']['expiresAt']);
		$this->assertSame('rita', $imported['data']['requester']);
		$this->assertSame('rita', $imported['actor']);
		$this->assertSame('approval-created', $imported['data']['metadata']['approvalRequestId']);
		$this->assertSame('integriq', $imported['data']['appId']);

		// Two record writes: the pending create, then the taskUuid link.
		$this->assertCount(2, $this->saved);
		$this->assertArrayNotHasKey('taskUuid', $this->saved[0]);
		$this->assertSame('task-9', $this->saved[1]['taskUuid']);

	}//end testSuspendForSynchronizationMirrorsOneLinkedSharedTask()

	/**
	 * A behaviour outside the shared vocabulary is NOT forwarded: the
	 * mirror carries no behaviour rather than a refused word.
	 *
	 * @return void
	 */
	public function testAnUnknownBehaviourStaysAppLocal(): void {
		$imported = null;
		$this->taskService->method('import')->willReturnCallback(
			function (array $data, ?string $actor) use (&$imported): Task {
				$imported = $data;

				return $this->task('task-9');
			}
		);

		$this->service->suspendForSynchronization(
			synchronizationId: 'sync-1',
			approverGroup: 'woo-approvers',
			onReject: 'explode',
			onTimeout: 'explode',
			ttlSeconds: 60,
		);

		$this->assertArrayNotHasKey('onTimeout', $imported);
		$this->assertArrayNotHasKey('onReject', $imported);

	}//end testAnUnknownBehaviourStaysAppLocal()

	/**
	 * A failing shared service never fails the suspension: the record is
	 * created pending, without a taskUuid, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAMirrorFailureNeverGatesTheSuspension(): void {
		$this->taskService->method('import')->willThrowException(new RuntimeException('peer app down'));
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$record = $this->service->suspendForSynchronization(
			synchronizationId: 'sync-1',
			approverGroup: 'woo-approvers',
			onReject: 'error',
			onTimeout: 'error',
			ttlSeconds: 60,
		);

		$this->assertSame('pending', $record->getObject()['status']);
		$this->assertCount(1, $this->saved, 'no link write happened');
		$this->assertArrayNotHasKey('taskUuid', $this->saved[0]);
		$this->assertNotEmpty(array_filter($warnings, static fn (string $m): bool => str_contains($m, 'could not mirror')));

	}//end testAMirrorFailureNeverGatesTheSuspension()

	/**
	 * An approval closes the mirror as approved, attributed to the
	 * deciding user.
	 *
	 * @return void
	 */
	public function testAnApprovalClosesTheMirrorAsApproved(): void {
		$this->taskService->expects($this->once())->method('applyTimerOutcome')
			->with(
				$this->equalTo('task-9'),
				$this->equalTo('transition:approved'),
				$this->equalTo('integriq:alice'),
				$this->stringContains('approved')
			)
			->willReturn($this->task('task-9'));

		$this->service->completeApproval(
			approvalRequest: $this->entity(['status' => 'pending', 'taskUuid' => 'task-9']),
			approver: $this->approver(),
			resumeResult: 'success',
		);

	}//end testAnApprovalClosesTheMirrorAsApproved()

	/**
	 * A dead-letter rejection routes the mirror the same way the record
	 * went.
	 *
	 * @return void
	 */
	public function testADeadLetterRejectionDeadLettersTheMirror(): void {
		$this->taskService->expects($this->once())->method('applyTimerOutcome')
			->with(
				$this->equalTo('task-9'),
				$this->equalTo('dead_letter'),
				$this->equalTo('integriq:alice'),
				$this->anything()
			)
			->willReturn($this->task('task-9'));

		$this->service->reject(
			approvalRequest: $this->entity(['status' => 'pending', 'onReject' => 'dead_letter', 'taskUuid' => 'task-9']),
			approver: $this->approver(),
			comment: 'niet akkoord',
		);

	}//end testADeadLetterRejectionDeadLettersTheMirror()

	/**
	 * A plain rejection closes the mirror as rejected.
	 *
	 * @return void
	 */
	public function testAPlainRejectionClosesTheMirrorAsRejected(): void {
		$this->taskService->expects($this->once())->method('applyTimerOutcome')
			->with(
				$this->equalTo('task-9'),
				$this->equalTo('transition:rejected'),
				$this->anything(),
				$this->anything()
			)
			->willReturn($this->task('task-9'));

		$this->service->reject(
			approvalRequest: $this->entity(['status' => 'pending', 'onReject' => 'error', 'taskUuid' => 'task-9']),
			approver: $this->approver(),
			comment: 'nee',
		);

	}//end testAPlainRejectionClosesTheMirrorAsRejected()

	/**
	 * A record without a mirror (pre-seam, or a failed mirror) decides
	 * without touching the shared service, and a failing close is
	 * swallowed.
	 *
	 * @return void
	 */
	public function testAMissingOrFailingMirrorNeverGatesTheDecision(): void {
		$this->taskService->expects($this->never())->method('applyTimerOutcome');
		$this->service->completeApproval(
			approvalRequest: $this->entity(['status' => 'pending']),
			approver: $this->approver(),
			resumeResult: 'success',
		);

		$this->setUp();
		$this->taskService->method('applyTimerOutcome')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('warning');
		$saved = $this->service->completeApproval(
			approvalRequest: $this->entity(['status' => 'pending', 'taskUuid' => 'task-9']),
			approver: $this->approver(),
			resumeResult: 'success',
		);
		$this->assertSame('approved', $saved->getObject()['status']);

	}//end testAMissingOrFailingMirrorNeverGatesTheDecision()
}//end class
