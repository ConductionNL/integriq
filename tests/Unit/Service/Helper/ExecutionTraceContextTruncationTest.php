<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the step cap of the execution trace: past 500 retained steps a step
 * is counted by status instead of kept, and the step buffer ends in one
 * synthetic `truncated` entry that says how many were dropped.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
 */
#[CoversClass(ExecutionTraceContext::class)]
final class ExecutionTraceContextTruncationTest extends TestCase {

	/**
	 * Fill a context with the given number of steps of one status.
	 *
	 * @param ExecutionTraceContext $context The context.
	 * @param int                   $count   How many steps.
	 * @param string                $status  Their status.
	 *
	 * @return void
	 */
	private function addSteps(ExecutionTraceContext $context, int $count, string $status): void {
		for ($i = 0; $i < $count; $i++) {
			$context->addStep(
				type: 'synchronization',
				name: 'record-' . $i,
				timing: null,
				status: $status,
				input: ['record' => $i],
				output: ['record' => $i],
				startedAtMicrotime: 1000.0,
				finishedAtMicrotime: 1000.5,
			);
		}
	}//end addSteps()

	/**
	 * Exactly 500 steps are all kept and no truncated entry is added.
	 *
	 * @return void
	 */
	public function testFiveHundredStepsAreKeptWithoutATruncatedEntry(): void {
		$context = new ExecutionTraceContext(entryPoint: 'synchronization');
		$this->addSteps(context: $context, count: 500, status: 'success');

		$steps = $context->getSteps();

		$this->assertCount(500, $steps);
		$this->assertSame('record-499', $steps[499]['name']);
		$this->assertSame(500, $steps[499]['order']);
	}//end testFiveHundredStepsAreKeptWithoutATruncatedEntry()

	/**
	 * Steps past the cap are counted per status and reported in one final
	 * truncated entry, without their input or output.
	 *
	 * @return void
	 */
	public function testStepsPastTheCapAreCountedAndReportedInATruncatedEntry(): void {
		$context = new ExecutionTraceContext(entryPoint: 'synchronization');
		$this->addSteps(context: $context, count: 500, status: 'success');
		$this->addSteps(context: $context, count: 2, status: 'error');
		$this->addSteps(context: $context, count: 3, status: 'success');

		$steps = $context->getSteps();

		$this->assertCount(501, $steps);
		$this->assertSame('record-499', $steps[499]['name']);

		$last = $steps[500];
		$this->assertSame(501, $last['order']);
		$this->assertSame('synchronization', $last['type']);
		$this->assertSame('truncated', $last['name']);
		$this->assertSame('truncated', $last['status']);
		$this->assertNull($last['timing']);
		$this->assertSame(0, $last['durationMs']);
		$this->assertSame([], $last['input']);
		$this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $last['startedAt']));
		$this->assertSame(
			[
				'retainedSteps' => 500,
				'droppedSteps' => 5,
				'droppedByStatus' => ['error' => 2, 'success' => 3],
			],
			$last['output']
		);
	}//end testStepsPastTheCapAreCountedAndReportedInATruncatedEntry()

	/**
	 * Reading the steps twice does not add a second truncated entry.
	 *
	 * @return void
	 */
	public function testTheTruncatedEntryIsNotStoredInTheBuffer(): void {
		$context = new ExecutionTraceContext(entryPoint: 'synchronization');
		$this->addSteps(context: $context, count: 501, status: 'error');

		$context->getSteps();
		$steps = $context->getSteps();

		$this->assertCount(501, $steps);
		$this->assertSame(['error' => 1], $steps[500]['output']['droppedByStatus']);
	}//end testTheTruncatedEntryIsNotStoredInTheBuffer()
}//end class
