<?php

/**
 * Scheduling contract of the OpenTelemetry export: queue one finished trace for export.
 *
 * @category Contract
 * @package  OCA\Integriq\BackgroundJob
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

namespace OCA\Integriq\BackgroundJob;

/**
 * Queues the export of one finished trace, for callers that decide a trace is
 * due and leave the job that sends it to this component.
 */
interface TraceExportSchedulerInterface {
	/**
	 * Queue the first export attempt of one trace.
	 *
	 * @param string $traceId The trace id to export.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function scheduleExport(string $traceId): void;
}
