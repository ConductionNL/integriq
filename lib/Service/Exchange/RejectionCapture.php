<?php

/**
 * Integriq RejectionCapture: how one exchange rejection was captured.
 *
 * Kept beside ExchangeRejectionService so a rejection row is built from the
 * capture and the rejection itself, not from the job, target, code, status
 * and time one by one.
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
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

/**
 * The job and target a rejection belongs to, its error code, status and capture time.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
 */
final class RejectionCapture {

	/**
	 * The error code.
	 *
	 * @var string
	 */
	public string $code = '';

	/**
	 * The status: failed, replayed or discarded.
	 *
	 * @var string
	 */
	public string $status = '';

	/**
	 * The capture time.
	 *
	 * @var string
	 */
	public string $capturedAt = '';

	/**
	 * Constructor.
	 *
	 * @param string $jobId  The job's uuid.
	 * @param string $target The job's target.
	 */
	public function __construct(
		public readonly string $jobId,
		public readonly string $target,
	) {

	}//end __construct()
}//end class
