<?php

/**
 * OpenConnector LogCleanUpTask.
 *
 * Background job task for cleaning up old logs in the OpenConnector
 * application. Removes expired call logs and job logs to maintain
 * database performance.
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

use DateTime;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Background job task for cleaning up expired logs.
 *
 * Runs periodically to remove old call logs and job logs from the database
 * and prevent storage bloat.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class LogCleanUpTask extends TimedJob {
	/**
	 * Constructor.
	 *
	 * Initializes the log cleanup task with required dependencies and
	 * configures the background job settings.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param OrObjectService $orObjectService OR object service for log operations.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly OrObjectService $orObjectService,
	) {
		parent::__construct(time: $time);

		// Run every minute. @todo change to hour.
		$this->setInterval(seconds: 60);

		// Delay until low-load time.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only run one instance of this job at a time.
		$this->setAllowParallelRuns(allow: true);

	}//end __construct()

	/**
	 * Delete expired objects for a given schema in the openconnector register.
	 *
	 * Finds all objects with a non-null `expires` field that is in the past,
	 * then deletes them one by one via OR ObjectService.
	 *
	 * @param string $schema The schema slug to clean up.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function cleanupSchema(string $schema): void {
		$now = (new DateTime())->format('Y-m-d H:i:s');
		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'openconnector',
					'schema' => $schema,
					'expires[lt]' => $now,
				],
			]
		);

		$objects = $matches['results'] ?? $matches;
		foreach ($objects as $object) {
			try {
				$this->orObjectService->deleteObject(uuid: $object->getUuid());
			} catch (\Exception $e) {
				// Continue with remaining objects even if one deletion fails.
			}
		}

	}//end cleanupSchema()

	/**
	 * Execute the log cleanup task.
	 *
	 * This method removes expired logs from all log schemas to maintain
	 * database performance and prevent storage bloat.
	 *
	 * @param mixed $argument Task arguments (not used in this implementation).
	 *
	 * @return void
	 *
	 * @psalm-param   mixed $argument
	 * @phpstan-param mixed $argument
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	public function run(mixed $argument): void {
		// Clear expired call logs.
		$this->cleanupSchema(schema: 'call_log');

		// Clear expired job logs.
		$this->cleanupSchema(schema: 'job_log');

		// Clear expired synchronization contract logs.
		$this->cleanupSchema(schema: 'synchronization_contract_log');

		// Clear expired synchronization logs.
		$this->cleanupSchema(schema: 'synchronization_log');

	}//end run()
}//end class
