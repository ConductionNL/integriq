<?php

/**
 * Contract of the JobService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\BackgroundJob\IJob;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Public operations of {@see JobService} that other components call.
 *
 * The method signatures repeat those of JobService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface JobServiceInterface {
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
	public function scheduleJob(ObjectEntity $job): ObjectEntity;

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
	public function getJobListId(IJob|string $job): ?int;

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
	public function executeJob(ObjectEntity $job, bool $forceRun = false, ?ExecutionTraceContext $trace = null): ?ObjectEntity;

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
	public function run(): array;
}
