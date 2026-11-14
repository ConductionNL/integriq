<?php

/**
 * Integriq exchange job outcome.
 *
 * How one exchange run ends: its terminal status, the job_log message and the
 * record counts. {@see ExchangeJobRunner} saves it on the job and raises the
 * concluded event from it.
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

/**
 * The terminal state of one run.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 */
final class ExchangeJobOutcome {

	/**
	 * The record counts; none processed unless the run dispatched records.
	 *
	 * @var ExchangeRecordCounts
	 */
	public ExchangeRecordCounts $counts;

	/**
	 * Constructor.
	 *
	 * @param string $status  The terminal status.
	 * @param string $message The job_log line.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message,
	) {
		$this->counts = new ExchangeRecordCounts(processed: 0, accepted: 0);
	}//end __construct()
}//end class
