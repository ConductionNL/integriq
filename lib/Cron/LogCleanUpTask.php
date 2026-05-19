<?php

/**
 * LogCleanUpTask
 *
 * Background job task for cleaning up old logs in the OpenConnector application.
 * This task removes expired call logs and job logs to maintain system performance.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
 * @author   OpenConnector Development Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/openconnector
 * @version  1.0.0
 */

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Db\CallLogMapper;
use OCA\OpenConnector\Db\JobLogMapper;
use OCA\OpenConnector\Db\SynchronizationContractLogMapper;
use OCA\OpenConnector\Db\SynchronizationLogMapper;
use OCP\BackgroundJob\TimedJob;
use OCP\BackgroundJob\IJob;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Background job task for cleaning up expired logs
 *
 * This task runs periodically to remove old call logs and job logs
 * from the database to prevent database bloat and maintain performance.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class LogCleanUpTask extends TimedJob
{
	/**
	 * Call log mapper for database operations
	 */
	private readonly CallLogMapper $callLogMapper;

	/**
	 * Job log mapper for database operations
	 */
	private readonly JobLogMapper $jobLogMapper;

	/**
	 * LogCleanUpTask constructor
	 *
	 * Initializes the log cleanup task with required dependencies
	 * and configures the background job settings.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling
	 * @param CallLogMapper $callLogMapper Mapper for call log operations
	 * @param JobLogMapper $jobLogMapper Mapper for job log operations
	 *
	 * @psalm-param ITimeFactory $time
	 * @psalm-param CallLogMapper $callLogMapper
	 * @psalm-param JobLogMapper $jobLogMapper
	 */
	public function __construct(
		ITimeFactory $time,
		CallLogMapper $callLogMapper,
		JobLogMapper $jobLogMapper,
        private readonly SynchronizationContractLogMapper $syncContractLogMapper,
        private readonly SynchronizationLogMapper $syncLogMapper
	) {
		parent::__construct($time);
		$this->callLogMapper = $callLogMapper;
		$this->jobLogMapper = $jobLogMapper;

		// Run every minute @todo change to hour
		$this->setInterval(60);

		// Delay until low-load time
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

		// Only run one instance of this job at a time
		$this->setAllowParallelRuns(true);
	}

	/**
	 * Execute the log cleanup task
	 *
	 * This method removes expired logs from both call logs and job logs
	 * tables to maintain database performance and prevent storage bloat.
	 *
	 * @param mixed $argument Task arguments (not used in this implementation)
	 *
	 * @return void
	 *
	 * @psalm-param mixed $argument
	 * @phpstan-param mixed $argument
	 */
	public function run(mixed $argument): void
	{
		// Clear expired call logs from the database
		$this->callLogMapper->clearLogs();

		// Clear expired job logs from the database
		$this->jobLogMapper->clearLogs();

        // Clear expired synchronization contract logs
        $this->syncContractLogMapper->clearLogs();

        $this->syncLogMapper->cleanupExpired();
	}
}
