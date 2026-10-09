<?php

/**
 * Integriq OpenTelemetry export job.
 *
 * Sends one queued execution trace to the configured OpenTelemetry
 * collector, off the request or run that produced it (REQ-OTEL-002,
 * design D3). A failed send is queued again, at most three times, and then
 * dropped with one log line.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
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

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Observability\Otel\OtelSettings;
use OCA\Integriq\Observability\Otel\SpanMapper;
use OCA\Integriq\Observability\Otel\TraceExporterInterface;
use OCA\Integriq\Service\ExecutionTraceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exports one execution trace as OpenTelemetry spans.
 *
 * @psalm-api
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
 */
class OtelExportJob extends QueuedJob {

	/**
	 * How often a failed send is tried again before it is dropped.
	 *
	 * @var int
	 */
	public const MAX_RETRIES = 3;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ExecutionTraceService $traces Reads the persisted trace.
	 * @param SpanMapper $mapper Maps the trace to OTLP spans.
	 * @param TraceExporterInterface $exporter Sends the spans.
	 * @param OtelSettings $settings The export settings.
	 * @param IJobList $jobList Queues a retry.
	 * @param LoggerInterface $logger Logs a dropped trace.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ExecutionTraceService $traces,
		private readonly SpanMapper $mapper,
		private readonly TraceExporterInterface $exporter,
		private readonly OtelSettings $settings,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Send the trace named in the argument.
	 *
	 * @param mixed $argument `{traceId, attempt}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function run(mixed $argument): void {
		$traceId = '';
		$attempt = 0;
		if (is_array($argument) === true) {
			$traceId = (string)($argument['traceId'] ?? '');
			$attempt = (int)($argument['attempt'] ?? 0);
		}

		// Export switched off since the trace was queued: nothing to send.
		if ($traceId === '' || $this->settings->isEnabled() === false) {
			return;
		}

		try {
			$trace = $this->traces->findForExport(traceId: $traceId);
			if ($trace === null) {
				return;
			}

			$this->exporter->export(payload: $this->mapper->map(trace: $trace, serviceName: $this->settings->serviceName()));
		} catch (Throwable $e) {
			if ($attempt < self::MAX_RETRIES) {
				$this->jobList->add(self::class, ['traceId' => $traceId, 'attempt' => ($attempt + 1)]);
				return;
			}

			$this->logger->warning(
				'OtelExportJob: dropped a trace after ' . (self::MAX_RETRIES + 1) . ' failed sends: ' . $e->getMessage(),
				['traceId' => $traceId]
			);
		}//end try

	}//end run()
}//end class
