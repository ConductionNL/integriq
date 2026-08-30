# events-cloudevents — Delta: `mapping` action-dispatch kind

## Purpose

Extends the subscription action-dispatch switch already established by the
base spec's "action dispatch MUST support webhook, synchronization, or job
kinds" requirement with a fourth kind, `mapping`, whose concrete behaviour
(Forms-specific answer resolution, `MappingService`/`CallService` reuse) is
specced in the new `nextcloud-forms-connector` capability spec
(REQ-004). This delta only records that `mapping` is now a recognised
`action.kind` value in the base dispatch switch, and that the existing
retry/backoff/dead-letter/replay machinery applies to it identically to the
three pre-existing kinds — it deliberately does not restate REQ-004's
Forms-specific fetch/resolve/call sequence to avoid duplicating scenarios
already owned by `nextcloud-forms-connector`.

## ADDED Requirements

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
