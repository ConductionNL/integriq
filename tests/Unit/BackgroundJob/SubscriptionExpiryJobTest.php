<?php

/**
 * Unit tests for SubscriptionExpiryJob.
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
 * @spec openspec/changes/access-developer-portal-and-subscriptions/specs/api-product-gateway/spec.md#requirement-a-subscription-can-end-on-a-date-req-devp-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\SubscriptionExpiryJob;
use OCA\Integriq\Service\SubscriptionExpiryService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for the daily subscription expiry job.
 */
#[CoversClass(SubscriptionExpiryJob::class)]
class SubscriptionExpiryJobTest extends TestCase {

	/**
	 * Run a job.
	 *
	 * @param SubscriptionExpiryJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(SubscriptionExpiryJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The job runs once a day, runs the pass and logs its counts.
	 *
	 * @return void
	 */
	public function testTheJobRunsDailyAndLogsThePass(): void {
		$expiry = $this->createMock(SubscriptionExpiryService::class);
		$expiry->expects($this->once())->method('sweep')->willReturn(['expired' => 1, 'reminded' => 2]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('SubscriptionExpiryJob: pass complete', ['expired' => 1, 'reminded' => 2]);

		$job = new SubscriptionExpiryJob($this->createMock(ITimeFactory::class), $expiry, $logger);
		self::runJob(job: $job);

		$this->assertSame(86400, $job->getInterval());
	}//end testTheJobRunsDailyAndLogsThePass()

	/**
	 * A failing pass is logged as an error and does not escape into cron.
	 *
	 * @return void
	 */
	public function testAFailingPassIsLoggedNotThrown(): void {
		$expiry = $this->createMock(SubscriptionExpiryService::class);
		$expiry->method('sweep')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('SubscriptionExpiryJob: pass failed: register down', $this->anything());

		self::runJob(job: new SubscriptionExpiryJob($this->createMock(ITimeFactory::class), $expiry, $logger));
	}//end testAFailingPassIsLoggedNotThrown()
}//end class
