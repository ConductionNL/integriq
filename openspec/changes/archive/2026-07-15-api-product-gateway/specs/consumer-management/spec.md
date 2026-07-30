# consumer-management Specification (Delta)

## ADDED Requirements

### Requirement: Consumer detail surfaces its API Product subscriptions (REQ-CON-SUB-001)

The Consumer detail view in the *Consumers* section MUST list the
consumer's `api_product_subscription` rows (product name, tier, status),
read-only, alongside the authentication and rate-limit/quota configuration
it already renders (`REQ-CON-RL-005`). This requirement adds visibility
only; subscription creation/approval happens on the API Products pages
(`api-product-gateway` `REQ-APG-003`/`REQ-APG-004`), not here.

@e2e exclude consumer detail subscription list — Playwright regression added in the implementation phase alongside the existing Consumer detail journey

#### Scenario: an operator sees a consumer's active and pending subscriptions

- GIVEN a Consumer with one `active` subscription to "WOO Publications API" at tier `free` and one `pending_approval` subscription to "KVK Lookup API" at tier `gold`
- WHEN the operator opens that Consumer's detail view
- THEN both subscriptions are listed with their product name, tier, and status

#### Scenario: a consumer with no subscriptions shows an empty state

- GIVEN a Consumer with no `api_product_subscription` rows
- WHEN the operator opens that Consumer's detail view
- THEN the subscriptions section renders an empty state, not an error

### Requirement: Per-product-tier policy takes precedence over the consumer-level rate limit (REQ-CON-SUB-002)

The inbound rate-limit/quota policy applied MUST be the subscription's tier
policy, not the Consumer's own `rateLimit`/`quota` (`REQ-CON-RL-001`), when
a request targets an `Endpoint` that belongs to an `api_product` and the
resolved consumer has an `active` `api_product_subscription` to that
product. The Consumer's own `rateLimit`/`quota` remains the
policy for every other endpoint the same consumer calls that is not part of
that product. This requirement states the precedence rule from the
Consumer's perspective; the resolution mechanism lives in `endpoint-runtime`
and is specified by `api-product-gateway` `REQ-APG-005`.

@e2e exclude backend precedence rule — covered by PHPUnit, no browser UI

#### Scenario: tier policy overrides the consumer's own rate limit on a product endpoint

- GIVEN a Consumer with `rateLimit {requestsPerWindow: 1000, windowSeconds: 60}` AND an `active` subscription to a product's `free` tier `{requestsPerWindow: 2, windowSeconds: 60}`
- WHEN that consumer calls the product's endpoint
- THEN the `free` tier's 2-requests-per-window limit is enforced, not the consumer's 1000-requests-per-window limit

#### Scenario: the consumer's own rate limit still governs non-product endpoints

- GIVEN the same Consumer as above, also calling an unrelated `Endpoint` that belongs to no `api_product`
- WHEN that consumer calls the unrelated endpoint
- THEN the consumer's own `rateLimit {requestsPerWindow: 1000, windowSeconds: 60}` is enforced unchanged
