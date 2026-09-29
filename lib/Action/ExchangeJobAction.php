<?php

/**
 * Integriq exchange job action.
 *
 * The `jobClass` of every exchange job: JobService resolves it from the
 * container and calls run() on the scheduler's pass.
 *
 * @category Action
 * @package  OCA\Integriq\Action
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Action;

use OCA\Integriq\Service\Exchange\ExchangeJobRunner;

/**
 * Runs one exchange job (design D4).
 *
 * The job's own uuid arrives as `_jobId`, passed by JobService::executeJob()
 * next to `_executionTrace`; an exchange job keeps its `arguments` empty.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 */
class ExchangeJobAction {

	/**
	 * Constructor.
	 *
	 * @param ExchangeJobRunner $runner The runner.
	 */
	public function __construct(
		private readonly ExchangeJobRunner $runner,
	) {

	}//end __construct()

	/**
	 * Run the exchange job named by the arguments.
	 *
	 * @param array<string,mixed> $arguments The job arguments, carrying `_jobId`.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function run(array $arguments): array {
		$jobId = (string)($arguments['_jobId'] ?? ($arguments['jobId'] ?? ''));
		if ($jobId === '') {
			return ['level' => 'ERROR', 'message' => 'An exchange job ran without its own id.'];
		}

		return $this->runner->run(jobId: $jobId);

	}//end run()
}//end class
