<?php

/**
 * Contract of the TraceCaseRecorder.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Regression
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Regression;

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Public operations of {@see TraceCaseRecorder} that other components call.
 */
interface TraceCaseRecorderInterface {
	/**
	 * Save one step of a trace as a test case.
	 *
	 * Values the engineer edited in the dialog (`name`, `input`,
	 * `expectedOutput`, `ignoredPaths`) take the place of the snapshot's;
	 * anything not given is the step's own snapshot, unchanged.
	 *
	 * @param string              $traceId   The trace's id.
	 * @param int                 $stepOrder The step's `order` in the trace.
	 * @param array<string,mixed> $edits     Optional `name`, `description`, `input`, `expectedOutput`, `ignoredPaths`.
	 *
	 * @return array<string,mixed> The case as stored, with its `id` and the `redactedPaths` it still holds.
	 *
	 * @throws OutOfBoundsException     When the trace or the step does not exist.
	 * @throws InvalidArgumentException When the step is not a mapping or synchronization step, or names no subject.
	 *
	 * @spec openspec/changes/archive/2026-11-24-automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-test-case-can-be-recorded-from-a-traced-execution-req-irt-002
	 */
	public function record(string $traceId, int $stepOrder, array $edits = []): array;
}
