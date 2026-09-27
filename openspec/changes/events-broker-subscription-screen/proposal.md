---
kind: code
depends_on: [event-broker-transport]
---

# Proposal: events-broker-subscription-screen

## Summary

Integriq can publish an event to RabbitMQ, a Kafka REST proxy or a generic
CloudEvents endpoint, but only when someone writes the subscription object by
hand through the API. The Webhooks page offers webhook, synchronization and
job, and not broker. This change puts "Broker" on the subscription form, with
the broker picked from the transports this instance has, its topic and
routing, and its connection settings with a credential reference instead of a
password.

## Why

Matrix row `integriq:evt-broker`, "Publish events to a message broker such as
Kafka or RabbitMQ." The matrix rates integriq `partial` with `built.state`
`built`, and records where it is reached: "no UI: a subscription with
action.kind='broker' can only be created by writing to the object directly via
the API, not through the Webhooks page's subscription form". The row reached
integriq's matrix from a sibling matrix (`source: sibling-matrix`, sibling row
`dossiq:12.14`); integriq owns it.

There is no demand row. Competitors rated `yes`:

- n8n (`n8n`), source read at n8n@2.40.7:
  "packages/nodes-base/nodes/Kafka/Kafka.node.ts,
  packages/nodes-base/nodes/RabbitMQ/RabbitMQ.node.ts,
  packages/nodes-base/nodes/Amqp/Amqp.node.ts,
  packages/nodes-base/nodes/MQTT/Mqtt.node.ts and
  packages/nodes-base/nodes/Aws/SQS/AwsSqs.node.ts:19 publish messages". No
  evidence URL is recorded for this cell.
- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/kafka-connector/latest/index.md, "Apache Kafka
  Connector 4.15 publishes and consumes Kafka topics", and
  https://docs.mulesoft.com/amqp-connector/latest/index.md, which "sends and
  receives 'messages using an AMQP 0.9.1-compliant broker' such as RabbitMQ".
- Frank!Framework (`frank`), source read at v10.2.0:
  "messaging/src/main/java/org/frankframework/extensions/kafka/KafkaSender.java:50
  publishes to Kafka,
  messaging/src/main/java/org/frankframework/messaging/amqp/AmqpSender.java:71
  to AMQP 1.0 brokers such as RabbitMQ". No evidence URL is recorded for this
  cell.

This change covers one row: `integriq:evt-broker`.

## What integriq already has

The transport half is built by `event-broker-transport` (tasks 1 to 5 ticked):

- `lib/Broker/BrokerTransportRegistry.php:148` (`describeAll()`) lists every
  transport with its id, label, whether it needs a topic and its content
  modes, for example `lib/Broker/Transport/RabbitMqHttpTransport.php:84`.
- `lib/Service/EventService.php:1065` dispatches `action.kind: broker`, and
  `:1772` (`dispatchBrokerAction()`) publishes through the registry with the
  subscription's retry policy.
- The `event_subscription` schema already allows `broker` in `action.kind`
  and declares `brokerId`, `topic`, `routingKey`, `contentMode` and
  `orderingKey` (`lib/Settings/integriq_register.json:1054`).

What is missing:

- `src/modals/EventSubscription/SubscriptionActionFields.vue:214` lists three
  kinds: webhook, synchronization and job. Broker is not one of them.
- No route exposes `describeAll()`, so a screen cannot know which brokers
  exist.
- Connection settings are read from `protocolSettings.broker`
  (`lib/Service/EventService.php:1906`), and the RabbitMQ transport expects a
  `password` there (`lib/Broker/Transport/RabbitMqHttpTransport.php:145`).
  `lib/Controller/EventsController.php:600` (`redactSubscription()`) masks
  the signing secrets only, so a broker password written there is returned in
  clear by the app's own subscription endpoints.

## What this change builds

1. `GET /api/events/brokers`, listing the transports from `describeAll()`.
2. "Broker" in the subscription form's action kinds, with a broker select,
   topic, routing key when the broker uses one, content mode from the
   transport's own list, and ordering key.
3. Broker connection fields on the form: base URL, virtual host for RabbitMQ,
   and a credential picked as a `credentialRef` (ADR-064), never a password.
4. `EventService` resolves a `credentialRef` under `protocolSettings.broker`
   before it hands the settings to the transport.
5. `redactSubscription()` masks every secret key under
   `protocolSettings.broker` for subscriptions written before this change.

## Out of scope

- New transports, such as native AMQP, MQTT or Kafka without a REST proxy.
- Consuming from a broker. This row is about publishing.
- Moving existing plaintext broker passwords into the credential store. The
  change masks them and the form asks for a credential on the next edit.
  `migrate-inline-secrets-to-broker` migrates source secrets only, so a
  migration of stored subscription values is a follow-up nobody owns yet, and
  this change says so rather than assigning it.
