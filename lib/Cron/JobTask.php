<?php

/**
 * JobTask
 * 
 * Background job task for executing jobs in the OpenConnector application.
 * This task runs scheduled jobs by delegating execution to the JobService.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
 * @author   OpenConnector Development Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/openconnector
 * @version  1.0.0
 */

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\JobService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;

/**
 * Background job task for executing scheduled jobs
 *
 * This task serves as a simple wrapper around the JobService,
 * handling the execution of individual jobs based on their
 * scheduled intervals and configurations.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class JobTask extends TimedJob
{
	/**
	 * Job service for handling job execution logic
	 */
	private readonly JobService $jobService;

	/**
	 * JobTask constructor
	 *
	 * Initializes the job task with required dependencies and
	 * configures the background job settings.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling
	 * @param JobService $jobService Service for handling job execution
	 *
	 * @psalm-param ITimeFactory $time
	 * @psalm-param JobService $jobService
	 */
	public function __construct(
		ITimeFactory $time,
		JobService $jobService
	) {
		parent::__construct($time);
		$this->jobService = $jobService;

		// Run every 5 minutes
		$this->setInterval(300);

		// Set as time insensitive to run during low-load periods
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);

		// Only run one instance of this job at a time
		$this->setAllowParallelRuns(false);
	}

	/**
	 * Execute the job task
	 *
	 * This method delegates job execution to the JobService,
	 * which handles all the complex business logic for job
	 * validation, execution, and logging.
	 *
	 * @param mixed $argument The job arguments containing jobId and optional parameters
	 *
	 * @return void
	 *
	 * @psalm-param array{jobId?: int, forceRun?: bool} $argument
	 * @phpstan-param mixed $argument
	 */
	public function run(mixed $argument): void
	{
		// Delegate job execution to the service layer
		$this->jobService->run();
	}
}
