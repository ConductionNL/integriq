<?php

/**
 * Contract of the SpanMapper.
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
 * Public operations of {@see SpanMapper} that other components call.
 */
interface SpanMapperInterface {
	/**
	 * Map one trace.
	 *
	 * @param array $trace The `execution_trace` object data.
	 * @param string $serviceName The `service.name` resource attribute.
	 *
	 * @return array The OTLP JSON payload.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
	 */
	public function map(array $trace, string $serviceName): array;

	/**
	 * The span id a step gets: the one its outbound call carried in its
	 * `traceparent`, or one derived from the trace id and the step order so
	 * the same step always maps to the same span.
	 *
	 * @param array $step The step.
	 * @param string $traceId The execution trace id.
	 *
	 * @return string 16 hex characters.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function stepSpanId(array $step, string $traceId): string;
}
