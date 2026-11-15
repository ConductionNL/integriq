<?php

/**
 * JobTask hands every five-minute tick to the job service, one run at a time.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\JobTask;
use OCA\Integriq\Service\JobServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\BackgroundJob\JobTask
 */
class JobTaskTest extends TestCase {

	public function testItRunsEveryFiveMinutesOnScheduleAndAlone(): void {
		$task = new JobTask($this->createMock(ITimeFactory::class), $this->createMock(JobServiceInterface::class));

		$this->assertSame(300, $task->getInterval());
		$this->assertTrue($task->isTimeSensitive());
		$this->assertFalse($task->getAllowParallelRuns());
	}

	public function testARunDelegatesToTheJobServiceOnce(): void {
		$service = $this->createMock(JobServiceInterface::class);
		$service->expects($this->once())->method('run');

		(new JobTask($this->createMock(ITimeFactory::class), $service))->run(['ignored' => true]);
	}
}
