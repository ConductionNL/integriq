<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\FlowExecution;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * A flow run's steps by their order: the list index of a step order, and
 * where a run starts (the first step, or the step a resume names).
 *
 * @covers \OCA\Integriq\Service\Helper\FlowExecution
 * @uses   \OCA\Integriq\Service\Helper\FlowToken
 */
final class FlowExecutionTest extends TestCase {

	public function testItFindsAStepByItsOrderNotByItsPosition(): void {
		$execution = $this->execution(steps: [['order' => 30, 'id' => 'c'], ['order' => 10, 'id' => 'a'], ['order' => '20', 'id' => 'b']]);

		$this->assertSame(1, $execution->indexOf(order: 10));
		$this->assertSame(2, $execution->indexOf(order: 20));
		$this->assertSame(0, $execution->indexOf(order: 30));
		$this->assertNull($execution->indexOf(order: 40));
	}

	public function testTheLastStepWithAnOrderWins(): void {
		$execution = $this->execution(steps: [['order' => 1, 'id' => 'first'], ['order' => 1, 'id' => 'second']]);

		$this->assertSame(1, $execution->indexOf(order: 1));
	}

	public function testARunWithoutAStartOrderStartsAtTheFirstStep(): void {
		$this->assertSame(0, $this->execution(steps: [['order' => 5]])->startIndex(startOrder: null));
	}

	public function testARunWithoutStepsStartsAtZeroWhateverItNames(): void {
		$this->assertSame(0, $this->execution(steps: [])->startIndex(startOrder: 7));
	}

	public function testAResumeStartsAtTheNamedStepOrNowhere(): void {
		$execution = $this->execution(steps: [['order' => 5], ['order' => 9]]);

		$this->assertSame(1, $execution->startIndex(startOrder: 9));
		$this->assertNull($execution->startIndex(startOrder: 6));
	}

	public function testItKeepsTheRunItWasGiven(): void {
		$token = new FlowToken();
		$run   = new ObjectEntity();
		$steps = [['order' => 1]];

		$execution = new FlowExecution(steps: $steps, flowToken: $token, flowRun: $run);

		$this->assertSame($steps, $execution->steps);
		$this->assertSame($token, $execution->flowToken);
		$this->assertSame($run, $execution->flowRun);
	}

	/**
	 * @param array<int, array<string, mixed>> $steps
	 */
	private function execution(array $steps): FlowExecution {
		return new FlowExecution(steps: $steps, flowToken: new FlowToken(), flowRun: new ObjectEntity());
	}
}
