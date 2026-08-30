# Design — activate outbound webhooks (register CloudEventListener)

## Context

Verified at HEAD (`origin/development`, commit `0f1a6c1b`) before touching
anything:

- `grep -rn "CloudEventListener" lib/ tests/` returns exactly two hits: the
  class's own `class CloudEventListener` declaration, and one comment in
  `PeppolOutboundConsumer.php` ("cross-app hook `ObjectCreatedEventListener`/
  `CloudEventListener` use"). `Application.php`'s `register()` method wires
  `ObjectCreatedEventListener`, `ObjectUpdatedEventListener`,
  `ViewDeletedEventListener`, `ObjectDeletedEventListener`, and
  `PeppolOutboundConsumer` on `Object{Created,Updated,Deleted}Event` — but
  never `CloudEventListener`. Confirmed genuinely unregistered.
- The three already-registered `Object*EventListener`s all forward into
  `SynchronizationService::handleObjectEventSynchronization`, a completely
  different concern (pull/push data synchronization pipelines) — none of them
  touch `EventService` or CloudEvents. Confirmed nothing else forwards OR
  object events into the CloudEvent machinery.
- `EventService` (signing, retry, dead-letter, `handleObjectCreated/Updated/Deleted`,
  `processEvent`, `deliverMessage`) is fully built, specced
  (`events-cloudevents`, `webhook-signing`, `dead-letter-replay`), and covered
  by a green `EventServiceTest` — every method is reachable only from tests
  and from `emitCloudEvent()` (used by `PeppolOutboundConsumer` for its own
  domain-specific events), never from the OR-object-lifecycle path.
- `CloudEventListener::handle()` expects OR's real
  `ObjectCreatedEvent::getObject()` / `ObjectUpdatedEvent::getNewObject()`/
  `getOldObject()` / `ObjectDeletedEvent::getObject()` shapes — checked
  against `openregister/lib/Event/Object{Created,Updated,Deleted}Event.php`
  at HEAD; the shapes match exactly what the listener already calls.

## The recursion bug (found during verification, not in the original brief)

`EventService::handleObjectCreated()` / `handleObjectUpdated()` /
`handleObjectDeleted()` each unconditionally call
`$this->objectService->saveObject(..., register: 'openconnector', schema: 'event')`
— they persist the CloudEvent as an OR object regardless of whether any
subscription matches it (matching only decides whether an `event_message`
is *additionally* created). `deliverMessage()` also mutates an existing
`event_message` object via `saveObject()` on every delivery attempt
(success, failure, retry).

Separately, OpenRegister dispatches `ObjectCreatedEvent` /
`ObjectUpdatedEvent` **unconditionally for every insert/update**, regardless
of register or schema — verified in `openregister/lib/Db/MagicMapper.php`:
`insert()` always ends with `$this->eventDispatcher->dispatchTyped(new
ObjectCreatedEvent(...))`; `update()` always ends with
`dispatchTyped(new ObjectUpdatedEvent(...))`. There is no register/schema
exemption anywhere in OR's dispatch path.

Chain this together and registering `CloudEventListener` naively — without
any guard — produces a **guaranteed infinite loop on the very first object
mutation with a matching subscription** (and, less severely but still
wastefully, one extra generation of noise on every mutation even with none):

1. Some app creates/updates/deletes an object → OR fires
   `Object{Created,Updated,Deleted}Event`.
2. `CloudEventListener` forwards it → `EventService::handleObjectCreated()`
   calls `saveObject(register: 'openconnector', schema: 'event', ...)`.
3. That `saveObject()` call is itself an OR insert → OR fires
   `ObjectCreatedEvent` for the **new `event` object**.
4. `CloudEventListener` is registered on `ObjectCreatedEvent` for *any*
   object → it receives this event too → calls `handleObjectCreated()`
   again, this time on the `event` object → persists *another* `event`
   object describing the first → step 3 repeats. Forever, synchronously,
   inside the original request.

The same loop exists through `event_message`: `processEvent()` creates an
`event_message` (`ObjectCreatedEvent` on schema `event_message`) and, for
push subscriptions, immediately calls `deliverMessage()`, which then
*updates* that same `event_message` (`ObjectUpdatedEvent` on schema
`event_message`) to record the delivery outcome — both would re-enter the
listener without a guard.

**Fix**: `CloudEventListener::isSelfReference()` checks
`$object->getRegister() === 'openconnector' && in_array($object->getSchema(),
['event', 'event_message'], true)` and returns early before calling into
`EventService` at all. This is necessary regardless of the subscription-gate
decision below — it is not an optimisation, it is what makes activation
correct rather than a fleet-wide outage. `event_subscription` itself is
excluded from the guard's necessity (subscribe/unsubscribe never triggers a
further `EventService` write) but is left un-forwarded implicitly by the
firehose gate in the common case, and would be a legitimate thing to
CloudEvent-forward in principle if the gate ever passed.

`$object->getRegister()` / `getSchema()` are called directly (not guarded by
`method_exists`), matching `SynchronizationService::doHandleObjectEventSynchronization`'s
established pattern. **Not** matching `PeppolOutboundConsumer`'s
`method_exists($object, 'getRegister') === true && ...` pattern — that
guard is dead code: OR's `ObjectEntity` (and NC's base `Entity` class) only
declares `getId()`/`setId()` as real methods; every other getter (`getRegister`,
`getSchema`, `getUuid`, …) is dispatched via `Entity::__call()`, and PHP's
`method_exists()` does not detect methods reachable only through `__call`.
The condition is therefore always `false`, the guard never trips, and
`PeppolOutboundConsumer` filters on `type` alone, not register/schema as its
comment claims. This is a real, separate, pre-existing bug — filed as a
follow-up issue (see `tasks.md`), NOT fixed here (different service, own
risk surface, out of scope for an activation change).

## Decisions

### D1 — Firehose gate: cheap existence check, not per-subscription pre-filtering

`EventService::hasActiveSubscriptions()` does a `findAll(limit: 1, filters:
{register: openconnector, schema: event_subscription, status: active})` and
returns whether any row came back. `CloudEventListener` calls this **first**,
before the self-reference check or any `EventService` forwarding call — an
install with zero subscriptions (every instance today, since the feature has
never worked) pays exactly one cheap `findAll(limit:1)` per object mutation
and nothing else: no `event` OR object, no matching logic, no write.

**Alternative considered**: pre-compute whether a subscription *could* match
this specific event's type/source inside the listener, to avoid persisting
`event` records even when *some* subscriptions exist but none match this
particular mutation. Rejected for this change: it would require duplicating
`doesEventMatchSubscription`'s matching logic (type/source/filter-dialect
evaluation) in the listener, risking drift between two copies of the same
rule, for a case (some subscriptions configured, but not matching this
particular event) that only matters once outbound webhooks are actually in
active use — at which point `processEvent`'s existing, already-specified,
already-tested REQ-001 contract (persist `event`, evaluate matches, persist
`event_message` per match) is the correct, single-source-of-truth behaviour.
The all-or-nothing gate captures the dominant real-world case (feature
unused → zero cost) without touching the specified matching contract.

### D2 — Fail-open check: already correct, no code change needed

Verified `WebhookSignatureService::sign()` is pure `hash_hmac()` — no I/O, no
externally-triggerable exception today. But `EventService::deliverMessage()`
already structures the call correctly for the case where a future change
makes `sign()` throw: the signature is computed and the
`X-OpenConnector-Signature` header is assigned *before* `$client->post()` is
reached, inside the same `try` block whose `catch (Exception $e)` routes to
`recordFailure()`. A `sign()` exception therefore aborts before any bytes
leave the process and the message is recorded as a failed attempt — never
sent unsigned. Proven by
`EventServiceTest::testDeliverMessageNeverSendsUnsignedWhenSigningFails()`
(mocks `WebhookSignatureService::sign()` to throw, asserts
`IClientService::newClient()` is never called and the message is persisted
`status = 'failed'`).

### D3 — Broaden the listener's catch from `\Exception` to `\Throwable`

`ObjectUpdatedEvent::getOldObject()` is typed `?ObjectEntity` — nullable "if
not available" per its own docblock. Every current OR call site
(`MagicMapper::update()`, `SaveObjects.php`'s bulk path) happens to always
populate it (verified — `update()` falls back to `$oldEntity = $entity` on a
failed re-fetch rather than leaving it null), but `EventService::handleObjectUpdated(ObjectEntity
$oldObject, ObjectEntity $newObject)` requires non-null and would throw a
`TypeError` if OR ever *did* dispatch a null old-state. `TypeError` extends
`\Error`, not `\Exception` — the listener's original `catch (\Exception $e)`
would NOT catch it, and since this listener runs *synchronously inside* the
host app's save transaction (matching the established pattern documented on
`SynchronizationService::handleObjectEventSynchronization`: "A failure here
must never unwind into that save"), an uncaught `TypeError` here would 500 an
unrelated app's unrelated object save. Two independent fixes, same root
cause: (a) explicit `$oldObject === null` guard before calling
`handleObjectUpdated`, logging a warning and returning; (b) `catch
(\Throwable $e)` instead of `catch (\Exception $e)` as defense in depth for
any other future OR/EventService failure mode.

### D4 — Privacy/RBAC: assessed as already-bounded, not newly implemented

Considered blocking activation on payload redaction or shipping
off-by-default per-subscription. Rejected both:

- **Subscription creation is already gated**, not open: `EventsController::subscribe()`
  /`updateSubscription()`/`unsubscribe()` all call
  `ActionAuthService::requireAction()` (ADR-023) — admin-only by default,
  broadened only by an admin editing the action matrix in Settings. Verified
  by reading the controller at HEAD. This contradicts the
  `events-cloudevents` spec's own REQ-005 Notes ("any authenticated Nextcloud
  user can list, modify, or delete ANY subscription... Severity: high") —
  that note appears to predate ADR-023 landing in this controller and is
  stale against the code. Since activating the listener does not change
  `EventsController`'s auth posture at all (it was already reachable,
  already gated the same way, before this change — subscriptions could
  already be created, just never delivered), there is no NEW exposure from
  registering the listener. Left the stale spec note alone (correcting a
  different requirement's Notes is out of scope for an activation change)
  and filed a follow-up issue to re-verify/update it.
- **No field-level redaction exists or is specced** for the delivered
  payload (full object attributes to the subscription's `sink`). This is the
  standard webhook model (Stripe/GitHub send full object state too) and,
  given the admin-only subscribe gate above, is bounded by "an admin who can
  already read every object on the instance decides where a copy goes" — not
  a new authorization bypass. Building per-field/per-schema redaction is a
  real, separate feature (no extension point exists in `event_subscription`'s
  schema today) — filed as a follow-up issue rather than either silently
  shipping it unbuilt-and-unmentioned, or blocking this activation on it.

## Seed Data

No new schema, no new OR register/schema entries, no fixture/seed data
required. `event`, `event_message`, `event_subscription` schemas already
exist (from the archived `retrofit-2026-05-24-events-cloudevents`,
`openconnector-webhook-signing`, `openconnector-dead-letter-replay`
changes). This change only wires an existing, already-tested code path to an
existing, already-tested event bus — no data to seed for the change itself.
Manual verification (documented in `tasks.md`) uses an admin-created
`event_subscription` against `https://webhook.site` or similar, which is
operator setup, not seed data.

## Declarative vs. imperative (ADR-031)

This change does not touch business-rule behaviour — it wires an existing
imperative event-listener class into the DI event dispatcher and adds two
guard conditions (self-reference check, subscription-existence check) plus a
null-guard and a broadened catch type. None of this is lifecycle,
aggregation, calculation, notification, relation, or widget behaviour in
OpenRegister's `x-openregister-*` sense — `x-openregister-notifications`
handles *in-app* notification dispatch on schema events; it has no concept of
signed outbound HTTP delivery to third-party HTTPS sinks with retry/backoff/
dead-letter/HMAC-signing semantics, which is exactly the (already-built,
already-specced) `events-cloudevents` + `webhook-signing` +
`dead-letter-replay` capability this change activates. No declarative
extension point fits; the existing PHP service (`EventService`) is the
correct home per ADR-031's "external API integrations" /
"background jobs that orchestrate external systems" carve-out (ADR-003)
already exercised by the specs this change activates. No exception
documentation needed — there was never a declarative fit to begin with.
