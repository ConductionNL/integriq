<?php

/**
 * Integriq OpenTelemetry export job.
 *
 * Sends one queued execution trace to the configured OpenTelemetry
 * collector, off the request or run that produced it (REQ-OTEL-002,
 * design D3). A failed send is queued again with a growing delay, at most
 * three times, and then dropped with one log line. After five failed sends
 * in a row the job sends nothing for five minutes (a circuit breaker), so
 * a collector outage cannot tie up cron with timeouts.
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
use RuntimeException;
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
	 * The delay before the first retry, doubled for each further one.
	 *
	 * @var int
	 */
	public const RETRY_DELAY_SECONDS = 300;

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
	 * @param mixed $argument `{traceId, attempt, notBefore?}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function run(mixed $argument): void {
		$traceId = '';
		$attempt = 0;
		$notBefore = 0;
		if (is_array($argument) === true) {
			$traceId = (string)($argument['traceId'] ?? '');
			$attempt = (int)($argument['attempt'] ?? 0);
			$notBefore = (int)($argument['notBefore'] ?? 0);
		}

		// Export switched off since the trace was queued: nothing to send.
		if ($traceId === '' || $this->settings->isEnabled() === false) {
			return;
		}

		$now = $this->time->getTime();
		if ($notBefore > $now) {
			// A delayed retry that is not due yet waits for a later cron run.
			$this->jobList->add(self::class, $argument);
			return;
		}

		try {
			if ($this->settings->isBreakerOpen(now: $now) === true) {
				throw new RuntimeException('Sends are paused after repeated collector failures.');
			}

			$trace = $this->traces->findForExport(traceId: $traceId);
			if ($trace === null) {
				return;
			}

			try {
				$this->exporter->export(payload: $this->mapper->map(trace: $trace, serviceName: $this->settings->serviceName()));
			} catch (Throwable $e) {
				$this->settings->recordSendFailure(now: $now);
				throw $e;
			}

			$this->settings->recordSendSuccess();
		} catch (Throwable $e) {
			if ($attempt < self::MAX_RETRIES) {
				$this->jobList->add(
					self::class,
					[
						'traceId' => $traceId,
						'attempt' => ($attempt + 1),
						'notBefore' => ($now + (self::RETRY_DELAY_SECONDS * (2 ** $attempt))),
					]
				);
				return;
			}

			$this->logger->warning(
				'OtelExportJob: dropped a trace after ' . (self::MAX_RETRIES + 1) . ' failed sends: ' . $e->getMessage(),
				['traceId' => $traceId]
			);
		}//end try

	}//end run()
}//end class
