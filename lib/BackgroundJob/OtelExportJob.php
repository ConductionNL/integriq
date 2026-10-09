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

use OCA\Integriq\Observability\Otel\OtelExportBreaker;
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
	 * @param IJobList $jobList Schedules a retry for its run time.
	 * @param LoggerInterface $logger Logs a dropped trace.
	 * @param OtelExportBreaker $breaker Pauses sends during a collector outage.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ExecutionTraceService $traces,
		private readonly SpanMapper $mapper,
		private readonly TraceExporterInterface $exporter,
		private readonly OtelSettings $settings,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly OtelExportBreaker $breaker,
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
		$argument = $this->argument(argument: $argument);
		$traceId = $argument['traceId'];

		// Export switched off since the trace was queued: nothing to send.
		if ($traceId === '' || $this->settings->isEnabled() === false) {
			return;
		}

		$now = $this->time->getTime();
		if ($argument['notBefore'] > $now) {
			// A retry picked up before it is due (a job row from before retries
			// were scheduled) waits until then instead of being runnable at once.
			$this->jobList->scheduleAfter(self::class, $argument['notBefore'], $argument);
			return;
		}

		$pausedUntil = $this->breaker->openUntil();
		if ($pausedUntil > $now) {
			// Sends are paused: wait for the pause to end without using up
			// an attempt, since nothing was sent.
			$argument['notBefore'] = $pausedUntil;
			$this->jobList->scheduleAfter(self::class, $pausedUntil, $argument);
			return;
		}

		try {
			$this->send(traceId: $traceId, now: $now);
		} catch (Throwable $e) {
			$this->retryOrDrop(traceId: $traceId, attempt: $argument['attempt'], now: $now, reason: $e->getMessage());
		}

	}//end run()

	/**
	 * The job argument, normalised.
	 *
	 * @param mixed $argument The raw argument.
	 *
	 * @return array{traceId: string, attempt: int, notBefore: int}
	 */
	private function argument(mixed $argument): array {
		if (is_array($argument) === false) {
			$argument = [];
		}

		$normalised = [
			'traceId' => (string)($argument['traceId'] ?? ''),
			'attempt' => (int)($argument['attempt'] ?? 0),
			'notBefore' => (int)($argument['notBefore'] ?? 0),
		];

		return $normalised;

	}//end argument()

	/**
	 * Send one trace.
	 *
	 * @param string $traceId The trace to send.
	 * @param int $now The current unix time.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the collector failed.
	 */
	private function send(string $traceId, int $now): void {
		$trace = $this->traces->findForExport(traceId: $traceId);
		if ($trace === null) {
			return;
		}

		try {
			$this->exporter->export(payload: $this->mapper->map(trace: $trace, serviceName: $this->settings->serviceName()));
		} catch (Throwable $e) {
			$this->breaker->recordFailure(now: $now);
			throw new RuntimeException($e->getMessage(), 0, $e);
		}

		$this->breaker->recordSuccess();

	}//end send()

	/**
	 * Queue a later retry, five, ten and twenty minutes out, or drop the
	 * trace with one log line once the retries are spent.
	 *
	 * @param string $traceId The trace.
	 * @param int $attempt The attempt that just failed.
	 * @param int $now The current unix time.
	 * @param string $reason Why the send failed.
	 *
	 * @return void
	 */
	private function retryOrDrop(string $traceId, int $attempt, int $now, string $reason): void {
		if ($attempt < self::MAX_RETRIES) {
			$notBefore = ($now + (self::RETRY_DELAY_SECONDS * (2 ** $attempt)));
			$this->jobList->scheduleAfter(
				self::class,
				$notBefore,
				[
					'traceId' => $traceId,
					'attempt' => ($attempt + 1),
					'notBefore' => $notBefore,
				]
			);
			return;
		}

		$this->logger->warning(
			'OtelExportJob: dropped a trace after ' . ($attempt + 1) . ' failed sends: ' . $reason,
			['traceId' => $traceId]
		);

	}//end retryOrDrop()
}//end class
