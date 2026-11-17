# OpenTelemetry and the error sink

Integriq sends its execution traces to an OpenTelemetry collector, forwards errors to an error service the organisation chooses, and exports the spans other apps hand it.
An administrator configures all three on the integriq admin page.
No app needs code for its errors to arrive.

This page covers Woo row 13.29: errors and traces go to a monitoring service the organisation chooses, configured without code.

## Traces

Integriq exports each finished execution trace as OTLP JSON to `{collector}/v1/traces`, from a background job.
A failed or replayed trace is always sent.
The share of successful traces that is sent is the sampling ratio.
Spans carry names, timing, status and an allowlisted set of attributes, never message content.

The settings live under the `otel_*` app settings and on the section "Send traces to a monitoring service".

## Error sink

Integriq listens to Nextcloud's `OCP\Log\BeforeMessageLoggedEvent`.
It queues every message whose app is in the configured list and whose level is at or above the configured minimum.
A background job sends it to the configured service.

| Setting | App setting | Default |
| --- | --- | --- |
| On or off | `errorsink_enabled` | off |
| Kind: `sentry` or `otlp-logs` | `errorsink_kind` | `sentry` |
| DSN (`sentry`) or collector base URL (`otlp-logs`) | `errorsink_endpoint` | empty |
| The service runs in our own network | `errorsink_allow_local` | off |
| Lowest level, 0 (debug) to 4 (fatal) | `errorsink_min_level` | `3` (error) |
| Apps, comma separated | `errorsink_apps` | `integriq,openregister,opencatalogi` |
| Credential for the collector login (`otlp-logs`) | `errorsink_credential_name` | empty |
| Header the login goes in (`otlp-logs`) | `errorsink_header_name` | `Authorization` |

Saving is refused when the sink is on without an endpoint.
An `http` address is only taken when it is marked as running in our own network, the same rule as the trace collector.

### What leaves

A forwarded error carries the app, the level, the time, the exception class, the file, the line, the request id and the message text.
The message and the file pass integriq's redactor first: credentials and BSN-shaped numbers are replaced by `[redacted]`.
Context values, stack traces, request bodies, step input and step output never leave.

### Wire formats

- `sentry`: the DSN `https://key@sentry.example.org/42` becomes one POST to `https://sentry.example.org/api/42/envelope/` with the header `X-Sentry-Auth: Sentry sentry_version=7, sentry_client=integriq-errorsink/1.0, sentry_key=key`. The body is a Sentry envelope with one `event` item.
- `otlp-logs`: one POST of an OTLP JSON `resourceLogs` body to `{endpoint}/v1/logs`. The resource's `service.name` is the app that logged the message.

### A sink that is down

The listener never makes an HTTP call and never logs.
A sink that is down never blocks or slows the request that logged the error.
A failed send is tried again after five, ten and twenty minutes, and then dropped without a log line.

## Span command for other apps

Another app hands integriq its finished spans with the typed command `OCA\Integriq\Event\SpanExportRequestedEvent`.
The spans go through the same queue, sampler and exporter as integriq's own traces, under the sending app's `service.name`.

### Constructor

```php
new \OCA\Integriq\Event\SpanExportRequestedEvent(string $appId, array $spans)
```

- `appId`: the sending app's id, such as `opencatalogi`. It becomes the `service.name` of the spans.
- `spans`: a list of at most 100 spans.

### Span keys

| Key | Type | Rule |
| --- | --- | --- |
| `traceId` | string | 32 lowercase hex characters, not all zero |
| `spanId` | string | 16 lowercase hex characters, not all zero |
| `parentSpanId` | string | 16 lowercase hex characters, or absent for a root span |
| `name` | string | not empty; identifier-like words are replaced by `{id}`, at most 200 characters are kept |
| `startTimeUnixNano` | int | nanoseconds since the epoch, above zero |
| `endTimeUnixNano` | int | not before `startTimeUnixNano` |
| `status` | string | `ok` or `error` |
| `attributes` | map | string keys to scalar values |

Only the attributes `http.request.method`, `http.response.status_code` and `url.full` are kept.
Any other attribute is dropped and the span is still queued.
`url.full` loses its query string, fragment and credentials, and identifier-like path segments become `{id}`.

### Result keys

After the dispatch, `getResult()` answers an array with three keys:

| Key | Type | Meaning |
| --- | --- | --- |
| `queued` | int | spans queued for export |
| `rejected` | int | spans refused as malformed |
| `reasons` | list of strings | why spans were refused or not queued |

A refused span gives a reason naming its field, such as `spans[0].traceId: must be 32 lowercase hex characters, not all zero`.
The other reasons are:

- `no-listener`: nobody handled the event. This is the answer the event starts with.
- `export-disabled`: export is switched off; nothing is queued.
- `export-paused`: sends are paused after repeated collector failures; nothing is queued.
- `sampled-out`: a successful span fell outside the sampling ratio. It is neither queued nor rejected.
- `queue-failed`: the spans could not be queued.

The event is handled without a Nextcloud session.

### When integriq is absent

Without integriq the event class does not exist and nothing listens.
A sending app guards with `class_exists()` and treats a missing class, or a result that is not `queued` above zero, as "not exported".
It never blocks or fails its own request on that.

```php
$class = 'OCA\\Integriq\\Event\\SpanExportRequestedEvent';
if (class_exists($class) === true) {
    $event = new $class('opencatalogi', [
        [
            'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'spanId' => '00f067aa0ba902b7',
            'name' => 'GET /api/publications',
            'startTimeUnixNano' => 1760000000000000000,
            'endTimeUnixNano' => 1760000000250000000,
            'status' => 'ok',
            'attributes' => ['http.request.method' => 'GET', 'http.response.status_code' => 200],
        ],
    ]);
    $dispatcher->dispatchTyped($event);
    $exported = ($event->getResult()['queued'] ?? 0) > 0;
}
```

Integriq does not instrument openregister or opencatalogi itself.
Each of them dispatches this command from its own request path, in a change of its own.
