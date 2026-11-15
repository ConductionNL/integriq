<?php

/**
 * Integriq outbound exchange record.
 *
 * One record as an export adapter receives it: its correlation id
 * (`<jobId>:<recordId>`) and its mapped data.
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
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

/**
 * One record sent to a target adapter.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-005-export-handlers-hand-records-to-the-existing-adapters
 */
final class OutboundRecord {

	/**
	 * Constructor.
	 *
	 * @param string              $kenmerk The correlation id, `<jobId>:<recordId>`.
	 * @param array<string,mixed> $data    The mapped record.
	 */
	public function __construct(
		public readonly string $kenmerk,
		public readonly array $data,
	) {

	}//end __construct()
}//end class
