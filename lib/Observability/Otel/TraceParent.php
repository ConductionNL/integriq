<?php

/**
 * W3C trace context for integriq's execution traces.
 *
 * Reads and writes the `traceparent` header (W3C Trace Context level 1).
 * An execution's `traceId` is a UUIDv4, the same 128 bits as a W3C trace
 * id, so the two convert both ways without loss (design D5).
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
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

/**
 * Parses and formats W3C traceparent values.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
 */
class TraceParent {

	/**
	 * The header name, lower case as W3C writes it.
	 *
	 * @var string
	 */
	public const HEADER = 'traceparent';

	/**
	 * Read a traceparent header.
	 *
	 * Only version 00 in the exact W3C shape is accepted. An all-zero trace
	 * id or span id is invalid by the standard. Anything else is ignored, so
	 * a crafted header can only ever be refused, never half-read.
	 *
	 * @param string|null $header The raw header value.
	 *
	 * @return array{traceId: string, parentSpanId: string}|null The execution trace id (dashed uuid) and the caller's span id, or null.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function parse(?string $header): ?array {
		$value = strtolower(trim((string)$header));
		if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $value, $matches) !== 1) {
			return null;
		}

		if ($matches[1] === str_repeat('0', 32) || $matches[2] === str_repeat('0', 16)) {
			return null;
		}

		return [
			'traceId' => $this->toUuid(hex: $matches[1]),
			'parentSpanId' => $matches[2],
		];

	}//end parse()

	/**
	 * Format the traceparent an outbound call carries.
	 *
	 * @param string $traceId The execution trace id (dashed or not).
	 * @param string $spanId The 16-hex span id of the step making the call.
	 *
	 * @return string The header value, sampled flag set.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function format(string $traceId, string $spanId): string {
		return '00-' . $this->toHex(traceId: $traceId) . '-' . $spanId . '-01';

	}//end format()

	/**
	 * A trace id as 32 lower-case hex characters.
	 *
	 * @param string $traceId The execution trace id.
	 *
	 * @return string The hex form.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
	 */
	public function toHex(string $traceId): string {
		return strtolower(str_replace('-', '', $traceId));

	}//end toHex()

	/**
	 * A fresh random span id.
	 *
	 * @return string 16 lower-case hex characters.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function newSpanId(): string {
		return bin2hex(random_bytes(8));

	}//end newSpanId()

	/**
	 * A 32-hex trace id as a dashed uuid.
	 *
	 * @param string $hex The 32 hex characters.
	 *
	 * @return string The dashed form.
	 */
	private function toUuid(string $hex): string {
		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
			. substr($hex, 16, 4) . '-' . substr($hex, 20, 12);

	}//end toUuid()
}//end class
