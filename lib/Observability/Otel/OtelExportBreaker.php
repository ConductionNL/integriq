<?php

/**
 * Integriq OpenTelemetry export circuit breaker.
 *
 * Counts failed sends to the collector; after a run of them, sends pause
 * for a cooldown so a collector outage cannot tie up cron with timeouts
 * (REQ-OTEL-002).
 *
 * @category Observability
 * @package  OCA\Integriq\Observability\Otel
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
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

use OCP\IAppConfig;

/**
 * Pauses sends after repeated collector failures.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
 */
class OtelExportBreaker {

	/**
	 * The app id the breaker state is stored under.
	 *
	 * @var string
	 */
	private const APP_ID = 'integriq';

	/**
	 * Consecutive failed sends after which sends pause.
	 *
	 * @var int
	 */
	public const THRESHOLD = 5;

	/**
	 * Seconds sends stay paused once the breaker opened.
	 *
	 * @var int
	 */
	public const COOLDOWN_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the failure count and the pause end, shared by every cron worker.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Whether sends are paused.
	 *
	 * @param int $now The current unix time.
	 *
	 * @return bool True while the cooldown runs.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function isOpen(int $now): bool {
		return $this->openUntil() > $now;

	}//end isOpen()

	/**
	 * When the current or last pause ends.
	 *
	 * @return int The unix time sends resume, 0 when sends never paused.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function openUntil(): int {
		return $this->appConfig->getValueInt(self::APP_ID, 'otel_breaker_open_until', 0);

	}//end openUntil()

	/**
	 * Count a trace that was not queued because sends are paused, in the
	 * `otel_skipped_total` app setting.
	 *
	 * @return bool True for the first trace skipped in the current pause,
	 *              so the caller logs one warning per pause, not per trace.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function recordSkipped(): bool {
		$skipped = ($this->appConfig->getValueInt(self::APP_ID, 'otel_skipped_total', 0) + 1);
		$this->appConfig->setValueInt(self::APP_ID, 'otel_skipped_total', $skipped);

		$openUntil = $this->openUntil();
		if ($this->appConfig->getValueInt(self::APP_ID, 'otel_skipped_warned_until', 0) === $openUntil) {
			return false;
		}

		$this->appConfig->setValueInt(self::APP_ID, 'otel_skipped_warned_until', $openUntil);

		return true;

	}//end recordSkipped()

	/**
	 * Count a failed send; the threshold-th failure in a row pauses sends
	 * for the cooldown and starts the count again.
	 *
	 * @param int $now The current unix time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function recordFailure(int $now): void {
		$failures = ($this->appConfig->getValueInt(self::APP_ID, 'otel_breaker_failures', 0) + 1);
		if ($failures >= self::THRESHOLD) {
			$this->appConfig->setValueInt(self::APP_ID, 'otel_breaker_open_until', ($now + self::COOLDOWN_SECONDS));
			$failures = 0;
		}

		$this->appConfig->setValueInt(self::APP_ID, 'otel_breaker_failures', $failures);

	}//end recordFailure()

	/**
	 * A successful send ends the run of failures.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function recordSuccess(): void {
		if ($this->appConfig->getValueInt(self::APP_ID, 'otel_breaker_failures', 0) !== 0) {
			$this->appConfig->setValueInt(self::APP_ID, 'otel_breaker_failures', 0);
		}

	}//end recordSuccess()
}//end class
