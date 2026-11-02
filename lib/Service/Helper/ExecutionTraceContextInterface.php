<?php

/**
 * Contract of the ExecutionTraceContext.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Helper
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

namespace OCA\Integriq\Service\Helper;

/**
 * Public operations of {@see ExecutionTraceContext} that other components call.
 */
interface ExecutionTraceContextInterface {
	/**
	 * Append one ordered step to the in-memory buffer.
	 *
	 * `$input`/`$output` MUST already be redacted by the caller via
	 * `SensitiveFieldRegistry::redactArray()` (design.md Decision 3) — this
	 * method never redacts, and never re-derives redaction from raw data.
	 *
	 * `$startedAtMicrotime`/`$finishedAtMicrotime` (as returned by
	 * `microtime(true)`) let the caller report the step's own wall-clock
	 * span; when omitted, both default to "now" so `durationMs` is `0` for
	 * steps whose caller does not track sub-step timing.
	 *
	 * @param string $type Step type: rule|mapping|synchronization|call.
	 * @param string $name Human-readable step name (rule name, mapping name, etc.).
	 * @param string|null $timing Pipeline phase the step ran in (before|after), or null when not applicable.
	 * @param string $status success|skipped|skipped_dry_run|error.
	 * @param array<string, mixed> $input Already-redacted input snapshot.
	 * @param array<string, mixed> $output Already-redacted output snapshot.
	 * @param float|null $startedAtMicrotime microtime(true) at step start; defaults to now.
	 * @param float|null $finishedAtMicrotime microtime(true) at step end; defaults to now.
	 * @param string|null $spanId The span id an outbound call carried in its traceparent, when this step made one
	 *                            (REQ-OTEL-004); the exported span then has the id the partner saw.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	public function addStep(
		string $type,
		string $name,
		?string $timing,
		string $status,
		array $input = [],
		array $output = [],
		?float $startedAtMicrotime = null,
		?float $finishedAtMicrotime = null,
		?string $spanId = null,
	): void;

	/**
	 * Get the minted traceId.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getTraceId(): string;

	/**
	 * Get the entry point that minted this context.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getEntryPoint(): string;

	/**
	 * Get the entry point's own id (endpoint/job/subscription/synchronization uuid).
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getEntryPointId(): ?string;

	/**
	 * Get the ISO 8601 timestamp this context was minted.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getStartedAt(): string;

	/**
	 * Get the microsecond start of this context.
	 *
	 * @return int Microseconds since the epoch.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
	 */
	public function getStartedAtUs(): int;

	/**
	 * Record the caller's span id from an accepted inbound traceparent.
	 *
	 * @param string $parentSpanId 16 hex characters.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function setParentSpanId(string $parentSpanId): void;

	/**
	 * The caller's span id, when the trace continues a caller's trace.
	 *
	 * @return string|null 16 hex characters, or null.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function getParentSpanId(): ?string;

	/**
	 * Continue a caller's W3C trace: exported spans and outbound
	 * traceparent headers carry this id, the record keeps its own.
	 *
	 * @param string $otelTraceId The inbound trace id (dashed uuid form).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function setOtelTraceId(string $otelTraceId): void;

	/**
	 * The inbound W3C trace id, when the execution continues a caller's
	 * trace; null when Integriq started the trace.
	 *
	 * @return string|null The dashed trace id, or null.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function getInboundOtelTraceId(): ?string;

	/**
	 * The W3C trace id this execution travels under: the caller's when one
	 * was accepted, Integriq's own otherwise.
	 *
	 * @return string The dashed trace id.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function getOtelTraceId(): string;

	/**
	 * Get the ordered step buffer.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	public function getSteps(): array;

	/**
	 * Get the original trace id this context replays, when applicable.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 */
	public function getReplayOf(): ?string;

	/**
	 * Whether this context was created by a replay.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 */
	public function isReplay(): bool;

	/**
	 * Whether this context is a dry-run preview — write-shaped stages MUST
	 * suppress their side effect (rule-pipeline REQ-RULE-011).
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-dry-run-mode-suppresses-write-shaped-rule-dispatch-req-rule-011
	 */
	public function isDryRun(): bool;

	/**
	 * Get what triggered this execution.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getTriggeredBy(): ?string;
}
