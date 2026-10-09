<?php

/**
 * The seam a trace exporter implements.
 *
 * Integriq sends OTLP/HTTP JSON itself today (design D2). This interface
 * lets an SDK-based exporter, or the AppHost observability engine (ADR-040),
 * take over without touching the span mapper.
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
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

/**
 * Sends a mapped OTLP payload to the collector.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
 */
interface TraceExporterInterface {

	/**
	 * Send one OTLP `resourceSpans` payload.
	 *
	 * @param array $payload The OTLP JSON payload, as {@see SpanMapper::map()} builds it.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the collector refused or could not be reached.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
	 */
	public function export(array $payload): void;
}//end interface
