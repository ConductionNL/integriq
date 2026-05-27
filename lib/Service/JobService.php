<?php

/**
 * OpenConnector JobService.
 *
 * Service class for handling job execution logic in the OpenConnector application.
 * This service manages job retrieval, validation, execution, and logging.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use DateTime;
use Exception;
use OCP\BackgroundJob\IJob;

/**
 * Service class for handling job execution and management
 *
 * This service provides methods for executing jobs, managing job states,
 * and handling job logging. It encapsulates the complex business logic
 * that was previously in the JobTask cron job.
 *
 * @psalm-api
 * @phpstan-type JobArgument array{jobId?: string, forceRun?: bool}
 * @phpstan-type JobResult array{level?: string, message?: string, stackTrace?: array<string>, nextRun?: int}
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 */
class JobService
{

    private const DEFAULT_SUCCESS_LOG_RETENTION = 3600000;

    private const DEFAULT_ERROR_LOG_RETENTION = 2592000000;

    /**
     * Retention (ms) applied to error JobLogs.
     *
     * @var integer
     */
    private int $errorRetention;

    /**
     * Retention (ms) applied to successful JobLogs.
     *
     * @var integer
     */
    private int $successRetention;

    /**
     * JobService constructor.
     *
     * Initializes the job service with required dependencies for job execution
     * and management operations.
     *
     * @param IJobList           $jobList            The job list manager for background jobs.
     * @param ORObjectService    $objectService      The OR ObjectService for data access.
     * @param IDBConnection      $connection         Database connection for direct queries.
     * @param ContainerInterface $containerInterface Container for dependency injection.
     * @param IUserSession       $userSession        User session manager.
     * @param IUserManager       $userManager        User manager for user operations.
     * @param IAppConfig         $appConfig          App config used to read global retention overrides.
     *
     * @psalm-param IJobList $jobList
     * @psalm-param ORObjectService $objectService
     * @psalm-param IDBConnection $connection
     * @psalm-param ContainerInterface $containerInterface
     * @psalm-param IUserSession $userSession
     * @psalm-param IUserManager $userManager
     */
    public function __construct(
        private readonly IJobList $jobList,
        private readonly ORObjectService $objectService,
        private readonly IDBConnection $connection,
        private readonly ContainerInterface $containerInterface,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        IAppConfig $appConfig,
    ) {
        $this->errorRetention   = self::DEFAULT_ERROR_LOG_RETENTION;
        $this->successRetention = self::DEFAULT_SUCCESS_LOG_RETENTION;
        if ($appConfig->hasKey(app: 'openconnector', key: 'retention') === true) {
            $retentionPayload       = json_decode(
                $appConfig->getValueString(app: 'openconnector', key: 'retention'),
                true
            );
            $this->errorRetention   = ($retentionPayload['jobLogRetention'] ?? self::DEFAULT_ERROR_LOG_RETENTION);
            $this->successRetention = ($retentionPayload['successLogRetention'] ?? self::DEFAULT_SUCCESS_LOG_RETENTION);
        }

    }//end __construct()

    /**
     * Calculates the used retention for created logs.
     *
     * Consists of the maximum of the retention from the source and the global
     * retention, unless either is 0 (indefinite retention).
     *
     * @param integer ...$retentions The list of retentions in milliseconds to find the maximum duration for.
     *
     * @return \DateTime|null The calculated expiry.
     *
     * @throws \DateMalformedStringException On invalid datetime composition.
     *
     * @TODO: At a later point in time this should be changed to using the most specific source for expiration
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-4
     */
    private function calculateExpires(...$retentions): ?\DateTime
    {
        if (in_array(0, $retentions, true) === true) {
            return null;
        }

        return new \DateTime('now +'.max($retentions).'milliseconds');

    }//end calculateExpires()

    /**
     * Truncate message if it exceeds safe database limits
     *
     * This method ensures that job log messages don't exceed reasonable length
     * limits that could cause database performance issues or memory problems.
     * While the database column is now TEXT type, very long messages should
     * still be truncated for performance and readability.
     *
     * @param string $message   The original message to truncate
     * @param int    $maxLength Maximum allowed message length (default: 10000 characters)
     *
     * @return string The truncated message with truncation indicator if needed
     *
     * @psalm-param    string $message
     * @psalm-param    int $maxLength
     * @psalm-return   string
     * @phpstan-param  string $message
     * @phpstan-param  int $maxLength
     * @phpstan-return string
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-4
     */
    private function truncateMessage(string $message, int $maxLength=10000): string
    {
        // If message is within limits, return as-is.
        if (strlen($message) <= $maxLength) {
            return $message;
        }

        // Truncate and add indicator.
        $truncated  = substr($message, 0, ($maxLength - 50));
        $truncated .= '... [Message truncated - original length: '.strlen($message).' characters]';

        return $truncated;

    }//end truncateMessage()

    /**
     * Schedule a job for execution
     *
     * This method handles the scheduling of jobs in the background job list.
     * It checks if the job should be enabled/disabled and schedules it accordingly.
     *
     * @param ObjectEntity $job The job ObjectEntity to schedule
     *
     * @return ObjectEntity The updated job ObjectEntity
     *
     * @psalm-param    ObjectEntity $job
     * @psalm-return   ObjectEntity
     * @phpstan-param  ObjectEntity $job
     * @phpstan-return ObjectEntity
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-4
     */
    public function scheduleJob(ObjectEntity $job): ObjectEntity
    {
        $jobData = $job->getObject();

        // Let's first check if the job should be disabled.
        if (($jobData['isEnabled'] ?? true) === false || ($jobData['jobListId'] ?? null) !== null) {
            // @todo fix this (call to protected method).
            // $this->jobList->removeById($jobData['jobListId']);
            $jobData['jobListId'] = null;
            return $this->objectService->saveObject(
                object: $jobData,
                register: 'openconnector',
                schema: 'job',
                uuid: $job->getUuid()
            );
        }

        // Let's not update the job if it's already scheduled @todo we should.
        if (isset($jobData['jobListId']) === true && $jobData['jobListId'] !== null) {
            return $job;
        }

        // Oke this is a new job let's schedule it.
        $arguments          = ($jobData['arguments'] ?? []);
        $arguments['jobId'] = $job->getUuid();

        // Schedule the job using the new JobTask class.
        $scheduleAfter = ($jobData['scheduleAfter'] ?? null);
        if ($scheduleAfter !== null) {
            $runAfter = (new DateTime($scheduleAfter))->getTimestamp();
            $this->jobList->scheduleAfter(\OCA\OpenConnector\Cron\JobTask::class, $runAfter, $arguments);
        }

        if ($scheduleAfter === null) {
            $this->jobList->add(\OCA\OpenConnector\Cron\JobTask::class, $arguments);
        }

        // Set the job list id.
        $jobData['jobListId'] = $this->getJobListId(job: \OCA\OpenConnector\Cron\JobTask::class);
        // Save the job to the database.
        return $this->objectService->saveObject(
            object: $jobData,
            register: 'openconnector',
            schema: 'job',
            uuid: $job->getUuid()
        );

    }//end scheduleJob()

    /**
     * Get the job list ID of the last job in the list.
     *
     * This function retrieves the database ID of the most recently added job
     * of a specific class from the background job list. This is needed because
     * the Nextcloud job list doesn't provide a better way to get the last job ID.
     *
     * @param class-string<IJob>|IJob $job The job class or instance to find the ID for.
     *
     * @return integer|null The job list ID if found, null otherwise.
     *
     * @see https://github.com/nextcloud/server/blob/master/lib/private/BackgroundJob/JobList.php#L134
     *
     * @psalm-param    class-string<IJob>|IJob $job
     * @psalm-return   int|null
     * @phpstan-param  class-string<IJob>|IJob $job
     * @phpstan-return int|null
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-4
     */
    public function getJobListId(IJob|string $job): int|null
    {
        // Extract the class name from either string or object.
        if ($job instanceof IJob) {
            $class = get_class($job);
        } else {
            $class = $job;
        }

        // Build query to find the most recent job of this class.
        $query = $this->connection->getQueryBuilder();
        $query->select('id')
            ->from('jobs')
            ->where($query->expr()->eq('class', $query->createNamedParameter($class)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        // Execute query and fetch result.
        $result = $query->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return ($row['id'] ?? null);

    }//end getJobListId()

    /**
     * Execute a job based on the provided job ObjectEntity and optional forceRun flag
     *
     * This method handles the complete job execution process including:
     * - Job validation and retrieval
     * - User session management
     * - Job execution timing
     * - Result processing and logging
     * - Next run scheduling
     *
     * @param ObjectEntity $job      The job ObjectEntity to be executed
     * @param bool         $forceRun Optional flag to force run the job
     *
     * @return ObjectEntity|null The job log entry created for this execution
     *
     * @throws \OCP\DB\Exception Database operation exceptions
     * @throws ContainerExceptionInterface Container operation exceptions
     * @throws NotFoundExceptionInterface When required services are not found
     *
     * @psalm-param    ObjectEntity $job
     * @psalm-return   ObjectEntity|null
     * @phpstan-param  ObjectEntity $job
     * @phpstan-return ObjectEntity|null
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-4
     */
    public function executeJob(ObjectEntity $job, bool $forceRun=false): ?ObjectEntity
    {
        $jobData = $job->getObject();

        // Initialize stack trace for logging.
        $stackTrace = [];
        if ($forceRun === true) {
            $stackTrace[] = 'Doing a force run for this job, ignoring "enabled" & "nextRun" check...';
        }

        // Check if the job is enabled (unless force run is requested).
        if ($forceRun === false && ($jobData['isEnabled'] ?? true) === false) {
            return $this->saveJobLog(
                job: $job,
                jobData: $jobData,
                logData: [
                    'level'   => 'WARNING',
                    'message' => 'This job is disabled',
                ]
            );
        }

        // Check if the job is scheduled to run (unless force run is requested).
        $nextRunStr = ($jobData['nextRun'] ?? null);
        if ($forceRun === false && $nextRunStr !== null) {
            $nextRun = new DateTime($nextRunStr);
            if ($nextRun > new DateTime()) {
                // Do not log, just skip execution.
                return null;
            }
        }

        // Set user session if job has a specific user configured.
        // Capture the prior session user so we can restore it after the job runs
        // — without restoration, the first user-scoped job's identity sticks
        // for every subsequent job in the same cron pass (#1006).
        $userId               = ($jobData['userId'] ?? null);
        $priorSessionUser     = $this->userSession->getUser();
        $sessionUserOverridden = false;
        if (empty($userId) === false) {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                // Deleted/missing user — skip the job with a WARNING log entry
                // rather than crashing setUser(null) inside the try/finally below.
                return $this->saveJobLog(
                    job: $job,
                    jobData: $jobData,
                    logData: [
                        'level'   => 'WARNING',
                        'message' => sprintf(
                            'Configured userId "%s" does not exist; skipping job.',
                            $userId
                        ),
                    ]
                );
            }

            $this->userSession->setUser($user);
            $sessionUserOverridden = true;
        }

        // Record execution start time for performance tracking.
        $timeStart = microtime(true);

        // Get the job action class from the container and execute it.
        $action    = $this->containerInterface->get($jobData['jobClass']);
        $arguments = ($jobData['arguments'] ?? []);
        if (is_array($arguments) === false) {
            $arguments = [];
        }

        try {
            $result = $action->run($arguments);
        } finally {
            // Always restore the prior session user so identity does not bleed
            // across jobs in the same cron pass (#1006).
            if ($sessionUserOverridden === true) {
                $this->userSession->setUser($priorSessionUser);
            }
        }

        // Calculate execution time in milliseconds.
        $timeEnd       = microtime(true);
        $executionTime = (($timeEnd - $timeStart) * 1000);

        // Handle single run jobs by disabling them after execution.
        $isSingleRun = ($jobData['isSingleRun'] ?? false);
        if ($forceRun === false && $isSingleRun === true) {
            $jobData['isEnabled'] = false;
        }

        // Update job with last run time and calculate next run time.
        $jobData['lastRun'] = (new DateTime())->format('c');
        if ($forceRun === false) {
            $nextRun = new DateTime('now + '.($jobData['interval'] ?? 0).' seconds');

            // Handle rate limiting if specified in result.
            if (isset($result['nextRun']) === true) {
                $nextRunRateLimit = DateTime::createFromFormat('U', $result['nextRun'], $nextRun->getTimezone());
                // Check if the current seconds part is not zero, and if so, round up to the next minute.
                if ($nextRunRateLimit->format('s') !== '00') {
                    $nextRunRateLimit->modify('next minute');
                }

                if ($nextRunRateLimit > $nextRun) {
                    $nextRun = $nextRunRateLimit;
                }
            }

            // Set time to the current hour and minute (remove seconds).
            $nextRun->setTime(hour: $nextRun->format('H'), minute: $nextRun->format('i'));
            $jobData['nextRun'] = $nextRun->format('c');
        }

        // Persist job updates to database.
        $this->objectService->saveObject(
            object: $jobData,
            register: 'openconnector',
            schema: 'job',
            uuid: $job->getUuid()
        );

        $logRetention   = (int) ($jobData['logRetention'] ?? 0);
        $errorRetention = (int) ($jobData['errorRetention'] ?? 0);

        // Build initial job log data with success status.
        $successExpiry = $this->calculateExpires(...[($logRetention * 1000), $this->successRetention]);
        if ($successExpiry !== null) {
            $successExpiryFormatted = $successExpiry->format('c');
        } else {
            $successExpiryFormatted = null;
        }

        $logData = [
            'level'         => 'SUCCESS',
            'message'       => 'Success',
            'executionTime' => $executionTime,
            'expires'       => $successExpiryFormatted,
        ];

        // Process job execution result and update log accordingly.
        if (is_array($result) === true) {
            if (isset($result['level']) === true) {
                $logData['level'] = $result['level'];

                if ($result['level'] !== 'SUCCESS') {
                    $expiresDate = $this->calculateExpires(...[($errorRetention * 1000), $this->errorRetention]);
                    if ($expiresDate !== null) {
                        $logData['expires'] = $expiresDate->format('c');
                    } else {
                        $logData['expires'] = null;
                    }
                }
            }

            if (isset($result['message']) === true) {
                // Truncate message if it's too long for database safety.
                $logData['message'] = $this->truncateMessage(message: $result['message']);
            }

            if (isset($result['stackTrace']) === true) {
                $stackTrace = array_merge($stackTrace, $result['stackTrace']);
            }
        }//end if

        $logData['stackTrace'] = $stackTrace;

        return $this->saveJobLog(job: $job, jobData: $jobData, logData: $logData);
    }//end executeJob()

    /**
     * Save a job log entry via ObjectService.
     *
     * @param ObjectEntity $job     The job ObjectEntity.
     * @param array        $jobData The job data array.
     * @param array        $logData The log fields to store.
     *
     * @return ObjectEntity The saved job_log ObjectEntity.
     * @throws \OCP\DB\Exception
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-4
     */
    private function saveJobLog(ObjectEntity $job, array $jobData, array $logData): ObjectEntity
    {
        $logObject = array_merge(
            [
                'jobId'     => $job->getUuid(),
                'jobClass'  => $jobData['jobClass'] ?? null,
                'jobListId' => $jobData['jobListId'] ?? null,
                'arguments' => $jobData['arguments'] ?? [],
                'lastRun'   => $jobData['lastRun'] ?? null,
                'nextRun'   => $jobData['nextRun'] ?? null,
                'created'   => (new DateTime())->format('c'),
            ],
            $logData
        );

        // Default expiry per level if not already set.
        if (isset($logObject['expires']) === false) {
            switch ($logObject['level'] ?? '') {
                case 'INFO':
                    $logObject['expires'] = (new DateTime('+1 hour'))->format('c');
                    break;
                case 'WARNING':
                case 'ERROR':
                    $logObject['expires'] = (new DateTime('+3 days'))->format('c');
                    break;
                default:
                    $logObject['expires'] = (new DateTime('+30 days'))->format('c');
            }
        }

        return $this->objectService->saveObject(
            object: $logObject,
            register: 'openconnector',
            schema: 'job_log'
        );
    }//end saveJobLog()

    /**
     * Run all jobs that are scheduled to run (nextRun <= now).
     *
     * @return ObjectEntity[] Array of job log results.
     *
     * @psalm-return   array<ObjectEntity>
     * @phpstan-return ObjectEntity[]
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-4
     */
    public function run(): array
    {
        // Fetch all jobs that are enabled and whose nextRun is in the past or null.
        $now     = (new DateTime())->format('c');
        $matches = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register'  => 'openconnector',
                        'schema'    => 'job',
                        'isEnabled' => true,
                    ],
                ]
                );
        $jobs    = ($matches['results'] ?? $matches);
        $results = [];

        foreach ($jobs as $job) {
            $jobData = $job->getObject();
            $nextRun = ($jobData['nextRun'] ?? null);

            // Skip jobs that are not yet due.
            if ($nextRun !== null && (new DateTime($nextRun)) > new DateTime()) {
                continue;
            }

            // Per-job isolation (#1005): a throw inside executeJob must NOT
            // skip remaining due jobs in this cron pass, and must NOT leave
            // the failing job's nextRun unchanged (otherwise it stays "due
            // immediately" and re-blocks every subsequent tick).
            try {
                $log = $this->executeJob(job: $job);
                if ($log !== null) {
                    $results[] = $log;
                }
            } catch (\Throwable $e) {
                // Build a stack trace for the error log (truncate per-frame to
                // avoid pathological size).
                $stackTrace = [];
                foreach ($e->getTrace() as $frame) {
                    if (isset($frame['file']) === true) {
                        $location = $frame['file'].':'.($frame['line'] ?? '?');
                    } else if (isset($frame['class']) === true) {
                        $location = $frame['class'].($frame['type'] ?? '::').($frame['function'] ?? '?');
                    } else {
                        $location = ($frame['function'] ?? '?');
                    }

                    $stackTrace[] = $location;
                    if (count($stackTrace) >= 50) {
                        break;
                    }
                }

                // Advance nextRun by the job's configured interval so the failing
                // job no longer blocks siblings on the next cron tick.
                try {
                    $intervalSeconds = (int) ($jobData['interval'] ?? 0);
                    $nextRunDt       = new DateTime('now + '.$intervalSeconds.' seconds');
                    $nextRunDt->setTime(
                        hour: (int) $nextRunDt->format('H'),
                        minute: (int) $nextRunDt->format('i')
                    );
                    $jobData['lastRun'] = (new DateTime())->format('c');
                    $jobData['nextRun'] = $nextRunDt->format('c');
                    $this->objectService->saveObject(
                        object: $jobData,
                        register: 'openconnector',
                        schema: 'job',
                        uuid: $job->getUuid()
                    );
                } catch (\Throwable $advanceError) {
                    // Don't let nextRun advancement failure mask the original exception
                    // — fall through to the error log below.
                    unset($advanceError);
                }

                // Write an error-level job_log so operators can see the failure.
                try {
                    $errorLog = $this->saveJobLog(
                        job: $job,
                        jobData: $jobData,
                        logData: [
                            'level'      => 'ERROR',
                            'message'    => $this->truncateMessage(
                                message: sprintf(
                                    '%s: %s',
                                    get_class($e),
                                    $e->getMessage()
                                )
                            ),
                            'stackTrace' => $stackTrace,
                        ]
                    );
                    if ($errorLog !== null) {
                        $results[] = $errorLog;
                    }
                } catch (\Throwable $logError) {
                    // Swallow logging failure — we must continue to the next job.
                    unset($logError);
                }

                continue;
            }//end try
        }//end foreach

        return $results;

    }//end run()
}//end class
