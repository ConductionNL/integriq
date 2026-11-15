<?php

/**
 * Integriq exchange hand-off.
 *
 * One exchange job's mapped records on their way to the target's adapter:
 * the job, its target and direction, its scope, the records, and for an
 * import that lands in the owning app, that app and its reference.
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
 * Hand one job's records to its target.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-005-export-handlers-hand-records-to-the-existing-adapters
 */
final class ExchangeHandOff {

	/**
	 * Constructor.
	 *
	 * @param string                           $jobId     The job's uuid.
	 * @param string                           $target    The target id.
	 * @param string                           $direction The direction.
	 * @param array<string,mixed>              $scope     The job's scope.
	 * @param array<int, array<string, mixed>> $records   `{recordId, sourceKind, data}`, data already mapped.
	 * @param string                           $ownerApp  The owning app, for an import that lands there.
	 * @param string                           $ownerRef  The owner's reference, for an import that lands there.
	 */
	public function __construct(
		public readonly string $jobId,
		public readonly string $target,
		public readonly string $direction,
		public readonly array $scope,
		public readonly array $records,
		public readonly string $ownerApp='',
		public readonly string $ownerRef='',
	) {

	}//end __construct()
}//end class
