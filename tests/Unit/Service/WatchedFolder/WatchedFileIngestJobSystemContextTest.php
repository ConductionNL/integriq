<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The watched file job runs in cron with nobody signed in, so its ingest runs as the system.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\WatchedFolder;

use OCA\Integriq\Service\WatchedFolder\WatchedFileIngestJob;
use OCA\Integriq\Service\WatchedFolderService;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\Integriq\Service\WatchedFolder\WatchedFileIngestJob
 */
class WatchedFileIngestJobSystemContextTest extends TestCase {

	/**
	 * Run the job's protected run() with an argument.
	 *
	 * @param WatchedFileIngestJob $job      The job.
	 * @param mixed                $argument The job argument.
	 *
	 * @return void
	 */
	private static function runJob(WatchedFileIngestJob $job, mixed $argument): void {
		$run = new ReflectionMethod(objectOrMethod: $job, method: 'run');
		$run->invoke($job, $argument);
	}//end runJob()

	/**
	 * The ingest, and every write in it, runs inside OpenRegister's system context.
	 *
	 * @return void
	 */
	public function testTheIngestRunsAsTheSystem(): void {
		$seen = [];
		$service = $this->createMock(WatchedFolderService::class);
		$service->expects($this->once())->method('ingest')
			->with('sync-1', 42)
			->willReturnCallback(static function () use (&$seen): string {
				$seen[] = SystemOperationContext::isActive();
				return 'ingested';
			});

		self::runJob(
			job: new WatchedFileIngestJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class)),
			argument: ['fileId' => 42, 'synchronizationId' => 'sync-1']
		);

		$this->assertSame(expected: [true], actual: $seen);
		$this->assertFalse(SystemOperationContext::isActive(), 'the elevation ends with the ingest');
	}//end testTheIngestRunsAsTheSystem()

	/**
	 * A job without a file id or synchronization id does not elevate or ingest.
	 *
	 * @return void
	 */
	public function testAnIncompleteJobIngestsNothing(): void {
		$service = $this->createMock(WatchedFolderService::class);
		$service->expects($this->never())->method('ingest');

		self::runJob(
			job: new WatchedFileIngestJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class)),
			argument: ['fileId' => 42]
		);
	}//end testAnIncompleteJobIngestsNothing()
}//end class
