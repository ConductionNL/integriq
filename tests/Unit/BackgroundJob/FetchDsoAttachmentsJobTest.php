<?php

/**
 * Unit tests for FetchDsoAttachmentsJob.
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
 * @spec openspec/changes/dso-attachments-on-the-request/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\FetchDsoAttachmentsJob;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The job hands the queued request uuid to the fetcher, drops a malformed
 * argument, and never lets a failure take the cron worker down.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
 */
class FetchDsoAttachmentsJobTest extends TestCase {

	/**
	 * Run the job's protected run() with an argument.
	 *
	 * @param FetchDsoAttachmentsJob $job The job.
	 * @param mixed $argument The queued argument.
	 *
	 * @return void
	 */
	private function runJob(FetchDsoAttachmentsJob $job, mixed $argument): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);

	}//end runJob()

	/**
	 * The job is a QueuedJob and fetches the queued request.
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-the-endpoint-does-not-wait-for-the-bijlagen
	 *
	 * @return void
	 */
	public function testRunFetchesTheQueuedRequest(): void {
		$fetcher = $this->getMockBuilder(DsoAttachmentFetcher::class)->disableOriginalConstructor()->getMock();
		$fetcher->expects($this->once())->method('fetchPending')->with('verzoek-1')->willReturn([]);
		$job = new FetchDsoAttachmentsJob($this->createMock(ITimeFactory::class), $fetcher, $this->createMock(LoggerInterface::class));

		$this->assertInstanceOf(QueuedJob::class, $job);
		$this->runJob($job, ['requestUuid' => 'verzoek-1']);

	}//end testRunFetchesTheQueuedRequest()

	/**
	 * An argument without a request uuid is dropped with a warning.
	 *
	 * @return void
	 */
	public function testMalformedArgumentIsDropped(): void {
		$fetcher = $this->getMockBuilder(DsoAttachmentFetcher::class)->disableOriginalConstructor()->getMock();
		$fetcher->expects($this->never())->method('fetchPending');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');
		$job = new FetchDsoAttachmentsJob($this->createMock(ITimeFactory::class), $fetcher, $logger);

		$this->runJob($job, 'verzoek-1');
		$this->runJob($job, ['requestUuid' => '']);

	}//end testMalformedArgumentIsDropped()

	/**
	 * A fetcher failure is logged, not thrown.
	 *
	 * @return void
	 */
	public function testFailureIsLoggedNotThrown(): void {
		$fetcher = $this->getMockBuilder(DsoAttachmentFetcher::class)->disableOriginalConstructor()->getMock();
		$fetcher->method('fetchPending')->willThrowException(new RuntimeException('store unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('store unavailable'));
		$job = new FetchDsoAttachmentsJob($this->createMock(ITimeFactory::class), $fetcher, $logger);

		$this->runJob($job, ['requestUuid' => 'verzoek-1']);

	}//end testFailureIsLoggedNotThrown()
}//end class
