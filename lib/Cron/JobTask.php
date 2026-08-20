<?php

/**
 * OpenConnector JobTask.
 *
 * Background job task for executing jobs in the OpenConnector application.
 * This task runs scheduled jobs by delegating execution to the JobService.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\JobService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Background job task for executing scheduled jobs.
 *
 * This task serves as a simple wrapper around the JobService, handling the
 * execution of individual jobs based on their scheduled intervals.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class JobTask extends TimedJob {

	/**
	 * Job service for handling job execution logic.
	 *
	 * @var JobService
	 */
	private readonly JobService $jobService;

	/**
	 * Constructor.
	 *
	 * Initializes the job task with required dependencies and configures the
	 * background job settings.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param JobService $jobService Service for handling job execution.
	 */
	public function __construct(
		ITimeFactory $time,
		JobService $jobService,
	) {
		parent::__construct(time: $time);
		$this->jobService = $jobService;

		// Run every 5 minutes.
		$this->setInterval(seconds: 300);

		// Set as time sensitive to honour the cron schedule.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_SENSITIVE);

		// Only run one instance of this job at a time.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the job task.
	 *
	 * This method delegates job execution to the JobService, which handles
	 * all the complex business logic for job validation and logging.
	 *
	 * @param mixed $argument The job arguments containing jobId and optional parameters.
	 *
	 * @return void
	 *
	 * @psalm-param   array{jobId?: int, forceRun?: bool} $argument
	 * @phpstan-param mixed $argument
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	public function run(mixed $argument): void {
		// Delegate job execution to the service layer.
		$this->jobService->run();

	}//end run()
}//end class
