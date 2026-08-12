<?php

/**
 * Unit tests for FlowAction — the FlowRunnerService-backed cron Action.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Action;

use OCA\OpenConnector\Action\FlowAction;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FlowAction::run().
 *
 * @spec openspec/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003
 */
class FlowActionTest extends TestCase {

	/**
	 * @var FlowRunnerService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $flowRunnerService;

	/**
	 * @var FlowAction
	 */
	private FlowAction $action;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->flowRunnerService = $this->createMock(FlowRunnerService::class);
		$this->action = new FlowAction($this->flowRunnerService);

	}//end setUp()

	/**
	 * No `flowId` argument is an immediate ERROR, no FlowRunnerService call.
	 *
	 * @return void
	 */
	public function testRunWithoutFlowIdReturnsError(): void {
		$this->flowRunnerService->expects($this->never())->method('run');

		$result = $this->action->run([]);

		$this->assertSame('ERROR', $result['level']);
	}//end testRunWithoutFlowIdReturnsError()

	/**
	 * An unresolvable flowId is a WARNING (matches SynchronizationAction's
	 * "not found" precedent), not a fatal error.
	 *
	 * @return void
	 */
	public function testRunWithUnknownFlowIdReturnsWarning(): void {
		$this->flowRunnerService->method('findFlow')->willThrowException(new DoesNotExistException('not found'));

		$result = $this->action->run(['flowId' => 'missing-flow']);

		$this->assertSame('WARNING', $result['level']);
	}//end testRunWithUnknownFlowIdReturnsWarning()

	/**
	 * A `completed` flow run maps to job_log level SUCCESS and passes
	 * `triggerSource: 'cron'` through to FlowRunnerService::run() —
	 * job-management REQ-JOB-003 / TC-15.
	 *
	 * @return void
	 */
	public function testRunCompletedFlowMapsToSuccess(): void {
		$flow = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Test flow'], 'flow-1');
		$flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'completed'], 'flow-run-1');

		$this->flowRunnerService->method('findFlow')->with('flow-1')->willReturn($flow);
		$this->flowRunnerService->expects($this->once())
			->method('run')
			->with($this->identicalTo($flow), [], 'cron')
			->willReturn($flowRun);

		$result = $this->action->run(['flowId' => 'flow-1']);

		$this->assertSame('SUCCESS', $result['level']);
	}//end testRunCompletedFlowMapsToSuccess()

	/**
	 * A `dead_letter` flow run maps to job_log level WARNING —
	 * job-management REQ-JOB-003 / TC-25.
	 *
	 * @return void
	 */
	public function testRunDeadLetterFlowMapsToWarning(): void {
		$flow = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Test flow'], 'flow-1');
		$flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'dead_letter'], 'flow-run-1');

		$this->flowRunnerService->method('findFlow')->willReturn($flow);
		$this->flowRunnerService->method('run')->willReturn($flowRun);

		$result = $this->action->run(['flowId' => 'flow-1']);

		$this->assertSame('WARNING', $result['level']);
	}//end testRunDeadLetterFlowMapsToWarning()

	/**
	 * A `stopped`/`failed` flow run maps to job_log level ERROR —
	 * job-management REQ-JOB-003 / TC-25.
	 *
	 * @return void
	 */
	public function testRunStoppedFlowMapsToError(): void {
		$flow = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Test flow'], 'flow-1');
		$flowRun = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'stopped'], 'flow-run-1');

		$this->flowRunnerService->method('findFlow')->willReturn($flow);
		$this->flowRunnerService->method('run')->willReturn($flowRun);

		$result = $this->action->run(['flowId' => 'flow-1']);

		$this->assertSame('ERROR', $result['level']);
	}//end testRunStoppedFlowMapsToError()
}//end class
