<?php

/**
 * Unit tests for OsoRetryJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-oso/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\OsoRetryJob;
use OCA\Integriq\Service\OsoService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the scheduled OSO export retry background job.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
 */
class OsoRetryJobTest extends TestCase {

	/**
	 * @var OsoService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $osoService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var OsoRetryJob
	 */
	private OsoRetryJob $job;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->osoService = $this->createMock(OsoService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new OsoRetryJob($timeFactory, $this->osoService, $this->logger);

	}//end setUp()

	/**
	 * The job wires its dependencies and constructs without error.
	 *
	 * @return void
	 */
	public function testConstructs(): void {
		$this->assertInstanceOf(OsoRetryJob::class, $this->job);

	}//end testConstructs()

	/**
	 * Running the job invokes one retryFailed() sweep.
	 *
	 * @return void
	 */
	public function testRunInvokesRetryFailed(): void {
		$this->osoService->expects($this->once())->method('retryFailed')->willReturn(2);

		$this->job->run(null);

	}//end testRunInvokesRetryFailed()

	/**
	 * With no eligible rows, retryFailed() no-ops (returns 0) and the job does not error.
	 *
	 * @return void
	 */
	public function testRunWithNoEligibleRowsNoOps(): void {
		$this->osoService->method('retryFailed')->willReturn(0);
		$this->logger->expects($this->never())->method('error');

		$this->job->run(null);

	}//end testRunWithNoEligibleRowsNoOps()

	/**
	 * A sweep-level exception is contained and logged.
	 *
	 * @return void
	 */
	public function testRunContainsSweepException(): void {
		$this->osoService->method('retryFailed')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$this->job->run(null);

	}//end testRunContainsSweepException()
}//end class
