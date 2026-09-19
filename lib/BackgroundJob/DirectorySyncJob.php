<?php

/**
 * Integriq Directory Sync Job.
 *
 * The scheduled half of a directory connection. A membership that ended in the
 * directory has to end here without anyone acting, which is the difference
 * between synchronising membership and authenticating against a directory.
 *
 * Mirrors `ApprovalTimeoutSweepJob`'s TimedJob idiom. The interval is hourly
 * rather than by the minute: a directory is not a queue, and an hour is the
 * cadence a gemeente's in-en-uitdienst process actually moves at.
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Directory\DirectorySyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs every enabled directory connection on a schedule.
 *
 * @psalm-api
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
 */
class DirectorySyncJob extends TimedJob {

	/**
	 * Run interval in seconds (one hour).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param DirectorySyncService $directorySyncService The directory sync orchestrator.
	 * @param LoggerInterface $logger Logger for run outcomes.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DirectorySyncService $directorySyncService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

	}//end __construct()

	/**
	 * Run every enabled directory connection.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	protected function run($argument): void {
		try {
			$records = $this->directorySyncService->runAll();
		} catch (Throwable $exception) {
			$this->logger->error(
				'[DirectorySyncJob] directory run failed: ' . $exception->getMessage(),
				['exception' => $exception]
			);

			return;
		}

		foreach ($records as $record) {
			$this->logger->info(
				'[DirectorySyncJob] ran ' . (string)($record['connectionName'] ?? '') . ': '
				. (string)($record['membershipsAdded'] ?? 0) . ' added, '
				. (string)($record['membershipsRemoved'] ?? 0) . ' removed',
				['runId' => ($record['runId'] ?? null), 'status' => ($record['status'] ?? null)]
			);
		}

	}//end run()
}//end class
