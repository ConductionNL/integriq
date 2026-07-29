# events-cloudevents — Delta: action-aware dispatch, jsonlogic filter dialect, per-subscription retry policy

## Purpose

Extends the CloudEvents fan-out/delivery machinery so a matched subscription can drive a synchronization or
a job run — not only an outbound webhook POST — and so subscription filters can use a `jsonlogic` dialect
(in addition to the existing `exact`/`prefix`/`suffix`/`expression` dialects) and a per-subscription retry
policy override. This is the shared-machinery half of `nextcloud-event-hub`; the Nextcloud-native event
producers live in the new `nextcloud-event-triggers` capability and route through this unchanged pipeline.

## MODIFIED Requirements

### Requirement: CloudEvent fan-out to matching subscriptions (REQ-001)

`EventService::processEvent(ObjectEntity $event)` MUST query OR for every
`event_subscription` with `status = 'active'`, evaluate each against the event via
`doesEventMatchSubscription`, and persist a new `event_message` for each match.
A subscription matches when (a) its `types[]` is empty OR includes
`event.type`, AND (b) its `source` is null OR matches `event.source` exactly,
AND (c) every entry in its `filters[]` array evaluates to true via `evaluateFilters`.
`evaluateFilters` MUST support five filter dialects: `exact` (field equality), `prefix`
(`str_starts_with`), `suffix` (`str_ends_with`), `expression` (Symfony ExpressionLanguage evaluated
against the event data as context), and `jsonlogic` (the `jwadhams/json-logic-php` library's
`JsonLogic::apply($filter['jsonlogic'], $eventData)`, coerced to boolean — the same library already used
by `EndpointService`'s rule-condition engine, applied here for the first time to event-subscription
filters). When ANY filter in the array fails, the subscription is rejected; only an
all-pass result delivers the message. For each created message, the method MUST resolve the subscription's
effective delivery action (`action.kind`, defaulting to `webhook` when `action` is absent — see REQ-008)
and, when the matched subscription has `style = 'push'`, dispatch accordingly: `kind='webhook'` invokes
`deliverMessage` unchanged; `kind='synchronization'`/`kind='job'` invoke the corresponding REQ-008 handler
instead of `deliverMessage`. The method MUST log + rethrow on any exception, and MUST return the array of
created `event_message` ObjectEntities.

#### Scenario: an event with no matching subscriptions produces zero messages

- **GIVEN** an event with `type = 'com.example.foo'` and no active subscriptions
  whose `types` array contains that type
- **WHEN** `processEvent($event)` runs
- **THEN** the method SHALL return `[]`
- **AND** no `event_message` SHALL be persisted

#### Scenario: a matching push subscription with no configured action triggers immediate webhook delivery

- **GIVEN** an event matching subscription `S` (style=push, sink=https://x/cb, `action` absent)
- **WHEN** `processEvent($event)` runs
- **THEN** an `event_message` SHALL be persisted with `status='pending'`
- **AND** `deliverMessage` SHALL be invoked on that message (default `action.kind = 'webhook'`)
- **AND** the message persists `status='delivered'` on 2xx response

#### Scenario: filter array short-circuits on first fail

- **GIVEN** a subscription with `filters = [{exact: {a: 1}}, {prefix: {b: 'pre-'}}]`
  and an event with `a = 2`
- **WHEN** `evaluateFilters($eventData, $filters)` runs
- **THEN** the method SHALL return `false` after evaluating only the `exact` filter
- **AND** the `prefix` filter SHALL NOT be evaluated

#### Scenario: a subscription with empty types[] matches every event type

- **GIVEN** a subscription with `types = []`
- **WHEN** `doesEventMatchSubscription` runs against any event
- **THEN** the `types` gate SHALL be a no-op (no rejection on type mismatch)

#### Scenario: a jsonlogic filter evaluates against the event payload

- **GIVEN** a subscription with `filters = [{jsonlogic: {"in": ["invoice", {"var":
  "data.attributes.tags"}]}}]` and an event whose `data.attributes.tags` includes `"invoice"`
- **WHEN** `evaluateFilters($eventData, $filters)` runs
- **THEN** the method SHALL return `true` for that filter entry via `JsonLogic::apply`

#### Notes

- ExpressionLanguage filters evaluate caller-supplied expression strings against the
  event payload. Subscription owners (whoever can call `subscribe`) effectively get
  code-execution-equivalent over an event data context. Observed-but-suspicious;
  flagged for security review. (The main spec's REQ-005 Notes also flag missing auth on `subscribe`; at
  HEAD that is stale at the ACTION level — `subscribe` now enforces the ADR-023 `event.subscribe` action
  via `ActionAuthService::requireAction` — but the per-OBJECT ownership gap noted there remains open.)
  `jsonlogic` does NOT carry this risk — its operator set has no code-execution primitive —
  but still evaluates subscriber-supplied logic against the full event payload; see
  `nextcloud-event-hub` `design.md` Security Considerations.

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
- when the incremented `retryCount < maxRetries`:
  `status='failed'` and `nextAttempt = lastAttempt + min(baseSeconds × factor^(retryCount−1),
  capSeconds)`; when the failing response carries a `Retry-After` header (seconds or
  HTTP-date), `nextAttempt` MUST be the LATER of the backoff value and the
  header value (the header may delay a retry, never hasten it);
- when the incremented `retryCount >= maxRetries`: `status='abandoned'` and
  `nextAttempt=null` (terminal — see the schema's own lifecycle contract);

and return `false`. `baseSeconds`, `factor`, `capSeconds`, and `maxRetries` default to the existing class
constants (`RETRY_BASE_SECONDS=60`, `RETRY_FACTOR=4`, `RETRY_CAP_SECONDS=21600`, `maxRetries` default `5`)
UNLESS the resolved subscription declares `retryPolicy` (see REQ-009), in which case each present key in
`retryPolicy` overrides the corresponding default independently — a `retryPolicy` setting only
`maxRetries` still uses the default `baseSeconds`/`factor`/`capSeconds`. This same failure-path bookkeeping
(retryCount/lastAttempt/attempts[]/status/nextAttempt) applies verbatim when the delivery attempt is a
`synchronization` or `job` action dispatch (REQ-008), not only an HTTP POST — "delivery" in this
requirement means "the configured action attempt," of which an HTTP POST is the default kind.

**Sweep.** `processRetries(int $maxRetries=5)` MUST select every `event_message`
with `status IN ('pending','failed')` AND `retryCount < $maxRetries` AND
(`nextAttempt` null OR `nextAttempt <= now`), attempt delivery for each via
`deliverMessage` (or the REQ-008 action handler for non-webhook messages), and return the count of
successful deliveries. The sweep's `$maxRetries` parameter is a sweep-level pre-filter only (a coarse,
global safety cap); it MUST NOT be treated as authoritative over a message's own subscription-declared
`retryPolicy.maxRetries` — the terminal `abandoned` decision is made per-message by the failure-path logic
above using the resolved subscription's own `maxRetries`, so a message whose subscription sets
`maxRetries=3` correctly reaches `abandoned` (and stops being swept, because its `status` is no longer
`pending`/`failed`) even when the sweep itself is invoked with the default `$maxRetries=5`. The sweep MUST
NOT select messages whose `status` is `delivered` or `abandoned`, and MUST NOT re-attempt a message before
its `nextAttempt`.

#### Scenario: 2xx delivery marks the message delivered

- **GIVEN** a `pending` push message
- **WHEN** `deliverMessage(...)` runs AND the sink returns HTTP 200
- **THEN** the message SHALL be persisted with `status='delivered'`, a `deliveredAt`
  ISO timestamp, `nextAttempt=null`, and `deliveryResponse.statusCode = 200`
- **AND** the method SHALL return `true`

#### Scenario: a failed delivery increments retryCount and schedules a backoff retry

- **GIVEN** a `pending` push message with `retryCount = 0`, no `retryPolicy` set on its subscription, AND a
  sink returning HTTP 500
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

#### Scenario: a subscription-declared retryPolicy overrides the default backoff schedule

- **GIVEN** a subscription with `retryPolicy = {baseSeconds: 30, factor: 2, capSeconds: 1800,
  maxRetries: 3}` and a message at `retryCount = 0` whose delivery fails
- **WHEN** `deliverMessage(...)` runs
- **THEN** `nextAttempt` SHALL be `lastAttempt + 30s` (not the default 60s)
- **AND** the message SHALL reach `status='abandoned'` after its 3rd failed attempt (not the default 5th)

#### Scenario: a partial retryPolicy only overrides the keys it sets

- **GIVEN** a subscription with `retryPolicy = {maxRetries: 8}` only
- **WHEN** a delivery fails at `retryCount = 0`
- **THEN** `nextAttempt` SHALL still use the default `baseSeconds=60`/`factor=4` schedule
- **AND** the message SHALL only reach `abandoned` after 8 failed attempts, not the default 5

#### Notes

- The `id > $cursor` filter assumes monotonic UUID ordering. UUIDv4 is not
  monotonic; UUIDv7 is. Whether this filter does what the code thinks depends on
  what OR's `findAll` does with the `>` operator on `id`. Observed; flagged.

## ADDED Requirements

### Requirement: A subscription's action dispatch MUST support webhook, synchronization, or job kinds (REQ-008)

`EventService` MUST resolve, for each created `event_message` (REQ-001), the matched subscription's
effective delivery action from an optional `event_subscription.action` field declaring `{kind:
'webhook'|'synchronization'|'job', sink?, synchronizationId?, jobId?}`. When `action` is absent, the
effective `kind` MUST be `webhook` using the subscription's existing top-level `sink` field — 100% unchanged
behaviour for every subscription created before this requirement existed. Dispatch MUST proceed as follows:

- `kind='webhook'`: `deliverMessage` runs exactly as specified in REQ-002.
- `kind='synchronization'`: resolve `action.synchronizationId` to a `synchronization` OR object. If
  unresolvable, persist the message `status='failed'` with `error='synchronization not found'` and apply
  REQ-002's failure-path bookkeeping (this is a retryable condition — the referenced synchronization may be
  created later or the reference corrected). If resolved, invoke `SynchronizationService::synchronize($synchronization)`;
  any thrown exception, OR a synchronization result that reports failure, is treated as a REQ-002 failure-path
  attempt; a successful run is treated as a REQ-002 success-path attempt (`status='delivered'`).
- `kind='job'`: resolve `action.jobId` to a `job` OR object with the same not-found handling as above, then
  invoke `JobService::executeJob($job, forceRun: true)` with the same success/failure bookkeeping.

`kind='synchronization'`/`kind='job'` MUST NOT invoke `deliverMessage` (no HTTP request is made for these
kinds) and MUST NOT apply `webhook-signing` (there is no outbound HTTP request to sign). An unrecognised
`action.kind` value MUST be treated as a configuration error: the `event_message` is persisted
`status='failed'` with a descriptive `error`, WITHOUT incrementing `retryCount` toward eventual retry (a
config error will not self-resolve on retry) — surfaced to operators via the dead-letter detail view
(`dead-letter-replay` REQ-DLR-002).

#### Scenario: action.kind=synchronization runs the synchronization instead of an HTTP call

- **GIVEN** a subscription with `action = {kind: 'synchronization', synchronizationId: '<uuid>'}` matching
  an incoming event
- **WHEN** `processEvent` dispatches the created `event_message`
- **THEN** `SynchronizationService::synchronize` SHALL be invoked with the resolved synchronization
- **AND** `deliverMessage` SHALL NOT be invoked
- **AND** on a successful run the message SHALL be persisted `status='delivered'`

#### Scenario: action.kind=synchronization failure follows the standard retry/backoff/abandon machine

- **GIVEN** the same subscription AND `SynchronizationService::synchronize` throws
- **WHEN** the dispatch runs
- **THEN** the message SHALL be persisted `status='failed'`, `retryCount` incremented, and `nextAttempt`
  scheduled per REQ-002's backoff (or the subscription's `retryPolicy`)
- **AND** repeated failures SHALL eventually reach `status='abandoned'` exactly as a webhook would

#### Scenario: action.kind=job runs the job instead of an HTTP call

- **GIVEN** a subscription with `action = {kind: 'job', jobId: '<uuid>'}` matching an incoming event
- **WHEN** `processEvent` dispatches the created `event_message`
- **THEN** `JobService::executeJob` SHALL be invoked (`forceRun: true`) with the resolved job
- **AND** `deliverMessage` SHALL NOT be invoked

#### Scenario: an unrecognised action.kind fails once without entering the retry loop

- **GIVEN** a subscription with `action = {kind: 'carrier-pigeon'}`
- **WHEN** `processEvent` dispatches the created `event_message`
- **THEN** the message SHALL be persisted `status='failed'` with a descriptive `error`
- **AND** `retryCount` SHALL remain `0` (not incremented — this is a config error, not a transient failure)

### Requirement: A subscription's retry/backoff policy MUST be independently configurable (REQ-009)

`event_subscription.retryPolicy` MUST be an optional field declaring `{baseSeconds?: int, factor?: int,
capSeconds?: int, maxRetries?: int}`, each key independently overridable (see REQ-002 for the precise
override semantics and defaults). Adding this field MUST NOT change the behaviour of any subscription that
does not set it.

#### Scenario: a subscription without retryPolicy uses the unchanged global defaults

- **GIVEN** a subscription with no `retryPolicy` key
- **WHEN** its deliveries fail repeatedly
- **THEN** the backoff schedule and abandon threshold SHALL be identical to this app's pre-existing
  behaviour (60s / ×4 / 6h cap / 5 retries)
