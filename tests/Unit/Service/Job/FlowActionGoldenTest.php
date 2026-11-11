<?php

/**
 * Golden tests for FlowAction::run.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Job;

use Exception;
use OCA\Integriq\Service\FlowRunnerServiceInterface;
use OCA\Integriq\Service\Job\FlowAction;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the whole job response — level, message and stack trace — of every path.
 */
class FlowActionGoldenTest extends TestCase {

	/**
	 * Run the action over a runner that answers as given.
	 *
	 * @param array            $argument The job argument.
	 * @param \Throwable|null  $findFail What findFlow throws.
	 * @param \Throwable|null  $runFail  What run throws.
	 * @param array            $runData  The flow run's object.
	 *
	 * @return array The response.
	 */
	private function respond(array $argument, ?\Throwable $findFail=null, ?\Throwable $runFail=null, array $runData=[]): array {
		$flow   = $this->createMock(ObjectEntity::class);
		$runner = $this->createMock(FlowRunnerServiceInterface::class);
		if ($findFail !== null) {
			$runner->method('findFlow')->willThrowException($findFail);
		} else {
			$runner->method('findFlow')->with('7')->willReturn($flow);
		}

		if ($runFail !== null) {
			$runner->method('run')->willThrowException($runFail);
		} else {
			$flowRun = $this->createMock(ObjectEntity::class);
			$flowRun->method('getObject')->willReturn($runData);
			$runner->method('run')->with($flow, [], 'cron')->willReturn($flowRun);
		}

		return (new FlowAction($runner))->run($argument);

	}//end respond()

	/**
	 * Every path's response.
	 *
	 * @return void
	 */
	public function testEveryResponse(): void {
		$start = ['Check for a valid flow ID', 'Getting flow: 7', 'Running flow'];
		$this->assertSame(
			['stackTrace' => ['Check for a valid flow ID', 'No flow ID provided'], 'message' => 'No flow ID provided', 'level' => 'ERROR'],
			$this->respond([])
		);
		$this->assertSame(
			['stackTrace' => ['Check for a valid flow ID', 'Getting flow: 7', 'Flow not found: 7'], 'message' => 'Flow not found: 7', 'level' => 'WARNING'],
			$this->respond(['flowId' => 7], new DoesNotExistException('x'))
		);
		$this->assertSame(
			['stackTrace' => array_merge($start, ['Failed to run flow: boom']), 'message' => 'Failed to run flow: boom', 'level' => 'ERROR'],
			$this->respond(['flowId' => '7'], null, new Exception('boom'))
		);

		$levels = ['completed' => 'SUCCESS', 'dead_letter' => 'WARNING', 'suspended' => 'WARNING', 'failed' => 'ERROR', 'stopped' => 'ERROR', 'running' => 'SUCCESS', '' => 'SUCCESS'];
		foreach ($levels as $status => $level) {
			$message = 'Flow run ended with status: '.$status;
			$this->assertSame(
				['stackTrace' => array_merge($start, [$message]), 'message' => $message, 'level' => $level],
				$this->respond(['flowId' => '7'], null, null, $status === '' ? [] : ['status' => $status]),
				'status '.$status
			);
		}

	}//end testEveryResponse()
}//end class
