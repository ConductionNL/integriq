# events-cloudevents Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-events-cloudevents. Update Purpose after archive.

@e2e exclude backend CloudEvent fan-out service (event dispatch, no browser UI) — covered by PHPUnit/Newman
## Requirements
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

### Requirement: Pull subscription cursor pagination (REQ-003)

Pull-based subscriptions MUST be served via cursor pagination.
`EventService::pullEvents(ObjectEntity $subscription, ?int $limit=100, ?string
$cursor=null)` MUST query `event_message` filtered by the subscription's UUID and
`status='pending'`. When `$cursor` is non-null, the method MUST apply a filter
`id > $cursor` to skip past previously-delivered messages. The method MUST return
an array `{messages: ObjectEntity[], cursor: string|null}` where `cursor` is the
UUID of the last message in the returned set (or `null` when the result is empty),
so the caller can pass it on the next `pull` call to continue pagination.
`EventsController::pull(string $subscriptionId)` MUST resolve the subscription via OR
`find`, return 404 if missing, return 400 if `style !== 'pull'`, and otherwise call
`EventService::pullEvents` passing `limit` and `cursor` query parameters from the
request.

#### Scenario: a pull call without a cursor returns the first page

- **GIVEN** a pull subscription with 150 pending messages and `pull(subscriptionId)`
  with `limit=100`
- **WHEN** the controller runs
- **THEN** the response SHALL contain 100 messages
- **AND** `cursor` SHALL be the UUID of the 100th message
- **AND** subsequent `pull` calls with that cursor SHALL return the remaining 50

#### Scenario: a pull call on a push-style subscription returns 400

- **GIVEN** a subscription with `style='push'`
- **WHEN** `EventsController::pull($subscriptionId)` runs
- **THEN** the response SHALL be HTTP 400 with `error = "Subscription is not pull-based"`

#### Scenario: an empty result returns a null cursor

- **GIVEN** a pull subscription with zero pending messages
- **WHEN** `pullEvents(...)` runs
- **THEN** the result SHALL be `{messages: [], cursor: null}`

#### Notes

- The `id > $cursor` filter assumes monotonic UUID ordering. UUIDv4 is not
  monotonic; UUIDv7 is. Whether this filter does what the code thinks depends on
  what OR's `findAll` does with the `>` operator on `id`. Observed; flagged.

### Requirement: OR object lifecycle → CloudEvent producer (REQ-004)

OR object lifecycle changes MUST be turned into canonical CloudEvents.
`EventService::handleObjectCreated(ObjectEntity $object)`,
`handleObjectUpdated(ObjectEntity $oldObject, ObjectEntity $newObject)`, and
`handleObjectDeleted(ObjectEntity $object)` MUST each persist a new `event` record
in OR with the canonical CloudEvents shape: `source = '/objects/<type>'`, `type` =
the corresponding `com.nextcloud.openregister.object.{created,updated,deleted}`,
`time` = ISO 8601 now, `subject` = the object UUID, `data.type` + `data.id` = the
object's type + UUID, AND (for created/updated) `data.attributes` = the object's
serialised state. For updated events the `data.previous.attributes` MUST carry the
old state. After persisting the `event`, each handler MUST invoke `processEvent`
and return its result.

#### Scenario: created handler emits a CloudEvent with full attributes

- **GIVEN** a newly-created `ObjectEntity` of type `person` with UUID `uuid-1`
- **WHEN** `handleObjectCreated($object)` runs
- **THEN** a new `event` record SHALL be persisted with
  `type='com.nextcloud.openregister.object.created'`, `source='/objects/person'`,
  `subject='uuid-1'`, AND `data.attributes` equal to the object's serialised state
- **AND** `processEvent` SHALL be invoked

#### Scenario: updated handler carries previous state under data.previous

- **GIVEN** an `oldObject` (state A) AND a `newObject` (state B)
- **WHEN** `handleObjectUpdated($oldObject, $newObject)` runs
- **THEN** the persisted `event.data.previous.attributes` SHALL equal state A
- **AND** the persisted `event.data.attributes` SHALL equal state B

#### Scenario: deleted handler omits attributes (object is gone)

- **GIVEN** a deleted `ObjectEntity` of type `person` with UUID `uuid-1`
- **WHEN** `handleObjectDeleted($object)` runs
- **THEN** the persisted `event.data` SHALL contain `type` and `id` only — no
  `attributes` block

#### Notes

- All three handlers compute `userId` as `objectData['userId'] ?? null` — the
  userId reflects the object's owner field, not the actor who triggered the change.
  For audit-trail purposes the correct field would be the Nextcloud session user.
  Observed; flagged.

### Requirement: Events and subscriptions REST surface (REQ-005)

`EventsController` MUST expose seven JSON endpoints, each marked
`@NoAdminRequired` and `@NoCSRFRequired`:

- `messages(int $id)` — returns the event AND its messages (404 if event missing)
- `subscribe()` — creates a new `event_subscription` from request params, stripping
  any `_*`-prefixed internal fields; returns 400 on any exception
- `updateSubscription(string $subscriptionId)` — updates an existing subscription
  the same way; returns 404 if missing, 400 on other exceptions
- `unsubscribe(string $subscriptionId)` — deletes the subscription; returns 404
  if missing
- `subscriptions()` — lists subscriptions filtered by any request param (with `_*`
  internal fields stripped); returns `{results: [...]}`
- `subscriptionMessages(string $subscriptionId)` — returns the subscription + its
  messages (404 if missing)
- `pull(string $subscriptionId)` — see REQ-003 (cursor pagination on pull
  subscriptions)

Every list method MUST honour optional `limit` (default 50) and `offset` (default 0)
query parameters.

#### Scenario: subscribing with a valid payload returns the saved subscription

- **GIVEN** a request `POST /api/events/subscriptions` with body
  `{types: ['com.x'], style: 'push', sink: 'https://x/cb'}`
- **WHEN** `subscribe()` runs
- **THEN** an `event_subscription` SHALL be persisted in OR
- **AND** the response SHALL be HTTP 200 with the saved object's serialised state

#### Scenario: updating a missing subscription returns 404

- **GIVEN** `updateSubscription('missing-uuid')`
- **WHEN** the controller runs
- **THEN** the response SHALL be HTTP 404 with
  `error = "Subscription not found"`

#### Notes

- **Security / IDOR**: every controller method is `@NoAdminRequired` with no
  per-object authorization check — any authenticated Nextcloud user can list, modify,
  or delete ANY subscription by UUID, regardless of who owns it. Compare with
  hydra-gate-no-admin-idor pattern. **Severity: high — flagged for security review.**
- **CSRF**: `@NoCSRFRequired` removes Nextcloud's built-in CSRF token requirement on
  state-changing endpoints (`subscribe`, `updateSubscription`, `unsubscribe`). The
  intent is presumably to allow API consumers without a Nextcloud session, but the
  effect is that any authed browser tab can trigger these endpoints on a victim's
  session. Observed; flagged.
- The internal-field strip (`str_starts_with($key, '_')`) is a shallow guard that
  does not stop the caller from supplying arbitrary other top-level fields on a
  schema-less save. Observed; flagged.

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

The retry sweep MUST run from cron without any manual call site. A NC
background job `lib/Cron/EventRetryJob.php` (TimedJob, interval 300
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

### Requirement: CloudEventListener registration and safety guards (REQ-008)

`CloudEventListener` MUST be registered on OpenRegister's `ObjectCreatedEvent`,
`ObjectUpdatedEvent`, and `ObjectDeletedEvent` in `Application.php`, forwarding
into `EventService::handleObjectCreated`/`handleObjectUpdated`/
`handleObjectDeleted` (REQ-004) subject to two guards evaluated in this order:

1. **Firehose gate**: `EventService::hasActiveSubscriptions()` MUST be
   checked first. When it returns `false` (no `event_subscription` with
   `status = 'active'` exists anywhere on the instance), the listener MUST
   return without calling any `EventService` method and without any OR
   write.
2. **Self-reference guard**: when at least one active subscription exists,
   the listener MUST inspect the affected object's `register`/`schema`
   (`getObject()` for created/deleted, `getNewObject()` for updated) and
   return without forwarding when `register === 'openconnector'` AND
   `schema` is `event` or `event_message` — CloudEvent-machinery's own
   storage, whose mutation would otherwise re-trigger this listener.

Additionally, for `ObjectUpdatedEvent`, when `getOldObject()` returns `null`
the listener MUST log a warning and return without calling
`handleObjectUpdated` (rather than passing `null` into its non-nullable
parameter). The listener's top-level exception handling MUST catch
`\Throwable`, not only `\Exception`, so a `TypeError`/`Error` cannot unwind
into the host app's save operation that triggered the underlying OR event.

#### Scenario: a matching event with an active subscription is forwarded

- **GIVEN** at least one active `event_subscription` exists
- **AND** an OR object of register `someapp`/schema `person` is created
- **WHEN** `ObjectCreatedEvent` fires
- **THEN** `CloudEventListener` MUST call
  `EventService::handleObjectCreated($object)`

#### Scenario: zero active subscriptions means zero forwarding, for every event type

- **GIVEN** no `event_subscription` with `status = 'active'` exists anywhere
  on the instance
- **WHEN** any `ObjectCreatedEvent`, `ObjectUpdatedEvent`, or
  `ObjectDeletedEvent` fires, for any register/schema
- **THEN** `CloudEventListener` MUST NOT call `handleObjectCreated`,
  `handleObjectUpdated`, or `handleObjectDeleted`
- **AND** no `event` OR object MUST be persisted

#### Scenario: the CloudEvent machinery's own writes are never re-forwarded

- **GIVEN** at least one active `event_subscription` exists
- **AND** an OR object of register `openconnector`/schema `event` (or
  `event_message`) is created or updated
- **WHEN** the corresponding `ObjectCreatedEvent`/`ObjectUpdatedEvent` fires
- **THEN** `CloudEventListener` MUST NOT forward it to `EventService`
- **AND** no further `event`/`event_message` object MUST be persisted as a
  result (breaking the recursion described in `design.md`)

#### Scenario: an ordinary openconnector-register object still forwards

- **GIVEN** at least one active `event_subscription` exists
- **AND** an OR object of register `openconnector`/schema `source` (NOT
  `event`/`event_message`) is created
- **WHEN** `ObjectCreatedEvent` fires
- **THEN** `CloudEventListener` MUST forward it normally (the self-reference
  guard is schema-specific, not register-wide)

#### Scenario: a null previous-object-state update is skipped, not crashed

- **GIVEN** at least one active `event_subscription` exists
- **AND** an `ObjectUpdatedEvent` fires whose `getOldObject()` returns `null`
- **WHEN** `CloudEventListener::handle()` runs
- **THEN** it MUST log a warning and return without calling
  `handleObjectUpdated`
- **AND** it MUST NOT throw

#### Scenario: a Throwable from EventService does not unwind into the host save

- **GIVEN** `EventService::handleObjectCreated()` throws a `\TypeError` (not
  an `\Exception`)
- **WHEN** `CloudEventListener::handle()` processes the corresponding event
- **THEN** the `\TypeError` MUST be caught and logged
- **AND** `handle()` MUST NOT propagate it to its caller

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

### Requirement: A subscription's action dispatch MUST support a `notificaties` kind for ZGW Notificaties API publishing (REQ-010)

`EventService::attemptDelivery()`'s existing `action.kind` switch (REQ-008) MUST support a fourth value,
`notificaties`, with action shape `{kind: 'notificaties', sourceId, kanaal, hoofdObjectField?,
resourceField?, actieMap?, kenmerken?}`. Dispatch MUST proceed as follows: resolve `action.sourceId` to a
`Source` OR object (not-found handling identical to REQ-008's `synchronizationId`/`jobId` resolution —
`status='failed'`, REQ-002 failure-path bookkeeping, retryable); build the ZGW notification body via
`notificaties-api-connector` REQ-005's `buildNotificationBody`; POST it via `CallService::call($source,
'/notificaties', 'POST', ['json' => $body])`. A 2xx response is a REQ-002 success-path attempt
(`status='delivered'`); any non-2xx response or thrown exception is a REQ-002 failure-path attempt, subject
to the same backoff/abandon schedule (including any subscription-declared `retryPolicy`, REQ-009) as a
webhook or synchronization dispatch. `kind='notificaties'` MUST NOT invoke `deliverMessage` (no direct
webhook POST) and MUST NOT apply `webhook-signing` (there is no outbound HTTP request in that sense — the
notification body IS the request). An `action.kanaal` that is absent or empty is a configuration error
under `notificaties-api-connector` REQ-006's terms (not a transient failure — `retryCount` NOT
incremented), following REQ-008's existing "unrecognised kind" pattern.

#### Scenario: action.kind=notificaties publishes the ZGW notification body instead of an HTTP webhook

- **GIVEN** a subscription with `action = {kind: 'notificaties', sourceId: '<uuid>', kanaal: 'zaken'}`
  matching an incoming event
- **WHEN** `processEvent` dispatches the created `event_message`
- **THEN** `CallService::call` SHALL be invoked against the resolved `Source` with the built ZGW
  notification body
- **AND** `deliverMessage` SHALL NOT be invoked
- **AND** on a 2xx response the message SHALL be persisted `status='delivered'`

#### Scenario: action.kind=notificaties failure follows the standard retry/backoff/abandon machine

- **GIVEN** the same subscription AND the remote Notificaties API returns HTTP 500
- **WHEN** the dispatch runs
- **THEN** the message SHALL be persisted `status='failed'`, `retryCount` incremented, and `nextAttempt`
  scheduled per REQ-002's backoff (or the subscription's `retryPolicy`, REQ-009)
- **AND** repeated failures SHALL eventually reach `status='abandoned'` exactly as a webhook or
  synchronization dispatch would

#### Scenario: an unresolvable sourceId is a retryable failure, not a hard error

- **GIVEN** a subscription with `action = {kind: 'notificaties', sourceId: 'missing-uuid', kanaal:
  'zaken'}`
- **WHEN** the dispatch runs
- **THEN** the message SHALL be persisted `status='failed'` with `error='source not found'`
- **AND** `retryCount` SHALL be incremented (the referenced Source may be created or corrected later —
  same treatment as an unresolvable `synchronizationId`/`jobId` under REQ-008)

### Requirement: Inbound ZGW Notificaties API notifications are normalized to CloudEvents via emitCloudEvent (REQ-011)

An authenticated, well-formed inbound ZGW notification MUST be turned into a canonical CloudEvent (handled
by `notificaties-api-connector` REQ-002's callback auth and REQ-003's body validation) via the existing
`EventService::emitCloudEvent(string $type, string $source, ?string $subject, array $data, ?string
$userId=null)` entry point — unchanged from its `peppol-access-point-connector` REQ-004 introduction. No
new `event` OR-object construction path is added; `notificaties-api-connector` REQ-003 is the second real
consumer of this generalised producer entry point (the first being `peppol-access-point-connector`'s
`nl.conduction.peppol.delivery.status`/`nl.conduction.peppol.inbound.received` events), confirming the
abstraction generalises as intended. The resulting `event` fans out through `processEvent` (REQ-001)
exactly like any other CloudEvent — a matched `event_subscription` may dispatch via `webhook`,
`synchronization`, `job` (REQ-008), or `notificaties` (REQ-010) unchanged.

#### Scenario: emitCloudEvent is the only construction path for a normalized notification

- **GIVEN** `notificaties-api-connector`'s `handleInboundNotification` normalizes a verified inbound
  notification
- **WHEN** it persists the resulting `event`
- **THEN** it SHALL do so exclusively via `EventService::emitCloudEvent()`
- **AND** the created `event` SHALL be indistinguishable, in storage shape, from one produced by
  `handleObjectCreated`/`handleNextcloudEvent`/any other `emitCloudEvent` caller

#### Scenario: a normalized notification fans out to every dispatch kind uniformly

- **GIVEN** three active subscriptions matching the same normalized notification event, with
  `action.kind` = `webhook`, `synchronization`, and `notificaties` respectively
- **WHEN** `processEvent` runs
- **THEN** all three SHALL receive a created `event_message` and be dispatched via their respective kind
  — the notification's origin (ZGW Notificaties API vs. an OR object mutation vs. an NC-native event) has
  no bearing on fan-out or dispatch behaviour

### Requirement: A subscription's action dispatch MAY additionally support a `mapping` kind (REQ-012)

`EventService::attemptDelivery()`'s action-dispatch switch (base spec) MUST
recognise `action.kind: 'mapping'` as a fourth valid value alongside
`webhook`, `synchronization`, and `job`, dispatching it to
`dispatchMappingAction()` (behaviour specced in `nextcloud-forms-connector`
REQ-004). `kind='mapping'` MUST NOT invoke `deliverMessage` (no direct
webhook HTTP request is made) and MUST NOT apply `webhook-signing`,
identical in posture to the existing `synchronization`/`job` kinds. Success
and failure bookkeeping MUST use the same `recordDeliverySuccess`/
`recordFailure` machinery as every other kind, so `mapping`-kind messages
are subject to the exact same retry/backoff/abandon/dead-letter/replay
behaviour (`dead-letter-replay`) as a webhook delivery. Adding this kind
MUST NOT change dispatch behaviour for any subscription whose `action.kind`
is absent or one of the three pre-existing values — 100% unchanged for
every subscription created before this requirement existed.

#### Scenario: action.kind=mapping is dispatched to dispatchMappingAction, not deliverMessage

- **GIVEN** a subscription with `action = {kind: 'mapping', mappingId:
  '<uuid>', sourceId: '<uuid>', endpoint: '/leads'}` matching an incoming
  event
- **WHEN** `attemptDelivery()` dispatches the created `event_message`
- **THEN** `dispatchMappingAction()` SHALL be invoked
- **AND** `deliverMessage` SHALL NOT be invoked

#### Scenario: pre-existing action kinds are unaffected

- **GIVEN** subscriptions with `action.kind` absent, `'webhook'`,
  `'synchronization'`, or `'job'`
- **WHEN** each dispatches a matching `event_message`
- **THEN** dispatch behaviour is byte-identical to before this requirement
  — the switch gains one new `case`, no existing `case` or the `default`
  (unrecognised-kind) branch changes

#### Scenario: mapping-kind failures retry exactly like a webhook failure

- **GIVEN** a subscription with `action.kind: 'mapping'` whose dispatch
  throws
- **WHEN** the dispatch runs
- **THEN** the message is persisted `status='failed'`, `retryCount`
  incremented, and `nextAttempt` scheduled per the standard backoff (or the
  subscription's `retryPolicy`) — identical to a `synchronization`/`job`
  kind failure

