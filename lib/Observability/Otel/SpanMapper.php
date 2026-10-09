<?php

/**
 * Turns one persisted execution trace into OTLP spans.
 *
 * One root span for the execution and one child span per retained step
 * (design D1). Spans carry names, timing, status and an allowlisted set of
 * attributes, never a step's input or output (design D4): a redacted
 * snapshot still holds names, addresses and case numbers.
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
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-spans-carry-no-message-content-req-otel-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

/**
 * Maps an `execution_trace` object to an OTLP JSON `resourceSpans` payload.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-spans-carry-no-message-content-req-otel-003
 */
class SpanMapper {

	/**
	 * OTLP span kinds.
	 */
	private const KIND_INTERNAL = 1;
	private const KIND_SERVER = 2;
	private const KIND_CLIENT = 3;

	/**
	 * OTLP status codes.
	 */
	private const STATUS_UNSET = 0;
	private const STATUS_OK = 1;
	private const STATUS_ERROR = 2;

	/**
	 * Constructor.
	 *
	 * @param TraceParent $traceParent Converts trace ids to their hex form.
	 */
	public function __construct(
		private readonly TraceParent $traceParent,
	) {

	}//end __construct()

	/**
	 * Map one trace.
	 *
	 * @param array $trace The `execution_trace` object data.
	 * @param string $serviceName The `service.name` resource attribute.
	 *
	 * @return array The OTLP JSON payload.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
	 */
	public function map(array $trace, string $serviceName): array {
		$traceId = (string)($trace['traceId'] ?? '');
		// A trace that continues a caller's exports under the caller's W3C
		// trace id; the record's own id still names the spans.
		$otelTraceId = (string)($trace['otelTraceId'] ?? '');
		if ($otelTraceId === '') {
			$otelTraceId = $traceId;
		}

		$traceHex = $this->traceParent->toHex(traceId: $otelTraceId);
		$rootSpanId = $this->spanId(traceId: $traceId, salt: 'root');
		$rootStart = $this->micros(micros: ($trace['startedAtUs'] ?? null), iso: ($trace['startedAt'] ?? null));
		$rootEnd = $this->micros(micros: ($trace['finishedAtUs'] ?? null), iso: ($trace['finishedAt'] ?? null));
		$rootEnd = max($rootEnd, $rootStart);

		$spans = [];
		$dropped = 0;
		foreach (($trace['steps'] ?? []) as $step) {
			if (is_array($step) === false) {
				continue;
			}

			if (($step['status'] ?? null) === 'truncated') {
				// The tally of steps counted but not kept is a root attribute,
				// not a span (REQ-OTEL-001).
				$dropped = (int)($step['output']['droppedSteps'] ?? 0);
				continue;
			}

			$spans[] = $this->stepSpan(step: $step, traceId: $traceId, traceHex: $traceHex, rootSpanId: $rootSpanId, fallbackStart: $rootStart);
		}

		$entryPoint = (string)($trace['entryPoint'] ?? 'execution');
		$root = [
			'traceId' => $traceHex,
			'spanId' => $rootSpanId,
			'name' => 'integriq.' . $entryPoint,
			'kind' => $this->rootKind(entryPoint: $entryPoint),
			'startTimeUnixNano' => $this->nanos(micros: $rootStart),
			'endTimeUnixNano' => $this->nanos(micros: $rootEnd),
			'attributes' => $this->attributes(
				values: [
					'integriq.entry_point' => $entryPoint,
					'integriq.entry_point_id' => ($trace['entryPointId'] ?? null),
					'integriq.trace_id' => $traceId,
					'integriq.steps.dropped' => $dropped,
				]
			),
			'status' => ['code' => $this->rootStatus(status: (string)($trace['status'] ?? ''))],
		];
		$parent = (string)($trace['parentSpanId'] ?? '');
		if (preg_match('/^[0-9a-f]{16}$/', $parent) === 1) {
			$root['parentSpanId'] = $parent;
		}

		array_unshift($spans, $root);

		return [
			'resourceSpans' => [
				[
					'resource' => [
						'attributes' => $this->attributes(values: ['service.name' => $serviceName]),
					],
					'scopeSpans' => [
						[
							'scope' => ['name' => 'integriq.execution-trace'],
							'spans' => $spans,
						],
					],
				],
			],
		];

	}//end map()

	/**
	 * The span id a step gets: the one its outbound call carried in its
	 * `traceparent`, or one derived from the trace id and the step order so
	 * the same step always maps to the same span.
	 *
	 * @param array $step The step.
	 * @param string $traceId The execution trace id.
	 *
	 * @return string 16 hex characters.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
	 */
	public function stepSpanId(array $step, string $traceId): string {
		$own = (string)($step['spanId'] ?? '');
		if (preg_match('/^[0-9a-f]{16}$/', $own) === 1) {
			return $own;
		}

		return $this->spanId(traceId: $traceId, salt: (string)($step['order'] ?? '0'));

	}//end stepSpanId()

	/**
	 * One child span.
	 *
	 * @param array $step The step.
	 * @param string $traceId The execution trace id.
	 * @param string $traceHex The trace id in hex.
	 * @param string $rootSpanId The root span id.
	 * @param int $fallbackStart The root start, for a step without a time.
	 *
	 * @return array The span.
	 */
	private function stepSpan(array $step, string $traceId, string $traceHex, string $rootSpanId, int $fallbackStart): array {
		$type = (string)($step['type'] ?? 'step');
		$start = $this->micros(micros: ($step['startedAtUs'] ?? null), iso: ($step['startedAt'] ?? null));
		if ($start === 0) {
			$start = $fallbackStart;
		}

		$durationMs = max(0, (int)($step['durationMs'] ?? 0));
		$status = (string)($step['status'] ?? '');

		$values = [
			'integriq.step.type' => $type,
			'integriq.step.name' => (string)($step['name'] ?? ''),
			'integriq.step.status' => $status,
			'integriq.step.duration_ms' => $durationMs,
		];
		$kind = self::KIND_INTERNAL;
		if ($type === 'call') {
			$kind = self::KIND_CLIENT;
			$values += $this->httpAttributes(input: ($step['input'] ?? []), output: ($step['output'] ?? []));
		}

		$code = self::STATUS_OK;
		if ($status === 'error') {
			$code = self::STATUS_ERROR;
		}

		return [
			'traceId' => $traceHex,
			'spanId' => $this->stepSpanId(step: $step, traceId: $traceId),
			'parentSpanId' => $rootSpanId,
			'name' => trim($type . ' ' . (string)($step['name'] ?? '')),
			'kind' => $kind,
			'startTimeUnixNano' => $this->nanos(micros: $start),
			'endTimeUnixNano' => $this->nanos(micros: ($start + ($durationMs * 1000))),
			'attributes' => $this->attributes(values: $values),
			'status' => ['code' => $code],
		];

	}//end stepSpan()

	/**
	 * The HTTP attributes of a call step: method, status code and the URL
	 * without its query string, credentials or identifiers. Nothing else of
	 * the request or response.
	 *
	 * @param mixed $input The step input (the redacted call_log request).
	 * @param mixed $output The step output (the redacted call_log response).
	 *
	 * @return array<string, mixed> The attributes.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-spans-carry-no-message-content-req-otel-003
	 */
	private function httpAttributes(mixed $input, mixed $output): array {
		$values = [];
		if (is_array($input) === true) {
			$values['http.request.method'] = strtoupper((string)($input['method'] ?? ''));
			$values['url.full'] = $this->safeUrl(url: (string)($input['url'] ?? ''));
		}

		if (is_array($output) === true && isset($output['statusCode']) === true) {
			$values['http.response.status_code'] = (int)$output['statusCode'];
		}

		return $values;

	}//end httpAttributes()

	/**
	 * A URL fit for an external collector: scheme, host, port and path only,
	 * with every path segment that looks like an identifier replaced by
	 * `{id}`. The query string, the fragment and `user:pass@` are dropped, so
	 * a BSN in `/ingeschrevenpersonen/999993653` or a credential in the
	 * userinfo never leaves integriq (REQ-OTEL-003).
	 *
	 * @param string $url The called URL.
	 *
	 * @return string The safe URL, or '' when it cannot be parsed.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-spans-carry-no-message-content-req-otel-003
	 */
	private function safeUrl(string $url): string {
		$url = substr($url, 0, strcspn($url, '?#'));
		$parts = parse_url($url);
		if (is_array($parts) === false || isset($parts['host']) === false) {
			return '';
		}

		$safe = (string)($parts['scheme'] ?? 'https') . '://' . $parts['host'];
		if (isset($parts['port']) === true) {
			$safe .= ':' . (int)$parts['port'];
		}

		$segments = explode('/', (string)($parts['path'] ?? ''));
		foreach ($segments as $index => $segment) {
			if ($this->looksLikeIdentifier(segment: rawurldecode($segment)) === true) {
				$segments[$index] = '{id}';
			}
		}

		return $safe . implode('/', $segments);

	}//end safeUrl()

	/**
	 * Whether a path segment looks like an identifier rather than a route
	 * word: it holds a run of four or more digits (a BSN, a KvK or case
	 * number, a numeric id), or it is a long token (a uuid, a hash, an
	 * opaque key).
	 *
	 * @param string $segment One decoded path segment.
	 *
	 * @return bool True when the segment must be masked.
	 */
	private function looksLikeIdentifier(string $segment): bool {
		if ($segment === '') {
			return false;
		}

		return preg_match('/\d{4,}/', $segment) === 1
			|| (strlen($segment) >= 20 && preg_match('/^[A-Za-z0-9_\-.=+~]+$/', $segment) === 1 && preg_match('/\d/', $segment) === 1);

	}//end looksLikeIdentifier()

	/**
	 * OTLP key/value attributes, empty values left out.
	 *
	 * @param array<string, mixed> $values The attributes.
	 *
	 * @return array<int, array> The OTLP attribute list.
	 */
	private function attributes(array $values): array {
		$list = [];
		foreach ($values as $key => $value) {
			if ($value === null || $value === '') {
				continue;
			}

			if (is_int($value) === true) {
				$list[] = ['key' => $key, 'value' => ['intValue' => (string)$value]];
				continue;
			}

			$list[] = ['key' => $key, 'value' => ['stringValue' => (string)$value]];
		}

		return $list;

	}//end attributes()

	/**
	 * A deterministic span id.
	 *
	 * @param string $traceId The execution trace id.
	 * @param string $salt What the span stands for.
	 *
	 * @return string 16 hex characters.
	 */
	private function spanId(string $traceId, string $salt): string {
		return substr(hash('sha256', $traceId . ':' . $salt), 0, 16);

	}//end spanId()

	/**
	 * Microseconds since the epoch, from the precise field or the ISO time.
	 *
	 * @param mixed $micros The microsecond value, when recorded.
	 * @param mixed $iso The whole-second ISO 8601 time, the fallback.
	 *
	 * @return int Microseconds, or 0 when neither is usable.
	 */
	private function micros(mixed $micros, mixed $iso): int {
		if (is_numeric($micros) === true && (int)$micros > 0) {
			return (int)$micros;
		}

		$seconds = strtotime((string)$iso);
		if ($seconds === false) {
			return 0;
		}

		return ($seconds * 1000000);

	}//end micros()

	/**
	 * Nanoseconds as the decimal string OTLP JSON expects.
	 *
	 * @param int $micros Microseconds since the epoch.
	 *
	 * @return string Nanoseconds.
	 */
	private function nanos(int $micros): string {
		return $micros . '000';

	}//end nanos()

	/**
	 * The root span kind: a server span when a caller made the request.
	 *
	 * @param string $entryPoint endpoint|job|event|sync.
	 *
	 * @return int The OTLP kind.
	 */
	private function rootKind(string $entryPoint): int {
		if ($entryPoint === 'endpoint') {
			return self::KIND_SERVER;
		}

		return self::KIND_INTERNAL;

	}//end rootKind()

	/**
	 * The root span status from the trace status.
	 *
	 * @param string $status success|failed|short_circuited|running.
	 *
	 * @return int The OTLP status code.
	 */
	private function rootStatus(string $status): int {
		if ($status === 'failed') {
			return self::STATUS_ERROR;
		}

		if ($status === 'success' || $status === 'short_circuited') {
			return self::STATUS_OK;
		}

		return self::STATUS_UNSET;

	}//end rootStatus()
}//end class
