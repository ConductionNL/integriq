<?php

/**
 * Integriq Acknowledged Record.
 *
 * The exchange job and record an authority's acknowledgement answers, read
 * from its kenmerk, what the authority answered, and once the job is found
 * its target and owning app.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

/**
 * One acknowledged record. Built and used by ExchangeAcknowledgementListener only.
 *
 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
 */
final class AcknowledgedRecord {
	/**
	 * Whether the authority accepted the record.
	 *
	 * @var bool
	 */
	public bool $accepted = false;

	/**
	 * The authority's code.
	 *
	 * @var string
	 */
	public string $signaalcode = '';

	/**
	 * The authority's description, empty when none.
	 *
	 * @var string
	 */
	public string $description = '';

	/**
	 * The job's exchange target, once the job is found.
	 *
	 * @var string
	 */
	public string $target = '';

	/**
	 * The app that owns the job, once the job is found.
	 *
	 * @var string
	 */
	public string $ownerApp = '';

	/**
	 * Constructor.
	 *
	 * @param string $jobId    The job the kenmerk names.
	 * @param string $recordId The record the kenmerk names, empty when it names none.
	 */
	public function __construct(
		public readonly string $jobId,
		public readonly string $recordId,
	) {
	}//end __construct()

	/**
	 * Read the job and record from a kenmerk `<job>:<record>`.
	 *
	 * @param string $kenmerk The correlation id echoed back by the authority.
	 *
	 * @return self The acknowledged record.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public static function fromKenmerk(string $kenmerk): self {
		$parts = explode(':', $kenmerk, 2);

		return new self(jobId: $parts[0], recordId: ($parts[1] ?? ''));
	}//end fromKenmerk()
}//end class
