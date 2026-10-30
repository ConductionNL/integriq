<?php

/**
 * Contract of the OtelExportBreaker.
 *
 * @category Contract
 * @package  OCA\Integriq\Observability\Otel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

/**
 * Public operations of {@see OtelExportBreaker} that other components call.
 */
interface OtelExportBreakerInterface {
	/**
	 * Whether sends are paused.
	 *
	 * @param int $now The current unix time.
	 *
	 * @return bool True while the cooldown runs.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function isOpen(int $now): bool;

	/**
	 * When the current or last pause ends.
	 *
	 * @return int The unix time sends resume, 0 when sends never paused.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function openUntil(): int;

	/**
	 * Count a trace that was not queued because sends are paused, with an
	 * atomic increment of `otel_skipped_total` in the distributed cache, so
	 * the traced request writes nothing to the database per trace.
	 *
	 * @return int|null For the first trace skipped in the current pause, the
	 *                  running total of skipped traces since the cache was
	 *                  last cleared, across all pauses (per node when the
	 *                  distributed cache falls back to APCu; 0 when no
	 *                  memory cache counts them), so the caller logs one
	 *                  warning per pause; null for every later one.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function recordSkipped(): ?int;

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
	public function recordFailure(int $now): void;

	/**
	 * A successful send ends the run of failures.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function recordSuccess(): void;
}
