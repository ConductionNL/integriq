<?php

/**
 * Unit tests for BankfeedSyncJob.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Cron;

use OCA\OpenConnector\Cron\BankfeedSyncJob;
use OCA\OpenConnector\Service\BankfeedSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the scheduled bankfeed sync background job.
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
 */
class BankfeedSyncJobTest extends TestCase {

	/**
	 * @var BankfeedSyncService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $syncService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var BankfeedSyncJob
	 */
	private BankfeedSyncJob $job;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->syncService = $this->createMock(BankfeedSyncService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new BankfeedSyncJob($timeFactory, $this->syncService, $this->logger);

	}//end setUp()

	/**
	 * The job wires its dependencies and constructs without error.
	 *
	 * @return void
	 */
	public function testConstructs(): void {
		$this->assertInstanceOf(BankfeedSyncJob::class, $this->job);

	}//end testConstructs()

	/**
	 * Running the job invokes one syncAll sweep — REQ-004.
	 *
	 * @return void
	 */
	public function testRunInvokesSyncAll(): void {
		$this->syncService->expects($this->once())->method('syncAll')->willReturn(2);

		$this->job->run(null);

	}//end testRunInvokesSyncAll()

	/**
	 * A sweep-level exception is contained and logged — the cron pipeline never wedges.
	 *
	 * @return void
	 */
	public function testRunContainsSweepException(): void {
		$this->syncService->method('syncAll')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error');

		$this->job->run(null);

	}//end testRunContainsSweepException()
}//end class
