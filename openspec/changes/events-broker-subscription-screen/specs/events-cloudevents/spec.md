# events-cloudevents Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- events-broker-subscription-screen

## Purpose

An administrator sets up a subscription that publishes matched events to a
message broker from the Webhooks page, without writing the object through the
API, and without storing a broker password on the subscription. Matrix row
`integriq:evt-broker`.

## ADDED Requirements

### Requirement: The app lists the broker transports it has (REQ-EBSC-001)

Integriq MUST expose `GET /api/events/brokers` returning, for every registered
broker transport, its id, label, whether it needs a topic and its content
modes, as `BrokerTransportRegistry::describeAll()` reports them. The route MUST
require the action `event.subscriptions`.

#### Scenario: the list reflects the registry
- GIVEN an instance with the RabbitMQ, Kafka REST, CloudEvents HTTP and log transports registered
- WHEN an administrator's browser calls `GET /api/events/brokers`
- THEN four entries are returned with their ids and labels, and `log` is labelled "No broker configured"
- @e2e exclude an API listing; covered by PHPUnit on EventsController and by the form test that consumes it

#### Scenario: the list needs the action
- GIVEN a signed-in user outside every group allowed `event.subscriptions`
- WHEN they call `GET /api/events/brokers`
- THEN the response is 403
- @e2e exclude an authorization refusal; covered by PHPUnit on EventsController

### Requirement: The subscription form offers broker as a delivery action (REQ-EBSC-002)

The subscription form on the Webhooks page MUST offer "Broker" as an action
kind. Choosing it MUST show a broker select fed by `GET /api/events/brokers`, a
topic field when the broker needs one, a routing key field for RabbitMQ, a
content mode select limited to that broker's content modes, and an ordering
key field. Saving MUST write `action.kind: broker` with `brokerId`, `topic`,
`routingKey`, `contentMode` and `orderingKey`.

#### Scenario: an administrator publishes case events to RabbitMQ
- GIVEN an administrator on the Webhooks page creating a subscription for event type `nl.zaak.created`
- WHEN they choose "Broker", pick RabbitMQ, enter exchange `zaken` and routing key `zaak.created`, and save
- THEN the stored subscription has `action.kind` `broker`, `brokerId` `rabbitmq`, `topic` `zaken` and `routingKey` `zaak.created`
- e2e: tests/e2e/events-broker-subscription.spec.ts

#### Scenario: Kafka offers only the modes it supports
- GIVEN the Kafka REST transport describes structured mode only
- WHEN the administrator picks Kafka
- THEN the content mode select offers structured only and the routing key field is hidden
- e2e: tests/e2e/events-broker-subscription.spec.ts

### Requirement: Broker credentials are a credential reference, resolved at publish (REQ-EBSC-003)

The form MUST store broker credentials as `protocolSettings.broker.credentialRef`
and MUST NOT offer a password or token field. At publish time `EventService`
MUST resolve the reference and pass the secret to the transport. A reference
that cannot be resolved MUST be recorded as a configuration error on the event
message and MUST NOT enter the retry loop.

#### Scenario: a publish uses the referenced credential
- GIVEN a broker subscription whose `credentialRef` names credential `rabbitmq-zaken`
- WHEN a matching event is dispatched
- THEN the RabbitMQ transport receives the username and password of that credential and the subscription object holds no secret
- @e2e exclude credential resolution inside dispatch; covered by PHPUnit on EventService with a mocked BrokeredCallService

#### Scenario: an unknown credential fails once
- GIVEN a broker subscription whose `credentialRef` names a credential that does not exist
- WHEN a matching event is dispatched
- THEN the event message records a configuration error and is not scheduled for retry
- @e2e exclude a failure path; covered by PHPUnit on EventService

### Requirement: Stored broker secrets are masked on the app's subscription endpoints (REQ-EBSC-004)

The subscription endpoints of `EventsController` MUST mask `password` and
`token` under `protocolSettings.broker` the same way they mask the signing
secrets. The form MUST tell the administrator when a masked value is present
and offer a credential to replace it.

#### Scenario: an old plaintext password is not returned
- GIVEN a subscription written through the API with `protocolSettings.broker.password` set
- WHEN an administrator lists subscriptions through `GET /api/events/subscriptions`
- THEN the password is returned as a mask, never in clear
- @e2e exclude a redaction rule; covered by PHPUnit on EventsController
