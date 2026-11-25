<?php
/**
 * Unit tests for the rights route of the intake inbox.
 *
 * The inbox asks once which of its row actions the signed-in user may use:
 * the answer is the ADR-023 action gate's own `can()` for `intake.assign` and
 * `intake.reply`, so a reader without the right never sees the action.
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
use OCA\Integriq\Intake\IntakeChannelRegistryInterface;
use OCA\Integriq\Intake\IntakeReplyServiceInterface;
use OCA\Integriq\Intake\IntakeRoutingServiceInterface;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The rights route: the session, and the gate's answer per action.
 *
 * @covers \OCA\Integriq\Controller\IntakeChannelsController
 */
class IntakeChannelsControllerRightsTest extends TestCase {

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
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->actionAuth = $this->createMock(originalClassName: ActionAuthServiceInterface::class);
	}//end setUp()

	/**
	 * A handler granted assign but not reply is told exactly that.
	 *
	 * @return void
	 */
	public function testTheAnswerIsTheGatesAnswerPerAction(): void {
		$this->signedIn();
		$this->actionAuth->expects($this->exactly(count: 2))
			->method('can')
			->willReturnCallback(
				static fn (IUser $user, string $action): bool => $action === IntakeChannelsController::ACTION_ASSIGN
			);

		$response = $this->controller()->rights();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['assign' => true, 'reply' => false], actual: $response->getData());
	}//end testTheAnswerIsTheGatesAnswerPerAction()

	/**
	 * A reader with neither right sees neither action.
	 *
	 * @return void
	 */
	public function testAReaderWithoutTheRightsGetsNeither(): void {
		$this->signedIn();
		$this->actionAuth->method('can')->willReturn(false);

		$this->assertSame(expected: ['assign' => false, 'reply' => false], actual: $this->controller()->rights()->getData());
	}//end testAReaderWithoutTheRightsGetsNeither()

	/**
	 * Without a session the gate is not asked and the answer is 401.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheAnswerIsUnauthorized(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->actionAuth->expects($this->never())->method('can');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller()->rights()->getStatus());
	}//end testWithoutASessionTheAnswerIsUnauthorized()

	/**
	 * The controller under test.
	 *
	 * @return IntakeChannelsController
	 */
	private function controller(): IntakeChannelsController {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(argumentIndex: 0);

		return new IntakeChannelsController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $this->userSession,
			actionAuth: $this->actionAuth,
			registry: $this->createMock(originalClassName: IntakeChannelRegistryInterface::class),
			routingService: $this->createMock(originalClassName: IntakeRoutingServiceInterface::class),
			replyService: $this->createMock(originalClassName: IntakeReplyServiceInterface::class),
			gate: $this->createMock(originalClassName: WebhookGateInterface::class),
			orObjectService: ObjectServiceMockBuilder::make($this),
			l: $l10n,
		);
	}//end controller()

	/**
	 * Put a user in the session.
	 *
	 * @return void
	 */
	private function signedIn(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);
	}//end signedIn()
}//end class
