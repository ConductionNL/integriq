<?php

/**
 * Integriq job run.
 *
 * The state of one job execution while JobService runs it.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * One execution of a job: the job, its data as loaded, whether it is a force
 * run, and the trace and user it runs under. Built by JobService::executeJob()
 * and never handed out.
 *
 * @spec openspec/specs/job-scheduling/spec.md
 */
final class JobRun {

	/**
	 * The job's data as loaded.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $jobData;

	/**
	 * The active trace context, once the job is confirmed to run.
	 *
	 * @var ExecutionTraceContext|null
	 */
	public ?ExecutionTraceContext $trace = null;

	/**
	 * The user the job runs as, or null for the current session.
	 *
	 * @var IUser|null
	 */
	public ?IUser $user = null;

	/**
	 * The session user before the job's user was swapped in, restored after the action.
	 *
	 * @var IUser|null
	 */
	public ?IUser $priorSessionUser = null;

	/**
	 * Whether the cron loop started this run, rather than a person or an event
	 * delivery. Only a scheduled run without a userId acts as the system.
	 *
	 * @var bool
	 *
	 * @spec openspec/changes/archive/2026-11-25-scheduled-jobs-without-a-user-run-as-system/specs/job-scheduling/spec.md
	 */
	public bool $scheduled = false;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $job      The job.
	 * @param bool         $forceRun Whether this is a force run.
	 */
	public function __construct(
		public readonly ObjectEntity $job,
		public readonly bool $forceRun,
	) {
		$this->jobData = $job->getObject();

	}//end __construct()

	/**
	 * Calls the job's action as the identity the run acts under.
	 *
	 * A cron run of a job without `userId` has no user of its own: whatever
	 * session user the cron process happens to hold is an accident (none on a
	 * clean cron, an admin left behind by another job otherwise). Such a run
	 * acts as the system, through OpenRegister's SystemOperationContext, for
	 * integriq's own bookkeeping and for its target writes alike (Ruben,
	 * 25 Nov 2026). A job with `userId` keeps acting as that user, and a run
	 * started by a person or an event delivery keeps acting as the caller, so
	 * the `#[NoAdminRequired]` run endpoint never becomes a way to act as the
	 * system. When OpenRegister cannot grant the context, SystemWrite throws
	 * and the run is logged as an error rather than run as nobody.
	 *
	 * @param mixed $action    The job's action, from the container.
	 * @param array $arguments The arguments for the action.
	 *
	 * @return mixed The action's result.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemWrite is integriq's static system-write guard; there is no instance API.
	 *
	 * @spec openspec/changes/archive/2026-11-25-scheduled-jobs-without-a-user-run-as-system/specs/job-scheduling/spec.md
	 */
	public function callAction(mixed $action, array $arguments): mixed {
		if ($this->scheduled === false || $this->user !== null) {
			return $action->run($arguments);
		}

		return SystemWrite::run(
			what: sprintf('scheduled job %s', $this->job->getUuid()),
			operation: static fn (): mixed => $action->run($arguments)
		);
	}//end callAction()
}//end class
