<?php

/**
 * Integriq exchange record counts.
 *
 * How many records one exchange run processed and how many the target
 * accepted, and the result block {@see ExchangeJobRunner} stores on the job
 * and raises with the concluded event.
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
 * Processed and accepted records of one run.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 */
final class ExchangeRecordCounts {

	/**
	 * Constructor.
	 *
	 * @param int $processed Records processed.
	 * @param int $accepted  Records accepted.
	 */
	public function __construct(
		public readonly int $processed,
		public readonly int $accepted,
	) {
	}//end __construct()

	/**
	 * The result block.
	 *
	 * @return array{recordsProcessed: int, recordsAccepted: int, recordsRejected: int, runId: string, artefactRef: null}
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function result(): array {
		return [
			'recordsProcessed' => $this->processed,
			'recordsAccepted' => $this->accepted,
			'recordsRejected' => ($this->processed - $this->accepted),
			'runId' => '',
			'artefactRef' => null,
		];

	}//end result()
}//end class
