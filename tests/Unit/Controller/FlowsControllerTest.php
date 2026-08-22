<?php

/**
 * Unit tests for FlowsController — the manual "Run" trigger (REQ-007d).
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
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\FlowsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FlowsController::run().
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007
 */
class FlowsControllerTest extends TestCase {

	/**
	 * @var FlowRunnerService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $flowRunnerService;

	/**
	 * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var FlowsController
	 */
	private FlowsController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->flowRunnerService = $this->createMock(FlowRunnerService::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			static function (string $text, array $params = []) {
				return vsprintf($text, $params);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new FlowsController(
			'openconnector',
			$this->createMock(IRequest::class),
			$this->flowRunnerService,
			$this->actionAuth,
			$this->userSession,
			$l,
		);

	}//end setUp()

	/**
	 * TC-17: a manual run returns 200 with the resulting flow_run's status
	 * when the run completes.
	 *
	 * @return void
	 */
	public function testRunReturnsCompletedFlowRun(): void {
		$flow = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Test flow'], 'flow-1');
		$flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'completed', 'flowId' => 'flow-1'], 'flow-run-1');

		$this->flowRunnerService->method('findFlow')->with('flow-1')->willReturn($flow);
		$this->flowRunnerService->expects($this->once())
			->method('run')
			->with($this->identicalTo($flow), [], 'manual')
			->willReturn($flowRun);

		$response = $this->controller->run('flow-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('completed', $response->getData()['status']);

	}//end testRunReturnsCompletedFlowRun()

	/**
	 * A run ending `stopped` is surfaced as a 500 — the flow itself failed,
	 * distinct from a transport-level error.
	 *
	 * @return void
	 */
	public function testRunReturnsErrorStatusForStoppedFlowRun(): void {
		$flow = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Test flow'], 'flow-1');
		$flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'stopped', 'flowId' => 'flow-1'], 'flow-run-1');

		$this->flowRunnerService->method('findFlow')->willReturn($flow);
		$this->flowRunnerService->method('run')->willReturn($flowRun);

		$response = $this->controller->run('flow-1');

		$this->assertSame(500, $response->getStatus());

	}//end testRunReturnsErrorStatusForStoppedFlowRun()

	/**
	 * An unknown flow id returns 404.
	 *
	 * @return void
	 */
	public function testRunUnknownFlowReturns404(): void {
		$this->flowRunnerService->method('findFlow')->willThrowException(new DoesNotExistException('not found'));

		$response = $this->controller->run('missing-flow');

		$this->assertSame(404, $response->getStatus());

	}//end testRunUnknownFlowReturns404()

	/**
	 * A caller outside the `flow.run` action matrix is denied with 403 —
	 * ADR-023 action authorization, matching JobsController's own
	 * `requireAction()` gate.
	 *
	 * @return void
	 */
	public function testRunDeniedByActionMatrixReturns403(): void {
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));
		$this->flowRunnerService->expects($this->never())->method('run');

		$response = $this->controller->run('flow-1');

		$this->assertSame(403, $response->getStatus());

	}//end testRunDeniedByActionMatrixReturns403()
}//end class
