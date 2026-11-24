<?php

/**
 * The state of one reconciliation run.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

use OCA\Integriq\Service\SynchronizationRunLog;

/**
 * What a reconciliation run read and what it did so far.
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 */
class ReconciliationRun {

	/**
	 * The target items by id.
	 *
	 * @var array<string, array>
	 */
	public array $targetItems = [];

	/**
	 * The target ids a contract or an adoption claims.
	 *
	 * @var array<string, bool>
	 */
	public array $claimed = [];

	/**
	 * What the run did: complete, created, adopted, unmatched, failed and message.
	 *
	 * @var array{complete: bool, created: string[], adopted: array<string, string>, unmatched: string[], failed: array<string, string>, message: string}
	 */
	public array $report = ['complete' => false, 'created' => [], 'adopted' => [], 'unmatched' => [], 'failed' => [], 'message' => ''];

	/**
	 * Constructor.
	 *
	 * @param array                 $synchronization The synchronization being reconciled.
	 * @param SynchronizationRunLog $log             The run log.
	 */
	public function __construct(
		public readonly array $synchronization,
		public readonly SynchronizationRunLog $log,
	) {
	}//end __construct()

	/**
	 * Whether a target id is listed and not yet claimed.
	 *
	 * @param string $targetId The target id.
	 *
	 * @return bool True when the target lists it.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function isListed(string $targetId): bool {
		return $targetId !== '' && isset($this->targetItems[$targetId]) === true;
	}//end isListed()

	/**
	 * The listed target ids nothing claims.
	 *
	 * @return string[] The ids.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function unclaimed(): array {
		return array_values(array_map('strval', array_keys(array_diff_key($this->targetItems, $this->claimed))));
	}//end unclaimed()
}//end class
