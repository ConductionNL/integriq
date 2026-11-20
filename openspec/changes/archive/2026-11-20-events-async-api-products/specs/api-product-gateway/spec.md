# api-product-gateway Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- events-async-api-products

## Purpose

An API product carries event channels next to its endpoints. A consumer
subscribes to a channel with the same key, the same product subscription and
the same tier limits as a REST call, and reads an AsyncAPI document of the
product's channels. Matrix row `integriq:evt-async-apis`.

## ADDED Requirements

### Requirement: An API product can declare event channels (REQ-AAPI-001)

The `api_product` schema MUST accept a `channels` array whose items carry a
`slug`, a `title`, a non-empty list of CloudEvent `types` and a `description`.
A channel slug MUST be unique within a product version. The API product detail
page MUST let an administrator add, edit and remove channels.

#### Scenario: an administrator adds a publications channel
- GIVEN an administrator on the detail page of the product `woo-publications` version 2.0.0
- WHEN they add a channel `publications` carrying `nl.woo.publication.created` and save
- THEN the product's channels section lists `publications` with its type
- e2e: tests/e2e/events-async-api-products.spec.ts

### Requirement: A consumer reaches a channel under the product's policies (REQ-AAPI-002)

Pulling a channel's events through `GET /api/products/{productSlug}/channels/{channel}/events`
and registering a push subscription through
`POST /api/products/{productSlug}/channels/{channel}/subscriptions` MUST
authenticate the consumer the way a product endpoint does, MUST answer 403
`subscription_required` when the consumer has no active subscription to that
product, and MUST enforce the subscription tier's rate limit and quota under
the same counter key the product's endpoints use. A deprecated product version
MUST add the same Deprecation and Sunset headers to a pull response as to an
endpoint response.

#### Scenario: no subscription, no events
- GIVEN a consumer with a valid API key and no subscription to `woo-publications`
- WHEN it pulls the `publications` channel
- THEN the response is 403 with error `subscription_required`
- @e2e exclude a consumer API call; covered by Newman in `tests/postman/`

#### Scenario: endpoints and channels share one tier budget
- GIVEN a consumer on the `free` tier with 2 requests per 60 seconds
- WHEN it calls a product endpoint once and pulls a product channel twice within the window
- THEN the third request receives 429 with `Retry-After`
- @e2e exclude a rate-limit counter; covered by PHPUnit on ProductPolicyService

#### Scenario: endpoint enforcement is unchanged
- GIVEN the existing tests for REQ-APG-004 and REQ-APG-005
- WHEN they run against the moved policy code
- THEN they pass without modification
- @e2e exclude a refactor guard; covered by the existing PHPUnit suite

### Requirement: Push deliveries follow the product subscription (REQ-AAPI-003)

A push subscription created through a channel MUST filter on the channel's
CloudEvent types, MUST be signed like any webhook subscription, and MUST name
the product subscription it rides on. Each push delivery MUST count against
the tier quota. A delivery over quota MUST be deferred to the end of the quota
window and MUST NOT be dropped. When the product subscription is revoked,
integriq MUST stop push deliveries for that consumer. The route MUST refuse a
sink that is not `https` or that resolves to a loopback, link-local, private or
metadata address.

#### Scenario: a revoked consumer stops receiving
- GIVEN a consumer with a push subscription on `publications`
- WHEN an administrator revokes its product subscription and a publication is created
- THEN no delivery is made to the consumer's sink
- @e2e exclude an outbound delivery; covered by PHPUnit on EventService

#### Scenario: over quota waits
- GIVEN a consumer whose tier quota for the day is spent
- WHEN a matching event is dispatched to its push subscription
- THEN the delivery is recorded as failed with `retryAfter` at the end of the quota window and is delivered after it
- @e2e exclude a quota window; covered by PHPUnit on EventService

#### Scenario: an internal sink is refused
- GIVEN a consumer registering a push subscription with sink `http://169.254.169.254/latest`
- WHEN it posts the registration
- THEN the response is 400 and no subscription is created
- @e2e exclude a consumer API call; covered by PHPUnit on ProductChannelsController

### Requirement: The product publishes an AsyncAPI document of its channels (REQ-AAPI-004)

`GET /api/products/{productSlug}/asyncapi.json` MUST return an AsyncAPI 3.0
document generated from the latest active version of the product, with one
channel per product channel, one message per CloudEvent type, and the pull and
push operations. The document MUST be readable without authentication when the
product's visibility is `public`, and MUST require an authenticated consumer
with an active subscription when it is `private`.

#### Scenario: a developer reads the channels of a public product
- GIVEN the public product `woo-publications` with channel `publications`
- WHEN a developer requests its AsyncAPI document
- THEN the document is valid AsyncAPI 3.0 and lists channel `publications` with message `nl.woo.publication.created`
- @e2e exclude a JSON document; covered by PHPUnit with an AsyncAPI schema validation fixture
