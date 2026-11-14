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

use InvalidArgumentException;

/**
 * Maps an `execution_trace` object to an OTLP JSON `resourceSpans` payload.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the complexity sits in the URL masking, kept as one guarded step
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-spans-carry-no-message-content-req-otel-003
 */
class SpanMapper implements SpanMapperInterface {

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
	 * The attributes a span another app hands in may carry (REQ-OTEL-003):
	 * the HTTP method, the HTTP status code and the masked URL. Any other
	 * attribute is dropped, the span is kept.
	 *
	 * @var list<string>
	 */
	public const FOREIGN_ATTRIBUTES = ['http.request.method', 'http.response.status_code', 'url.full'];

	/**
	 * The longest span name kept, in characters.
	 *
	 * @var int
	 */
	private const MAX_NAME_CHARS = 200;

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
		$frame = $this->frameFor(trace: $trace);
		$spans = $this->stepSpans(trace: $trace, frame: $frame);
		array_unshift($spans, $this->rootSpan(trace: $trace, frame: $frame));

		return [
			'resourceSpans' => [
				[
					'resource' => [
						'attributes' => $this->attributes(values: ['service.name' => $serviceName]),
					],
					'scopeSpans' => $this->scopeSpans(spans: $spans),
				],
			],
		];

	}//end map()

	/**
	 * Check and clean one span another app handed in through
	 * `SpanExportRequestedEvent` (REQ-OTEL-007). The ids, times and status
	 * must be well formed; an attribute outside {@see FOREIGN_ATTRIBUTES} is
	 * dropped, `url.full` loses its query, credentials and identifiers, and
	 * identifier-like words in the name are masked.
	 *
	 * @param array<array-key, mixed> $span The span as the other app sent it.
	 *
	 * @return array<string, mixed> The clean span: traceId, spanId, parentSpanId ('' for a root span), name,
	 *                              startTimeUnixNano, endTimeUnixNano, status and the kept attributes.
	 *
	 * @throws InvalidArgumentException When a field is malformed; the message starts with the field name.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function foreignSpan(array $span): array {
		$traceId = $span['traceId'] ?? null;
		if (is_string($traceId) === false || preg_match('/^[0-9a-f]{32}$/', $traceId) !== 1 || trim($traceId, '0') === '') {
			throw new InvalidArgumentException('traceId: must be 32 lowercase hex characters, not all zero');
		}

		$spanId = $span['spanId'] ?? null;
		if (is_string($spanId) === false || preg_match('/^[0-9a-f]{16}$/', $spanId) !== 1 || trim($spanId, '0') === '') {
			throw new InvalidArgumentException('spanId: must be 16 lowercase hex characters, not all zero');
		}

		$parent = ($span['parentSpanId'] ?? '');
		if ($parent !== '' && (is_string($parent) === false || preg_match('/^[0-9a-f]{16}$/', $parent) !== 1)) {
			throw new InvalidArgumentException('parentSpanId: must be 16 lowercase hex characters or absent');
		}

		$name = $span['name'] ?? null;
		if (is_string($name) === false || trim($name) === '') {
			throw new InvalidArgumentException('name: must be a non-empty string');
		}

		[$start, $end] = $this->foreignTimes(span: $span);

		$status = $span['status'] ?? null;
		if (in_array($status, ['ok', 'error'], true) === false) {
			throw new InvalidArgumentException('status: must be ok or error');
		}

		$attributes = ($span['attributes'] ?? []);
		if (is_array($attributes) === false) {
			throw new InvalidArgumentException('attributes: must be a map of string keys to scalar values');
		}

		return [
			'traceId' => $traceId,
			'spanId' => $spanId,
			'parentSpanId' => (string)$parent,
			'name' => $this->safeName(name: $name),
			'startTimeUnixNano' => $start,
			'endTimeUnixNano' => $end,
			'status' => (string)$status,
			'attributes' => $this->foreignAttributes(attributes: $attributes),
		];

	}//end foreignSpan()

	/**
	 * Map spans other apps handed in, already cleaned by {@see foreignSpan()},
	 * to an OTLP JSON payload whose `service.name` is the app that sent them.
	 *
	 * @param list<array<string, mixed>> $spans The clean spans.
	 * @param string $serviceName The dispatching app id.
	 *
	 * @return array The OTLP JSON payload.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
	 */
	public function mapForeign(array $spans, string $serviceName): array {
		$mapped = [];
		foreach ($spans as $span) {
			$clean = $this->foreignSpan(span: $span);
			$otlp = [
				'traceId' => $clean['traceId'],
				'spanId' => $clean['spanId'],
				'name' => $clean['name'],
				'kind' => $this->foreignKind(span: $clean),
				'startTimeUnixNano' => (string)$clean['startTimeUnixNano'],
				'endTimeUnixNano' => (string)$clean['endTimeUnixNano'],
				'attributes' => $this->attributes(values: $clean['attributes']),
				'status' => ['code' => $this->stepStatusCode(status: $clean['status'])],
			];
			if ($clean['parentSpanId'] !== '') {
				$otlp['parentSpanId'] = $clean['parentSpanId'];
			}

			$mapped[] = $otlp;
		}

		return [
			'resourceSpans' => [
				[
					'resource' => [
						'attributes' => $this->attributes(values: ['service.name' => $serviceName]),
					],
					'scopeSpans' => [
						[
							'scope' => ['name' => 'integriq.app-spans'],
							'spans' => $mapped,
						],
					],
				],
			],
		];

	}//end mapForeign()

	/**
	 * The start and end of a foreign span, in nanoseconds.
	 *
	 * @param array<array-key, mixed> $span The span as sent.
	 *
	 * @return array{0: int, 1: int} The start and the end.
	 *
	 * @throws InvalidArgumentException When a time is missing, not positive, or the end is before the start.
	 */
	private function foreignTimes(array $span): array {
		$start = $span['startTimeUnixNano'] ?? null;
		if (is_int($start) === false || $start <= 0) {
			throw new InvalidArgumentException('startTimeUnixNano: must be a positive integer');
		}

		$end = $span['endTimeUnixNano'] ?? null;
		if (is_int($end) === false || $end < $start) {
			throw new InvalidArgumentException('endTimeUnixNano: must be an integer not before startTimeUnixNano');
		}

		return [$start, $end];

	}//end foreignTimes()

	/**
	 * The allowlisted attributes of a foreign span, with scalar values only.
	 *
	 * @param array<array-key, mixed> $attributes The attributes as sent.
	 *
	 * @return array<string, int|string> The kept attributes.
	 */
	private function foreignAttributes(array $attributes): array {
		$kept = [];
		foreach ($attributes as $key => $value) {
			if (in_array($key, self::FOREIGN_ATTRIBUTES, true) === false || is_scalar($value) === false) {
				continue;
			}

			if ($key === 'http.response.status_code') {
				$kept[$key] = (int)$value;
				continue;
			}

			if ($key === 'http.request.method') {
				$kept[$key] = substr(strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$value) ?? ''), 0, 16);
				continue;
			}

			$kept[$key] = $this->safeUrl(url: (string)$value);
		}

		return $kept;

	}//end foreignAttributes()

	/**
	 * A span name with every identifier-like word or path segment replaced by
	 * `{id}`, so `GET /api/objects/999993653` leaves as `GET /api/objects/{id}`.
	 *
	 * @param string $name The name as sent.
	 *
	 * @return string The safe name.
	 */
	private function safeName(string $name): string {
		$name = substr(trim($name), 0, strcspn(trim($name), '?#'));
		$safe = (string)preg_replace_callback(
			'#[^\s/]+#',
			function (array $match): string {
				if ($this->looksLikeIdentifier(segment: $match[0]) === true) {
					return '{id}';
				}

				return $match[0];
			},
			$name
		);

		return mb_substr($safe, 0, self::MAX_NAME_CHARS);

	}//end safeName()

	/**
	 * A foreign span's kind: a server span for an incoming HTTP request with
	 * no parent, else internal.
	 *
	 * @param array<string, mixed> $span The clean span.
	 *
	 * @return int The OTLP span kind.
	 */
	private function foreignKind(array $span): int {
		if ($span['parentSpanId'] === '' && isset($span['attributes']['http.request.method']) === true) {
			return self::KIND_SERVER;
		}

		return self::KIND_INTERNAL;

	}//end foreignKind()

	/**
	 * The ids and start every span of a trace shares.
	 *
	 * @param array $trace The `execution_trace` object data.
	 *
	 * @return TraceFrame The frame.
	 */
	private function frameFor(array $trace): TraceFrame {
		$traceId = (string)($trace['traceId'] ?? '');
		// A trace that continues a caller's exports under the caller's W3C
		// trace id; the record's own id still names the spans.
		$otelTraceId = (string)($trace['otelTraceId'] ?? '');
		if ($otelTraceId === '') {
			$otelTraceId = $traceId;
		}

		return new TraceFrame(
			traceId: $traceId,
			traceHex: $this->traceParent->toHex(traceId: $otelTraceId),
			rootSpanId: $this->spanId(traceId: $traceId, salt: 'root'),
			rootStart: $this->micros(micros: ($trace['startedAtUs'] ?? null), iso: ($trace['startedAt'] ?? null))
		);

	}//end frameFor()

	/**
	 * One span per kept step; the truncation tally goes on the frame.
	 *
	 * @param array      $trace The `execution_trace` object data.
	 * @param TraceFrame $frame The trace's shared ids and start; takes the dropped-step count.
	 *
	 * @return list<array> The step spans, in order.
	 */
	private function stepSpans(array $trace, TraceFrame $frame): array {
		$spans = [];
		foreach (array_filter((array)($trace['steps'] ?? []), 'is_array') as $step) {
			if (($step['status'] ?? null) === 'truncated') {
				// The tally of steps counted but not kept is a root attribute,
				// not a span (REQ-OTEL-001).
				$frame->droppedSteps = (int)($step['output']['droppedSteps'] ?? 0);
				continue;
			}

			$spans[] = $this->stepSpan(step: $step, frame: $frame);
		}

		return $spans;

	}//end stepSpans()

	/**
	 * The one scope the spans are exported under.
	 *
	 * @param list<array> $spans The root span and the step spans.
	 *
	 * @return list<array> The scope spans.
	 */
	private function scopeSpans(array $spans): array {
		return [
			[
				'scope' => ['name' => 'integriq.execution-trace'],
				'spans' => $spans,
			],
		];

	}//end scopeSpans()

	/**
	 * The root span of a trace.
	 *
	 * @param array      $trace The `execution_trace` object data.
	 * @param TraceFrame $frame The trace's shared ids and start.
	 *
	 * @return array The root span.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
	 */
	private function rootSpan(array $trace, TraceFrame $frame): array {
		$rootEnd = $this->micros(micros: ($trace['finishedAtUs'] ?? null), iso: ($trace['finishedAt'] ?? null));
		$entryPoint = (string)($trace['entryPoint'] ?? 'execution');
		$root = [
			'traceId' => $frame->traceHex,
			'spanId' => $frame->rootSpanId,
			'name' => 'integriq.' . $entryPoint,
			'kind' => $this->rootKind(entryPoint: $entryPoint),
			'startTimeUnixNano' => $this->nanos(micros: $frame->rootStart),
			'endTimeUnixNano' => $this->nanos(micros: max($rootEnd, $frame->rootStart)),
			'attributes' => $this->rootAttributes(trace: $trace, frame: $frame),
			'status' => ['code' => $this->rootStatus(status: (string)($trace['status'] ?? ''))],
		];

		return $this->withParent(root: $root, trace: $trace);
	}//end rootSpan()

	/**
	 * The root span under the caller's span, when the trace continues one.
	 *
	 * @param array $root  The root span.
	 * @param array $trace The `execution_trace` object data.
	 *
	 * @return array The root span, with a parentSpanId when the trace names a valid one.
	 */
	private function withParent(array $root, array $trace): array {
		$parent = (string)($trace['parentSpanId'] ?? '');
		if (preg_match('/^[0-9a-f]{16}$/', $parent) === 1) {
			$root['parentSpanId'] = $parent;
		}

		return $root;
	}//end withParent()

	/**
	 * The root span's attributes: the entry point, the trace id and the dropped-step count.
	 *
	 * @param array      $trace The `execution_trace` object data.
	 * @param TraceFrame $frame The trace's shared ids and start.
	 *
	 * @return array The OTLP attributes.
	 */
	private function rootAttributes(array $trace, TraceFrame $frame): array {
		return $this->attributes(
			values: [
				'integriq.entry_point' => (string)($trace['entryPoint'] ?? 'execution'),
				'integriq.entry_point_id' => ($trace['entryPointId'] ?? null),
				'integriq.trace_id' => $frame->traceId,
				'integriq.steps.dropped' => $frame->droppedSteps,
			]
		);
	}//end rootAttributes()

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
	 * @param array      $step  The step.
	 * @param TraceFrame $frame The trace's shared ids, and the root start for a step without a time.
	 *
	 * @return array The span.
	 */
	private function stepSpan(array $step, TraceFrame $frame): array {
		$type = (string)($step['type'] ?? 'step');
		$start = $this->stepStart(step: $step, frame: $frame);

		return [
			'traceId' => $frame->traceHex,
			'spanId' => $this->stepSpanId(step: $step, traceId: $frame->traceId),
			'parentSpanId' => $frame->rootSpanId,
			'name' => trim($type . ' ' . (string)($step['name'] ?? '')),
			'kind' => $this->stepKind(type: $type),
			'startTimeUnixNano' => $this->nanos(micros: $start),
			'endTimeUnixNano' => $this->nanos(micros: ($start + ($this->durationMs(step: $step) * 1000))),
			'attributes' => $this->attributes(values: $this->stepValues(step: $step)),
			'status' => ['code' => $this->stepStatusCode(status: (string)($step['status'] ?? ''))],
		];

	}//end stepSpan()

	/**
	 * When a step started; the trace's start when the step carries no time.
	 *
	 * @param array      $step  The step.
	 * @param TraceFrame $frame The trace's shared ids and start.
	 *
	 * @return int Microseconds since the epoch.
	 */
	private function stepStart(array $step, TraceFrame $frame): int {
		$start = $this->micros(micros: ($step['startedAtUs'] ?? null), iso: ($step['startedAt'] ?? null));
		if ($start === 0) {
			return $frame->rootStart;
		}

		return $start;

	}//end stepStart()

	/**
	 * A step's duration in milliseconds, never below zero.
	 *
	 * @param array $step The step.
	 *
	 * @return int The duration.
	 */
	private function durationMs(array $step): int {
		return max(0, (int)($step['durationMs'] ?? 0));

	}//end durationMs()

	/**
	 * A step's attribute values: its type, name, status and duration, and the HTTP ones for a call.
	 *
	 * @param array $step The step.
	 *
	 * @return array The values.
	 */
	private function stepValues(array $step): array {
		$type = (string)($step['type'] ?? 'step');
		$values = [
			'integriq.step.type' => $type,
			'integriq.step.name' => (string)($step['name'] ?? ''),
			'integriq.step.status' => (string)($step['status'] ?? ''),
			'integriq.step.duration_ms' => $this->durationMs(step: $step),
		];
		if ($type === 'call') {
			$values += $this->httpAttributes(input: ($step['input'] ?? []), output: ($step['output'] ?? []));
		}

		return $values;

	}//end stepValues()

	/**
	 * A step's span kind: client for an outbound call, else internal.
	 *
	 * @param string $type The step type.
	 *
	 * @return int The OTLP span kind.
	 */
	private function stepKind(string $type): int {
		if ($type === 'call') {
			return self::KIND_CLIENT;
		}

		return self::KIND_INTERNAL;

	}//end stepKind()

	/**
	 * A step's status code: error for a failed step, else ok.
	 *
	 * @param string $status The step status.
	 *
	 * @return int The OTLP status code.
	 */
	private function stepStatusCode(string $status): int {
		if ($status === 'error') {
			return self::STATUS_ERROR;
		}

		return self::STATUS_OK;

	}//end stepStatusCode()

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
	 * word. The segment is URL-decoded until stable (at most three more
	 * passes, for double encoding); it is masked when it then holds an `@`
	 * (an e-mail address), or a run of four or more digits once every
	 * character other than an ASCII letter or digit is taken out (a BSN
	 * written as `999993653`, `999.993.653`, `999_993_653`, with a
	 * non-breaking space or an encoded slash, a KvK or case number, a
	 * numeric id), or when it is an opaque token. A token, written in a
	 * token alphabet, is opaque at 32 characters or more, or at 20 or more
	 * when it holds a digit or mixes upper and lower case (a uuid, a hash, a
	 * key). A lowercase route word under 32 characters, such as
	 * `ingeschrevenpersonen`, stays.
	 *
	 * @param string $segment One decoded path segment.
	 *
	 * @return bool True when the segment must be masked.
	 */
	private function looksLikeIdentifier(string $segment): bool {
		if ($segment === '') {
			return false;
		}

		// Three more passes reach a stable value for any realistic double or
		// triple encoding; once stable, a further pass changes nothing.
		$decoded = rawurldecode(rawurldecode(rawurldecode($segment)));
		if (str_contains($decoded, '@') === true
			|| preg_match('/\d{4,}/', (string)preg_replace('/[^A-Za-z0-9]/', '', $decoded)) === 1
		) {
			return true;
		}

		$length = strlen($segment);
		if ($length < 20 || preg_match('/^[A-Za-z0-9_\-.=+~]+$/', $segment) !== 1) {
			return false;
		}

		return $length >= 32
			|| preg_match('/\d/', $segment) === 1
			|| (preg_match('/[a-z]/', $segment) === 1 && preg_match('/[A-Z]/', $segment) === 1);

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
