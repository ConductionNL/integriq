<?php

/**
 * Contract of the ExchangeJobRunner.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Exchange
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

namespace OCA\Integriq\Service\Exchange;

/**
 * Public operations of {@see ExchangeJobRunner} that other components call.
 */
interface ExchangeJobRunnerInterface {
	/**
	 * Run one exchange job.
	 *
	 * @param string $jobId The job's uuid.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function run(string $jobId): array;
}
