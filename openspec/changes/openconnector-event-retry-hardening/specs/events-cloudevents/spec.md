# events-cloudevents — delta

## MODIFIED Requirements

### Requirement: Push delivery with status tracking and retry sweep (REQ-002)

`EventService::deliverMessage(ObjectEntity $message)` MUST resolve the message's
`subscriptionId` to an `event_subscription` via OR `find`, return `false` early if
either the message's subscription ID is null OR the subscription does not exist OR
the subscription's `style !== 'push'`. For push subscriptions the method MUST POST
the message payload (JSON-encoded) to `subscription.sink` with `Content-Type:
application/cloudevents+json`, a 30-second timeout, and any extra headers from
`subscription.protocolSettings.headers`.

**Success path.** On a 2xx response the method MUST persist the message with
`status='delivered'`, `deliveredAt` (ISO 8601), `nextAttempt=null`, and a
`deliveryResponse` block containing `statusCode` + `body`, and return `true`.

**Failure path.** On any non-2xx response OR any thrown exception, the method
MUST log the error and persist, in one save:

- `retryCount` incremented by 1;
- `lastAttempt` = ISO 8601 now;
- an entry appended to `attempts[]` with `{at, statusCode|null, error|null}`;
- when the incremented `retryCount < maxRetries` (default 5):
  `status='failed'` and `nextAttempt = lastAttempt + min(60s × 4^(retryCount−1),
  6h)`; when the failing response carries a `Retry-After` header (seconds or
  HTTP-date), `nextAttempt` MUST be the LATER of the backoff value and the
  header value (the header may delay a retry, never hasten it);
- when the incremented `retryCount >= maxRetries`: `status='abandoned'` and
  `nextAttempt=null` (terminal — see the schema's own lifecycle contract);

and return `false`.

**Sweep.** `processRetries(int $maxRetries=5)` MUST select every `event_message`
with `status IN ('pending','failed')` AND `retryCount < $maxRetries` AND
(`nextAttempt` null OR `nextAttempt <= now`), attempt delivery for each via
`deliverMessage`, and return the count of successful deliveries. The sweep MUST
NOT select messages whose `status` is `delivered` or `abandoned`, and MUST NOT
re-attempt a message before its `nextAttempt`.

#### Scenario: 2xx delivery marks the message delivered

- **GIVEN** a `pending` push message
- **WHEN** `deliverMessage(...)` runs AND the sink returns HTTP 200
- **THEN** the message SHALL be persisted with `status='delivered'`, a `deliveredAt`
  ISO timestamp, `nextAttempt=null`, and `deliveryResponse.statusCode = 200`
- **AND** the method SHALL return `true`

#### Scenario: a failed delivery increments retryCount and schedules a backoff retry

- **GIVEN** a `pending` push message with `retryCount = 0` AND a sink returning
  HTTP 500
- **WHEN** `deliverMessage(...)` runs
- **THEN** the message SHALL be persisted with `status='failed'`,
  `retryCount = 1`, `lastAttempt` set, and `nextAttempt ≈ lastAttempt + 60s`
- **AND** `attempts[]` SHALL contain one entry with `statusCode = 500`
- **AND** the method SHALL return `false`

#### Scenario: Retry-After delays the next attempt beyond the backoff

- **GIVEN** a push message with `retryCount = 0` AND a sink returning HTTP 429
  with `Retry-After: 600`
- **WHEN** `deliverMessage(...)` runs
- **THEN** `nextAttempt` SHALL be ~600s after `lastAttempt` (the header value,
  because it exceeds the 60s backoff step)

#### Scenario: the final failed attempt transitions the message to terminal abandoned

- **GIVEN** a push message with `retryCount = 4` and `$maxRetries = 5` AND a
  failing sink
- **WHEN** `deliverMessage(...)` runs
- **THEN** the message SHALL be persisted with `status='abandoned'`,
  `retryCount = 5`, and `nextAttempt = null`
- **AND** subsequent `processRetries` sweeps SHALL never select it again

#### Scenario: the sweep retries failed messages whose nextAttempt has passed

- **GIVEN** a `failed` message with `retryCount = 2` and `nextAttempt` in the
  past, AND a `failed` message with `nextAttempt` one hour in the future
- **WHEN** `processRetries(5)` runs
- **THEN** `deliverMessage` SHALL be invoked for the first message only

#### Scenario: processRetries respects the maxRetries cap

- **GIVEN** a `failed` message with `retryCount = 5` and `processRetries($maxRetries=5)`
- **WHEN** the sweep runs
- **THEN** `deliverMessage` SHALL NOT be invoked for that message

#### Scenario: delivered and abandoned messages are never swept

- **GIVEN** one `delivered` message and one `abandoned` message
- **WHEN** `processRetries(5)` runs
- **THEN** `deliverMessage` SHALL NOT be invoked for either message

## ADDED Requirements

### Requirement: Delivery attempt audit trail on the message (REQ-006)

The `event_message` schema SHALL declare an `attempts` array property; each
delivery attempt (immediate push or sweep-driven) SHALL append exactly one entry
`{at: ISO 8601, statusCode: int|null, error: string|null}` — `statusCode` for
HTTP-level failures, `error` for transport exceptions, both null only on
success. The array is naturally bounded by `maxRetries + 1` entries. The
existing `deliveryResponse`/`error` fields continue to reflect only the most
recent outcome.

#### Scenario: each failed attempt appends one audit entry

- **GIVEN** a message that fails twice (HTTP 503, then a connection timeout) and
  then succeeds
- **WHEN** the three attempts have run
- **THEN** `attempts[]` SHALL contain exactly three entries in order: one with
  `statusCode = 503`, one with a non-null `error`, one with `statusCode = 200`
  and `error = null`

### Requirement: Scheduled retry sweep background job (REQ-007)

A NC background job `lib/Cron/EventRetryJob.php` (TimedJob, interval 300
seconds) SHALL invoke `EventService::processRetries()`. The job MUST be
registered via `<background-jobs>` in `appinfo/info.xml` (NOT via a
non-existent `IRegistrationContext::registerJob()` call). The job MUST catch
and log any exception from the sweep so a single poisoned message cannot wedge
the cron pipeline.

#### Scenario: the retry job is registered and runs the sweep

- **GIVEN** the app is installed and cron runs
- **WHEN** the `EventRetryJob` interval elapses
- **THEN** `processRetries()` SHALL be invoked
- **AND** a due `failed` message with a reachable sink SHALL transition to
  `delivered` without any manual intervention

#### Scenario: a sweep exception is contained

- **GIVEN** `processRetries()` throws for one corrupt message
- **WHEN** `EventRetryJob` runs
- **THEN** the job SHALL log the exception and complete without rethrowing
