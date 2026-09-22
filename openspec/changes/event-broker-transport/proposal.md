---
kind: code
---

# Proposal: event-broker-transport

## Summary

A matched CloudEvent can go to a message broker, not only to a webhook. One
transport contract, three real brokers behind it, and a dormant default that
refuses rather than pretending.

## Motivation

Parity ledger row 12.14, "message broker or event streaming". GZAC has it,
zaaksysteem has part of it, and the row said integriq has none of it.

**The ledger's reading of the code is out of date, and the correction matters.**
The row reads "no broker, and zero hits for CloudEvents", and the second half is
wrong: `lib/Service/EventService.php` is a full CloudEvents engine. It fans an
event out to matching subscriptions, evaluates five filter dialects, tracks
delivery status, backs off, dead-letters and replays. `action.kind` already
dispatches six ways: `webhook`, `synchronization`, `job`, `notificaties`, `flow`
and `mapping`.

What is missing is narrower and real: **every one of those six ends in an HTTP
call integriq makes itself.** There is no way to hand an event to a broker and
let the broker fan it out, which is what "message broker or event streaming"
asks for.

The gap is already visible in our own schema. `event_subscription.protocol` is
documented as "Delivery protocol (HTTP, AMQP, MQTT, NATS)" and
`event_subscription.sink` as "Delivery target URL (or AMQP/MQTT endpoint)".
Nothing reads either. A field that documents four protocols and implements one
is worse than an absent field: an administrator can configure `AMQP`, see it
saved, and watch deliveries go out over HTTP.

## What changes

- **A broker transport contract.** `BrokerTransportInterface` with
  `getId()`, `describe()` and `publish()`, held in a first-wins
  `BrokerTransportRegistry`, the same collision policy the intake channel
  registry uses.
- **A `broker` action kind** on `event_subscription.action`, dispatched by
  `EventService` through the registry, with the retry, backoff, dead-letter and
  replay bookkeeping every other kind already gets. A broker publish is a
  delivery attempt like any other.
- **The CloudEvents HTTP protocol binding, both content modes.** Structured
  (`application/cloudevents+json`, the whole event in the body) is what
  `deliverMessage` already sends. Binary (`ce-*` headers, the data alone in the
  body) is what a broker ingress usually wants, and we could not speak it.
- **Three transports that need no new dependency**, because each broker has an
  HTTP ingress:
  - `rabbitmq`, over the management API's publish endpoint;
  - `kafka-rest`, over the Confluent REST Proxy's produce endpoint;
  - `cloudevents-http`, for any CloudEvents HTTP sink, which covers Knative,
    Azure Event Grid and a broker sitting behind its own gateway.
- **A dormant `log` transport** that logs the call and refuses with "broker not
  configured", so an unconfigured broker never looks like a delivered one.

## The two silent successes this closes

Both of these are why a broker publish is not a webhook POST with a different
URL.

- **RabbitMQ answers `200 OK` with `{"routed": false}`** when the exchange
  accepted the message and no queue matched it. The message is gone. A
  transport that reads only the status code records a delivery that reached
  nobody.
- **The Kafka REST Proxy answers `200 OK` with a per-record `error_code`** when
  the produce failed. Same shape, same consequence.

Each transport reads its broker's own answer, not the HTTP status alone, and a
publish that reached no queue is a failure that retries.

## Out of scope, and why

- **Native AMQP, Kafka, MQTT and NATS wire protocols.** Each needs a PHP
  extension or a socket client, a connection pool and a supervised consumer
  loop. None of that belongs in a request-scoped Nextcloud app, and pretending
  otherwise is how you get a broker that works in a test and hangs in
  production. The HTTP ingress of each broker is real, supported and enough to
  publish.
- **Consuming from a broker.** Integriq receives events today by being POSTed
  to, and a broker that can push to a webhook already reaches it. A pull
  consumer is a background worker with its own offset bookkeeping, and it is a
  change of its own.
- **Reading `event_subscription.protocol`.** The new `action.kind` is the
  authority, and it names a configured transport rather than a protocol family.
  `protocol` stays a free-text label, and its description is corrected to say
  so rather than promising four protocols nothing reads.

## Impact

- **Affected specs**: `events-cloudevents` (delta).
- **Affected code**: `lib/Broker/*`, `lib/Service/EventService.php`,
  `lib/AppInfo/Application.php`, `lib/Settings/integriq_register.json`.
- **Backwards compatible**: a new `action.kind` value and a new registry.
  Every existing subscription keeps its kind and its behaviour. A subscription
  that names no `action` still defaults to `webhook`.
- **Consumers**: none change. A broker publish produces the same
  `event_message` record, with the same status vocabulary, that a webhook
  delivery produces.
