<?php

/**
 * Unit tests for releasing a held message through SenderIdentityController (REQ-OSI-010).
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-message-that-fails-a-send-check-is-held-for-review
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SenderIdentityController;
use OCA\Integriq\Outbound\Identity\DomainAlignmentCheckerInterface;
use OCA\Integriq\Outbound\Identity\HeldMessageReleaseInterface;
use OCA\Integriq\Outbound\Identity\HoldQueueInterface;
use OCA\Integriq\Outbound\Identity\OptOutRegistryInterface;
use OCA\Integriq\Outbound\Identity\SenderIdentityServiceInterface;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenServiceInterface;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for SenderIdentityController::release().
 *
 * @covers \OCA\Integriq\Controller\SenderIdentityController
 */
class SenderIdentityReleaseTest extends TestCase {

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Action gate mock.
	 *
	 * @var ActionAuthServiceInterface&MockObject
	 */
	private ActionAuthServiceInterface&MockObject $actionAuth;

	/**
	 * Release service mock.
	 *
	 * @var HeldMessageReleaseInterface&MockObject
	 */
	private HeldMessageReleaseInterface&MockObject $release;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->release = $this->createMock(HeldMessageReleaseInterface::class);

	}//end setUp()

	/**
	 * The controller, with or without the release service.
	 *
	 * @param bool $withRelease Whether the release service is injected.
	 *
	 * @return SenderIdentityController The controller.
	 */
	private function controller(bool $withRelease = true): SenderIdentityController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new SenderIdentityController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->userSession,
			$this->actionAuth,
			$this->createMock(SenderIdentityServiceInterface::class),
			$this->createMock(DomainAlignmentCheckerInterface::class),
			$this->createMock(UnsubscribeTokenServiceInterface::class),
			$this->createMock(OptOutRegistryInterface::class),
			$this->createMock(HoldQueueInterface::class),
			$l,
			$this->createMock(IURLGenerator::class),
			($withRelease === true ? $this->release : null)
		);

	}//end controller()

	/**
	 * Sign anna in.
	 *
	 * @return IUser The user.
	 */
	private function signedIn(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$this->userSession->method('getUser')->willReturn($user);
		return $user;

	}//end signedIn()

	/**
	 * Nobody signed in releases nothing.
	 *
	 * @return void
	 */
	public function testAnonymousCannotRelease(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->release->expects($this->never())->method('release');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->release('msg-1')->getStatus());

	}//end testAnonymousCannotRelease()

	/**
	 * Without the send right the action gate refuses before anything is released.
	 *
	 * @return void
	 */
	public function testReleasingNeedsTheSendRight(): void {
		$this->signedIn();
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException("Action 'outbound.release' requires admin rights"));
		$this->release->expects($this->never())->method('release');

		$this->expectException(OCSForbiddenException::class);
		$this->controller()->release('msg-1');

	}//end testReleasingNeedsTheSendRight()

	/**
	 * A held message is released in the caller's name, under the release action.
	 *
	 * @return void
	 */
	public function testAHeldMessageIsReleasedInTheCallersName(): void {
		$user = $this->signedIn();
		$this->actionAuth->expects($this->once())->method('requireAction')->with($user, SenderIdentityController::ACTION_RELEASE);
		$answer = ['message' => 'msg-1', 'status' => 'released', 'retry' => ['retried' => ['a@example.org']]];
		$this->release->expects($this->once())->method('release')->with('msg-1', 'anna')->willReturn($answer);

		$response = $this->controller()->release('msg-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());

	}//end testAHeldMessageIsReleasedInTheCallersName()

	/**
	 * A message not held is a conflict with the reason; an unknown one is not found; no service is unavailable.
	 *
	 * @return void
	 */
	public function testRefusalsAnswerWithTheirStatus(): void {
		$this->signedIn();
		$this->release->method('release')->willReturnCallback(
			static function (string $uuid): array {
				if ($uuid === 'unknown') {
					throw new DoesNotExistException('No outbound message "unknown".');
				}

				throw new RuntimeException('Only a message held for review can be released.');
			}
		);

		$conflict = $this->controller()->release('msg-2');
		$this->assertSame(Http::STATUS_CONFLICT, $conflict->getStatus());
		$this->assertSame(['error' => 'Only a message held for review can be released.'], $conflict->getData());

		$missing = $this->controller()->release('unknown');
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['error' => 'No such message.'], $missing->getData());

		$unavailable = $this->controller(false)->release('msg-2');
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $unavailable->getStatus());

	}//end testRefusalsAnswerWithTheirStatus()

}//end class
