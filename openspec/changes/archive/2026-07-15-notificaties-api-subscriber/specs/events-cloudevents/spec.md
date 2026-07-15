# events-cloudevents — Delta: notificaties action-dispatch kind + inbound-notification producer entry point

## Purpose

Extends the existing action-dispatch switch (REQ-008: `webhook` | `synchronization` | `job`) with a fourth
`notificaties` kind, so a matched `event_subscription` can publish to a ZGW Notificaties API `kanaal`
instead of only POSTing a webhook — inheriting REQ-002's retry/backoff/dead-letter bookkeeping unchanged,
the same way `synchronization`/`job` already do. Also documents that inbound ZGW notifications are a new,
generic producer into this pipeline via the pre-existing `emitCloudEvent()` entry point (REQ-004's
"domain-specific CloudEvent type" generalisation, first exercised by `peppol-access-point-connector`, now
exercised a second time here) — no new fan-out/matching logic is introduced. This is the shared-machinery
half of `notificaties-api-subscriber`; the ZGW-domain-specific abonnement lifecycle, callback auth, and
wire-shape mapping live in the new `notificaties-api-connector` capability and route through this unchanged
pipeline, exactly as `nextcloud-event-triggers` does for NC-native producers.

## ADDED Requirements

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
