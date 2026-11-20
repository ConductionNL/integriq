# Design: events-async-api-products

Kind: code. A channel is part of an API product, the policy check is lifted
out of `EndpointService` so both endpoints and channels call it, and delivery
reuses the event subscription machinery.

## Where it fits

- Schema: a fragment `lib/Settings/register.d/events-async-api-products.json`
  (ADR-037) deep-merges a `channels` array onto `api_product`, the way
  `lib/Settings/register.d/api-product-gateway.json` merges fields onto
  `call_log`. It also merges `productSubscription` (uuid) and `productChannel`
  (slug) onto `event_subscription`, so a consumer's channel subscription names
  the product subscription it rides on.
- Policy: a new `lib/Service/ProductPolicyService.php` takes the logic now in
  `lib/Service/EndpointService.php:977` (`enforceInboundRateLimit()`),
  `:1208` (`resolveActiveSubscription()`), `:1252` (`resolveTierPolicy()`),
  `:1096` (`applyRateLimitDecision()`) and `:1293`
  (`buildDeprecationHeaders()`). `EndpointService` calls it with unchanged
  behaviour; channels call the same method.
- Controller and routes: `lib/Controller/ProductChannelsController.php` with
  `productChannels#subscribe` (POST
  `/api/products/{productSlug}/channels/{channel}/subscriptions`),
  `productChannels#unsubscribe` (DELETE on the same path plus `/{id}`),
  `productChannels#pull` (GET `/api/products/{productSlug}/channels/{channel}/events`)
  and `productChannels#asyncapi` (GET `/api/products/{productSlug}/asyncapi.json`),
  added after the product routes at `appinfo/routes.php:542`. They are
  `#[PublicPage]` and authenticate the consumer through
  `AuthorizationService`, which already resolves a consumer at
  `lib/Service/AuthorizationService.php:921` (`getResolvedConsumer()`), as a
  product endpoint does.
- Delivery: `productChannels#subscribe` creates an `event_subscription` owned
  by the consumer, with the channel's CloudEvent types as its filter and a
  signing secret, so `webhook-signing` applies. `lib/Service/EventService.php`
  checks the product subscription before a push delivery of a subscription
  that carries `productSubscription`.
- Page: `src/views/ApiProducts/ApiProductDetail.vue` (registered at
  `src/registry.js:223`) gains a channels section with add, edit and remove,
  and a link to the AsyncAPI document.

## D1. A channel belongs to a product, not to a new schema

A product already has a name, a version, a status, tiers and subscriptions.
Putting `channels` on it means a consumer's one product subscription covers
its endpoints and its events, and a deprecated product version deprecates
both. The alternative was a separate `event_api` schema with its own tiers and
subscriptions, the WSO2 shape. Rejected: it would duplicate the tier model and
the approval gate, and a consumer would hold two subscriptions for one
integration.

## D2. Lift the policy check, do not copy it

The subscription lookup, the 403, the tier resolution, the rate-limit key and
the deprecation headers already exist in `EndpointService`. Channels need
exactly that, so the code moves into one service both call. The alternative
was to call `EndpointService` from the channel controller. Rejected: those
methods are private and tied to an endpoint object; widening them would make
the endpoint service the owner of events.

## D3. A push delivery spends quota, and over quota it waits

A pull is a request and is limited like one. A push is integriq calling the
consumer, but it still consumes the product the consumer subscribed to, so it
counts against the tier quota under the same key. When the quota is spent,
the delivery is recorded as failed with `retryAfter` set to the window end,
which the existing retry path in `EventService` honours. The alternative was
to drop deliveries over quota. Rejected: a consumer that misses events cannot
tell, and the product owner cannot replay what was never kept.

## D4. The AsyncAPI document is generated, not stored

The document is built from the product's channels on request, with each
channel's CloudEvent types as messages and the push and pull operations. The
alternative was to store an uploaded AsyncAPI file. Rejected for this change:
a stored document drifts from the channels that are actually served.

## Declarative versus imperative

The channel list, the tier limits and the approval flag are declared on the
product. Enforcing them is imperative, in `ProductPolicyService`, because a
rate-limit counter and a quota window are state OpenRegister does not keep for
integriq. The revoked-subscription stop is a read at dispatch, not a listener,
so a revoke takes effect on the next delivery without a second write path.

## Seed data

The seeded `api-product-woo-publications-v2` product
(`lib/Settings/register.d/api-product-gateway.json:177`) gains one channel,
`publications`, carrying `nl.woo.publication.created` and
`nl.woo.publication.updated`. The v1 row stays without channels, so the seed
shows a deprecated version that had none.

## Risks

- Moving the policy check can change endpoint behaviour by accident. The
  existing PHPUnit tests for REQ-APG-004 and REQ-APG-005 run against the moved
  code before any channel code is added.
- A consumer's push sink is an outbound call to an address the consumer
  chooses. Today a sink is set by an administrator holding `event.subscribe`
  and `EventService` posts to it as stored (`lib/Service/EventService.php:595`),
  with no host check. A consumer-chosen sink is not trusted: the subscribe
  route accepts `https` only and refuses a host that resolves to a loopback,
  link-local, private or metadata address, the guard ADR-067 decision 3
  describes. When OpenRegister's shared `EgressGuard` is available the route
  uses it instead of its own check.
