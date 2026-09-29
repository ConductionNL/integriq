# events-cloudevents Specification (delta)

## ADDED Requirements

### Requirement: A subscription's action dispatch MUST support a `broker` kind (REQ-013)

`event_subscription.action.kind` SHALL accept `broker`. Under it
`EventService` MUST resolve `action.brokerId` against a
`BrokerTransportRegistry` and publish the message's CloudEvent through that
transport instead of POSTing it itself.

The registry MUST hold at most one transport per id, refusing and logging a
second claim on a taken id, so a transport never quietly changes behaviour
because a later registration overwrote it.

A broker publish is a delivery attempt. Its success and failure bookkeeping
MUST be identical to REQ-002's: `recordDeliverySuccess` on success, and on
failure the incremented `retryCount`, the appended `attempts[]` entry, the
`status` and the `nextAttempt` computed from the subscription's effective
retry policy. A `brokerId` that names no registered transport, and an absent
`topic` where the transport requires one, are configuration errors and MUST be
recorded as such rather than entering the retry loop.

#### Scenario: A matched event is published to the named broker

- **GIVEN** an active push subscription with `action = {kind: 'broker', brokerId: 'rabbitmq', topic: 'zaken'}`
- **WHEN** a matching event is processed
- **THEN** the `rabbitmq` transport SHALL be asked to publish the event
- **AND** the message SHALL persist `status = 'delivered'`

#### Scenario: An unknown brokerId is a configuration error, not a retry

- **GIVEN** a subscription with `action = {kind: 'broker', brokerId: 'nothing-answers-to-this'}`
- **WHEN** a matching event is processed
- **THEN** the message SHALL record a configuration error
- **AND** `retryCount` SHALL remain 0 and `nextAttempt` SHALL be null

#### Scenario: A refused publish retries like any other failed delivery

- **GIVEN** a subscription whose transport refuses the publish
- **WHEN** a matching event is processed
- **THEN** `retryCount` SHALL be 1, an `attempts[]` entry SHALL be appended, and `nextAttempt` SHALL be set from the retry policy

### Requirement: A broker that accepted a message it delivered to nobody is a failure (REQ-014)

A transport MUST read its broker's own answer, not the HTTP status alone.

The `rabbitmq` transport MUST treat a `200` response carrying
`{"routed": false}` as a failure: the exchange accepted the message and no
queue matched it, so nothing received it. The `kafka-rest` transport MUST treat
a `200` response whose first record carries a non-null `error_code` as a
failure. Neither SHALL record a delivery.

This is the whole reason a broker publish is not a webhook POST with a
different URL. A transport that reads only the status code reports a delivery
that reached nobody, and a delivery that reached nobody looks exactly like one
that arrived.

#### Scenario: RabbitMQ routes the message to no queue

- **GIVEN** a `rabbitmq` transport and an exchange with no queue bound to the routing key
- **WHEN** the publish returns HTTP 200 with `{"routed": false}`
- **THEN** the transport SHALL report a refusal naming the unrouted routing key
- **AND** the message SHALL NOT be recorded as delivered

#### Scenario: The Kafka REST Proxy reports a per-record error under HTTP 200

- **GIVEN** a `kafka-rest` transport
- **WHEN** the produce returns HTTP 200 with `offsets[0].error_code = 1`
- **THEN** the transport SHALL report a refusal carrying that error
- **AND** the message SHALL NOT be recorded as delivered

#### Scenario: A routed publish is a delivery

- **GIVEN** a `rabbitmq` transport and an exchange bound to a queue
- **WHEN** the publish returns HTTP 200 with `{"routed": true}`
- **THEN** the transport SHALL report the publish as sent

### Requirement: CloudEvents travel in structured or binary content mode (REQ-015)

Integriq SHALL render a CloudEvent for HTTP in both content modes of the
CloudEvents HTTP Protocol Binding, and a broker subscription SHALL choose
between them with `action.contentMode`, defaulting to `structured`.

**Structured** puts the whole event in the body under
`Content-Type: application/cloudevents+json`. **Binary** puts the event's
`data` alone in the body, under the data's own content type, and carries every
other attribute as a `ce-` prefixed header: `ce-specversion`, `ce-id`,
`ce-source`, `ce-type`, and `ce-time`, `ce-subject` and
`ce-dataschema` when present. Extension attributes SHALL travel as `ce-<name>`.

A header value MUST be the attribute's string form. An attribute whose value is
not a scalar SHALL NOT be written as a header, because a header carrying
`Array` is a lie the receiver cannot detect.

#### Scenario: Binary mode moves the attributes into headers

- **GIVEN** a CloudEvent with `type = 'nl.conduction.zaak.created'` and a JSON `data` object
- **WHEN** it is rendered in binary content mode
- **THEN** the body SHALL be the `data` object alone
- **AND** `ce-type` SHALL be `nl.conduction.zaak.created`
- **AND** `Content-Type` SHALL be the data's own content type, not `application/cloudevents+json`

#### Scenario: Structured mode keeps the envelope in the body

- **GIVEN** the same event
- **WHEN** it is rendered in structured content mode
- **THEN** the body SHALL be the whole event
- **AND** `Content-Type` SHALL be `application/cloudevents+json`
- **AND** no `ce-` header SHALL be written

#### Scenario: A non-scalar attribute is left out of the headers

- **GIVEN** a CloudEvent carrying an extension attribute whose value is an array
- **WHEN** it is rendered in binary content mode
- **THEN** that attribute SHALL NOT appear as a header

### Requirement: An unconfigured broker refuses rather than reporting success (REQ-016)

The registry SHALL ship a `log` transport that records the call and refuses
with "broker not configured". It SHALL never report a publish as sent.

A deployment with no broker therefore fails loudly on the first matched event
rather than accumulating `delivered` messages nothing received. This is the
dormant-seam pattern the fleet already uses for an adapter that is not yet
wired, and it exists because a silent no-op and a working integration produce
the same green.

#### Scenario: The dormant transport never reports a send

- **GIVEN** a subscription pointing at the `log` transport
- **WHEN** a matching event is processed
- **THEN** the attempt SHALL be logged
- **AND** the publish SHALL be reported as refused, never as sent
