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

use DateTime;
use Exception;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

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
 *
 * @spec openspec/specs/job-scheduling/spec.md
 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
 */
class JobService {

	/**
	 * Retention (ms) applied to error JobLogs.
	 *
	 * Declared once on job_log schema (x-openregister-archival P30D).
	 * See openspec/architecture/adr-004-retention-constants-migrating-to-or-archival.md.
	 *
	 * @var integer
	 */
	private int $errorRetention;

	/**
	 * Retention (ms) applied to successful JobLogs.
	 *
	 * Declared once on job_log schema (x-openregister-archival PT1H).
	 * See openspec/architecture/adr-004-retention-constants-migrating-to-or-archival.md.
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
	 * @param IJobList $jobList The job list manager for background jobs.
	 * @param ORObjectService $objectService The OR ObjectService for data access.
	 * @param IDBConnection $connection Database connection for direct queries.
	 * @param ContainerInterface $containerInterface Container for dependency injection.
	 * @param IUserSession $userSession User session manager.
	 * @param IUserManager $userManager User manager for user operations.
	 * @param IAppConfig $appConfig App config used to read global retention overrides.
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
		$this->errorRetention = 2592000000;
		$this->successRetention = 3600000;
		if ($appConfig->hasKey(app: 'openconnector', key: 'retention') === true) {
			$retentionPayload = json_decode(
				$appConfig->getValueString(app: 'openconnector', key: 'retention'),
				true
			);
			$this->errorRetention = ($retentionPayload['jobLogRetention'] ?? 2592000000);
			$this->successRetention = ($retentionPayload['successLogRetention'] ?? 3600000);
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
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function calculateExpires(...$retentions): ?\DateTime {
		if (in_array(0, $retentions, true) === true) {
			return null;
		}

		return new \DateTime('now +' . max($retentions) . 'milliseconds');
	}//end calculateExpires()

	/**
	 * Truncate message if it exceeds safe database limits
	 *
	 * This method ensures that job log messages don't exceed reasonable length
	 * limits that could cause database performance issues or memory problems.
	 * While the database column is now TEXT type, very long messages should
	 * still be truncated for performance and readability.
	 *
	 * @param string $message The original message to truncate
	 * @param int $maxLength Maximum allowed message length (default: 10000 characters)
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
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function truncateMessage(string $message, int $maxLength = 10000): string {
		// If message is within limits, return as-is.
		if (strlen($message) <= $maxLength) {
			return $message;
		}

		// Truncate and add indicator.
		$truncated = substr($message, 0, ($maxLength - 50));
		$truncated .= '... [Message truncated - original length: ' . strlen($message) . ' characters]';

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
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	public function scheduleJob(ObjectEntity $job): ObjectEntity {
		$jobData = $job->getObject();

		// First: if the job is already scheduled (carries a non-null jobListId)
		// we bail out unchanged. This branch MUST run before the disable check
		// — the previous condition `isEnabled === false || jobListId !== null`
		// ate the early-return at the bottom of the method (already-scheduled
		// jobs had their jobListId cleared on every call). Surfaced by the
		// JobServiceTest::testScheduleJobSkipsAlreadyScheduledJob suite once
		// #1015 unblocked it from running.
		if (isset($jobData['jobListId']) === true && $jobData['jobListId'] !== null
			&& ($jobData['isEnabled'] ?? true) !== false
		) {
			return $job;
		}

		// Now check if the job should be disabled.
		if (($jobData['isEnabled'] ?? true) === false) {
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

		// Oke this is a new job let's schedule it.
		$arguments = ($jobData['arguments'] ?? []);
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
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	public function getJobListId(IJob|string $job): ?int {
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
		$row = $result->fetch();
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
	 * @param ObjectEntity $job The job ObjectEntity to be executed
	 * @param bool $forceRun Optional flag to force run the job
	 * @param ExecutionTraceContext|null $trace The active execution trace context. When null (the common
	 *                                          cron/manual-run case), a fresh `job`-entryPoint context
	 *                                          is minted here and its persistence owned by this method
	 *                                          (execution-trace REQ-001/REQ-004). When supplied
	 *                                          (`ExecutionTraceService::replay()`'s job-entryPoint
	 *                                          dispatch), reused instead.
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
	 * @spec openspec/specs/job-scheduling/spec.md
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function executeJob(ObjectEntity $job, bool $forceRun = false, ?ExecutionTraceContext $trace = null): ?ObjectEntity {
		$jobData = $job->getObject();

		// READ THE SINGLE-RUN FLAG HERE, while $jobData is still the job as
		// LOADED. Two reasons, both real:
		//
		//  - The schema declares `singleRun` — it is what job-form-fields.json
		//    renders and what every seeded connector writes. This method used
		//    to read `isSingleRun`, which NOTHING writes, so the flag was
		//    declared, editable, and enforced nowhere: a job marked "run once"
		//    ran on every tick, forever. `isSingleRun` is still accepted, as
		//    the spelling this method has always used.
		//  - Read further down, after the method has assigned lastRun/nextRun,
		//    static analysis has narrowed $jobData to those keys alone and
		//    reports the offset as non-existent. Reading it up here describes
		//    the job as it arrived, which is what the flag actually means.
		// The job AS LOADED, kept immutable. $jobData is mutated below
		// (lastRun, nextRun, isEnabled), and every later `??` read of a key
		// this method never assigns is reported by static analysis as an
		// offset that "does not exist" on the narrowed shape — three such
		// reads were carrying baseline entries whose text embedded that
		// inferred shape, so they broke the moment the inference shifted.
		// Reading configuration from the loaded job is both analysable and
		// closer to what these values mean.
		$jobConfig = $job->getObject();

		$isSingleRun = (($jobConfig['singleRun'] ?? null) === true || ($jobConfig['isSingleRun'] ?? null) === true);

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
					'level' => 'WARNING',
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
		$userId = ($jobData['userId'] ?? null);
		$priorSessionUser = $this->userSession->getUser();
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
						'level' => 'WARNING',
						'message' => sprintf(
							'Configured userId "%s" does not exist; skipping job.',
							$userId
						),
					]
				);
			}

			$this->userSession->setUser($user);
			$sessionUserOverridden = true;
		}//end if

		// Execution-trace REQ-001: a cron-triggered or manual job run is one
		// of the four traced entry points. Minted here — once the job is
		// confirmed enabled/due (or force-run) — not at the top of the
		// method, so a cron tick that finds nothing due to run produces no
		// trace noise. When invoked directly (no active trace supplied —
		// the common cron/manual-run case), mint a fresh `job`-entryPoint
		// context and own its persistence below. When `$trace` is already
		// supplied (`ExecutionTraceService::replay()`'s job-entryPoint
		// dry-run/forced dispatch), reuse it instead.
		$ownsJobTrace = ($trace === null);
		if ($ownsJobTrace === true) {
			$jobTraceTriggeredBy = 'cron';
			if ($forceRun === true) {
				$jobTraceTriggeredBy = 'manual';
			}

			$trace = new ExecutionTraceContext(
				entryPoint: 'job',
				entryPointId: $job->getUuid(),
				triggeredBy: $jobTraceTriggeredBy
			);
		}

		// Record execution start time for performance tracking.
		$timeStart = microtime(true);

		// Get the job action class from the container and execute it.
		$action = $this->containerInterface->get($jobData['jobClass']);
		$arguments = ($jobData['arguments'] ?? []);
		if (is_array($arguments) === false) {
			$arguments = [];
		}

		// Thread the active trace context to job actions that know how to
		// consume it (currently `SynchronizationAction`; other action
		// classes ignore the extra key unchanged). Never persisted as part
		// of the job's own `arguments` field.
		$arguments['_executionTrace'] = $trace;

		// H3: wrap execution in a catch so executeJob writes a job_log on any
		// thrown exception, not just when called from run().  Without this, a
		// controller-invoked run (JobsController::run/test) swallows the
		// exception and writes no evidence of the failure.
		$result = null;
		$executionThrew = false;
		$thrownException = null;

		try {
			$result = $action->run($arguments);
		} catch (\Throwable $e) {
			$executionThrew = true;
			$thrownException = $e;
		} finally {
			// Always restore the prior session user so identity does not bleed
			// across jobs in the same cron pass (#1006).
			if ($sessionUserOverridden === true) {
				$this->userSession->setUser($priorSessionUser);
			}
		}

		// Calculate execution time in milliseconds. The job_log schema requires an
		// integer (or null), so round the millisecond delta to a whole number before
		// it is persisted — a raw float fails OpenRegister object validation (500).
		$timeEnd = microtime(true);
		$executionTime = (int)round(($timeEnd - $timeStart) * 1000);

		// Pre-compute lastRun/nextRun so the job log reflects the correct
		// timeline even though we write the log BEFORE advancing the job row
		// (M1 fix: log first, then advance the timeline).
		$jobData['lastRun'] = (new DateTime())->format('c');
		if ($forceRun === false) {
			if ($executionThrew === false) {
				// Read from $jobConfig, the job AS LOADED: $jobData has been
				// mutated above (lastRun) and static analysis narrows it to the
				// keys this method assigns, so `interval` reads as non-existent.
				// Same remedy the comment at the top of this method describes.
				$nextRunDt = new DateTime('now + ' . ($jobConfig['interval'] ?? 0) . ' seconds');

				// Handle rate limiting if specified in result.
				if (isset($result['nextRun']) === true) {
					$nextRunRateLimit = DateTime::createFromFormat('U', (string)$result['nextRun'], $nextRunDt->getTimezone());
					if ($nextRunRateLimit !== false) {
						// Check if the current seconds part is not zero, and if so, round up to the next minute.
						if ($nextRunRateLimit->format('s') !== '00') {
							$nextRunRateLimit->modify('next minute');
						}

						if ($nextRunRateLimit > $nextRunDt) {
							$nextRunDt = $nextRunRateLimit;
						}
					}
				}
			} else {
				// On failure advance by the job's interval so it doesn't block
				// the next cron tick (same logic as run()'s catch block).
				$nextRunDt = new DateTime('now + ' . ((int)($jobConfig['interval'] ?? 0)) . ' seconds');
			}//end if

			// Set time to the current hour and minute (remove seconds).
			$nextRunDt->setTime(hour: (int)$nextRunDt->format('H'), minute: (int)$nextRunDt->format('i'));
			$jobData['nextRun'] = $nextRunDt->format('c');
		}//end if

		// Handle single run jobs by disabling them after execution. The flag
		// was captured at the top, from the job as loaded — see there for why.
		if ($forceRun === false && $isSingleRun === true && $executionThrew === false) {
			$jobData['isEnabled'] = false;
		}

		// M1: Build and write the job log BEFORE persisting lastRun/nextRun so
		// that any DB failure writing the job row cannot leave the timeline
		// advanced with no log entry as evidence.
		$logRetention = (int)($jobConfig['logRetention'] ?? 0);
		$errorRetention = (int)($jobConfig['errorRetention'] ?? 0);

		if ($executionThrew === true && $thrownException !== null) {
			// H3: write an error log for exceptions thrown during executeJob.
			$throwableFrames = [];
			foreach ($thrownException->getTrace() as $frame) {
				if (isset($frame['file']) === true) {
					$throwableFrames[] = $frame['file'] . ':' . ($frame['line'] ?? '?');
				} elseif (isset($frame['class']) === true) {
					$throwableFrames[] = $frame['class'] . ($frame['type'] ?? '::') . $frame['function'];
				} else {
					$throwableFrames[] = $frame['function'];
				}

				if (count($throwableFrames) >= 50) {
					break;
				}
			}

			$stackTrace = array_merge($stackTrace, $throwableFrames);
			$expiresDate = $this->calculateExpires(...[($errorRetention * 1000), $this->errorRetention]);
			$errorLogData = [
				'level' => 'ERROR',
				'message' => $this->truncateMessage(
					message: sprintf('%s: %s', get_class($thrownException), $thrownException->getMessage())
				),
				'executionTime' => $executionTime,
				'stackTrace' => $stackTrace,
				'expires' => null,
			];

			if ($expiresDate !== null) {
				$errorLogData['expires'] = $expiresDate->format('c');
			}

			$logEntry = $this->saveJobLog(job: $job, jobData: $jobData, logData: $errorLogData);
		} else {
			// Build success/non-error log.
			$successExpiry = $this->calculateExpires(...[($logRetention * 1000), $this->successRetention]);

			$logData = [
				'level' => 'SUCCESS',
				'message' => 'Success',
				'executionTime' => $executionTime,
				'expires' => null,
			];

			if ($successExpiry !== null) {
				$logData['expires'] = $successExpiry->format('c');
			}

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
					$logData['message'] = $this->truncateMessage(message: $result['message']);
				}

				if (isset($result['stackTrace']) === true) {
					$stackTrace = array_merge($stackTrace, $result['stackTrace']);
				}
			}//end if

			$logData['stackTrace'] = $stackTrace;
			$logEntry = $this->saveJobLog(job: $job, jobData: $jobData, logData: $logData);
		}//end if

		// M1: Advance the job's timeline only AFTER the log entry is safely written.
		$this->objectService->saveObject(
			object: $jobData,
			register: 'openconnector',
			schema: 'job',
			uuid: $job->getUuid()
		);

		// Execution-trace REQ-004: a self-minted `job`-entryPoint trace is
		// persisted here, exactly once, when the run completes. Best-effort
		// — a persistence failure MUST NOT fail the job run it is observing.
		if ($ownsJobTrace === true && $trace !== null) {
			$jobTraceStatus = 'success';
			if ($executionThrew === true) {
				$jobTraceStatus = 'failed';
			}

			$jobTraceError = null;
			if ($executionThrew === true && $thrownException !== null) {
				$jobTraceError = [
					'message' => $thrownException->getMessage(),
					'ruleType' => null,
					'ruleName' => null,
				];
			}

			try {
				$this->containerInterface->get(ExecutionTraceService::class)->persist(
					trace: $trace,
					status: $jobTraceStatus,
					error: $jobTraceError
				);
			} catch (\Throwable $exception) {
				// Best-effort: execution_trace persistence failure MUST NOT
				// fail the job run it is observing. JobService has no
				// injected logger; the job_log entry above already captures
				// the run's own outcome.
				unset($exception);
			}
		}//end if

		return $logEntry;
	}//end executeJob()

	/**
	 * Save a job log entry via ObjectService.
	 *
	 * @param ObjectEntity $job The job ObjectEntity.
	 * @param array $jobData The job data array.
	 * @param array $logData The log fields to store.
	 *
	 * @return ObjectEntity The saved job_log ObjectEntity.
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function saveJobLog(ObjectEntity $job, array $jobData, array $logData): ObjectEntity {
		$logObject = array_merge(
			[
				'jobId' => $job->getUuid(),
				'jobClass' => $jobData['jobClass'] ?? null,
				'jobListId' => $jobData['jobListId'] ?? null,
				'arguments' => $jobData['arguments'] ?? [],
				'lastRun' => $jobData['lastRun'] ?? null,
				'nextRun' => $jobData['nextRun'] ?? null,
				'created' => (new DateTime())->format('c'),
			],
			$logData
		);

		// The job_log schema types `stackTrace` as 'object or null', but it is built
		// as a numerically-indexed list of frame strings (which JSON-encodes to an
		// array). Normalise it to a string-keyed object (or null when empty) so it
		// passes OpenRegister object validation instead of failing with a 500.
		$stackTraceFrames = ($logObject['stackTrace'] ?? []);
		if (empty($stackTraceFrames) === true) {
			$logObject['stackTrace'] = null;
		} else {
			$normalisedStackTrace = [];
			foreach (array_values((array)$stackTraceFrames) as $frameIndex => $frameValue) {
				$normalisedStackTrace['frame_' . $frameIndex] = $frameValue;
			}

			$logObject['stackTrace'] = $normalisedStackTrace;
		}

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
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	public function run(): array {
		// Fetch all jobs that are enabled and whose nextRun is in the past or null.
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => 'openconnector',
					'schema' => 'job',
					'isEnabled' => true,
				],
			]
		);
		$jobs = ($matches['results'] ?? $matches);
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
				// ExecuteJob() handles its own error logging and timeline advancement
				// (H3 fix). This catch only fires if executeJob itself throws due to
				// an infrastructure failure (e.g. saveObject/saveJobLog DB error).
				// Swallow and continue so remaining jobs still execute this cron pass.
				unset($e);
				continue;
			}//end try
		}//end foreach

		return $results;
	}//end run()
}//end class
