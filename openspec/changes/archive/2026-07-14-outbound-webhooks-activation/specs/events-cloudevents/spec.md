# events-cloudevents — Activation Delta

**Spec refs**: `events-cloudevents` REQ-004 (OR object lifecycle → CloudEvent
producer, already specced and implemented but had no caller). This delta adds
the wiring requirement plus the two safety properties that make wiring it
safe.

## ADDED Requirements

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
