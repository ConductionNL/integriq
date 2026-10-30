<?php

/**
 * Integriq JobService.
 *
 * Service class for handling job execution logic in the Integriq application.
 * This service manages job retrieval, validation, execution, and logging.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Service;

use DateTime;
use Exception;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUser;
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
class JobService implements JobServiceInterface {

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
		if ($appConfig->hasKey(app: 'integriq', key: 'retention') === true) {
			$retentionPayload = json_decode(
				$appConfig->getValueString(app: 'integriq', key: 'retention'),
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
	 *
	 * @orphaned-write-capability exclude Deferred, NOT dismissed — tracked in
	 * ConductionNL/integriq#1534. This method genuinely has zero production
	 * callers, verified on both `origin/development` and the app-id rename
	 * branch, so the finding is pre-existing and real rather than rename damage.
	 * If it is what it looks like, jobs never get their `jobListId` stamped and
	 * so never reach Nextcloud's scheduler — failing silently, the same family
	 * as the stored-jobClass defect. Wiring or removing it is unrelated
	 * behaviour change that does not belong in an app-id rename; #1534 carries
	 * the symptoms to check.
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
		if (isset($jobData['jobListId']) === true
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
				register: 'integriq',
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
			$this->jobList->scheduleAfter(\OCA\Integriq\BackgroundJob\JobTask::class, $runAfter, $arguments);
		}

		if ($scheduleAfter === null) {
			$this->jobList->add(\OCA\Integriq\BackgroundJob\JobTask::class, $arguments);
		}

		// Set the job list id.
		$jobData['jobListId'] = $this->getJobListId(job: \OCA\Integriq\BackgroundJob\JobTask::class);
		// Save the job to the database.
		return $this->objectService->saveObject(
			object: $jobData,
			register: 'integriq',
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

		$gate = $this->checkJobMayRun(job: $job, jobData: $jobData, forceRun: $forceRun);
		if ($gate['run'] === false) {
			return $gate['log'];
		}

		// Execution-trace REQ-001: minted only once the job is confirmed to run,
		// so a cron tick that finds nothing due produces no trace noise. A trace
		// supplied by `ExecutionTraceService::replay()` is reused instead and
		// persisted by its owner.
		$ownsJobTrace = ($trace === null);
		$trace = ($trace ?? $this->mintJobTrace(job: $job, forceRun: $forceRun));

		$outcome = $this->runJobAction(job: $job, jobData: $jobData, trace: $trace, user: $gate['user']);
		$logEntry = $this->recordJobRun(job: $job, jobData: $jobData, forceRun: $forceRun, outcome: $outcome);

		if ($ownsJobTrace === true) {
			$this->persistJobTrace(trace: $trace, exception: $outcome['exception']);
		}

		return $logEntry;
	}//end executeJob()

	/**
	 * Decides whether a job may run now, and resolves the user it runs as.
	 *
	 * A disabled job and a job whose configured user is missing are skipped with a
	 * WARNING log; a job that is not yet due is skipped silently. A force run
	 * ignores the enabled flag and the schedule.
	 *
	 * @param ObjectEntity $job The job.
	 * @param array $jobData The job's data as loaded.
	 * @param bool $forceRun Whether this is a force run.
	 *
	 * @return array{run: bool, log: ObjectEntity|null, user: IUser|null}
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function checkJobMayRun(ObjectEntity $job, array $jobData, bool $forceRun): array {
		if ($forceRun === false && ($jobData['isEnabled'] ?? true) === false) {
			$log = $this->saveJobLog(
				job: $job,
				jobData: $jobData,
				logData: ['level' => 'WARNING', 'message' => 'This job is disabled']
			);
			return ['run' => false, 'log' => $log, 'user' => null];
		}

		if ($this->isJobDue(jobData: $jobData, forceRun: $forceRun) === false) {
			return ['run' => false, 'log' => null, 'user' => null];
		}

		$userId = ($jobData['userId'] ?? null);
		if (empty($userId) === true) {
			return ['run' => true, 'log' => null, 'user' => null];
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			// A deleted user is skipped rather than crashing the identity swap.
			$message = sprintf('Configured userId "%s" does not exist; skipping job.', $userId);
			$log = $this->saveJobLog(job: $job, jobData: $jobData, logData: ['level' => 'WARNING', 'message' => $message]);
			return ['run' => false, 'log' => $log, 'user' => null];
		}

		return ['run' => true, 'log' => null, 'user' => $user];
	}//end checkJobMayRun()

	/**
	 * Checks whether a job's next run has arrived.
	 *
	 * @param array $jobData The job's data.
	 * @param bool $forceRun Whether this is a force run, which is always due.
	 *
	 * @return bool True when the job is due.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function isJobDue(array $jobData, bool $forceRun): bool {
		$nextRunStr = ($jobData['nextRun'] ?? null);
		if ($forceRun === true || $nextRunStr === null) {
			return true;
		}

		return (new DateTime($nextRunStr) > new DateTime()) === false;
	}//end isJobDue()

	/**
	 * Mints the `job`-entryPoint trace context for a job run.
	 *
	 * @param ObjectEntity $job The job.
	 * @param bool $forceRun Whether this is a force (manual) run.
	 *
	 * @return ExecutionTraceContext The new trace context.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	private function mintJobTrace(ObjectEntity $job, bool $forceRun): ExecutionTraceContext {
		$triggeredBy = 'cron';
		if ($forceRun === true) {
			$triggeredBy = 'manual';
		}

		return new ExecutionTraceContext(
			entryPoint: 'job',
			entryPointId: $job->getUuid(),
			triggeredBy: $triggeredBy
		);
	}//end mintJobTrace()

	/**
	 * Runs the job's action, as the job's user when one is configured.
	 *
	 * The prior session user is always restored, so identity does not bleed across
	 * jobs in the same cron pass (#1006). A thrown exception is captured, not
	 * rethrown, so the caller can log it.
	 *
	 * @param ObjectEntity $job The job.
	 * @param array $jobData The job's data.
	 * @param ExecutionTraceContext $trace The active trace context.
	 * @param IUser|null $user The user to run as, or null for the current session.
	 *
	 * @return array{result: mixed, exception: \Throwable|null, executionTime: int}
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function runJobAction(ObjectEntity $job, array $jobData, ExecutionTraceContext $trace, ?IUser $user): array {
		$priorSessionUser = $this->userSession->getUser();
		if ($user !== null) {
			// Volatile: setUser() would also write the session, and a fatal skips
			// the restore below. See ADR-099.
			$this->userSession->setVolatileActiveUser($user);
		}

		$timeStart = microtime(true);
		$action = $this->containerInterface->get($jobData['jobClass']);
		$arguments = $this->buildJobArguments(job: $job, jobData: $jobData, trace: $trace);

		$result = null;
		$exception = null;
		try {
			$result = $action->run($arguments);
		} catch (\Throwable $e) {
			$exception = $e;
		} finally {
			if ($user !== null) {
				$this->userSession->setVolatileActiveUser($priorSessionUser);
			}
		}

		// The job_log schema requires an integer number of milliseconds.
		$executionTime = (int)round((microtime(true) - $timeStart) * 1000);

		return ['result' => $result, 'exception' => $exception, 'executionTime' => $executionTime];
	}//end runJobAction()

	/**
	 * Builds the arguments passed to a job's action.
	 *
	 * Adds the active trace context and the job's own uuid; neither is persisted
	 * as part of the job's `arguments` field.
	 *
	 * @param ObjectEntity $job The job.
	 * @param array $jobData The job's data.
	 * @param ExecutionTraceContext $trace The active trace context.
	 *
	 * @return array The action arguments.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function buildJobArguments(ObjectEntity $job, array $jobData, ExecutionTraceContext $trace): array {
		$arguments = ($jobData['arguments'] ?? []);
		if (is_array($arguments) === false) {
			$arguments = [];
		}

		$arguments['_executionTrace'] = $trace;
		$arguments['_jobId'] = $job->getUuid();

		return $arguments;
	}//end buildJobArguments()

	/**
	 * Writes the job log for a run, then advances the job's timeline.
	 *
	 * The log is written first so a failure saving the job row cannot leave the
	 * timeline advanced with no log entry as evidence.
	 *
	 * @param ObjectEntity $job The job.
	 * @param array $jobData The job's data as loaded.
	 * @param bool $forceRun Whether this is a force run, which keeps the schedule.
	 * @param array{result: mixed, exception: \Throwable|null, executionTime: int} $outcome The run outcome.
	 *
	 * @return ObjectEntity The job log entry.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function recordJobRun(ObjectEntity $job, array $jobData, bool $forceRun, array $outcome): ObjectEntity {
		$jobConfig = $jobData;
		$jobData['lastRun'] = (new DateTime())->format('c');
		if ($forceRun === false) {
			$jobData['nextRun'] = $this->calculateNextRun(jobConfig: $jobConfig, outcome: $outcome)->format('c');
			if ($this->isSingleRunJob(jobConfig: $jobConfig) === true && $outcome['exception'] === null) {
				$jobData['isEnabled'] = false;
			}
		}

		$stackTrace = [];
		if ($forceRun === true) {
			$stackTrace[] = 'Doing a force run for this job, ignoring "enabled" & "nextRun" check...';
		}

		$logData = $this->buildJobLogData(jobConfig: $jobConfig, outcome: $outcome, stackTrace: $stackTrace);
		$logEntry = $this->saveJobLog(job: $job, jobData: $jobData, logData: $logData);

		$this->objectService->saveObject(
			object: $jobData,
			register: 'integriq',
			schema: 'job',
			uuid: $job->getUuid()
		);

		return $logEntry;
	}//end recordJobRun()

	/**
	 * Checks whether a job runs only once.
	 *
	 * The schema declares `singleRun`; `isSingleRun` is still accepted as the
	 * spelling this service read before.
	 *
	 * @param array $jobConfig The job's data as loaded.
	 *
	 * @return bool True for a single-run job.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function isSingleRunJob(array $jobConfig): bool {
		return ($jobConfig['singleRun'] ?? null) === true || ($jobConfig['isSingleRun'] ?? null) === true;
	}//end isSingleRunJob()

	/**
	 * Calculates a job's next run, truncated to the minute.
	 *
	 * After a failure the job advances by its interval, so it doesn't block the
	 * next cron tick.
	 *
	 * @param array $jobConfig The job's data as loaded.
	 * @param array{result: mixed, exception: \Throwable|null, executionTime: int} $outcome The run outcome.
	 *
	 * @return DateTime The next run.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function calculateNextRun(array $jobConfig, array $outcome): DateTime {
		if ($outcome['exception'] !== null) {
			$nextRunDt = new DateTime('now + ' . ((int)($jobConfig['interval'] ?? 0)) . ' seconds');
		} else {
			$nextRunDt = $this->calculateNextRunAfterResult(jobConfig: $jobConfig, result: $outcome['result']);
		}

		$nextRunDt->setTime(hour: (int)$nextRunDt->format('H'), minute: (int)$nextRunDt->format('i'));

		return $nextRunDt;
	}//end calculateNextRun()

	/**
	 * Calculates the next run after a run that returned normally.
	 *
	 * A batched action with work left (`resumeSoon`) runs again on the next tick;
	 * a later rate-limit `nextRun` in the result, rounded up to the minute, wins.
	 *
	 * @param array $jobConfig The job's data as loaded.
	 * @param mixed $result The action's result.
	 *
	 * @return DateTime The next run.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function calculateNextRunAfterResult(array $jobConfig, mixed $result): DateTime {
		$nextRunDt = new DateTime('now + ' . ($jobConfig['interval'] ?? 0) . ' seconds');
		if (($result['resumeSoon'] ?? false) === true) {
			$nextRunDt = new DateTime();
		}

		if (isset($result['nextRun']) === false) {
			return $nextRunDt;
		}

		$nextRunRateLimit = DateTime::createFromFormat('U', (string)$result['nextRun'], $nextRunDt->getTimezone());
		if ($nextRunRateLimit === false) {
			return $nextRunDt;
		}

		if ($nextRunRateLimit->format('s') !== '00') {
			$nextRunRateLimit->modify('next minute');
		}

		if ($nextRunRateLimit > $nextRunDt) {
			return $nextRunRateLimit;
		}

		return $nextRunDt;
	}//end calculateNextRunAfterResult()

	/**
	 * Builds the job log data for a run.
	 *
	 * @param array $jobConfig The job's data as loaded.
	 * @param array{result: mixed, exception: \Throwable|null, executionTime: int} $outcome The run outcome.
	 * @param string[] $stackTrace The stack trace collected so far.
	 *
	 * @return array The log data.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function buildJobLogData(array $jobConfig, array $outcome, array $stackTrace): array {
		if ($outcome['exception'] !== null) {
			return $this->buildJobErrorLogData(jobConfig: $jobConfig, outcome: $outcome, stackTrace: $stackTrace);
		}

		$expiry = $this->calculateExpires(...[((int)($jobConfig['logRetention'] ?? 0) * 1000), $this->successRetention]);
		$logData = [
			'level' => 'SUCCESS',
			'message' => 'Success',
			'executionTime' => $outcome['executionTime'],
			'expires' => $expiry?->format('c'),
		];

		$result = $outcome['result'];
		if (is_array($result) === true) {
			$logData = $this->applyJobResultToLogData(logData: $logData, result: $result, jobConfig: $jobConfig);
			if (isset($result['stackTrace']) === true) {
				$stackTrace = array_merge($stackTrace, $result['stackTrace']);
			}
		}

		$logData['stackTrace'] = $stackTrace;

		return $logData;
	}//end buildJobLogData()

	/**
	 * Applies the level and message an action returned to the log data.
	 *
	 * A level other than SUCCESS takes the error retention for its expiry.
	 *
	 * @param array $logData The log data so far.
	 * @param array $result The action's result.
	 * @param array $jobConfig The job's data as loaded.
	 *
	 * @return array The updated log data.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function applyJobResultToLogData(array $logData, array $result, array $jobConfig): array {
		if (isset($result['level']) === true) {
			$logData['level'] = $result['level'];
			if ($result['level'] !== 'SUCCESS') {
				$logData['expires'] = $this->calculateErrorExpiry(jobConfig: $jobConfig)?->format('c');
			}
		}

		if (isset($result['message']) === true) {
			$logData['message'] = $this->truncateMessage(message: $result['message']);
		}

		return $logData;
	}//end applyJobResultToLogData()

	/**
	 * Builds the job log data for a run whose action threw.
	 *
	 * @param array $jobConfig The job's data as loaded.
	 * @param array{result: mixed, exception: \Throwable, executionTime: int} $outcome The run outcome.
	 * @param string[] $stackTrace The stack trace collected so far.
	 *
	 * @return array The log data.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function buildJobErrorLogData(array $jobConfig, array $outcome, array $stackTrace): array {
		$exception = $outcome['exception'];
		$stackTrace = array_merge($stackTrace, $this->describeThrowableFrames(exception: $exception));
		$expiresDate = $this->calculateErrorExpiry(jobConfig: $jobConfig);

		return [
			'level' => 'ERROR',
			'message' => $this->truncateMessage(
				message: sprintf('%s: %s', get_class($exception), $exception->getMessage())
			),
			'executionTime' => $outcome['executionTime'],
			'stackTrace' => $stackTrace,
			'expires' => $expiresDate?->format('c'),
		];
	}//end buildJobErrorLogData()

	/**
	 * Calculates the expiry of an error log for a job.
	 *
	 * @param array $jobConfig The job's data as loaded.
	 *
	 * @return \DateTime|null The expiry, or null to keep the log indefinitely.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function calculateErrorExpiry(array $jobConfig): ?\DateTime {
		$errorRetention = (int)($jobConfig['errorRetention'] ?? 0);

		return $this->calculateExpires(...[($errorRetention * 1000), $this->errorRetention]);
	}//end calculateErrorExpiry()

	/**
	 * Describes up to 50 frames of a throwable's trace as `file:line` or `Class::function`.
	 *
	 * @param \Throwable $exception The throwable.
	 *
	 * @return string[] The frame descriptions.
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function describeThrowableFrames(\Throwable $exception): array {
		$frames = [];
		foreach (array_slice($exception->getTrace(), 0, 50) as $frame) {
			if (isset($frame['file']) === true) {
				$frames[] = $frame['file'] . ':' . ($frame['line'] ?? '?');
			} elseif (isset($frame['class']) === true) {
				$frames[] = $frame['class'] . ($frame['type'] ?? '::') . $frame['function'];
			} else {
				$frames[] = $frame['function'];
			}
		}

		return $frames;
	}//end describeThrowableFrames()

	/**
	 * Persists a self-minted job trace once the run completes (execution-trace REQ-004).
	 *
	 * Best-effort: a persistence failure must not fail the job run it observes.
	 *
	 * @param ExecutionTraceContext $trace The trace context.
	 * @param \Throwable|null $exception The exception the action threw, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	private function persistJobTrace(ExecutionTraceContext $trace, ?\Throwable $exception): void {
		$status = 'success';
		$error = null;
		if ($exception !== null) {
			$status = 'failed';
			$error = ['message' => $exception->getMessage(), 'ruleType' => null, 'ruleName' => null];
		}

		try {
			$this->containerInterface->get(ExecutionTraceService::class)->persist(
				trace: $trace,
				status: $status,
				error: $error
			);
		} catch (\Throwable $persistError) {
			// JobService has no injected logger; the job_log entry already
			// captures the run's own outcome.
			unset($persistError);
		}
	}//end persistJobTrace()

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
			register: 'integriq',
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
					'register' => 'integriq',
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
