---
kind: code
depends_on: []
---

# Proposal: events-async-api-products

## Summary

An API product in integriq bundles REST endpoints, and a consumer reaches them
with a key, a subscription at a tier, a rate limit and a quota. Events do not
get any of that. A consumer can only receive integriq's events through a
subscription an administrator creates, and the product policies never apply.
This change lets an API product carry event channels next to its endpoints, so
a consumer subscribes to a channel with the same key, the same product
subscription and the same tier limits as a REST call, and the product
publishes an AsyncAPI document for its channels.

## Why

Matrix row `integriq:evt-async-apis`, "Manage event streams like Kafka topics
as APIs with the same policies as REST." The matrix rates integriq `no` with
`built.state` `none`: "The API Products/gateway feature (api-product-gateway)
governs REST endpoints only".

There is no demand row. Competitors rated `yes`:

- Apache APISIX (`apisix`), source read at 3.18.0: "an upstream of scheme
  kafka (apisix/schema_def.lua:503) sits behind a normal route, so key-auth,
  limit-count and logging plugins apply to topic access as to REST
  (apisix/init.lua:640)". No evidence URL is recorded for this cell.
- WSO2 API Manager (`wso2`), source read at v4.7.0:
  "carbon-apimgt/components/apimgt/org.wso2.carbon.apimgt.rest.api.publisher.v1/src/main/resources/publisher-api.yaml:11017
  /apis/import-asyncapi; WebSocket, SSE and WebSub APIs get subscriptions,
  keys and streaming rate limits like REST APIs". No evidence URL is recorded
  for this cell.

Tyk and MuleSoft are rated `partial`: Tyk's stream APIs are enterprise only,
and MuleSoft documents AsyncAPI specs without runtime policies on topics.

This change covers one row: `integriq:evt-async-apis`.

## What integriq already has

- An API product with endpoints, tiers and a default tier
  (`lib/Settings/register.d/api-product-gateway.json:66` and `:75`), and a
  consumer subscription with approval (`lib/Controller/ProductSubscriptionsController.php:123`).
- Tier enforcement for a product endpoint: no active subscription answers 403
  `subscription_required`, and the tier's rate limit and quota are enforced
  under the key `product:<uuid>:consumer:<uuid>`
  (`lib/Service/EndpointService.php:977` to `:1017`), with deprecation headers
  from `buildDeprecationHeaders()` at `:1293`.
- Event subscriptions with push and pull delivery, but only for a Nextcloud
  user holding the `event.subscribe` and `event.pull` actions
  (`lib/Controller/EventsController.php:157` and `:399`), admin by default in
  `lib/actions.seed.json:39` and `:44`. A consumer with an API key cannot use
  them.

## What this change builds

1. `channels` on `api_product`: each with a slug, a title, the CloudEvent types
   it carries and a description.
2. Consumer routes on the product: register a push subscription to a channel,
   and pull a channel's events, authenticated like a product endpoint.
3. One product policy check shared by endpoints and channels: the active
   subscription, the tier rate limit and quota under the same key, and the
   deprecation headers. A push delivery counts against the tier quota; a
   delivery over quota waits for the next window rather than being dropped.
4. A revoked product subscription stops push deliveries to that consumer.
5. `GET /api/products/{productSlug}/asyncapi.json`, an AsyncAPI 3.0 document of
   the product's channels, public when the product is public.
6. A "Channels" section on the API product detail page.

## Out of scope

- Proxying a consumer into a Kafka topic or a RabbitMQ queue, the APISIX
  `kafka-proxy` shape. Integriq's channels carry integriq's own events;
  publishing onward to a broker is a broker subscription
  (`events-broker-subscription-screen`).
- Per-channel analytics on the product analytics page.
- Importing an AsyncAPI document to create channels.
