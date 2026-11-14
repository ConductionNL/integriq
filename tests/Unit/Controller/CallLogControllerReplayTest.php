<?php

/**
 * Unit tests for CallLogController's preview, replay and fire actions.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\CallLogController;
use OCA\Integriq\Outbound\Call\CallRecorderInterface;
use OCA\Integriq\Outbound\Call\CallReplayServiceInterface;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Previewing, replaying and firing calls, and the call a reader cannot find.
 *
 * @covers \OCA\Integriq\Controller\CallLogController
 */
final class CallLogControllerReplayTest extends TestCase {

	/** @var ActionAuthServiceInterface&MockObject */
	private ActionAuthServiceInterface $actionAuth;

	/** @var CallRecorderInterface&MockObject */
	private CallRecorderInterface $recorder;

	/** @var CallReplayServiceInterface&MockObject */
	private CallReplayServiceInterface $replay;

	/** @var IUser|null */
	private ?IUser $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->recorder   = $this->createMock(CallRecorderInterface::class);
		$this->replay     = $this->createMock(CallReplayServiceInterface::class);
		$user             = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		$this->user = $user;
	}//end setUp()

	/**
	 * The controller for one request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return CallLogController
	 */
	private function controller(array $params = []): CallLogController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new CallLogController('integriq', $request, $session, $this->actionAuth, $this->recorder, $this->replay, $l);
	}//end controller()

	/**
	 * Every action refuses without a signed-in user, before any check or call.
	 *
	 * @return void
	 */
	public function testEveryActionRefusesWithoutAUser(): void {
		$this->user = null;
		$this->actionAuth->expects($this->never())->method('requireAction');
		$this->replay->expects($this->never())->method($this->anything());
		$controller = $this->controller(['target' => 'x']);

		foreach ([$controller->show('c-1'), $controller->preview('c-1'), $controller->replay('c-1'), $controller->fire()] as $response) {
			$this->assertSame(401, $response->getStatus());
			$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		}
	}//end testEveryActionRefusesWithoutAUser()

	/**
	 * Showing an unknown call answers 404.
	 *
	 * @return void
	 */
	public function testShowingAnUnknownCallIs404(): void {
		$this->recorder->method('read')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller()->show('nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'No such call.'], $response->getData());
	}//end testShowingAnUnknownCallIs404()

	/**
	 * A preview needs the read action and answers the replay service's preview.
	 *
	 * @return void
	 */
	public function testPreviewAnswersTheReplayPreview(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, CallLogController::ACTION_READ);
		$this->replay->expects($this->once())->method('preview')->with('c-1')->willReturn(['request' => ['url' => 'https://x']]);

		$response = $this->controller()->preview('c-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['request' => ['url' => 'https://x']], $response->getData());
	}//end testPreviewAnswersTheReplayPreview()

	/**
	 * A preview of an unknown call answers 404.
	 *
	 * @return void
	 */
	public function testPreviewOfAnUnknownCallIs404(): void {
		$this->replay->method('preview')->willThrowException(new DoesNotExistException('gone'));

		$this->assertSame(404, $this->controller()->preview('nope')->getStatus());
	}//end testPreviewOfAnUnknownCallIs404()

	/**
	 * Replaying one call by its id needs the replay action and passes the options as given.
	 *
	 * @return void
	 */
	public function testReplayOfOneCallPassesTheActorAndOptions(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, CallLogController::ACTION_REPLAY);
		$this->replay->expects($this->once())->method('replayAll')
			->with(['c-1'], 'ann', ['dryRun' => true, 'mappingVersion' => '2'])
			->willReturn(['replayed' => 1]);

		$response = $this->controller(['calls' => ['ignored'], 'dryRun' => true, 'mappingVersion' => '2'])->replay('c-1');

		$this->assertSame(['replayed' => 1], $response->getData());
	}//end testReplayOfOneCallPassesTheActorAndOptions()

	/**
	 * Replaying a batch reads the call ids from the request as strings; dryRun counts only when it is true.
	 *
	 * @return void
	 */
	public function testReplayOfABatchReadsTheCallsFromTheRequest(): void {
		$this->replay->expects($this->once())->method('replayAll')
			->with(['1', 'c-2'], 'ann', ['dryRun' => false, 'mappingVersion' => null])
			->willReturn(['replayed' => 2]);

		$response = $this->controller(['calls' => [1, 'c-2'], 'dryRun' => 'true'])->replay();

		$this->assertSame(['replayed' => 2], $response->getData());
	}//end testReplayOfABatchReadsTheCallsFromTheRequest()

	/**
	 * A replay without calls, or with calls that are not a list, answers 400.
	 *
	 * @return void
	 */
	public function testReplayWithoutCallsIs400(): void {
		$this->replay->expects($this->never())->method('replayAll');

		foreach ([[], ['calls' => []], ['calls' => 'c-1']] as $params) {
			$response = $this->controller($params)->replay();
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'Name the calls to replay.'], $response->getData());
		}
	}//end testReplayWithoutCallsIs400()

	/**
	 * A replay naming an unknown call answers 404.
	 *
	 * @return void
	 */
	public function testReplayOfAnUnknownCallIs404(): void {
		$this->replay->method('replayAll')->willThrowException(new DoesNotExistException('gone'));

		$this->assertSame(404, $this->controller()->replay('nope')->getStatus());
	}//end testReplayOfAnUnknownCallIs404()

	/**
	 * Firing a call needs the replay action and answers 201 with the result.
	 *
	 * @return void
	 */
	public function testFireSendsTheRequestToTheTarget(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, CallLogController::ACTION_REPLAY);
		$this->replay->expects($this->once())->method('fire')
			->with('source-1', ['method' => 'GET'], 'ann', true)
			->willReturn(['status' => 200]);

		$response = $this->controller(['target' => 'source-1', 'request' => ['method' => 'GET'], 'dryRun' => true])->fire();

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['status' => 200], $response->getData());
	}//end testFireSendsTheRequestToTheTarget()

	/**
	 * A request that is not a list is sent as an empty one, and dryRun stays off unless it is true.
	 *
	 * @return void
	 */
	public function testFireSendsAnEmptyRequestForANonList(): void {
		$this->replay->expects($this->once())->method('fire')
			->with('source-1', [], 'ann', false)
			->willReturn([]);

		$this->controller(['target' => 'source-1', 'request' => 'GET /', 'dryRun' => '1'])->fire();
	}//end testFireSendsAnEmptyRequestForANonList()

	/**
	 * Firing without a target answers 400.
	 *
	 * @return void
	 */
	public function testFireWithoutATargetIs400(): void {
		$this->replay->expects($this->never())->method('fire');

		foreach ([[], ['target' => '   ']] as $params) {
			$response = $this->controller($params)->fire();
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'Name the target to call.'], $response->getData());
		}
	}//end testFireWithoutATargetIs400()
}//end class
