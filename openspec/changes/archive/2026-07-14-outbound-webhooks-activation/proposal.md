---
kind: code
depends_on: []
---

# openconnector — activate outbound webhooks (register CloudEventListener)

## Why

An abstraction audit found that `lib/EventListener/CloudEventListener.php` —
which forwards OpenRegister's `Object{Created,Updated,Deleted}Event`s into
`EventService` so they can be turned into signed CloudEvents and delivered to
external `event_subscription`s — is **never registered** in
`lib/AppInfo/Application.php`. `Application.php` wires four other OR-object
listeners (`ObjectCreatedEventListener`, `ObjectUpdatedEventListener`,
`ViewDeletedEventListener`, `ObjectDeletedEventListener`,
`PeppolOutboundConsumer`) but not `CloudEventListener`. Verified against HEAD
(commit `0f1a6c1b`, `origin/development`): a repo-wide grep for
`CloudEventListener` finds only the class's own self-reference and one
comment mentioning it; nothing else forwards OR object events into
`EventService`.

Consequence: **outbound webhooks / event subscriptions are dead fleet-wide.**
The signing (`WebhookSignatureService`), retry/backoff, and dead-letter-replay
machinery are all already built and specced (`webhook-signing`,
`cloud-event-management`, `events-cloudevents`, `dead-letter-replay`) and
covered by passing PHPUnit suites — but with no producer ever calling
`EventService::handleObjectCreated/Updated/Deleted`, no `event_message` is
ever created for an object mutation, so no external subscriber has ever
received a webhook from any Conduction app's object changes. An admin can
create an `event_subscription` today and it will simply never fire.

Registering the listener is not a one-line flip, though. Two problems must be
fixed first (see `design.md`):

1. **A guaranteed infinite-recursion bug.** `EventService::handleObjectCreated`
   / `Updated` / `Deleted` unconditionally persist a new `event` OR object
   (register `openconnector`, schema `event`) via `saveObject`. OpenRegister
   dispatches `ObjectCreatedEvent` for **every** insert, unconditionally
   (`MagicMapper::insert`/`update`, verified at HEAD). Registering
   `CloudEventListener` on `ObjectCreatedEvent` without excluding its own
   `event`/`event_message` writes means: create an `event` → OR fires
   `ObjectCreatedEvent` for it → `CloudEventListener` catches it → creates
   *another* `event` describing the first → forever. The same loop exists
   through `event_message` status updates (`deliverMessage` → `saveObject` →
   `ObjectUpdatedEvent` → `CloudEventListener`). This is not a hypothetical —
   it fires on the very first object mutation on any instance with the
   listener naively registered.
2. **An unbounded firehose.** Once registered (and the recursion above is
   fixed), *every* object mutation in *every* app on the instance would
   persist a new `event` OR object — even when zero subscriptions exist to
   receive it. `EventService::processEvent`'s subscription-matching gate
   (REQ-001) only decides whether an `event_message` is created; the `event`
   record itself is always written first, unconditionally.

## What Changes

- **Register `CloudEventListener`** in `Application.php` for
  `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent` — the
  activation itself.
- **Self-reference guard** in `CloudEventListener`: skip any object whose
  `register === 'openconnector'` and `schema` is `event` or `event_message`
  — the listener's own machinery — before forwarding. Fixes the infinite
  recursion.
- **Firehose gate**: new `EventService::hasActiveSubscriptions()` cheap
  existence check (`findAll(..., limit: 1)`), called first in
  `CloudEventListener::handle()`. Zero active `event_subscription`s on the
  instance (the default — nobody has ever been able to create a working one)
  means zero persistence cost for any object mutation, on any app, fleet-wide.
  An instance that HAS at least one active subscription falls through to the
  existing, already-specified `processEvent` contract unchanged.
- **Defensive null-guard**: OR's `ObjectUpdatedEvent::getOldObject()` is typed
  nullable ("null if not available"); `handleObjectUpdated()` requires a
  non-null old state. Every current OR call site happens to populate it, but
  this listener runs synchronously inside an arbitrary host app's save — a
  future OR change dispatching a null old-state must degrade to a logged skip,
  not an uncaught `TypeError` that 500s an unrelated save.
- **Broadened the listener's catch** from `\Exception` to `\Throwable` —
  matching the established pattern in `SynchronizationService::handleObjectEventSynchronization`
  ("a failure here must never unwind into that save"). A `TypeError`/`Error`
  is not an `\Exception` and was previously uncaught.

## Capabilities

### Modified Capabilities

- `events-cloudevents`: adds the wiring requirement (REQ-004 was already
  specced and implemented but had no caller) plus the two new safety
  properties (self-reference guard, firehose gate) that make activation safe.

## Impact

- **Code**: `lib/AppInfo/Application.php` (3 `addServiceListener` calls),
  `lib/EventListener/CloudEventListener.php` (guards + broadened catch),
  `lib/Service/EventService.php` (new `hasActiveSubscriptions()` method).
- **Behaviour change**: any Conduction instance with an `event_subscription`
  already configured (created via the pre-existing, always-available
  `EventsController::subscribe()` endpoint, action-RBAC gated
  admin-by-default per ADR-023) starts actually receiving webhooks the moment
  this ships. No schema change; no migration.
- **No UI change**: the Cloud Events / Consumers / Webhooks SPA sections
  already exist (`cloud-event-management`, `consumer-management` specs).

## Privacy / RBAC — assessed, not changed

- Creating/updating/deleting an `event_subscription` is gated by
  `ActionAuthService::requireAction()` (ADR-023): admin-only by default,
  broadened only by an admin editing the action matrix. This is a real,
  enforced RBAC gate — **not** the "any authenticated user" IDOR the
  `events-cloudevents` spec's REQ-005 Notes describe. That note predates
  ADR-023's introduction into `EventsController` and reads stale against the
  code at HEAD (confirmed by reading the controller: every
  subscribe/update/unsubscribe/list method calls `requireAction`). Left
  as-is in this change (correcting a different spec's stale note is out of
  scope for an activation change) — flagged as a follow-up.
- The delivered payload is the full object's serialised attributes,
  unredacted, to whatever `sink` the subscription's admin configured. This
  matches the industry-standard webhook model (Stripe, GitHub) and is bounded
  by the same admin-only gate above — an admin who can already read every
  object on the instance configures where a copy of qualifying objects goes.
  There is no per-field redaction and no schema-level authorization narrower
  than "admin can subscribe to anything" (out of scope here — no spec models
  field-level redaction for this surface); flagged as a follow-up issue
  rather than silently shipped as a surprise or blocked entirely.

## Out of scope

- Fixing `EventsController`'s REQ-005 IDOR/CSRF notes (predates this change;
  independent of whether the listener is registered).
- Field-level payload redaction / per-subscription authorization scoping
  narrower than "admin can subscribe to anything" (no extension point exists
  today; follow-up issue filed).
- `PeppolOutboundConsumer`'s `method_exists($object, 'getRegister')` guard —
  discovered while verifying this change (OR's magic `__call`-based getters
  make `method_exists` always return `false`, so the register/schema filter
  is dead code) but unrelated to outbound-webhooks activation; follow-up
  issue filed.
