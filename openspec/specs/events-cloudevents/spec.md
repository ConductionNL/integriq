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
`evaluateFilters` MUST support four filter dialects per CloudEvents subscription spec:
`exact` (field equality), `prefix` (`str_starts_with`), `suffix` (`str_ends_with`),
and `expression` (Symfony ExpressionLanguage evaluated against the event data as
context). When ANY filter in the array fails, the subscription is rejected; only an
all-pass result delivers the message. For each created message, when the matched
subscription has `style = 'push'` the method MUST attempt immediate delivery via
`deliverMessage`. The method MUST log + rethrow on any exception, and MUST return
the array of created `event_message` ObjectEntities.

#### Scenario: an event with no matching subscriptions produces zero messages

- **GIVEN** an event with `type = 'com.example.foo'` and no active subscriptions
  whose `types` array contains that type
- **WHEN** `processEvent($event)` runs
- **THEN** the method SHALL return `[]`
- **AND** no `event_message` SHALL be persisted

#### Scenario: a matching push subscription triggers immediate delivery

- **GIVEN** an event matching subscription `S` (style=push, sink=https://x/cb)
- **WHEN** `processEvent($event)` runs
- **THEN** an `event_message` SHALL be persisted with `status='pending'`
- **AND** `deliverMessage` SHALL be invoked on that message
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

#### Notes

- ExpressionLanguage filters evaluate caller-supplied expression strings against the
  event payload. Subscription owners (whoever can call `subscribe`) effectively get
  code-execution-equivalent over an event data context. Observed-but-suspicious;
  flagged for security review (REQ-005 Notes also flag the missing auth on
  `subscribe`).

### Requirement: Push delivery with status tracking and retry sweep (REQ-002)

`EventService::deliverMessage(ObjectEntity $message)` MUST resolve the message's
`subscriptionId` to an `event_subscription` via OR `find`, return `false` early if
either the message's subscription ID is null OR the subscription does not exist OR
the subscription's `style !== 'push'`. For push subscriptions the method MUST POST
the message payload (JSON-encoded) to `subscription.sink` with `Content-Type:
application/cloudevents+json`, a 30-second timeout, and any extra headers from
`subscription.protocolSettings.headers`. On a 2xx response the method MUST persist
the message with `status='delivered'`, `deliveredAt` (ISO 8601 timestamp), and a
`deliveryResponse` block containing `statusCode` + `body`. On any non-2xx response
OR any thrown exception, the method MUST log the error, persist
`status='failed'` with the error message, and return `false`. `processRetries(int
$maxRetries=5)` MUST scan every `event_message` with `status='pending'`, attempt
delivery for messages whose `retryCount < $maxRetries`, and return the count of
successes.

#### Scenario: 2xx delivery marks the message delivered

- **GIVEN** a `pending` push message
- **WHEN** `deliverMessage(...)` runs AND the sink returns HTTP 200
- **THEN** the message SHALL be persisted with `status='delivered'`, a `deliveredAt`
  ISO timestamp, and `deliveryResponse.statusCode = 200`
- **AND** the method SHALL return `true`

#### Scenario: 5xx delivery marks the message failed and returns false

- **GIVEN** a `pending` push message AND the sink returns HTTP 500
- **WHEN** `deliverMessage(...)` runs
- **THEN** the method SHALL throw, catch its own exception, log it, and persist
  `status='failed'` with `error` set to the exception message
- **AND** the method SHALL return `false`

#### Scenario: processRetries respects the maxRetries cap

- **GIVEN** a pending message with `retryCount = 5` and `processRetries($maxRetries=5)`
- **WHEN** the sweep runs
- **THEN** `deliverMessage` SHALL NOT be invoked for that message (retryCount >=
  maxRetries)

#### Notes

- `processRetries` does NOT increment `retryCount` on the message after a failed
  delivery — the field is read but never written. So in practice, a pending message
  with `retryCount = 0` will be re-attempted on every sweep indefinitely. Observed
  bug; flagged.
- `deliverMessage` does not respect any Retry-After header from the sink — it just
  marks the message failed and exits. Observed; flagged.

### Requirement: Pull subscription cursor pagination (REQ-003)

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

