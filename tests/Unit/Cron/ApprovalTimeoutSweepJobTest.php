<?php

/**
 * Unit tests for ApprovalTimeoutSweepJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/approval-workflow/spec.md#requirement-timeout-sweeping-and-fallback-outcomes-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Cron;

use OCA\Integriq\Cron\ApprovalTimeoutSweepJob;
use OCA\Integriq\Service\ApprovalService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the approval expiry sweep background job.
 *
 * @spec openspec/specs/approval-workflow/spec.md#requirement-timeout-sweeping-and-fallback-outcomes-req-005
 */
class ApprovalTimeoutSweepJobTest extends TestCase {

	/**
	 * @var ApprovalService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $approvalService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var ApprovalTimeoutSweepJob
	 */
	private ApprovalTimeoutSweepJob $job;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->approvalService = $this->createMock(ApprovalService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new ApprovalTimeoutSweepJob($timeFactory, $this->approvalService, $this->logger);

	}//end setUp()

	/**
	 * The job constructs without error.
	 *
	 * @return void
	 */
	public function testConstructs(): void {
		$this->assertInstanceOf(ApprovalTimeoutSweepJob::class, $this->job);

	}//end testConstructs()

	/**
	 * Running the job invokes the sweep — REQ-005.
	 *
	 * @return void
	 */
	public function testRunInvokesSweep(): void {
		$this->approvalService->expects($this->once())
			->method('sweepExpired')
			->willReturn(['swept' => 2, 'deadLettered' => 1]);

		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->setAccessible(true);
		$reflection->invoke($this->job, null);

	}//end testRunInvokesSweep()

	/**
	 * A sweep exception is caught and logged, never rethrown — REQ-005.
	 *
	 * @return void
	 */
	public function testRunContainsExceptions(): void {
		$this->approvalService->method('sweepExpired')
			->willThrowException(new \RuntimeException('poisoned row'));

		$this->logger->expects($this->once())->method('error');

		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->setAccessible(true);
		$reflection->invoke($this->job, null);
		$this->assertTrue(true);

	}//end testRunContainsExceptions()
}//end class
