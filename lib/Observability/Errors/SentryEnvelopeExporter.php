<?php

/**
 * Sentry compatible error sink exporter.
 *
 * Posts one forwarded error as a Sentry envelope to the endpoint the DSN
 * names: `https://key@sentry.example.org/42` becomes a POST to
 * `https://sentry.example.org/api/42/envelope/` with an `X-Sentry-Auth`
 * header built from the key. Any service that speaks the Sentry envelope
 * protocol (Sentry, GlitchTip, Bugsink) takes it.
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

use RuntimeException;

/**
 * Sends a forwarded error as a Sentry envelope.
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */
class SentryEnvelopeExporter extends ErrorSinkHttpExporter {

	/**
	 * The Sentry level per Nextcloud log level.
	 *
	 * @var array<int, string>
	 */
	private const LEVELS = [
		0 => 'debug',
		1 => 'info',
		2 => 'warning',
		3 => 'error',
		4 => 'fatal',
	];

	/**
	 * The client name the auth header carries.
	 *
	 * @var string
	 */
	private const CLIENT = 'integriq-errorsink/1.0';

	/**
	 * Send one event to the envelope endpoint of the configured DSN.
	 *
	 * @param array<string, mixed> $event The queued event.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the DSN is unusable, or the service refused or could not be reached.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function export(array $event): void {
		$dsn = $this->parseDsn(dsn: $this->settings->endpoint());

		$this->post(
			url: $dsn['url'],
			body: $this->body(event: $event),
			headers: [
				'Content-Type' => 'application/x-sentry-envelope',
				'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=' . self::CLIENT . ', sentry_key=' . $dsn['key'],
			]
		);

	}//end export()

	/**
	 * The envelope: a header line, an item header line and the event.
	 *
	 * @param array<string, mixed> $event The queued event.
	 *
	 * @return string The envelope body.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function body(array $event): string {
		$sentry = $this->sentryEvent(event: $event);
		$eventId = substr(hash('sha256', $this->json(value: $sentry)), 0, 32);
		$payload = $this->json(value: (['event_id' => $eventId] + $sentry));

		return $this->json(value: ['event_id' => $eventId]) . "\n"
			. $this->json(value: ['type' => 'event', 'content_type' => 'application/json', 'length' => strlen($payload)]) . "\n"
			. $payload . "\n";

	}//end body()

	/**
	 * The Sentry event. Its id is derived from the content, so a retry of
	 * the same event is deduplicated by the service.
	 *
	 * @param array<string, mixed> $event The queued event.
	 *
	 * @return array<string, mixed> The Sentry event, without its id.
	 */
	private function sentryEvent(array $event): array {
		$app = (string)($event['app'] ?? '');
		$message = (string)($event['message'] ?? '');
		$tags = array_filter(
			[
				'app' => $app,
				'request_id' => (string)($event['requestId'] ?? ''),
			],
			static fn (string $value): bool => $value !== ''
		);

		$sentry = [
			'timestamp' => (int)($event['time'] ?? 0),
			'platform' => 'php',
			'level' => (self::LEVELS[(int)($event['level'] ?? 3)] ?? 'error'),
			'logger' => $app,
			'tags' => $tags,
		];

		$exceptionClass = (string)($event['exceptionClass'] ?? '');
		if ($exceptionClass === '') {
			$sentry['message'] = ['formatted' => $message];
			return $sentry;
		}

		$exception = ['type' => $exceptionClass, 'value' => $message];
		if ((string)($event['file'] ?? '') !== '') {
			$exception['stacktrace'] = [
				'frames' => [
					['filename' => (string)$event['file'], 'lineno' => (int)($event['line'] ?? 0)],
				],
			];
		}

		$sentry['exception'] = ['values' => [$exception]];

		return $sentry;

	}//end sentryEvent()

	/**
	 * The envelope URL and the public key of a DSN.
	 *
	 * @param string $dsn The DSN, `https://key@host[:port][/path]/projectId`.
	 *
	 * @return array{url: string, key: string} The envelope endpoint and the key.
	 *
	 * @throws RuntimeException When the DSN has no key, host or project id.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
	 */
	public function parseDsn(string $dsn): array {
		$parts = parse_url($dsn);
		$path = (string)($parts['path'] ?? '');
		if (is_array($parts) === false
			|| (string)($parts['user'] ?? '') === ''
			|| (string)($parts['host'] ?? '') === ''
			|| preg_match('#^(.*)/(\d+)$#', $path, $match) !== 1
		) {
			throw new RuntimeException('The Sentry DSN is not usable.');
		}

		$base = (string)($parts['scheme'] ?? 'https') . '://' . $parts['host'];
		if (isset($parts['port']) === true) {
			$base .= ':' . (int)$parts['port'];
		}

		return [
			'url' => $base . $match[1] . '/api/' . $match[2] . '/envelope/',
			'key' => rawurldecode((string)$parts['user']),
		];

	}//end parseDsn()
}//end class
