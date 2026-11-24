# kiss-kcc-bridge Specification (delta)

## ADDED Requirements

### Requirement: A telephony provider seam with a verified webhook (REQ-005)

Integriq MUST define `CtiProviderInterface` with `getProviderId()`,
`getConfigSchema()`, `normalize()` and `verify()`, with a `log` binding and
a `webhook` binding whose field mapping uses the existing mapping engine.
`POST /api/cti/{sourceId}/events` MUST verify the request with the consumer
apiKey mechanism, answer 401 without processing on failure, and treat
`callId` plus `kind` as idempotent.

#### Scenario: An unsigned PBX post is refused
- GIVEN a CTI source with a consumer apiKey
- WHEN a request arrives without a valid key
- THEN the endpoint answers 401 and no event is dispatched
- @e2e exclude webhook authentication; covered by PHPUnit on `CtiController`

#### Scenario: A repeated event is processed once
- GIVEN a `ringing` event for `callId = 42` already processed
- WHEN the same event arrives again
- THEN no second `CallEvent` is dispatched
- @e2e exclude idempotency guard; covered by PHPUnit

### Requirement: A call carries its caller context as a typed event (REQ-006)

For every normalized call event integriq MUST resolve the caller number
(E.164) to a `partij` through the configured klantinteracties provider and
the open case references of REQ-003, and dispatch `CallEvent(kind, callId,
callerNumber, caller, openCases, agentId, sourceId, at)`, also published on
the CloudEvents fan-out as `nl.conduction.integriq.call.<kind>`. An unknown
number MUST still dispatch with `caller = null`.

#### Scenario: A known citizen rings
- GIVEN a `partij` with the phone number `+31612345678` and one open case
- WHEN a `ringing` event for that number arrives
- THEN a `CallEvent` with the partij and that case reference is dispatched and a CloudEvent `nl.conduction.integriq.call.ringing` is published
- @e2e exclude event dispatch; covered by PHPUnit with a log CTI provider and a stub klantinteracties provider

#### Scenario: An unknown number still rings
- GIVEN no `partij` matches the number
- WHEN a `ringing` event arrives
- THEN a `CallEvent` with `caller = null` is dispatched
- @e2e exclude event dispatch; covered by PHPUnit

### Requirement: A contact moment is written only when the agent asks (REQ-007)

On an `ended` event integriq MUST NOT create a klantcontact by itself. When
the consuming app calls the REQ-004 push endpoint with the `callId`,
integriq MUST create the klantcontact with `kanaal = telefoon`, the call
duration and the linked case, and link the onderwerpobject. Call events
MUST be kept 30 days through a declared retention and then dropped.

#### Scenario: The agent records the call
- GIVEN an `ended` event for `callId = 42` and an agent panel that posts it with a case reference
- WHEN integriq handles the push
- THEN one klantcontact with `kanaal = telefoon` and the duration exists, linked to the case
- @e2e exclude push endpoint; covered by PHPUnit on the REQ-004 path

#### Scenario: Nobody asks
- GIVEN an `ended` event and no push
- WHEN the retention job runs after 30 days
- THEN the call event is gone and no klantcontact was ever created
- @e2e exclude retention job; covered by PHPUnit with a fixed clock
