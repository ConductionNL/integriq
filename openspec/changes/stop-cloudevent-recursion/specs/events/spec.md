# events

## ADDED Requirements

### Requirement: CloudEvent generation SHALL NOT react to its own bookkeeping objects
The listener that turns OpenRegister object changes into CloudEvents MUST
ignore objects that are themselves event bookkeeping — the `event`,
`event_message` and `event_subscription` schemas of the `openconnector`
register — and MUST ignore any object carrying the generated-by provenance
marker, regardless of which register holds it.

Without this, creating a CloudEvent object itself raises an object-created
event, which creates another CloudEvent, without bound.

#### Scenario: A generated CloudEvent does not generate another
- **GIVEN** the listener is registered for object-created events
- **WHEN** an object is created in the `openconnector` register under the `event` schema
- **THEN** no further CloudEvent is created
- **AND** no `event_message` rows are created for it

#### Scenario: A real object still generates exactly one CloudEvent
- **GIVEN** an application creates an ordinary object in its own register
- **WHEN** the object-created event is dispatched
- **THEN** exactly ONE CloudEvent object is created for it

#### Scenario: Provenance survives a register or schema rename
- **GIVEN** a generated CloudEvent that carries the generated-by marker
- **AND** it lives in a register other than `openconnector`
- **WHEN** its creation raises an object-created event
- **THEN** the listener suppresses it on the marker alone

### Requirement: Event fan-out SHALL NOT run inside the originating write request
Subscription matching, `event_message` creation and delivery MUST happen in a
background job. The web request that created the object MUST NOT pay for them,
and an unreachable push subscriber MUST NOT be able to stall an unrelated
application's write.

#### Scenario: Object create returns without fanning out
- **WHEN** an object is created through the API
- **THEN** the response completes without creating `event_message` rows
- **AND** exactly one processing job is enqueued

#### Scenario: An unreachable subscriber does not block the writer
- **GIVEN** an active push subscription whose endpoint never responds
- **WHEN** an object is created
- **THEN** the create still returns promptly
- **AND** the delivery failure is recorded against the message, not the writer

### Requirement: Active subscriptions SHALL be resolved once per processing run
Matching MUST NOT re-query the subscription set for every event.

#### Scenario: Batch of events queries subscriptions once
- **WHEN** a processing run handles multiple queued events
- **THEN** the active subscription set is fetched once and reused
