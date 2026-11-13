<?php

/**
 * MessageRetryJobScheduler queues a StUF-ZDS retry job for one message at a given time.
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

use OCA\Integriq\BackgroundJob\MessageRetryJobScheduler;
use OCA\Integriq\BackgroundJob\StufZdsRetryJob;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\BackgroundJob\MessageRetryJobScheduler
 */
class MessageRetryJobSchedulerTest extends TestCase {

	public function testARetryIsScheduledAsAStufZdsRetryJobForThatMessage(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('scheduleAfter')
			->with(StufZdsRetryJob::class, 1794500000, ['messageId' => 'msg-9']);

		(new MessageRetryJobScheduler($jobs))->scheduleRetry('msg-9', 1794500000);
	}
}
