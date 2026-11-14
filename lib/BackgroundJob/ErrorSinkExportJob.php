<?php

/**
 * Integriq error sink export job.
 *
 * Sends one queued error event to the error service the administrator
 * chose, off the request that logged it (REQ-OTEL-006). A failed send is
 * queued again with a growing delay, at most three times, and then dropped
 * without a log line: a log line about a failing sink would be forwarded to
 * that same sink.
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
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Observability\Errors\ErrorSinkExporterInterface;
use OCA\Integriq\Observability\Errors\ErrorSinkListener;
use OCA\Integriq\Observability\Errors\ErrorSinkSettings;
use OCA\Integriq\Observability\Errors\OtlpLogExporter;
use OCA\Integriq\Observability\Errors\SentryEnvelopeExporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Throwable;

/**
 * Exports one forwarded error.
 *
 * @psalm-api
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */
class ErrorSinkExportJob extends QueuedJob {

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
	 * @param ErrorSinkSettings $settings The error sink settings.
	 * @param SentryEnvelopeExporter $sentry Sends to a Sentry compatible service.
	 * @param OtlpLogExporter $otlpLogs Sends to an OTLP logs collector.
	 * @param IJobList $jobList Schedules a retry for its run time.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ErrorSinkSettings $settings,
		private readonly SentryEnvelopeExporter $sentry,
		private readonly OtlpLogExporter $otlpLogs,
		private readonly IJobList $jobList,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Send the event named in the argument.
	 *
	 * @param mixed $argument `{event, attempt, notBefore?}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function run(mixed $argument): void {
		$argument = $this->argument(argument: $argument);

		// The sink was switched off since the event was queued: nothing to send.
		if ($argument['event'] === [] || $this->settings->isEnabled() === false) {
			return;
		}

		$now = $this->time->getTime();
		if ($argument['notBefore'] > $now) {
			$this->jobList->scheduleAfter(self::class, $argument['notBefore'], $argument);
			return;
		}

		$exporter = $this->exporter();
		$event = $argument['event'];
		try {
			ErrorSinkListener::whileSending(
				send: static function () use ($exporter, $event): void {
					$exporter->export(event: $event);
				}
			);
		} catch (Throwable) {
			$this->retryOrDrop(argument: $argument, now: $now);
		}

	}//end run()

	/**
	 * The exporter for the configured kind.
	 *
	 * @return ErrorSinkExporterInterface The exporter.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function exporter(): ErrorSinkExporterInterface {
		if ($this->settings->kind() === ErrorSinkSettings::KIND_OTLP_LOGS) {
			return $this->otlpLogs;
		}

		return $this->sentry;

	}//end exporter()

	/**
	 * The job argument, normalised.
	 *
	 * @param mixed $argument The raw argument.
	 *
	 * @return array{event: array<string, mixed>, attempt: int, notBefore: int}
	 */
	private function argument(mixed $argument): array {
		if (is_array($argument) === false) {
			$argument = [];
		}

		$event = ($argument['event'] ?? []);
		if (is_array($event) === false) {
			$event = [];
		}

		return [
			'event' => $event,
			'attempt' => (int)($argument['attempt'] ?? 0),
			'notBefore' => (int)($argument['notBefore'] ?? 0),
		];

	}//end argument()

	/**
	 * Queue a later retry, five, ten and twenty minutes out, or drop the
	 * event silently once the retries are spent.
	 *
	 * @param array{event: array<string, mixed>, attempt: int, notBefore: int} $argument The argument of the failed send.
	 * @param int $now The current unix time.
	 *
	 * @return void
	 */
	private function retryOrDrop(array $argument, int $now): void {
		if ($argument['attempt'] >= self::MAX_RETRIES) {
			return;
		}

		$notBefore = ($now + (self::RETRY_DELAY_SECONDS * (2 ** $argument['attempt'])));
		try {
			$this->jobList->scheduleAfter(
				self::class,
				$notBefore,
				[
					'event' => $argument['event'],
					'attempt' => ($argument['attempt'] + 1),
					'notBefore' => $notBefore,
				]
			);
		} catch (Throwable) {
			// A retry that cannot be queued is a dropped event; logging it
			// would only feed the sink that just failed.
			return;
		}

	}//end retryOrDrop()
}//end class
