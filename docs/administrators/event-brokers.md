# Publish events to a message broker

A subscription can hand a matched event to a broker instead of posting it
yourself. The broker then fans it out to whoever subscribed, and integriq stops
being the thing that has to reach every consumer.

Everything you already configure stays the same. The filters, the retry policy,
the dead letter queue and the replay all work on a broker delivery exactly as
they work on a webhook.

## Choose a broker on the subscription

Set the action kind to `broker` and name the transport:

```json
{
  "action": {
    "kind": "broker",
    "brokerId": "rabbitmq",
    "topic": "zaken",
    "routingKey": "zaak.created",
    "contentMode": "structured"
  }
}
```

`topic` is the exchange for RabbitMQ, the topic for Kafka, and the path after
the base URL for a generic sink. `routingKey` is the key RabbitMQ routes on;
leave it out and the event type is used. `contentMode` is `structured` or
`binary`, and `structured` is the default.

The connection lives on `protocolSettings.broker`, next to the signing secret,
because that block is redacted on every read:

```json
{
  "protocolSettings": {
    "broker": {
      "baseUrl": "https://rabbit.gemeente.nl",
      "vhost": "zaken",
      "username": "integriq",
      "password": "<from the credential store>"
    }
  }
}
```

## The three brokers

**RabbitMQ** publishes over the management API. Enable the management plugin,
give the account the `management` tag and write access on the vhost, and point
`baseUrl` at the management port, usually 15672.

**Kafka** publishes over the Confluent REST Proxy. Point `baseUrl` at the
proxy, not at a broker. Set `token` for a bearer token or `username` and
`password` for basic auth. Records go to one partition per key, so set an
ordering key when two events about one object must arrive in order.

**Any CloudEvents HTTP sink** covers the rest: Knative, Azure Event Grid, or a
broker behind its own gateway. This is the only transport that speaks binary
content mode end to end.

## Two answers that look like success

Both of these are why a broker publish is not a webhook with a different URL.
Integriq reads each broker's own answer, not the status code.

**RabbitMQ answers `200 OK` with `routed: false`** when the exchange took your
message and no queue was bound to the routing key. The message is gone. If you
see a run of these, your binding does not match the routing key you configured.

**The Kafka REST Proxy answers `200 OK` with an `error_code` on the record**
when the produce failed. The request succeeded. The produce did not.

Both are recorded as failed deliveries and both retry, so nothing is lost while
you fix the binding.

## Nothing configured yet

A subscription pointing at the `log` transport writes a warning and refuses.
It never reports a send. That is deliberate: a deployment with no broker should
fail on the first event, not collect a thousand messages marked delivered that
nobody received.

## What is not covered

- **Native AMQP, Kafka, MQTT and NATS.** Each needs a socket client and a
  supervised connection, which does not belong in a request-scoped app. Every
  broker here is reached over its own HTTP ingress.
- **Consuming from a broker.** Integriq receives events by being posted to. A
  broker that can push to a webhook already reaches it. A pull consumer with
  its own offset bookkeeping is separate work.
- **The `protocol` field on a subscription.** It is a label. `action.kind` and
  `action.brokerId` decide where the event goes.

## Walk it once

At the time of writing this page has been walked against each broker's
documented HTTP behaviour and against the transports' own tests, not against a
live broker. The RabbitMQ vhost encoding and the Kafka REST Proxy media type
are the two points where a live walk is most likely to correct it.
