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
}//end class
