<?php

/**
 * Integriq error sink listener.
 *
 * Reads every message Nextcloud logs, through `OCP\Log\BeforeMessageLoggedEvent`,
 * and queues the ones the administrator selected (an app in the list, a
 * level at or above the minimum) for {@see \OCA\Integriq\BackgroundJob\ErrorSinkExportJob}.
 * The listener never makes an HTTP call and never logs: it runs inside the
 * logger, so a log line of its own would come straight back to it, and a
 * failing sink could raise an error about itself. Every error of its own is
 * swallowed.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability\Errors
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

namespace OCA\Integriq\Observability\Errors;

use OCA\Integriq\BackgroundJob\ErrorSinkExportJob;
use OCA\Integriq\Outbound\BodyRedactor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Log\BeforeMessageLoggedEvent;
use Throwable;

/**
 * Queues the selected log messages for the error sink.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */
class ErrorSinkListener implements IEventListener {

	/**
	 * The longest JSON a Nextcloud background job argument may be.
	 *
	 * @var int
	 */
	private const MAX_ARGUMENT_BYTES = 3900;

	/**
	 * The longest message text that is forwarded, in characters.
	 *
	 * @var int
	 */
	private const MAX_MESSAGE_CHARS = 1000;

	/**
	 * The longest exception class or file path that is forwarded.
	 *
	 * @var int
	 */
	private const MAX_FIELD_CHARS = 255;

	/**
	 * True while the listener queues an event or the export job sends one,
	 * so a message logged on that path is not forwarded again.
	 *
	 * @var bool
	 */
	private static bool $busy = false;

	/**
	 * Constructor.
	 *
	 * @param ErrorSinkSettings $settings Which apps and levels are forwarded.
	 * @param BodyRedactor $redactor Removes credentials and BSNs before the event is queued.
	 * @param IJobList $jobList The queue the export job is added to.
	 * @param ITimeFactory $time The clock the event time is read from.
	 * @param IRequest $request Gives the request id.
	 */
	public function __construct(
		private readonly ErrorSinkSettings $settings,
		private readonly BodyRedactor $redactor,
		private readonly IJobList $jobList,
		private readonly ITimeFactory $time,
		private readonly IRequest $request,
	) {

	}//end __construct()

	/**
	 * Queue one logged message when the administrator selected it. Never
	 * throws and never logs.
	 *
	 * @param Event $event The logged message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function handle(Event $event): void {
		if (($event instanceof BeforeMessageLoggedEvent) === false || self::$busy === true) {
			return;
		}

		self::$busy = true;
		try {
			if ($this->settings->forwards(app: $event->getApp(), level: $event->getLevel()) === true) {
				$this->jobList->add(
					ErrorSinkExportJob::class,
					$this->fitted(argument: ['event' => $this->sinkEvent(event: $event), 'attempt' => 0])
				);
			}
		} catch (Throwable) {
			// Swallowed on purpose: logging here would feed this listener
			// again, and a broken sink must never reach the logging request.
			return;
		} finally {
			self::$busy = false;
		}

	}//end handle()

	/**
	 * Run a send without forwarding what it logs, so a failing sink cannot
	 * report about itself.
	 *
	 * @param callable(): void $send The send.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public static function whileSending(callable $send): void {
		$previous = self::$busy;
		self::$busy = true;
		try {
			$send();
		} finally {
			self::$busy = $previous;
		}

	}//end whileSending()

	/**
	 * The event that is queued: only the app, the level, the time, the
	 * exception class, the file, the line, the request id and the redacted
	 * message. Context values, traces and request bodies stay home.
	 *
	 * @param BeforeMessageLoggedEvent $event The logged message.
	 *
	 * @return array<string, int|string> The sink event.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function sinkEvent(BeforeMessageLoggedEvent $event): array {
		$message = $event->getMessage();

		return [
			'app' => $event->getApp(),
			'level' => $event->getLevel(),
			'time' => $this->time->getTime(),
			'exceptionClass' => $this->clip(text: $this->scalar(value: ($message['Exception'] ?? '')), max: self::MAX_FIELD_CHARS),
			'file' => $this->clip(text: $this->redactor->redactText(text: $this->scalar(value: ($message['File'] ?? ''))), max: self::MAX_FIELD_CHARS),
			'line' => (int)$this->scalar(value: ($message['Line'] ?? 0)),
			'requestId' => $this->clip(text: $this->requestId(), max: 64),
			'message' => $this->clip(text: $this->redactor->redactText(text: $this->text(message: $message)), max: self::MAX_MESSAGE_CHARS),
		];

	}//end sinkEvent()

	/**
	 * The message text: an exception's own message, after the message the
	 * caller logged it with, or a plain message.
	 *
	 * @param array<array-key, mixed> $message The logged entry.
	 *
	 * @return string The text.
	 */
	private function text(array $message): string {
		$custom = $this->scalar(value: ($message['CustomMessage'] ?? ''));
		$exceptionMessage = $this->scalar(value: ($message['Message'] ?? ''));
		if ($exceptionMessage === '') {
			return $this->scalar(value: ($message['message'] ?? ''));
		}

		if ($custom === '' || $custom === '--' || $custom === $exceptionMessage) {
			return $exceptionMessage;
		}

		return $custom . ': ' . $exceptionMessage;

	}//end text()

	/**
	 * The request id, or an empty string outside a request.
	 *
	 * @return string The id.
	 */
	private function requestId(): string {
		try {
			return $this->request->getId();
		} catch (Throwable) {
			return '';
		}

	}//end requestId()

	/**
	 * A scalar log value as a string; anything else as an empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The string.
	 */
	private function scalar(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;

	}//end scalar()

	/**
	 * Text cut to a number of characters.
	 *
	 * @param string $text The text.
	 * @param int $max The most characters kept.
	 *
	 * @return string The text.
	 */
	private function clip(string $text, int $max): string {
		return mb_substr($text, 0, $max);

	}//end clip()

	/**
	 * The job argument, with the message shortened until its JSON fits in a
	 * background job argument.
	 *
	 * @param array{event: array<string, int|string>, attempt: int} $argument The argument.
	 *
	 * @return array{event: array<string, int|string>, attempt: int} The argument that fits.
	 */
	private function fitted(array $argument): array {
		while (strlen((string)json_encode($argument)) > self::MAX_ARGUMENT_BYTES && $argument['event']['message'] !== '') {
			$message = (string)$argument['event']['message'];
			$argument['event']['message'] = mb_substr($message, 0, intdiv(mb_strlen($message), 2));
		}

		return $argument;

	}//end fitted()
}//end class
