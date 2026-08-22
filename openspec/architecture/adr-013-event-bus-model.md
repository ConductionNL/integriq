# ADR-013: Event-bus model — Event / EventSubscription / EventMessage / Consumer

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

Integriq's data model contains 15 schemas. ADR-005 documents the core
triad (Source → Synchronization → SynchronizationContract) and notes that
"Consumer + EventSubscription are the event-bus counterparts (see ADR-008)".
ADR-008 covers Endpoint dispatch. No ADR has ever documented the five
event-bus entities themselves — their purpose, their relationships, or why they
are separated from the Source/Sync/Contract triad.

The five event-bus entities are:

- `lib/Db/Event.php` — a CloudEvents-shaped record of something that happened.
- `lib/Db/EventSubscription.php` — a filter + delivery contract registered by
  a Consumer.
- `lib/Db/EventMessage.php` — a delivery record: one row per (Event ×
  matched Subscription).
- `lib/Db/Consumer.php` — an outbound subscriber identity: a service or
  application that authenticates against integriq and holds endpoint/mapping
  access rights.
- `lib/Service/EventService.php` — the service that fans Events out to
  matching Subscriptions and attempts delivery.

The event-bus surface is operationally orthogonal to the sync triad: syncs pull
data from remote sources on a schedule; events push notifications to registered
consumers when objects change. They share the `Source` entity as a context
reference but the delivery mechanism and schema shapes are entirely separate.

## Decision

The event-bus model is defined as follows:

**Event** (`lib/Db/Event.php`) implements the
[CloudEvents v1.0 specification](https://cloudevents.io/). Required attributes
are `uuid`, `source` (URI), `type`, `specversion` (`"1.0"`), and `time`. The
`data` field carries a JSON payload. Integriq-specific tracking fields
`userId`, `created`, `updated`, `processed`, and `status` (`"pending"` →
`"processed"` / `"failed"`) are added on top of the CloudEvents envelope; they
are NOT part of the CloudEvents spec and MUST NOT be forwarded to subscribers.

**Consumer** (`lib/Db/Consumer.php`) is an outbound subscriber identity. It
stores `authorizationType` / `authorizationConfiguration` (the auth credentials
the Consumer uses when integriq pushes to it) and access-control constraints
(`domains`, `ips`). A Consumer is NOT a Nextcloud user; it represents an
external service that receives pushed events. Multiple subscriptions can share
one Consumer identity.

**EventSubscription** (`lib/Db/EventSubscription.php`) is the join between a
Consumer identity and a set of event-type filters. It holds `source` (URI
prefix filter), `types` (array of CloudEvent type strings), `filters` (array of
filter expressions evaluated by `Symfony\Component\ExpressionLanguage`), `sink`
(delivery URL), `protocol`, `protocolSettings`, and `style` (`"push"` or
`"pull"`). A subscription with `style = "push"` triggers immediate delivery
inside `EventService::processEvent()`; a `"pull"` subscription enqueues the
EventMessage for the Consumer to retrieve later.

**EventMessage** (`lib/Db/EventMessage.php`) is the delivery record for one
(Event × Subscription) pair. It stores the `payload`, `status`
(`"pending"` / `"delivered"` / `"failed"`), `retryCount`, `lastAttempt`,
`nextAttempt`, and the original `lastResponse` from the Consumer's endpoint.
`EventMessage::incrementRetry()` advances `retryCount`, sets `lastAttempt =
now`, and sets `nextAttempt = now + backoffMinutes`. Integer FKs `eventId`,
`consumerId`, and `subscriptionId` link to the other three entities.

**Why these four entities are separate from the Source/Sync/Contract triad**:
- The sync triad (Source → Synchronization → SynchronizationContract) is a
  PULL model: integriq reaches out to a remote source to fetch and
  normalise data, then persists a per-object hash for change detection.
- The event-bus (Event → EventSubscription → EventMessage + Consumer) is a PUSH
  model: integriq is the origin; external systems register to receive
  notifications. The data direction is reversed and the delivery guarantees
  (retry, at-least-once) are different from sync semantics.
- Mixing the two models into shared schemas would conflate "remote data source"
  (Source in the sync triad) with "push subscriber identity" (Consumer) — they
  play different roles even though both are "external systems".

**EventService** (`lib/Service/EventService.php`) is the sole entry point for
fan-out. On `processEvent(Event $event)` it fetches all active subscriptions,
evaluates each subscription's filters via `doesEventMatchSubscription()`, creates
an `EventMessage` per matching subscription, and for `style = "push"` calls
`deliverMessage()` immediately. Delivery failures increment the retry counter
but do not block the fan-out loop; failed messages remain in the `EventMessage`
table for a background retry pass.

## Consequences

- New event types are registered by creating new `Event` rows; no schema change
  is needed.
- New subscriber kinds (e.g. WebSocket, AMQP) become new values of
  `EventSubscription.protocol`; the `protocolSettings` JSON field carries
  protocol-specific configuration without a schema change.
- The entity separation (`EventMessage` as a distinct delivery record) enables
  retry and replay without contaminating the source-sync flow — a failed event
  delivery does not affect sync runs.
- Integer FK references (`eventId`, `consumerId`, `subscriptionId` on
  `EventMessage`) will be translated to uuid references when chain B's
  storage migration runs; until then they are integer PKs pointing to the
  `oc_openconnector_events`, `oc_openconnector_consumers`, and
  `oc_openconnector_event_subscriptions` tables.
- ADR-005's note "Consumer + EventSubscription are the event-bus counterparts
  (see ADR-008)" is historically stale — ADR-008 covers Endpoint dispatch, not
  the event-bus. This ADR is the correct reference for the event-bus model.

## Evidence

- `lib/Db/Event.php:9-15` — class docblock: "implements the CloudEvents
  specification (https://cloudevents.io/)".
- `lib/Db/Event.php:17-35` — field declarations: required CloudEvents attributes
  (`uuid`, `source`, `type`, `specversion`, `time`) and tracking fields
  (`userId`, `status`, `processed`).
- `lib/Db/Consumer.php:10-17` — class docblock: "a service or application that
  consumes events, has access to endpoints and mappings, and is able to trigger
  actions based on the events … determines authentication and authorizations on
  all aspects of the platform."
- `lib/Db/Consumer.php:23-32` — field declarations: `authorizationType`,
  `authorizationConfiguration`, `domains`, `ips`.
- `lib/Db/EventSubscription.php:9-30` — class docblock and `@property`
  annotations: "subscription to events … following the CloudEvents
  specification. Supports both push and pull delivery styles."
- `lib/Db/EventSubscription.php:37-47` — field declarations: `source`, `types`,
  `filters`, `sink`, `protocol`, `protocolSettings`, `style`, `status`.
- `lib/Db/EventMessage.php:9-16` — class docblock: "Represents a message that
  needs to be or has been delivered to a consumer based on their subscription.
  Tracks delivery attempts, responses, and current status."
- `lib/Db/EventMessage.php:19-31` — field declarations: `eventId`, `consumerId`,
  `subscriptionId`, `status`, `retryCount`, `lastAttempt`, `nextAttempt`.
- `lib/Db/EventMessage.php:117-122` — `incrementRetry()` method advancing
  `retryCount`, `lastAttempt`, and `nextAttempt`.
- `lib/Service/EventService.php:48-75` — `processEvent()` fan-out loop: fetches
  active subscriptions, calls `doesEventMatchSubscription()`, creates
  `EventMessage`, and for push subscriptions calls `deliverMessage()` inline.
- `lib/Service/EventService.php:84-100` — `doesEventMatchSubscription()`:
  checks event type match, source match, and filter expressions.
