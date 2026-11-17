<?php

/**
 * Integriq AlertCheck: one threshold rule counted for one subject.
 *
 * Kept beside ConnectionAlertService so a check, its count and the alert it
 * writes take one value instead of the subject, the rule, the threshold and
 * the moment one by one.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;

/**
 * One rule of one source or synchronization, counted back from a moment.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */
final class AlertCheck {

	/**
	 * The subject's id.
	 *
	 * @var string
	 */
	public string $subjectId = '';

	/**
	 * The subject's name.
	 *
	 * @var string
	 */
	public string $subjectName = '';

	/**
	 * The rule: `failedCalls`, `failedRuns` or `invalidObjects`.
	 *
	 * @var string
	 */
	public string $rule = '';

	/**
	 * The threshold.
	 *
	 * @var array{count: int, windowMinutes: int}
	 */
	public array $threshold = ['count' => 0, 'windowMinutes' => 0];

	/**
	 * Constructor.
	 *
	 * @param string            $subjectType `source` or `synchronization`.
	 * @param DateTimeImmutable $now         The moment to count back from.
	 */
	public function __construct(
		public readonly string $subjectType,
		public readonly DateTimeImmutable $now,
	) {

	}//end __construct()

	/**
	 * The start of the rule's window, in Unix time.
	 *
	 * @return int The moment to count from.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
	 */
	public function since(): int {
		return $this->now->modify('-' . $this->threshold['windowMinutes'] . ' minutes')->getTimestamp();
	}//end since()

	/**
	 * What one counted record adds for this rule.
	 *
	 * @param array<string, mixed> $record A call log or a run record.
	 *
	 * @return int 1 for a failed call or a failed run, the invalid objects of a run.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
	 */
	public function valueOf(array $record): int {
		return match ($this->rule) {
			'failedCalls' => (int)(((int)($record['statusCode'] ?? 0)) >= 400),
			'failedRuns' => 1,
			default => (int)($record['invalid'] ?? 0),
		};
	}//end valueOf()
}//end class
