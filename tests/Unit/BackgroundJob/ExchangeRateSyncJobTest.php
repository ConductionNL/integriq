<?php

/**
 * Unit tests for ExchangeRateSyncJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\ExchangeRateSyncJob;
use OCA\Integriq\Service\ExchangeRate\ExchangeRateImportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The job runs one import and contains its failure.
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001
 */
class ExchangeRateSyncJobTest extends TestCase {

	/**
	 * Import service double.
	 *
	 * @var ExchangeRateImportService&MockObject
	 */
	private ExchangeRateImportService&MockObject $importService;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the job.
	 *
	 * @return ExchangeRateSyncJob
	 */
	private function makeJob(): ExchangeRateSyncJob {
		$this->importService = $this->createMock(ExchangeRateImportService::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

		return new ExchangeRateSyncJob($this->createMock(ITimeFactory::class), $this->importService, $this->logger);

	}//end makeJob()

	/**
	 * Call the protected run().
	 *
	 * @param ExchangeRateSyncJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(ExchangeRateSyncJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

	}//end runJob()

	/**
	 * A run imports once and logs what it stored.
	 *
	 * @return void
	 */
	public function testRunImportsOnce(): void {
		$job = $this->makeJob();
		$this->importService->expects($this->once())->method('importFromSource')
			->willReturn(['status' => 'stored', 'stored' => 29, 'dates' => ['2026-10-30']]);
		$this->logger->expects($this->once())->method('info')
			->with($this->stringContains('29 rates stored'), ['dates' => ['2026-10-30']]);
		$this->logger->expects($this->never())->method('error');

		$this->runJob($job);

	}//end testRunImportsOnce()

	/**
	 * A failed import is logged as an error and does not escape the job.
	 *
	 * @return void
	 */
	public function testRunContainsAFailedImport(): void {
		$job = $this->makeJob();
		$this->importService->method('importFromSource')->willThrowException(new RuntimeException('HTTP 503'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('HTTP 503'));

		$this->runJob($job);

	}//end testRunContainsAFailedImport()
}//end class
