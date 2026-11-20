<?php

/**
 * Integriq SpanExportRequested Event.
 *
 * The typed command another app uses to hand integriq its finished spans
 * for export to the OpenTelemetry collector the administrator chose
 * (REQ-OTEL-007). The sender builds this class by name, guarded with
 * `class_exists()`, and reads the answer from {@see getResult()} on the
 * same instance after the dispatch. The contract is written down in
 * `docs/features/opentelemetry.md`.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Spans another app asks integriq to export.
 *
 * Do not add or rename a constructor argument or a result key without
 * changing the contract page first: sibling apps build this class by name.
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
 */
final class SpanExportRequestedEvent extends Event {

	/**
	 * The answer. It starts as "not exported, nobody listened", so a dispatch
	 * no listener handled never reads as exported.
	 *
	 * @var array{queued: int, rejected: int, reasons: list<string>}
	 */
	private array $result = [
		'queued' => 0,
		'rejected' => 0,
		'reasons' => ['no-listener'],
	];

	/**
	 * Constructor.
	 *
	 * @param string $appId The id of the app that sends the spans; it becomes their `service.name`.
	 * @param array<int, mixed> $spans The finished spans, each with traceId, spanId, parentSpanId, name,
	 *                                 startTimeUnixNano, endTimeUnixNano, status and attributes.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly array $spans,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The app that sent the spans.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function getAppId(): string {
		return $this->appId;

	}//end getAppId()

	/**
	 * The spans as sent.
	 *
	 * @return array<int, mixed> The spans.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function getSpans(): array {
		return $this->spans;

	}//end getSpans()

	/**
	 * The answer: how many spans were queued, how many were rejected, and why.
	 *
	 * @return array{queued: int, rejected: int, reasons: list<string>} The result.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function getResult(): array {
		return $this->result;

	}//end getResult()

	/**
	 * Record the answer; called by integriq's listener.
	 *
	 * @param int $queued The spans queued for export.
	 * @param int $rejected The spans refused as malformed.
	 * @param list<string> $reasons Why spans were refused or not queued.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function setResult(int $queued, int $rejected, array $reasons): void {
		$this->result = [
			'queued' => $queued,
			'rejected' => $rejected,
			'reasons' => array_values(array_unique(array_map('strval', $reasons))),
		];

	}//end setResult()
}//end class
