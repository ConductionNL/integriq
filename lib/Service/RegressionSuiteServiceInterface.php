<?php

/**
 * Contract of the RegressionSuiteService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/archive/2026-11-24-automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use InvalidArgumentException;

/**
 * Public operations of {@see RegressionSuiteService} that other components call.
 */
interface RegressionSuiteServiceInterface {
	/**
	 * Run every case of a subject and record the run.
	 *
	 * @param string $subjectType `mapping` or `synchronization`.
	 * @param string $subjectId   The subject's uuid or slug, as the cases name it.
	 * @param string $userId      Who started the run.
	 *
	 * @return array<string,mixed> The run record as stored.
	 *
	 * @throws InvalidArgumentException When the subject type is not known.
	 *
	 * @spec openspec/changes/archive/2026-11-24-automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
	 */
	public function run(string $subjectType, string $subjectId, string $userId): array;
}
