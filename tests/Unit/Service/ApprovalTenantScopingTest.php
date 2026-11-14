<?php

/**
 * Cross-tenant refusal tests for approval requests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Controller\ApprovalsController;
use OCA\Integriq\Exception\ApprovalStateException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ApprovalDecisionServiceInterface;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Tests\Helpers\ForeignOrganisation;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A user of organisation A asks for an approval request of organisation B:
 * the lookup answers 404 and the request is neither shown nor decided. A
 * system read (the flow node, the engine's gate) still reaches it.
 *
 * @covers \OCA\Integriq\Service\ApprovalService
 * @covers \OCA\Integriq\Controller\ApprovalsController
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 */
class ApprovalTenantScopingTest extends TestCase {

	/**
	 * The ObjectService, holding only organisation B's approval request.
	 *
	 * @var ORObjectService&MockObject
	 */
	private $objects;

	/**
	 * The session of a user of organisation A.
	 *
	 * @var IUserSession&MockObject
	 */
	private $session;

	/**
	 * Set up an ObjectService that holds only another organisation's request.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$foreign = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'pending', 'requesterUserId' => 'bob'],
			'uuid-b'
		);

		$this->objects = $this->createMock(originalClassName: ORObjectService::class);
		$this->objects->method('find')->willReturnCallback(ForeignOrganisation::find(object: $foreign));

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * The approval service over the foreign-only ObjectService.
	 *
	 * @return ApprovalService
	 */
	private function service(): ApprovalService {
		return new ApprovalService(
			objectService: $this->objects,
			userSession: $this->session,
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			notificationManager: $this->createMock(originalClassName: INotificationManager::class),
			urlGenerator: $this->createMock(originalClassName: IURLGenerator::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()

	/**
	 * Another organisation's request is not found by a user-facing lookup.
	 *
	 * @return void
	 */
	public function testARequestOfAnotherOrganisationIsNotFound(): void {
		try {
			$this->service()->find(id: 'uuid-b');
			$this->fail('A request of another organisation was found.');
		} catch (ApprovalStateException $e) {
			$this->assertSame(expected: 404, actual: $e->getHttpStatus());
		}
	}//end testARequestOfAnotherOrganisationIsNotFound()

	/**
	 * A system read still reaches the request, for the paths that run without a user.
	 *
	 * @return void
	 */
	public function testASystemReadStillReachesIt(): void {
		$this->assertSame(expected: 'uuid-b', actual: $this->service()->find(id: 'uuid-b', asSystem: true)->getUuid());
	}//end testASystemReadStillReachesIt()

	/**
	 * Showing, approving or rejecting another organisation's request answers 404 and decides nothing.
	 *
	 * @return void
	 */
	public function testTheControllerRefusesARequestOfAnotherOrganisation(): void {
		$decisions = $this->createMock(originalClassName: ApprovalDecisionServiceInterface::class);
		$decisions->expects($this->never())->method($this->anything());

		$l = $this->createMock(originalClassName: IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$controller = new ApprovalsController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			approvalService: $this->service(),
			decisionService: $decisions,
			actionAuth: $this->createMock(originalClassName: ActionAuthServiceInterface::class),
			userSession: $this->session,
			l: $l,
		);

		$this->assertSame(expected: 404, actual: $controller->show('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->approve('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->reject('uuid-b')->getStatus());
	}//end testTheControllerRefusesARequestOfAnotherOrganisation()

}//end class
