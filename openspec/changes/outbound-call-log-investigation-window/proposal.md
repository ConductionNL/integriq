---
kind: code
depends_on: []
---

# Proposal: outbound-call-log-investigation-window

## Summary

Integriq keeps the body of outgoing calls in its call log by default, and keeps
it for as long as the record lives. This change turns that around. Status,
timing and redacted headers stay on every record. The request and response
bodies are stored only while an administrator has opened an investigation
window on that source. The window closes by itself at the end time the
administrator chose, and the bodies captured during it age out on their own
schedule.

## Why

Woo capability row 13.23, "Outgoing request logging can be switched on for an
investigation and switches itself off again". Our column reads `no`: "nothing
switches outgoing request logging on for an investigation or off again.
integriq lib/Outbound/Call/CallRecorder.php records calls permanently, which
is the opposite trade". The gap register: "Body-level logging of outgoing
calls is off by default and can be switched on per source for a bounded
window, after which it switches itself off and the logged bodies age out."

The row contradicts `outbound-call-log` REQ-OCD-001 ("every outbound call is a
record with its request and its response"). Ruben's decision **D5** of
2026-10-05 settled it: the row wins. "Body capture becomes opt-in and
time-boxed; status, timing and headers stay permanent." This change modifies
REQ-OCD-001 to say so.

What the code does today, read on `development` at b2005341:

- `CallService::buildAndPersistCallLog()` (`lib/Service/CallService.php:1740`)
  writes `request` with the whole redacted Guzzle config, so the request body
  (`body`, `json`, `form_params`, `multipart`) is always stored. The response
  body is dropped for a 2xx or 3xx unless the call passed `logBody`, and is
  always kept for a 4xx or 5xx.
- `CallRecorder::record()` (`lib/Outbound/Call/CallRecorder.php:115`) writes a
  `call_log` record for replays and pre-checks with the request and the
  response.
- Retention is per source (`logRetention`, `errorRetention`) with an instance
  default `callLogRetention` of 30 days (`lib/Service/RetentionDefaults.php:60`).
  `lib/BackgroundJob/LogCleanUpTask.php` removes expired records.
- One adapter sets `logBody` in code: `lib/Adapters/Slo/SloCurriculumClientHttp.php:99`,
  because SLO's curriculum is public data.

## What changes

1. A source gains an investigation window: `bodyCaptureUntil` (date-time),
   `bodyCaptureReason` and `bodyCaptureBy`. An administrator opens it for a
   number of hours, at most the instance maximum (default 72), with a reason.
   It can be closed early. Opening and closing are recorded on the source's
   audit trail with the principal.
2. A call to a source with an open window stores its request and response
   bodies, marks the record `bodyCaptured: true`, and sets `bodyExpiresAt` to
   the window end plus the body retention (default 7 days).
3. A call to a source without an open window stores no request body and no
   response body, success or failure. The record keeps the target, method,
   URL without secrets, status, timing, size, redacted headers and the trace
   id, under the existing retention.
4. `LogCleanUpTask` strips the bodies of every record past its
   `bodyExpiresAt`, and keeps the record.
5. A failed call keeps the request it sent as `replayRequest`, because REQ-OCD-002
   replays from it. `replayRequest` is readable only to the replay permission,
   is never shown in the call log detail outside a window, and is removed when
   the call succeeds on a replay or when its error retention ends.
6. The code-level `logBody` flag stays only for the callers a test lists. Today
   that is the SLO adapter, whose data is public. A new caller fails that test.

## What does not change

- Secret redaction before the write (REQ-OCD-001's second sentence and
  `http-call-engine` REQ-006).
- Replay, dry run, hand-fire, retry policy, mapping versions, verdicts and
  pre-checks (REQ-OCD-002 to REQ-OCD-007).
- The execution trace (`execution-trace`). Its step snapshots have their own
  redaction and retention.

## Fail closed

No window, an unparseable window, or a window in the past all mean no body is
stored. A clock or settings read that fails stores no body. Opening a window
needs the administrator permission, never the call log read permission.

## Dependencies

None in this plan. Wave 1, size M. Implements decision D5 for row 13.23.
