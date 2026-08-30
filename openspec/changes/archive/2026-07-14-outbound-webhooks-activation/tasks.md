# Tasks — activate outbound webhooks (register CloudEventListener)

> Verified at HEAD before touching anything: `CloudEventListener` is
> genuinely unregistered (grep confirms only self-references); the three
> already-registered `Object*EventListener`s forward to
> `SynchronizationService`, a different concern; nothing else forwards OR
> object events into `EventService`. See `design.md` Context.

- [x] Confirm the recursion hazard at HEAD: `EventService::handleObjectCreated/Updated/Deleted`
  unconditionally `saveObject()` an `event`/`event_message` OR object, and OR's
  `MagicMapper::insert()`/`update()` dispatch `ObjectCreatedEvent`/`ObjectUpdatedEvent`
  unconditionally for every register/schema — registering the listener
  without a self-reference guard would recurse infinitely on the first
  mutation. Documented in `design.md`.
- [x] Add `CloudEventListener::isSelfReference()`: skip forwarding when
  `register === 'openconnector'` and `schema` is `event` or `event_message`
  — calling `getRegister()`/`getSchema()` directly (not via `method_exists`,
  which is always false for OR's `__call`-dispatched magic getters)
- [x] Add `EventService::hasActiveSubscriptions()`: cheap `findAll(limit: 1,
  filters: {register: openconnector, schema: event_subscription, status:
  active})` existence check
- [x] Wire the firehose gate into `CloudEventListener::handle()`: call
  `hasActiveSubscriptions()` first, before the self-reference check or any
  `EventService` forwarding call, for all three event types
- [x] Guard `ObjectUpdatedEvent::getOldObject() === null` (OR's own type is
  nullable): log a warning and skip rather than pass null into
  `handleObjectUpdated()`'s non-nullable parameter
- [x] Broaden `CloudEventListener::handle()`'s catch from `\Exception` to
  `\Throwable` — matches the established
  `SynchronizationService::handleObjectEventSynchronization` pattern for a
  listener running synchronously inside an unrelated host save
- [x] Register `CloudEventListener` in `Application.php` for
  `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`
  (the activation)
- [x] Unit tests (`CloudEventListenerTest`, new): forwards on a matching
  subscription + non-self object; skips ALL forwarding when no active
  subscriptions (all 3 event types); skips `event`/`event_message`
  self-reference (both create and update paths); forwards ordinary
  `openconnector`-register objects that are NOT self-references; skips +
  logs on null `oldObject`; forwards a well-formed update/delete; contains a
  `\TypeError` (not just `\Exception`) without propagating; ignores
  unrelated NC events before even checking subscriptions
- [x] Unit tests (`EventServiceTest`, extended):
  `hasActiveSubscriptions()` true/false; signing-failure fail-open guard —
  `WebhookSignatureService::sign()` throwing means `IClientService::newClient()`
  is never called and the message is recorded `status = 'failed'`, never sent
  unsigned
- [x] Assess privacy/RBAC: confirm `EventsController::subscribe/updateSubscription/unsubscribe`
  are gated by `ActionAuthService::requireAction()` (ADR-023, admin-only
  default) — activation does not newly expose subscription creation; document
  in `design.md` D4 rather than block on unbuilt field-redaction
- [x] File follow-up issue: `events-cloudevents` spec REQ-005 Notes ("any
  authenticated user...IDOR...Severity: high") appears stale against
  `EventsController` at HEAD (ADR-023 `requireAction` gate is present) —
  needs re-verification/update, not fixed here
- [x] File follow-up issue: `PeppolOutboundConsumer::extractOutboundRequestedPayload()`'s
  `method_exists($object, 'getRegister'/'getSchema')` guards are dead code
  (OR's magic-`__call` getters never satisfy `method_exists`) — the
  register/schema filter this listener's comment claims to enforce is a
  no-op; discovered during this change's verification, unrelated to
  outbound-webhooks activation, not fixed here
- [x] `composer phpcs` + `composer phpstan` clean on the four touched files
- [x] Full existing PHPUnit suite green against clean `origin/development`
  baseline, then again with this change applied — report both real counts
- [x] Manual smoke verification: create an `event_subscription` (push,
  `types: []`, a reachable `sink`), mutate an OR object in any app, confirm
  exactly one `event_message` is created and delivered; confirm a second
  mutation with the subscription removed creates neither an `event` nor an
  `event_message` (firehose gate proven)

Acceptance criteria (plain bullets — verified by `/opsx-verify`):

- `CloudEventListener` is registered on `ObjectCreatedEvent`,
  `ObjectUpdatedEvent`, `ObjectDeletedEvent` in `Application.php`
- An object mutation with a matching active `event_subscription` results in
  exactly one signed (when configured) `event_message` delivery attempt
- An object mutation with zero active `event_subscription`s anywhere on the
  instance results in zero `event`/`event_message` writes
- Persisting/updating openconnector's own `event`/`event_message` objects
  never re-triggers `CloudEventListener` (no infinite recursion)
- A signing failure never results in an unsigned HTTP delivery
- A null `ObjectUpdatedEvent::getOldObject()` is skipped with a logged
  warning, never an uncaught error
- Full PHPUnit suite is green with no regressions from this change
