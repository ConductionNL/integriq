<?php

/**
 * ApprovalService listing unit tests
 *
 * Who sees which approval requests: an administrator sees every request the
 * filter matches, anyone else only the requests addressed to one of their
 * groups and the ones they asked for themselves. The status filter is a comma
 * list. A request without an approver group notifies nobody and says so, and
 * can be decided only by an administrator; a resumed run's outcome is written
 * back onto the request, and an untraced snapshot rehydrates to no trace.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for listing approval requests and the edges around them.
 *
 * @covers \OCA\Integriq\Service\ApprovalService
 * @uses \OCA\Integriq\Service\ApprovalTaskMirror
 */
class ApprovalServiceListingTest extends TestCase {

	/** @var ORObjectService&MockObject */
	private ORObjectService $objects;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var INotificationManager&MockObject */
	private INotificationManager $notifications;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->objects = $this->createMock(ORObjectService::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$this->groups->method('getUserGroupIds')->willReturnCallback(
			static fn (IUser $user): array => ($user->getUID() === 'hanna' ? ['inkoop', 'staff'] : [])
		);
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	public function testAnAdministratorSeesEveryRequestTheFilterMatches(): void {
		$rows = [$this->request('r1', 'inkoop', 'piet'), $this->request('r2', 'juridisch', 'piet')];
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'integriq', 'schema' => 'approval_request', 'status' => ['pending', 'approved']], 'limit' => 500]
		)->willReturn(['results' => $rows]);

		$this->assertSame($rows, $this->service()->listFor(user: $this->user('admin'), statusFilter: 'pending,approved'));
	}//end testAnAdministratorSeesEveryRequestTheFilterMatches()

	public function testOthersSeeTheirGroupsRequestsAndTheirOwn(): void {
		$forInkoop = $this->request('r1', 'inkoop', 'piet');
		$elsewhere = $this->request('r2', 'juridisch', 'piet');
		$ownElsewhere = $this->request('r3', 'juridisch', 'hanna');
		$noGroup = $this->request('r4', null, 'piet');
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'integriq', 'schema' => 'approval_request'], 'limit' => 500]
		)->willReturn([$forInkoop, $elsewhere, $ownElsewhere, $noGroup]);

		$visible = $this->service()->listFor(user: $this->user('hanna'));

		$this->assertSame([$forInkoop, $ownElsewhere], $visible);
	}//end testOthersSeeTheirGroupsRequestsAndTheirOwn()

	public function testAnEmptyStatusFilterFiltersNothing(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'integriq', 'schema' => 'approval_request'], 'limit' => 500]
		)->willReturn(['results' => []]);

		$this->assertSame([], $this->service()->listFor(user: $this->user('piet'), statusFilter: ''));
	}//end testAnEmptyStatusFilterFiltersNothing()

	public function testARequestWithoutAnApproverGroupNotifiesNobody(): void {
		$this->notifications->expects($this->never())->method('notify');
		$this->notifications->expects($this->never())->method('createNotification');
		$this->logger->expects($this->once())->method('warning')->with(
			'ApprovalService: approval_request created with no approverGroup — no notification sent',
			['id' => 'r9']
		);

		$this->service()->notifyApprovers(approvalRequest: $this->request('r9', '', 'piet'));
	}//end testARequestWithoutAnApproverGroupNotifiesNobody()

	public function testAResumedRunsOutcomeIsWrittenBackOntoTheRequest(): void {
		$request = $this->request('r5', 'inkoop', 'piet');
		$stored = $this->request('r5', 'inkoop', 'piet');
		$this->objects->expects($this->once())->method('saveObject')->with(
			['approverGroup' => 'inkoop', 'requesterUserId' => 'piet', 'status' => 'approved', 'resumeResult' => 'superseded'],
			'integriq',
			'approval_request',
			'r5'
		)->willReturn($stored);

		$this->assertSame($stored, $this->service()->recordResumeResult(approvalRequest: $request, resumeResult: 'superseded'));
	}//end testAResumedRunsOutcomeIsWrittenBackOntoTheRequest()

	public function testAnUntracedSnapshotRehydratesToNoTrace(): void {
		$service = $this->service();

		$this->assertNull($service->rehydrateTraceContext(snapshot: []));
		$this->assertNull($service->rehydrateTraceContext(snapshot: ['traceId' => '']));
		$this->assertNull($service->rehydrateTraceContext(snapshot: ['traceId' => 42]));
	}//end testAnUntracedSnapshotRehydratesToNoTrace()

	public function testAnAdministratorMayDecideAndARequestWithoutAGroupHasNoOtherApprover(): void {
		$service = $this->service();
		$withoutGroup = $this->request('r6', '', 'piet');

		$this->assertTrue($service->isAuthorizedApprover(approvalRequest: $withoutGroup, user: $this->user('admin')));
		$this->assertFalse($service->isAuthorizedApprover(approvalRequest: $withoutGroup, user: $this->user('hanna')));
	}//end testAnAdministratorMayDecideAndARequestWithoutAGroupHasNoOtherApprover()

	/**
	 * An approval request.
	 *
	 * @param string      $uuid      The uuid.
	 * @param string|null $group     The approver group (null: absent).
	 * @param string      $requester Who asked.
	 *
	 * @return ObjectEntity
	 */
	private function request(string $uuid, ?string $group, string $requester): ObjectEntity {
		$data = ['approverGroup' => $group, 'requesterUserId' => $requester, 'status' => 'approved'];
		if ($group === null) {
			unset($data['approverGroup']);
		}

		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end request()

	/**
	 * A user.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * The service over the doubles.
	 *
	 * @return ApprovalService
	 */
	private function service(): ApprovalService {
		return new ApprovalService(
			$this->objects,
			$this->createMock(IUserSession::class),
			$this->groups,
			$this->notifications,
			$this->createMock(IURLGenerator::class),
			$this->logger,
		);
	}//end service()
}//end class
