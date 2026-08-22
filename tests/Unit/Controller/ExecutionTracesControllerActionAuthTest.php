<?php

/**
 * Unit tests for ExecutionTracesController's ADR-023 action-authorization guard.
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
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ExecutionTracesController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A trace carries the full ordered `steps` array of an integration run — every
 * request and response payload that crossed that connector — and `replay()`
 * with `force: true` re-executes it for real through the original entry
 * point's own dispatch path.
 *
 * Before this guard, all three endpoints were `#[NoAdminRequired]` with an
 * authentication check only, and the service read them with OpenRegister's
 * rbac and multitenancy switched off in source. Reproduced live on a
 * two-account rig: `ocidorb GET /api/execution-traces/{id}` → **200** with
 * `ocidora`'s payload.
 *
 * Every refusal below is paired with the permitted case over the SAME
 * collaborators, so none of them can pass by refusing everybody.
 */
class ExecutionTracesControllerActionAuthTest extends TestCase {

	/**
	 * @var ExecutionTraceService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $traceService;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->traceService = $this->createMock(ExecutionTraceService::class);

	}//end setUp()

	/**
	 * Construct the controller with a session user of the given privilege.
	 *
	 * Real ActionAuthService over a mocked IAppConfig / IGroupManager: an
	 * empty matrix is the shipped first-install posture and resolves every
	 * action to `["admin"]`, so the refusal comes from the default rather than
	 * from anything arranged here.
	 *
	 * @param boolean $isAdmin Whether the caller passes the admin break-glass.
	 *
	 * @return ExecutionTracesController
	 */
	private function controller(bool $isAdmin): ExecutionTracesController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tester');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{}');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		return new ExecutionTracesController(
			'openconnector',
			$this->createMock(IRequest::class),
			$this->traceService,
			$l,
			$userSession,
			new ActionAuthService($appConfig, $groupManager)
		);

	}//end controller()

	/**
	 * A trace standing in for somebody else's run.
	 *
	 * @return ObjectEntity|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function someoneElsesTrace() {
		$trace = $this->createMock(ObjectEntity::class);
		$trace->method('getObject')->willReturn(
			['id' => 'trace-1', 'steps' => [['name' => 'somebody elses payload']]]
		);
		return $trace;
	}//end someoneElsesTrace()

	/**
	 * show() refuses an unauthorized caller with 403 and never reads the trace.
	 *
	 * @return void
	 */
	public function testShowRefusesAnUnauthorizedCallerWithoutReading(): void {
		$this->traceService->expects($this->never())->method('find');

		$response = $this->controller(isAdmin: false)->show('trace-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testShowRefusesAnUnauthorizedCallerWithoutReading()

	/**
	 * The control: an authorized caller still gets the trace and its steps.
	 *
	 * @return void
	 */
	public function testShowStillServesAnAuthorizedCaller(): void {
		$this->traceService->method('find')->willReturn($this->someoneElsesTrace());

		$response = $this->controller(isAdmin: true)->show('trace-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('steps', $response->getData());

	}//end testShowStillServesAnAuthorizedCaller()

	/**
	 * index() refuses an unauthorized caller with 403 and never lists.
	 *
	 * @return void
	 */
	public function testIndexRefusesAnUnauthorizedCallerWithoutListing(): void {
		$this->traceService->expects($this->never())->method('list');

		$response = $this->controller(isAdmin: false)->index();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testIndexRefusesAnUnauthorizedCallerWithoutListing()

	/**
	 * The control: an authorized caller still gets the list.
	 *
	 * @return void
	 */
	public function testIndexStillListsForAnAuthorizedCaller(): void {
		$this->traceService->method('list')
			->willReturn(['results' => [$this->someoneElsesTrace()], 'total' => 1]);

		$response = $this->controller(isAdmin: true)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['pagination']['total']);

	}//end testIndexStillListsForAnAuthorizedCaller()

	/**
	 * replay() refuses an unauthorized caller with 403 and re-executes nothing.
	 *
	 * This is the write: `force: true` dispatches through the original entry
	 * point for real.
	 *
	 * @return void
	 */
	public function testReplayRefusesAnUnauthorizedCallerAndExecutesNothing(): void {
		$this->traceService->expects($this->never())->method('replay');

		$response = $this->controller(isAdmin: false)->replay('trace-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testReplayRefusesAnUnauthorizedCallerAndExecutesNothing()

	/**
	 * The control: an authorized caller still replays.
	 *
	 * @return void
	 */
	public function testReplayStillRunsForAnAuthorizedCaller(): void {
		$this->traceService->expects($this->once())->method('replay')
			->willReturn($this->someoneElsesTrace());

		$response = $this->controller(isAdmin: true)->replay('trace-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testReplayStillRunsForAnAuthorizedCaller()
}//end class
