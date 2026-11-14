<?php

/**
 * Integriq SpanExportRequested listener.
 *
 * Answers {@see SpanExportRequestedEvent}: checks every span another app
 * handed in, and queues the valid, sampled ones for the same export job,
 * sampler and exporter as integriq's own traces, under the dispatching
 * app's `service.name` (REQ-OTEL-007). It needs no Nextcloud session and
 * never throws into the sender.
 *
 * @category Listener
 * @package  OCA\Integriq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Listener;

use InvalidArgumentException;
use OCA\Integriq\BackgroundJob\TraceExportSchedulerInterface;
use OCA\Integriq\Event\SpanExportRequestedEvent;
use OCA\Integriq\Observability\Otel\OtelExportBreakerInterface;
use OCA\Integriq\Observability\Otel\OtelSettingsInterface;
use OCA\Integriq\Observability\Otel\SpanMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues the spans another app hands in.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
 */
class SpanExportRequestedListener implements IEventListener {

	/**
	 * The most spans one dispatch may carry.
	 *
	 * @var int
	 */
	public const MAX_SPANS = 100;

	/**
	 * Constructor.
	 *
	 * @param OtelSettingsInterface $settings Whether export is on, and the sampler.
	 * @param SpanMapperInterface $mapper Checks and cleans each span.
	 * @param TraceExportSchedulerInterface $scheduler Queues the export job of a span.
	 * @param OtelExportBreakerInterface $breaker Pauses queuing during a collector outage.
	 * @param ITimeFactory $time The clock the breaker is read against.
	 * @param LoggerInterface $logger Logs a span that could not be queued.
	 */
	public function __construct(
		private readonly OtelSettingsInterface $settings,
		private readonly SpanMapperInterface $mapper,
		private readonly TraceExportSchedulerInterface $scheduler,
		private readonly OtelExportBreakerInterface $breaker,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Check, sample and queue the spans, and answer on the event.
	 *
	 * @param Event $event The command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function handle(Event $event): void {
		if (($event instanceof SpanExportRequestedEvent) === false) {
			return;
		}

		$spans = $event->getSpans();
		try {
			$refusal = $this->refusal(event: $event);
			if ($refusal !== null) {
				$event->setResult(queued: 0, rejected: $refusal[0], reasons: [$refusal[1]]);
				return;
			}

			$this->queueAll(event: $event, spans: array_values($spans));
		} catch (Throwable $e) {
			$this->logger->warning(
				'SpanExportRequestedListener: could not queue spans for OpenTelemetry export: ' . $e->getMessage(),
				['app' => 'integriq', 'sender' => $event->getAppId()]
			);
			$event->setResult(queued: 0, rejected: 0, reasons: ['queue-failed']);
		}

	}//end handle()

	/**
	 * Why the whole dispatch is refused, before any span is looked at.
	 *
	 * @param SpanExportRequestedEvent $event The command.
	 *
	 * @return array{0: int, 1: string}|null The rejected count and the reason, or null to go on.
	 */
	private function refusal(SpanExportRequestedEvent $event): ?array {
		if ($this->settings->isEnabled() === false) {
			return [0, 'export-disabled'];
		}

		$count = count($event->getSpans());
		if (preg_match('/^[a-z0-9_\-]{1,64}$/', $event->getAppId()) !== 1) {
			return [$count, 'appId: must be a Nextcloud app id'];
		}

		if ($count > self::MAX_SPANS) {
			return [$count, 'spans: at most ' . self::MAX_SPANS . ' per dispatch'];
		}

		if ($this->breaker->isOpen(now: $this->time->getTime()) === true) {
			return [0, 'export-paused'];
		}

		return null;

	}//end refusal()

	/**
	 * Check and queue each span, and record the answer.
	 *
	 * @param SpanExportRequestedEvent $event The command.
	 * @param list<mixed> $spans The spans as sent.
	 *
	 * @return void
	 */
	private function queueAll(SpanExportRequestedEvent $event, array $spans): void {
		$queued = 0;
		$rejected = 0;
		$reasons = [];
		foreach ($spans as $index => $span) {
			try {
				if (is_array($span) === false) {
					throw new InvalidArgumentException('span: must be an array');
				}

				$clean = $this->mapper->foreignSpan(span: $span);
			} catch (InvalidArgumentException $e) {
				$rejected++;
				$reasons[] = 'spans[' . $index . '].' . $e->getMessage();
				continue;
			}

			$status = 'success';
			if ($clean['status'] === 'error') {
				$status = 'failed';
			}

			if ($this->settings->isSampled(traceId: $clean['traceId'], status: $status, isReplay: false) === false) {
				$reasons[] = 'sampled-out';
				continue;
			}

			$this->scheduler->scheduleForeignSpan(appId: $event->getAppId(), span: $clean);
			$queued++;
		}

		$event->setResult(queued: $queued, rejected: $rejected, reasons: $reasons);

	}//end queueAll()
}//end class
