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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

	/**
	 * Check and clean one span another app handed in.
	 *
	 * @param array<array-key, mixed> $span The span as the other app sent it.
	 *
	 * @return array The clean span.
	 *
	 * @throws \InvalidArgumentException When a field is malformed; the message starts with the field name.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function foreignSpan(array $span): array;

	/**
	 * Map clean spans of another app to an OTLP JSON payload.
	 *
	 * @param list<array<string, mixed>> $spans The clean spans.
	 * @param string $serviceName The dispatching app id.
	 *
	 * @return array The OTLP JSON payload.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function mapForeign(array $spans, string $serviceName): array;
}
