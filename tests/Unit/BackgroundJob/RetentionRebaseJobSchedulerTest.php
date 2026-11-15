<?php

/**
 * Unit tests for RetentionRebaseJobScheduler.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\RetentionRebaseJob;
use OCA\Integriq\BackgroundJob\RetentionRebaseJobScheduler;
use OCA\Integriq\BackgroundJob\RetentionRebaseSchedulerInterface;
use OCA\Integriq\Service\RetentionSettings;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

/**
 * The scheduler queues the first run of a rebase, from the first page of the first kind.
 *
 * @covers \OCA\Integriq\BackgroundJob\RetentionRebaseJobScheduler
 */
class RetentionRebaseJobSchedulerTest extends TestCase {

	/**
	 * A rebase is queued as a RetentionRebaseJob over the kinds, at the start.
	 *
	 * @return void
	 */
	public function testItQueuesTheFirstRunOfTheJob(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->once())
			->method('add')
			->with(RetentionRebaseJob::class, ['kinds' => ['callLog', 'syncLog'], 'kind' => 0, 'offset' => 0]);

		$scheduler = new RetentionRebaseJobScheduler(jobList: $jobList);
		$this->assertInstanceOf(RetentionRebaseSchedulerInterface::class, $scheduler);

		$scheduler->scheduleRebase(kinds: ['callLog', 'syncLog']);
	}//end testItQueuesTheFirstRunOfTheJob()

	/**
	 * The job keeps naming the progress key and log kinds the settings service uses.
	 *
	 * @return void
	 */
	public function testTheJobAndTheSettingsShareTheProgressKeyAndKinds(): void {
		$this->assertSame('retention_rebase', RetentionRebaseJob::PROGRESS_KEY);
		$this->assertSame(RetentionSettings::REBASE_PROGRESS_KEY, RetentionRebaseJob::PROGRESS_KEY);
		$this->assertSame(['callLog', 'jobLog', 'syncLog', 'syncContractLog'], array_keys(RetentionRebaseJob::SCHEMAS));
		$this->assertSame(RetentionSettings::REBASE_SCHEMAS, RetentionRebaseJob::SCHEMAS);
	}//end testTheJobAndTheSettingsShareTheProgressKeyAndKinds()
}//end class
