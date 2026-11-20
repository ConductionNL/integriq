<?php

/**
 * Queues an OtelExportJob on the Nextcloud job list.
 *
 * @category BackgroundJob
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

use OCP\BackgroundJob\IJobList;

/**
 * Adds the first attempt of an {@see OtelExportJob} to the job list.
 */
class OtelExportJobScheduler implements TraceExportSchedulerInterface {
	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The background job list.
	 */
	public function __construct(
		private readonly IJobList $jobList,
	) {
	}//end __construct()

	/**
	 * Queue the first export attempt of one trace.
	 *
	 * @param string $traceId The trace id to export.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function scheduleExport(string $traceId): void {
		$this->jobList->add(OtelExportJob::class, ['traceId' => $traceId, 'attempt' => 0]);
	}//end scheduleExport()

	/**
	 * Queue the first export attempt of one span another app handed in.
	 *
	 * @param string $appId The app that sent the span; its `service.name`.
	 * @param array<string, mixed> $span The span, cleaned by the span mapper.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function scheduleForeignSpan(string $appId, array $span): void {
		$this->jobList->add(OtelExportJob::class, ['appId' => $appId, 'span' => $span, 'attempt' => 0]);
	}//end scheduleForeignSpan()
}//end class
