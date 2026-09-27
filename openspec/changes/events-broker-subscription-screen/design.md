# Design: events-broker-subscription-screen

Kind: code. The backend publishes already; this change adds the list of
brokers, the form fields, and a credential reference in place of a password.

## Where it fits

- Route: `events#brokers` (GET `/api/events/brokers`) on
  `lib/Controller/EventsController.php`, registered with the subscription
  routes at `appinfo/routes.php:451`. It returns
  `BrokerTransportRegistry::describeAll()` (`lib/Broker/BrokerTransportRegistry.php:148`)
  and requires the existing action `event.subscriptions`
  (`lib/actions.seed.json:42`), the same one that lists subscriptions.
- Form: `src/modals/EventSubscription/SubscriptionActionFields.vue:214`
  (`KIND_OPTIONS`) gains `broker`. A `<template v-if="actionKind === 'broker'">`
  block sits beside the synchronization block at `:117` and renders: a broker
  `NcSelect` fed by the new route, topic, routing key (shown when the broker is
  `rabbitmq`), content mode limited to the transport's `contentModes`, and
  ordering key. The component is already registered for the Webhooks page at
  `src/registry.js:154`.
- Connection fields: a new `src/modals/EventSubscription/BrokerConnectionFields.vue`
  writes `protocolSettings.broker` with `baseUrl`, `vhost` and
  `credentialRef`. The credential picker reuses the read and write helpers of
  `src/modals/v2/sourceCredentialRef.js`, which the Source form already uses.
- Dispatch: `lib/Service/EventService.php:1905` (`brokerSettings()`) resolves
  `protocolSettings.broker.credentialRef` into `username` and `password`, or
  `token` for Kafka, before `dispatchBrokerAction()` at `:1772` calls the
  transport. It calls `BrokeredCallService`, whose inject-only resolver at
  `lib/Service/BrokeredCallService.php:344` is private today; the task adds a
  public method for it.
- Redaction: `lib/Controller/EventsController.php:600` (`redactSubscription()`)
  also masks `password` and `token` under `protocolSettings.broker`.
- Schema: `protocolSettings` is already `writeOnly` through
  `lib/Settings/register.d/99-event-subscription-secrets-writeonly.json`, so
  the generic OpenRegister API never returned these values. No schema change.

## D1. The screen reads the broker list, it does not hard-code it

The form asks `GET /api/events/brokers` which transports this instance has.
The alternative was a fixed list of four in the Vue file, the way
`KIND_OPTIONS` is fixed. Rejected: the registry is the source of truth, a
deployment can register its own transport, and the `log` transport is there to
refuse on purpose (`event-broker-transport` REQ-016). Its own label is "No
broker configured" (`lib/Broker/Transport/LogBrokerTransport.php:83`), and the
form shows it last with a note that it refuses every publish, so nobody picks
it by accident.

## D2. A credential reference, not a password field

The transports read a password or token from their configuration
(`RabbitMqHttpTransport.php:145`). Putting a password field on the form would
put a plaintext secret on an OpenRegister object, which ADR-064 forbids. The
form stores a `credentialRef` and `EventService` resolves it at publish time.
The alternative was to point the broker action at a `source` and reuse the
source's authentication. Rejected for this change: a source is an HTTP call
configuration with its own call log and retry, and the broker dispatch already
has its retry policy on the subscription. Two retry policies for one publish
would disagree.

## D3. Mask what was written before

A subscription written through the API before this change can hold a broker
password in `protocolSettings.broker`. The app's own endpoints read it with
`_rbac: false` and `redactSubscription()` does not mask it. The change masks
`password` and `token` there. It does not move the stored values; the form
shows "a password is stored on this subscription; pick a credential to replace
it" when it finds a masked value.

## Declarative versus imperative

Publishing is imperative and stays in `EventService`. The form is declarative
in the sense that matters: the broker list and each broker's needs come from
`describe()`, so a new transport needs no form change.

## Risks

- A deployment without the credential broker cannot resolve a reference. The
  dispatch then records a configuration error, the same treatment an unknown
  `brokerId` gets at `lib/Service/EventService.php:1795`, and does not retry.
- Masking changes what the subscription endpoints return for existing broker
  subscriptions. A client that read the password back from integriq was
  reading a secret it should not have had.
