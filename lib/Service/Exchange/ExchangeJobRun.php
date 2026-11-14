<?php

/**
 * Integriq exchange job run.
 *
 * One exchange job while {@see ExchangeJobRunner} runs it: the job entity, the
 * job data as the run changes it (status, times, gate decision, error,
 * result), and the mapping row when the job names one. Built by the runner
 * per run and never handed out.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The job an exchange run is working on.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 */
final class ExchangeJobRun {

	/**
	 * The job's mapping row, when the job names one and it was found.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $mapping = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity        $job  The job.
	 * @param array<string,mixed> $data The job data, changed as the run goes.
	 */
	public function __construct(
		public readonly ObjectEntity $job,
		public array $data,
	) {
	}//end __construct()
}//end class
