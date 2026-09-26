<?php

/**
 * Unit tests for RodRetryJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-rod/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\RodRetryJob;
use OCA\Integriq\Service\RodService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the scheduled ROD outbound retry background job — proves the job
 * actually invokes RodService::retryFailed() (orphaned-capability rule).
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
 */
class RodRetryJobTest extends TestCase {

	/**
	 * @var RodService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $rodService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var RodRetryJob
	 */
	private RodRetryJob $job;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->rodService = $this->createMock(RodService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new RodRetryJob($timeFactory, $this->rodService, $this->logger);

	}//end setUp()

	/**
	 * The job wires its dependencies and constructs without error.
	 *
	 * @return void
	 */
	public function testConstructs(): void {
		$this->assertInstanceOf(RodRetryJob::class, $this->job);

	}//end testConstructs()

	/**
	 * Running the job invokes one retryFailed() sweep.
	 *
	 * @return void
	 */
	public function testRunInvokesRetryFailed(): void {
		$this->rodService->expects($this->once())->method('retryFailed')->willReturn(2);

		$this->job->run(null);

	}//end testRunInvokesRetryFailed()

	/**
	 * With no eligible rows, retryFailed() no-ops (returns 0) and the job does not error.
	 *
	 * @return void
	 */
	public function testRunWithNoEligibleRowsNoOps(): void {
		$this->rodService->method('retryFailed')->willReturn(0);
		$this->logger->expects($this->never())->method('error');

		$this->job->run(null);

	}//end testRunWithNoEligibleRowsNoOps()

	/**
	 * A sweep-level exception is contained and logged — the cron pipeline never wedges.
	 *
	 * @return void
	 */
	public function testRunContainsSweepException(): void {
		$this->rodService->method('retryFailed')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$this->job->run(null);

	}//end testRunContainsSweepException()
}//end class
