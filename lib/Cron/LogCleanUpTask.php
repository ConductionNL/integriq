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

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
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
     * LogCleanUpTask constructor
     *
     * Initializes the log cleanup task with required dependencies
     * and configures the background job settings.
     *
     * @param ITimeFactory    $time            Time factory for job scheduling
     * @param OrObjectService $orObjectService OR object service for log operations
     *
     * @psalm-param ITimeFactory $time
     */
    public function __construct(
        ITimeFactory $time,
        private readonly OrObjectService $orObjectService
    ) {
        parent::__construct($time);

        // Run every minute @todo change to hour
        $this->setInterval(60);

        // Delay until low-load time
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);

        // Only run one instance of this job at a time
        $this->setAllowParallelRuns(true);
    }//end __construct()

    /**
     * Delete expired objects for a given schema in the openconnector register.
     *
     * Finds all objects with a non-null `expires` field that is in the past,
     * then deletes them one by one via OR ObjectService.
     *
     * @param  string $schema The schema slug to clean up.
     * @return void
     */
    private function cleanupSchema(string $schema): void
    {
        $now     = (new \DateTime())->format('Y-m-d H:i:s');
        $matches = $this->orObjectService->findAll(
                config: [
                    'filters' => [
                        'register'    => 'openconnector',
                        'schema'      => $schema,
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
     * Execute the log cleanup task
     *
     * This method removes expired logs from all log schemas
     * to maintain database performance and prevent storage bloat.
     *
     * @param mixed $argument Task arguments (not used in this implementation)
     *
     * @return void
     *
     * @psalm-param   mixed $argument
     * @phpstan-param mixed $argument
     */
    public function run(mixed $argument): void
    {
        // Clear expired call logs
        $this->cleanupSchema('call_log');

        // Clear expired job logs
        $this->cleanupSchema('job_log');

        // Clear expired synchronization contract logs
        $this->cleanupSchema('synchronization_contract_log');

        // Clear expired synchronization logs
        $this->cleanupSchema('synchronization_log');
    }//end run()
}//end class
