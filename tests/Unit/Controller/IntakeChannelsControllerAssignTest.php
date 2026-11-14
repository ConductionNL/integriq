<?php
/**
 * Unit tests for the assign route of the intake inbox.
 *
 * A handler links a held message to a case: the route asks the ADR-023 action
 * gate for `intake.assign` before anything else, passes the picked case and
 * the signed-in handler on, and turns each refusal into its own status.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\IntakeChannelsController;
use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\IntakeAssignmentServiceInterface;
use OCA\Integriq\Intake\IntakeChannelRegistryInterface;
use OCA\Integriq\Intake\IntakeReplyServiceInterface;
use OCA\Integriq\Intake\IntakeRoutingServiceInterface;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The assign route: session, action right, the hand-on and the refusals.
 *
 * @covers \OCA\Integriq\Controller\IntakeChannelsController
 */
class IntakeChannelsControllerAssignTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The action gate double.
	 *
	 * @var ActionAuthServiceInterface&MockObject
	 */
	private ActionAuthServiceInterface $actionAuth;

	/**
	 * The assignment service double.
	 *
	 * @var IntakeAssignmentServiceInterface&MockObject
	 */
	private IntakeAssignmentServiceInterface $assignment;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->assignment = $this->createMock(IntakeAssignmentServiceInterface::class);
	}//end setUp()

	/**
	 * A handler with the right assigns: the picked case and the handler's own
	 * id are passed on, never an id from the request.
	 *
	 * @return void
	 */
	public function testAHandlerAssignsAMessageToTheCaseTheyPick(): void {
		$this->signedInAs(uid: 'behandelaar');
		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->isInstanceOf(IUser::class), IntakeChannelsController::ACTION_ASSIGN);
		$this->request->method('getParam')->willReturnMap([['caseRef', '', 'case-42']]);
		$answer = [
			'id' => 'msg-1',
			'status' => 'assigned',
			'targetRef' => 'case-42',
			'assignedBy' => 'behandelaar',
			'assignedAt' => '2026-11-14T09:30:00+00:00',
			'attachmentsLinked' => true,
		];
		$this->assignment->expects($this->once())
			->method('assign')
			->with('msg-1', ['caseRef' => 'case-42', 'assignedBy' => 'behandelaar'])
			->willReturn($answer);

		$response = $this->controller()->assign('msg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testAHandlerAssignsAMessageToTheCaseTheyPick()

	/**
	 * Nobody signed in assigns nothing, and the gate is not even asked.
	 *
	 * @return void
	 */
	public function testAnonymousCannotAssign(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->actionAuth->expects($this->never())->method('requireAction');
		$this->assignment->expects($this->never())->method('assign');

		$response = $this->controller()->assign('msg-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}//end testAnonymousCannotAssign()

	/**
	 * A user without the action right is refused before the message is touched.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheRightIsRefused(): void {
		$this->signedInAs(uid: 'lezer');
		$this->actionAuth->method('requireAction')
			->willThrowException(new OCSForbiddenException("Action 'intake.assign' requires admin rights"));
		$this->assignment->expects($this->never())->method('assign');

		$this->expectException(OCSForbiddenException::class);

		$this->controller()->assign('msg-1');
	}//end testAUserWithoutTheRightIsRefused()

	/**
	 * An unknown message is a 404, a refused assignment a 400, each with its reason.
	 *
	 * @return void
	 */
	public function testEachRefusalHasItsOwnStatus(): void {
		$this->signedInAs(uid: 'behandelaar');
		$this->assignment->method('assign')->willReturnOnConsecutiveCalls(
			$this->throwException(new IntakeChannelException('No inbound message "missing".')),
			$this->throwException(new IntakeRoutingException('Pick the case to assign the message to.'))
		);
		$controller = $this->controller();

		$missing = $controller->assign('missing');
		$noCase = $controller->assign('msg-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'No inbound message "missing".'], $missing->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $noCase->getStatus());
		$this->assertSame(['error' => 'Pick the case to assign the message to.'], $noCase->getData());
	}//end testEachRefusalHasItsOwnStatus()

	/**
	 * Without the assignment service the route says it is not available,
	 * after the right was still checked.
	 *
	 * @return void
	 */
	public function testWithoutTheServiceTheRouteIsUnavailable(): void {
		$this->signedInAs(uid: 'behandelaar');
		$this->actionAuth->expects($this->once())->method('requireAction');

		$response = $this->controller(withAssignment: false)->assign('msg-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'Assigning a message to a case is not available.'], $response->getData());
	}//end testWithoutTheServiceTheRouteIsUnavailable()

	/**
	 * The controller over the doubles.
	 *
	 * @param bool $withAssignment Whether the assignment service is wired.
	 *
	 * @return IntakeChannelsController
	 */
	private function controller(bool $withAssignment=true): IntakeChannelsController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$assignment = null;
		if ($withAssignment === true) {
			$assignment = $this->assignment;
		}

		return new IntakeChannelsController(
			appName: 'integriq',
			request: $this->request,
			userSession: $this->userSession,
			actionAuth: $this->actionAuth,
			registry: $this->createMock(IntakeChannelRegistryInterface::class),
			routingService: $this->createMock(IntakeRoutingServiceInterface::class),
			replyService: $this->createMock(IntakeReplyServiceInterface::class),
			gate: $this->createMock(WebhookGateInterface::class),
			orObjectService: ObjectServiceMockBuilder::make($this),
			l: $l10n,
			assignmentService: $assignment,
		);
	}//end controller()

	/**
	 * Put a user in the session.
	 *
	 * @param string $uid The account id.
	 *
	 * @return void
	 */
	private function signedInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedInAs()
}//end class
