<?php

/**
 * Queues finished execution traces for OpenTelemetry export.
 *
 * Never sends anything itself (REQ-OTEL-002, design D3): it only adds the
 * trace id to the job list for {@see \OCA\Integriq\BackgroundJob\OtelExportJob},
 * so a slow or absent collector cannot change the traced work.
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

use OCA\Integriq\BackgroundJob\OtelExportJob;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a persisted trace is exported, and queues it.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
 */
class TraceExportQueue {

	/**
	 * Constructor.
	 *
	 * @param OtelSettings $settings The export settings.
	 * @param IJobList $jobList The background job list.
	 * @param LoggerInterface $logger Logs a trace that could not be queued.
	 * @param OtelExportBreaker|null $breaker Pauses queuing during a collector outage; absent, never paused.
	 * @param ITimeFactory|null $time The clock the breaker is read against; the system clock when absent.
	 */
	public function __construct(
		private readonly OtelSettings $settings,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly ?OtelExportBreaker $breaker = null,
		private readonly ?ITimeFactory $time = null,
	) {

	}//end __construct()

	/**
	 * Queue a finished, sampled trace. A running trace (an approval
	 * suspension) waits for its final persist, and a dry run is never sent.
	 * While sends are paused after repeated collector failures nothing is
	 * queued, so an outage cannot pile up jobs; each sampled trace skipped
	 * then is counted, and the first one in a pause is logged. A failure to
	 * queue is logged and never reaches the traced work.
	 *
	 * @param ExecutionTraceContext $trace The persisted context.
	 * @param string $status The persisted status.
	 *
	 * @return bool True when the trace was queued.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function queue(ExecutionTraceContext $trace, string $status): bool {
		if ($status === 'running' || $trace->isDryRun() === true) {
			return false;
		}

		try {
			if ($this->settings->isEnabled() === false
				|| $this->settings->isSampled(traceId: $trace->getTraceId(), status: $status, isReplay: $trace->isReplay()) === false
			) {
				return false;
			}

			if ($this->breaker?->isOpen(now: ($this->time?->getTime() ?? time())) === true) {
				$this->skipWhilePaused(breaker: $this->breaker, traceId: $trace->getTraceId());
				return false;
			}

			$this->jobList->add(OtelExportJob::class, ['traceId' => $trace->getTraceId(), 'attempt' => 0]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TraceExportQueue: could not queue the trace for OpenTelemetry export: ' . $e->getMessage(),
				['traceId' => $trace->getTraceId()]
			);

			return false;
		}

		return true;

	}//end queue()

	/**
	 * Count a sampled trace skipped because sends are paused, and log one
	 * warning, with the running total of skipped traces, for the first one
	 * in each pause.
	 *
	 * @param OtelExportBreaker $breaker The open breaker.
	 * @param string $traceId The skipped trace.
	 *
	 * @return void
	 */
	private function skipWhilePaused(OtelExportBreaker $breaker, string $traceId): void {
		$skipped = $breaker->recordSkipped();
		if ($skipped === null) {
			return;
		}

		$this->logger->warning(
			'TraceExportQueue: OpenTelemetry sends are paused after repeated collector failures; traces finished before '
			. date('c', $breaker->openUntil()) . ' are not exported.',
			['traceId' => $traceId, 'skippedTotal' => $skipped]
		);

	}//end skipWhilePaused()
}//end class
