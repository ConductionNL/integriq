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

use OCA\Integriq\Observability\Otel\OtelExportBreakerInterface;
use OCA\Integriq\Observability\Otel\OtelSettingsInterface;
use OCA\Integriq\Observability\Otel\SpanMapperInterface;
use OCA\Integriq\Observability\Otel\TraceExporterInterface;
use OCA\Integriq\Service\ExecutionTraceServiceInterface;
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
	 * @param ExecutionTraceServiceInterface $traces Reads the persisted trace.
	 * @param SpanMapperInterface $mapper Maps the trace to OTLP spans.
	 * @param TraceExporterInterface $exporter Sends the spans.
	 * @param OtelSettingsInterface $settings The export settings.
	 * @param IJobList $jobList Schedules a retry for its run time.
	 * @param LoggerInterface $logger Logs a dropped trace.
	 * @param OtelExportBreakerInterface $breaker Pauses sends during a collector outage.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ExecutionTraceServiceInterface $traces,
		private readonly SpanMapperInterface $mapper,
		private readonly TraceExporterInterface $exporter,
		private readonly OtelSettingsInterface $settings,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly OtelExportBreakerInterface $breaker,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Send the trace named in the argument, or the span another app handed
	 * in (REQ-OTEL-007), under that app's `service.name`.
	 *
	 * @param mixed $argument `{traceId, attempt, notBefore?}` or `{appId, span, attempt, notBefore?}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function run(mixed $argument): void {
		$argument = $this->argument(argument: $argument);
		$traceId = $argument['traceId'];

		// Export switched off since the trace was queued: nothing to send.
		if (($traceId === '' && $argument['span'] === []) || $this->settings->isEnabled() === false) {
			return;
		}

		$now = $this->time->getTime();
		if ($argument['notBefore'] > $now) {
			// A retry picked up before it is due (a job row from before retries
			// were scheduled) waits until then instead of being runnable at once.
			$stored = $this->stored(argument: $argument, attempt: $argument['attempt'], notBefore: $argument['notBefore']);
			$this->jobList->scheduleAfter(self::class, $argument['notBefore'], $stored);
			return;
		}

		$pausedUntil = $this->breaker->openUntil();
		if ($pausedUntil > $now) {
			// Sends are paused: wait for the pause to end without using up
			// an attempt, since nothing was sent.
			$argument['notBefore'] = $pausedUntil;
			$stored = $this->stored(argument: $argument, attempt: $argument['attempt'], notBefore: $pausedUntil);
			$this->jobList->scheduleAfter(self::class, $pausedUntil, $stored);
			return;
		}

		try {
			$this->send(argument: $argument, now: $now);
		} catch (Throwable $e) {
			$this->retryOrDrop(argument: $argument, now: $now, reason: $e->getMessage());
		}

	}//end run()

	/**
	 * The job argument, normalised.
	 *
	 * @param mixed $argument The raw argument.
	 *
	 * @return array{traceId: string, appId: string, span: array, attempt: int, notBefore: int}
	 */
	private function argument(mixed $argument): array {
		if (is_array($argument) === false) {
			$argument = [];
		}

		$span = ($argument['span'] ?? []);
		if (is_array($span) === false || (string)($argument['appId'] ?? '') === '') {
			$span = [];
		}

		$normalised = [
			'traceId' => (string)($argument['traceId'] ?? ''),
			'appId' => (string)($argument['appId'] ?? ''),
			'span' => $span,
			'attempt' => (int)($argument['attempt'] ?? 0),
			'notBefore' => (int)($argument['notBefore'] ?? 0),
		];

		return $normalised;

	}//end argument()

	/**
	 * Send one trace, or one span of another app.
	 *
	 * @param array{traceId: string, appId: string, span: array, attempt: int, notBefore: int} $argument The normalised argument.
	 * @param int $now The current unix time.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the collector failed.
	 */
	private function send(array $argument, int $now): void {
		$payload = $this->payload(argument: $argument);
		if ($payload === null) {
			return;
		}

		try {
			$this->exporter->export(payload: $payload);
		} catch (Throwable $e) {
			$this->breaker->recordFailure(now: $now);
			throw new RuntimeException($e->getMessage(), 0, $e);
		}

		$this->breaker->recordSuccess();

	}//end send()

	/**
	 * The OTLP payload to send: the persisted trace mapped under integriq's
	 * service name, or the other app's span under that app's id.
	 *
	 * @param array{traceId: string, appId: string, span: array, attempt: int, notBefore: int} $argument The normalised argument.
	 *
	 * @return array|null The payload, or null when the trace is gone.
	 */
	private function payload(array $argument): ?array {
		if ($argument['span'] !== []) {
			return $this->mapper->mapForeign(spans: [$argument['span']], serviceName: $argument['appId']);
		}

		$trace = $this->traces->findForExport(traceId: $argument['traceId']);
		if ($trace === null) {
			return null;
		}

		return $this->mapper->map(trace: $trace, serviceName: $this->settings->serviceName());

	}//end payload()

	/**
	 * The argument a rescheduled job is stored with: the trace id, or the
	 * other app's id and span, then the attempt and when it is due.
	 *
	 * @param array{traceId: string, appId: string, span: array, attempt: int, notBefore: int} $argument The normalised argument.
	 * @param int $attempt The attempt the rescheduled job makes.
	 * @param int $notBefore When it is due.
	 *
	 * @return array<string, mixed> The stored argument.
	 */
	private function stored(array $argument, int $attempt, int $notBefore): array {
		$stored = ['traceId' => $argument['traceId']];
		if ($argument['span'] !== []) {
			$stored = ['appId' => $argument['appId'], 'span' => $argument['span']];
		}

		return $stored + ['attempt' => $attempt, 'notBefore' => $notBefore];

	}//end stored()

	/**
	 * Queue a later retry, five, ten and twenty minutes out, or drop the
	 * trace with one log line once the retries are spent.
	 *
	 * @param array{traceId: string, appId: string, span: array, attempt: int, notBefore: int} $argument The argument of the failed send.
	 * @param int $now The current unix time.
	 * @param string $reason Why the send failed.
	 *
	 * @return void
	 */
	private function retryOrDrop(array $argument, int $now, string $reason): void {
		$attempt = $argument['attempt'];
		if ($attempt < self::MAX_RETRIES) {
			$notBefore = ($now + (self::RETRY_DELAY_SECONDS * (2 ** $attempt)));
			$this->jobList->scheduleAfter(self::class, $notBefore, $this->stored(argument: $argument, attempt: ($attempt + 1), notBefore: $notBefore));
			return;
		}

		$traceId = $argument['traceId'];
		if ($traceId === '') {
			$traceId = (string)($argument['span']['traceId'] ?? '');
		}

		$this->logger->warning(
			'OtelExportJob: dropped a trace after ' . ($attempt + 1) . ' failed sends: ' . $reason,
			['traceId' => $traceId]
		);

	}//end retryOrDrop()
}//end class
