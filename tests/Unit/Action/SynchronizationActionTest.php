<?php

/**
 * Unit tests for SynchronizationAction, the cron Action that runs one synchronization.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bounded-jsonl-sync/specs/job-scheduling/spec.md#requirement-a-job-with-work-left-runs-again-on-the-next-tick
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Action;

use OCA\Integriq\Action\SynchronizationAction;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SynchronizationAction::run().
 */
class SynchronizationActionTest extends TestCase {

	/**
	 * Build the action over a service whose run returns the given result.
	 *
	 * @param array $result The synchronization log result the run returns.
	 *
	 * @return SynchronizationAction
	 */
	private function actionReturning(array $result): SynchronizationAction {
		$service = $this->createMock(SynchronizationService::class);
		$service->method('getSynchronization')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'sync'], 'sync-1'));
		$service->method('synchronize')->willReturn(['result' => $result]);

		return new SynchronizationAction($service);
	}//end actionReturning()

	/**
	 * A run that left batches to read asks to be resumed on the next tick.
	 *
	 * @return void
	 */
	public function testRunWithBatchesLeftAsksToResumeSoon(): void {
		$response = $this->actionReturning(
			['objects' => ['found' => 2], 'resume' => ['page' => 2, 'reason' => 'batch_limit_reached']]
		)->run(['synchronizationId' => 'sync-1']);

		$this->assertSame('INFO', $response['level']);
		$this->assertTrue($response['resumeSoon']);
	}//end testRunWithBatchesLeftAsksToResumeSoon()

	/**
	 * A run that read everything keeps the job's own interval.
	 *
	 * @return void
	 */
	public function testFinishedRunDoesNotAskToResume(): void {
		$response = $this->actionReturning(['objects' => ['found' => 2]])->run(['synchronizationId' => 'sync-1']);

		$this->assertSame('INFO', $response['level']);
		$this->assertArrayNotHasKey('resumeSoon', $response);
	}//end testFinishedRunDoesNotAskToResume()
}//end class
