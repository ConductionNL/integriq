---
status: implemented
retrofit: true
---

# Consumer and Webhook Management

## Purpose

OpenConnector exposes two related UI sections — **Consumers** and **Webhooks** —
that allow administrators to configure inbound access policies. A Consumer
describes an external system that is permitted to call OpenConnector's endpoints,
including its authorization type and allowed domains. Webhooks share the same
underlying schema (`consumer`) and surface but are presented separately for
clarity. This spec covers the observable browser UI behaviour and the backend
authentication enforcement (covered by PHPUnit/Newman). It is a retrofit spec.
## Requirements

### REQ-CON-UI-001: Consumer Management UI

OpenConnector MUST provide a Consumers section in its SPA where administrators can
browse, create, edit, and delete consumer configurations.

#### Scenario: consumers list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Consumers section via the sidebar nav or direct URL `/apps/openconnector/consumers`
- THEN the Consumers index page renders inside the main content area with content visible

#### Scenario: add consumer button opens the creation modal

- GIVEN the Consumers index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the consumer creation form

### REQ-WBHK-UI-001: Webhook Management UI

OpenConnector MUST provide a Webhooks section in its SPA where administrators can
browse, create, edit, and delete webhook consumers.

#### Scenario: webhooks list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Webhooks section via the sidebar nav or direct URL `/apps/openconnector/webhooks`
- THEN the Webhooks index page renders inside the main content area with content visible

#### Scenario: add webhook button opens the creation modal

- GIVEN the Webhooks index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the webhook/consumer creation form

### REQ-CON-001: Consumer authentication enforcement

The system SHALL enforce consumer-level authentication on inbound calls to
OpenConnector endpoints by resolving the `consumer` record associated with the
request and checking that the caller's credentials match the configured
`authorizationType` (none, apiKey, jwt, basic, oauth2). Requests failing
consumer auth SHALL receive HTTP 401 (or HTTP 403 when the credential is absent
on a protected endpoint).

For `authorizationType: apiKey`, the system SHALL resolve the `consumer` whose
`authorizationConfiguration.apiKey` matches the presented key under a
constant-time comparison, and SHALL record it as the resolved consumer for the
request (so its `rateLimit`/`quota` apply per `REQ-CON-RL-002`). A presented key
that matches no such consumer (and no rule-inline key) SHALL be rejected
fail-closed; an empty presented key SHALL never match. This enforcement is
additive to, and does not regress, the pre-existing rule-inline key path.

@e2e exclude backend consumer auth enforcement — covered by PHPUnit/Newman, not browser UI

#### Scenario: missing API key is rejected

- **GIVEN** a consumer with `authorizationType: apiKey`
- **WHEN** a request arrives without a matching API key header
- **THEN** the response is HTTP 401 (or 403 when the credential is absent)

#### Scenario: a valid Consumer apiKey authenticates and resolves the consumer

- **GIVEN** a consumer with `authorizationType: apiKey` and a configured
  `authorizationConfiguration.apiKey`
- **WHEN** a request presents that exact key on the configured header
- **THEN** authentication passes AND that consumer is the resolved consumer for
  the request

#### Scenario: a wrong API key is rejected

- **GIVEN** a consumer with `authorizationType: apiKey` and a configured apiKey
- **WHEN** a request presents a different key
- **THEN** the response is HTTP 401 AND no consumer is resolved AND no data is served

#### Scenario: a non-apiKey consumer is never matched via the apiKey path

- **GIVEN** a consumer whose `authorizationType` is not `apiKey`
- **WHEN** a request presents a key equal to a value in that consumer's config
- **THEN** the apiKey path does not authenticate it

#### Scenario: authorizationType none passes regardless of headers

- **GIVEN** a consumer with `authorizationType: none`
- **WHEN** any request arrives on a matched endpoint
- **THEN** auth passes regardless of headers

#### Scenario: unlisted domain is forbidden

- **GIVEN** a consumer with allowed `domains` configured
- **WHEN** a request originates from an unlisted domain
- **THEN** the response is HTTP 403

### Requirement: Consumer rate-limit and quota configuration (REQ-CON-RL-001)

The `consumer` schema MUST accept two optional configuration objects:
`rateLimit` = `{requestsPerWindow: int > 0, windowSeconds: int > 0}` (a
short-window burst limiter) and `quota` = `{limit: int > 0, period: one of
"hour" | "day" | "month"}` (a longer-horizon fair-use cap). Both are optional
and independent; when a field is absent the corresponding limit is unlimited.
Adding these fields MUST NOT change the behaviour of any existing consumer that
does not set them.

@e2e exclude backend schema configuration — covered by PHPUnit, no browser UI

#### Scenario: a consumer without rate-limit or quota is unlimited

- **GIVEN** a consumer whose `rateLimit` and `quota` are both absent
- **WHEN** it calls an endpoint any number of times
- **THEN** no request SHALL be throttled on rate-limit or quota grounds
- @e2e exclude backend config default — covered by PHPUnit

#### Scenario: rate-limit and quota are independent

- **GIVEN** a consumer with only `rateLimit` set (no `quota`)
- **WHEN** it calls within the window limit
- **THEN** no quota check SHALL reject the call
- @e2e exclude backend config default — covered by PHPUnit

### Requirement: Inbound rate-limit enforcement after authentication (REQ-CON-RL-002)

For an inbound endpoint request, the system MUST enforce the resolved
consumer's `rateLimit` and `quota` AFTER authentication has passed
(`REQ-CON-001`) and BEFORE the endpoint's rules or target run. A request that
exceeds `rateLimit.requestsPerWindow` within the current `windowSeconds` window
MUST receive HTTP 429. A request that exceeds `quota.limit` within the current
`period` MUST receive HTTP 429 until the period rolls over. Rate-limit
enforcement MUST NOT run before authentication: an unauthenticated caller MUST
receive the auth failure (401/403), never a 429. Counters MUST be maintained
atomically and shared across web workers (distributed cache atomic increment),
and short-window keys MUST self-expire on TTL so they do not accumulate.

@e2e exclude backend enforcement + concurrency — covered by PHPUnit/Newman, no browser UI

#### Scenario: over the short-window limit returns 429

- **GIVEN** a consumer with `rateLimit {requestsPerWindow: 2, windowSeconds: 60}`
- **WHEN** it makes 3 requests within the same 60-second window
- **THEN** the first 2 SHALL succeed and the 3rd SHALL receive HTTP 429
- @e2e exclude backend enforcement — covered by Newman

#### Scenario: over the quota returns 429 until the period rolls over

- **GIVEN** a consumer with `quota {limit: 100, period: "day"}` that has already
  made 100 requests today
- **WHEN** it makes another request the same day
- **THEN** the response SHALL be HTTP 429
- **AND** after the day rolls over the quota counter SHALL reset and requests
  SHALL succeed again
- @e2e exclude backend enforcement — covered by PHPUnit

#### Scenario: rate limiting never precedes authentication

- **GIVEN** an endpoint whose consumer has a `rateLimit` configured
- **WHEN** a request arrives without valid credentials
- **THEN** the response SHALL be the authentication failure (401/403), not 429
- @e2e exclude backend enforcement ordering — covered by PHPUnit

#### Scenario: counters are correct under concurrency

- **GIVEN** a consumer with `rateLimit {requestsPerWindow: 5, windowSeconds: 60}`
- **WHEN** 20 requests arrive concurrently across multiple workers
- **THEN** exactly 5 SHALL succeed and 15 SHALL receive HTTP 429 (atomic
  increment — no over-admission)
- @e2e exclude backend concurrency — covered by PHPUnit

### Requirement: IETF RateLimit response headers (REQ-CON-RL-003)

The endpoint response MUST carry IETF RateLimit headers whenever the resolved consumer has a `rateLimit` configured.
Specifically, such a response MUST include `RateLimit-Limit`,
`RateLimit-Remaining`, and `RateLimit-Reset` (seconds until the window resets),
per `draft-ietf-httpapi-ratelimit-headers`. A 429 response (rate-limit or quota)
MUST additionally carry a `Retry-After` header. A consumer with no `rateLimit`
configured MUST NOT receive these headers.

@e2e exclude backend response headers — covered by Newman, no browser UI

#### Scenario: under-limit responses expose the remaining budget

- **GIVEN** a consumer with `rateLimit {requestsPerWindow: 10, windowSeconds: 60}`
  that has made 3 requests this window
- **WHEN** it makes a 4th request
- **THEN** the response SHALL include `RateLimit-Limit: 10`,
  `RateLimit-Remaining: 6`, and a `RateLimit-Reset` in seconds
- @e2e exclude backend response headers — covered by Newman

#### Scenario: a 429 carries Retry-After

- **GIVEN** a consumer that has exceeded its `rateLimit`
- **WHEN** it makes another request
- **THEN** the 429 response SHALL include a `Retry-After` header
- @e2e exclude backend response headers — covered by Newman

### Requirement: Rate-limit rejections are observable (REQ-CON-RL-004)

An inbound 429 caused by consumer rate-limit or quota MUST be recorded on the
inbound observability surface. The existing `prometheus-metrics` 429 counter
MUST distinguish inbound consumer throttling from outbound source backoff via a
`direction` label (`inbound` | `outbound`); no separate metric is introduced.

@e2e exclude backend metrics — covered by PHPUnit, no browser UI

#### Scenario: inbound throttle increments the 429 counter with direction=inbound

- **GIVEN** a consumer that is rate-limited on an inbound call
- **WHEN** the 429 is returned
- **THEN** the 429 metric counter SHALL increment with label `direction=inbound`
- @e2e exclude backend metrics — covered by PHPUnit

### Requirement: Rate-limit and quota configuration UI (REQ-CON-RL-005)

The Consumer editor in the *Consumers* section MUST let an operator configure a
consumer's `rateLimit` (requests per window + window seconds) and `quota`
(limit + period) alongside the authentication configuration it already renders.
Leaving the inputs empty MUST persist an unlimited (absent) configuration.

#### Scenario: an operator sets a rate limit on a consumer

- **GIVEN** the Consumer editor for an existing consumer
- **WHEN** the operator enters a requests-per-window and window-seconds value and saves
- **THEN** the consumer's `rateLimit` SHALL be persisted and enforced on
  subsequent inbound calls
- @e2e exclude consumer editor rate-limit UI — Playwright regression added in the implementation phase alongside the existing Consumers journey

