---
status: implemented
retrofit: true
---

# Consumer and Webhook Management

## Purpose

Integriq exposes two related UI sections — **Consumers** and **Webhooks** —
that allow administrators to configure inbound access policies. A Consumer
describes an external system that is permitted to call Integriq's endpoints,
including its authorization type and allowed domains. Webhooks share the same
underlying schema (`consumer`) and surface but are presented separately for
clarity. This spec covers the observable browser UI behaviour and the backend
authentication enforcement (covered by PHPUnit/Newman). It is a retrofit spec.

## Requirements

### REQ-CON-UI-001: Consumer Management UI

Integriq MUST provide a Consumers section in its SPA where administrators can browse,
create, edit, and delete consumer configurations.

The create/edit form SHALL expose every authorable consumer property — name, description,
allowed domains, allowed IPs, authorization type, authorization configuration, rate limit and
quota — in a declared order. Display order, labels and help text for the schema-reading
surfaces (the consumer detail page's data widget and detail grid) SHALL come from the
`consumer` schema, not from the page's manifest entry.

The two allowlist properties SHALL be authored as multi-value lists that persist as JSON
arrays of strings. The form MUST NOT persist an empty array for either one: an empty array is
a configured allowlist that admits nobody (`REQ-CON-SCOPE-001`), so emitting one where the
operator expressed no restriction would reject every inbound request. An allowlist left empty,
or emptied of its last entry, SHALL persist as absent — the unrestricted state.

Authorization type SHALL be presented as a select over the types the app offers, and a stored
value outside that set SHALL be displayed rather than blanked, so saving an unrelated field
cannot silently rewrite it. The type list SHALL NOT be declared as a schema `enum`, which is
enforced on save and would make existing rows unsaveable.

#### Scenario: consumers list page mounts and shows content

- GIVEN an authenticated admin visits the integriq app
- WHEN they navigate to the Consumers section via the sidebar nav or direct URL `/apps/integriq/consumers`
- THEN the Consumers index page renders inside the main content area with content visible

#### Scenario: add consumer button opens the creation modal

- GIVEN the Consumers index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the consumer creation form

#### Scenario: every authorable property is on the form

- WHEN the user opens "+ Add" on the Consumers index
- THEN the form renders name, description, allowed domains, allowed IPs, authorization type,
  authorization configuration, rate limit and quota — in that order, taken from the schema's
  `order`, not alphabetically
- @e2e exclude asserted on the schema order — covered by tests/vitest/consumerDraft.spec.js

#### Scenario: a consumer created without an allowlist is unrestricted

- GIVEN the create form, with both allowlist inputs left empty
- WHEN the operator saves
- THEN the persisted consumer has NO `domains` and NO `ips` value — not an empty array
- AND inbound calls from any source SHALL NOT be rejected on source-scope grounds
- @e2e exclude the assertion is on the persisted shape and on inbound enforcement — covered by vitest over the payload builder, and by PHPUnit for `isAllowed()`

#### Scenario: removing the last allowlist entry stops restricting rather than denying all

- GIVEN a consumer with one allowed domain
- WHEN the operator removes that entry and saves
- THEN the persisted `domains` is absent, so the consumer becomes unrestricted
- AND it does NOT become an empty allowlist that rejects every request
- @e2e exclude asserted on the persisted shape — covered by vitest

#### Scenario: allowlist entries persist as an array, not a joined string

- GIVEN the create form
- WHEN the operator enters two domains as separate entries and saves
- THEN `domains` persists as a two-element array of strings, which is the shape
  `ConsumerScopeService::isAllowed()` reads
- @e2e exclude asserted on the persisted shape — covered by vitest

#### Scenario: a legacy comma-joined allowlist is repaired on save

- GIVEN a consumer whose `domains` was written by the pre-cutover editor as the single string
  `"example.com, example.org"` — a value that matched nothing at run time
- WHEN the operator opens that consumer and saves it
- THEN `domains` persists as `["example.com", "example.org"]`
- @e2e exclude asserted on the persisted shape — covered by vitest

#### Scenario: the type select preserves an off-list value

- WHEN a consumer holds an authorization type the picker does not offer
- THEN the select displays that stored value instead of reading as unset, and saving the
  consumer does not silently replace it
- @e2e exclude asserted on the draft seeding — covered by vitest

### REQ-WBHK-UI-001: Webhook Management UI

Integriq MUST provide a Webhooks section in its SPA where administrators can
browse, create, edit, and delete webhook consumers.

#### Scenario: webhooks list page mounts and shows content

- GIVEN an authenticated admin visits the integriq app
- WHEN they navigate to the Webhooks section via the sidebar nav or direct URL `/apps/integriq/webhooks`
- THEN the Webhooks index page renders inside the main content area with content visible

#### Scenario: add webhook button opens the creation modal

- GIVEN the Webhooks index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the webhook/consumer creation form

### REQ-CON-001: Consumer authentication enforcement

The system SHALL enforce consumer-level authentication on inbound calls to
Integriq endpoints by resolving the `consumer` record associated with the
request and checking that the caller's credentials match the configured
`authorizationType` (none, apiKey, jwt, basic, oauth2). Requests failing
consumer auth SHALL receive HTTP 401 (or HTTP 403 when the credential is absent
on a protected endpoint).

For `authorizationType: apiKey`, the system SHALL resolve the `consumer` whose
`authorizationConfiguration.apiKey` matches the presented key under a
constant-time comparison, and SHALL record it as the resolved consumer for the
request (so its `rateLimit`/`quota` apply per `REQ-CON-RL-002`, and its source
allowlist applies per `REQ-CON-SCOPE-001`). A presented key
that matches no such consumer (and no rule-inline key) SHALL be rejected
fail-closed; an empty presented key SHALL never match. This enforcement is
additive to, and does not regress, the pre-existing rule-inline key path.

Source-scope restriction of an authenticated consumer is NOT part of this
requirement; it is specified by `REQ-CON-SCOPE-001`, which runs after this one.

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

### Requirement: Consumer source-scope enforcement (REQ-CON-SCOPE-001)

For an inbound endpoint request that resolved a `consumer` (`REQ-CON-001`), the
system MUST enforce that consumer's configured source allowlist AFTER
authentication has passed and BEFORE the inbound rate limit (`REQ-CON-RL-002`),
so an out-of-scope caller is rejected without consuming rate-limit budget. The
control MUST fail closed: a source that does not match a configured allowlist
MUST receive HTTP 403.

The allowlist is the union of two optional `consumer` fields: `ips` (exact IPv4/
IPv6 addresses and CIDR ranges) and `domains` (hostname patterns — exact, or a
suffix wildcard `*.example.com` which also matches the apex `example.com`). When
**neither** field is configured the consumer is unrestricted; this MUST NOT change
the behaviour of any consumer that does not set them. When **either** is
configured, the request's source MUST match at least one entry across both lists.
A configured-but-empty list contributes no entries and therefore MUST NOT
allow-all. Malformed entries MUST be ignored rather than treated as wildcards, and
an IPv4 source MUST NOT match an IPv6 range or vice versa.

The client IP MUST be derived the way Nextcloud derives it
(`IRequest::getRemoteAddress()`, which honours the instance's `trusted_proxies` /
`forwarded_for_headers` configuration). The system MUST NOT derive it from a raw
`X-Forwarded-For`, `CF-Connecting-IP`, or any other caller-supplied header, since
an allowlist keyed on caller-controlled input is spoofable and therefore not a
control at all. On a deployment without `trusted_proxies` configured this yields
the proxy's address — the allowlist then over-rejects (fails closed), which is
accepted and documented rather than worked around by trusting headers.

`domains` MUST be matched against the forward-confirmed reverse DNS of the client
IP (PTR-resolve, then require the hostname to forward-resolve back to that same
IP). It MUST NOT be matched against `Origin`, `Referer`, or `Host`, which the
caller controls.

A request that resolved no consumer has no consumer allowlist to apply and is not
subject to this requirement.

@e2e exclude backend admission control on transport-layer facts (client IP, DNS) — covered by PHPUnit, no browser UI

#### Scenario: a source IP outside a configured allowlist is forbidden

- **GIVEN** a consumer with `ips` configured
- **WHEN** a request arrives from an IP matching no entry
- **THEN** the response is HTTP 403 and the endpoint target does not run
- @e2e exclude backend enforcement — covered by PHPUnit

#### Scenario: unlisted domain is forbidden

- **GIVEN** a consumer with allowed `domains` configured
- **WHEN** a request originates from an unlisted domain
- **THEN** the response is HTTP 403
- @e2e exclude backend enforcement — covered by PHPUnit

#### Scenario: a consumer with no allowlist is unrestricted

- **GIVEN** a consumer with neither `ips` nor `domains` configured
- **WHEN** a request arrives from any source
- **THEN** the request SHALL NOT be rejected on source-scope grounds
- @e2e exclude backend config default — covered by PHPUnit

#### Scenario: an empty-but-configured allowlist does not allow everything

- **GIVEN** a consumer whose `ips` (or `domains`) is present but empty
- **WHEN** a request arrives from any source
- **THEN** the response is HTTP 403
- @e2e exclude backend enforcement — covered by PHPUnit

#### Scenario: a forged forwarded header cannot satisfy the allowlist

- **GIVEN** a consumer with `ips` configured
- **WHEN** a request arrives from an unlisted address carrying an
  `X-Forwarded-For` naming an allowed address, from an untrusted proxy
- **THEN** the response is HTTP 403
- @e2e exclude backend enforcement on trusted-proxy derivation — covered by PHPUnit

#### Scenario: reverse DNS that does not forward-confirm cannot satisfy domains

- **GIVEN** a consumer with `domains` configured
- **WHEN** a request arrives from an IP whose PTR claims a listed hostname, but
  that hostname does not forward-resolve back to that IP
- **THEN** the response is HTTP 403
- @e2e exclude backend DNS enforcement — covered by PHPUnit

#### Scenario: the scope gate runs before the rate limiter

- **GIVEN** a consumer with a configured allowlist and a configured `rateLimit`
- **WHEN** a request arrives from an unlisted source
- **THEN** the response is HTTP 403 and the request SHALL NOT be counted against
  the consumer's rate limit
- @e2e exclude backend ordering — covered by PHPUnit

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

Enforcement MUST be independent of which `authorizationType` resolved the
consumer: a consumer resolved via the apiKey path is subject to exactly the same
limit as one resolved via the JWT path, so a caller cannot obtain an unlimited
budget by choosing an auth type.

Where a request authenticates a **Nextcloud user** rather than a consumer (the
endpoint's rule-inline `keys` map, or `basic`/`oauth` against the endpoint's
user/group lists), no `consumer` record — and therefore no consumer `rateLimit` —
exists to apply, and this requirement does not govern it. Such requests remain
subject to Nextcloud's own authentication and brute-force protections. This
boundary is deliberate: the alternative would be inventing an anonymous limit with
no configuration surface to set it from.

@e2e exclude backend enforcement + concurrency — covered by PHPUnit/Newman, no browser UI

#### Scenario: an apiKey-resolved consumer over its rate limit is throttled

- **GIVEN** a consumer with `authorizationType: apiKey` and a configured `rateLimit`
- **WHEN** it exceeds `requestsPerWindow` within the window
- **THEN** the response is HTTP 429, keyed on that consumer — choosing apiKey auth
  over jwt SHALL NOT yield an unlimited budget
- @e2e exclude backend enforcement — covered by PHPUnit

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

The Consumer editor in the *Consumers* section MUST let an operator configure a consumer's
`rateLimit` (requests per window + window seconds) and `quota` (limit + period) alongside the
authentication configuration it already renders. Leaving the inputs empty MUST persist an
unlimited (absent) configuration.

Each block's two halves SHALL be authored as typed fields — two positive integers for
`rateLimit`, a positive integer and a period for `quota` — not as a raw JSON object. A block
with only one half supplied MUST persist as unlimited rather than as a partial block, since
neither half alone defines a limiter. Clearing a previously configured block MUST persist an
explicit null, which is what removes it on update.

#### Scenario: an operator sets a rate limit on a consumer

- **GIVEN** the Consumer editor for an existing consumer
- **WHEN** the operator enters a requests-per-window and window-seconds value and saves
- **THEN** the consumer's `rateLimit` SHALL be persisted and enforced on subsequent inbound calls
- @e2e exclude consumer editor rate-limit UI — Playwright regression added in the implementation phase alongside the existing Consumers journey

#### Scenario: a half-filled rate limit persists as unlimited

- GIVEN the Consumer editor with a requests-per-window value but no window-seconds value
- WHEN the operator saves
- THEN `rateLimit` SHALL persist as null, and no request SHALL be throttled on rate-limit grounds
- @e2e exclude asserted on the persisted shape — covered by vitest

#### Scenario: clearing a configured limiter removes it

- GIVEN a consumer with a configured `rateLimit`
- WHEN the operator empties both inputs and saves
- THEN `rateLimit` SHALL persist as null and the consumer SHALL become unlimited again
- @e2e exclude asserted on the persisted shape — covered by vitest

### Requirement: apiKey consumer authentication MUST remain callable outside the endpoint-runtime dispatch path (REQ-CON-002)

`AuthorizationService::authorizeApiKey(string $header, array $keys)` MUST remain a public method on a
DI-injectable service (alongside its consumer-resolution behaviour, REQ-CON-001), callable by any
controller that needs to authenticate an inbound request against a `consumer` OR-object's
`authorizationConfiguration.apiKey` —
not refactored into a private helper reachable only from `EndpointsController`/`EndpointService`'s
dispatch path. A controller calling `authorizeApiKey()` directly (passing an empty `$keys` array when it
has no rule-inline keys of its own) MUST receive identical fail-closed, constant-time-comparison behaviour
to the endpoint-runtime call site: a presented key matching no `consumer` with `authorizationType = apiKey`
MUST throw `AuthenticationException`, and an empty presented key MUST never match.

#### Scenario: a non-endpoint-runtime controller authenticates via the same consumer apiKey path

- **GIVEN** a `consumer` with `authorizationType = 'apiKey'` and `authorizationConfiguration.apiKey =
  '<secret>'`
- **WHEN** a controller OTHER than `EndpointsController` calls `AuthorizationService::authorizeApiKey('<secret>',
  [])`
- **THEN** the call SHALL succeed and `getResolvedConsumer()` SHALL return that consumer — identical
  behaviour to the `endpoint-runtime`-mediated call site
- @e2e exclude backend service contract invoked from PHP, no browser UI — covered by PHPUnit

#### Scenario: an unmatched key fails closed identically regardless of caller

- **GIVEN** the same consumer
- **WHEN** a non-endpoint-runtime controller calls `authorizeApiKey('wrong-key', [])`
- **THEN** an `AuthenticationException` SHALL be thrown
- **AND** `getResolvedConsumer()` SHALL return `null`
- @e2e exclude backend service contract invoked from PHP, no browser UI — covered by PHPUnit

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

### Requirement: The consumer credential is write-only in the editor (REQ-CON-UI-002)

The Consumer editor MUST open the credential field empty on edit and MUST NOT treat that empty
field as an instruction to clear the stored credential.

`authorizationConfiguration` is declared `writeOnly: true`, so OpenRegister strips it from every
API response — admin included — and the editor has no value to pre-fill. An untouched credential
MUST therefore be omitted from the update payload, so OpenRegister's save-side preserve rule
carries the stored value forward. Clearing MUST require a deliberate act that sends an explicit
null.

The credential field SHALL be shown only for an authorization type that carries a credential.
Selecting a type that carries none MUST persist a null credential, so a decommissioned key is
retired rather than left unreachable at rest.

@e2e exclude the round-trip assertion is on the payload shape and on OpenRegister's preserve rule — covered by vitest and by OpenRegister's own suite

#### Scenario: editing an unrelated field keeps the stored credential

- GIVEN a consumer with a stored `authorizationConfiguration.apiKey`
- WHEN the operator edits only its description and saves
- THEN the payload OMITS `authorizationConfiguration`
- AND the stored key still authenticates inbound calls afterwards

#### Scenario: the credential field opens empty on edit

- GIVEN a consumer with a stored credential
- WHEN the operator opens it for editing
- THEN the credential editor is empty, and its help text states that this is the write-only
  boundary rather than a missing value

#### Scenario: clearing the credential is explicit

- GIVEN a consumer with a stored credential
- WHEN the operator uses the clear control and saves
- THEN the payload carries `authorizationConfiguration: null` and the stored credential is removed

#### Scenario: switching to a credential-less type retires the credential

- GIVEN a consumer with `authorizationType: apiKey` and a stored key
- WHEN the operator changes the type to `none` and saves
- THEN the credential editor is no longer shown
- AND the payload carries `authorizationConfiguration: null`, so the key is not left stored and
  unreachable

#### Scenario: invalid credential JSON blocks the save

- GIVEN the credential editor containing text that is not a JSON object
- WHEN the operator attempts to save
- THEN the save is refused with an inline parse error, rather than persisting a value the engine
  cannot read

### Requirement: A signed webhook acts as its consumer's account (REQ-CM-020)

Every signed public webhook in `WebhookProfiles` MUST authenticate a delivery against its own consumer (`authorizationType` per webhook), read with an engine read. It MUST verify the signature header named in the consumer's `authorizationConfiguration` over the exact raw body. It MUST run every OpenRegister write as the account in the consumer's `userId`, inside `runAs()`, and restore the previous user afterwards. A bad signature MUST answer 401. A missing connection, a missing, unknown or disabled account, or an account without the profile's rights on its schema MUST answer 503 with `<channel>_<code>`, write nothing and alert the administrators. A write that OpenRegister refuses MUST answer 503 too. An upgrade MUST move a webhook's legacy source secret into its consumer, without an account. Ruben approved this model on 2026-10-04.

#### Scenario: A signed delivery without a session is stored as the consumer's account

- GIVEN a webhook consumer whose account may write the webhook's schema
- WHEN a correctly signed delivery arrives without a Nextcloud session
- THEN it is stored
- AND every write runs as that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A connection without a usable account refuses with 503

- GIVEN a webhook consumer without an account
- WHEN a correctly signed delivery arrives
- THEN the answer is 503 `<channel>_account_unavailable`
- AND nothing is written
- AND the administrators get one notification that names the webhook
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A wrong signature is refused with 401

- GIVEN a webhook consumer
- WHEN a delivery arrives signed with another secret
- THEN the answer is 401 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A refused write is answered 503

- GIVEN a webhook consumer whose account OpenRegister refuses to let write
- WHEN a correctly signed delivery arrives
- THEN the answer is 503 `<channel>_delivery_not_stored`, never 200
- @e2e exclude server-to-server webhook: covered by PHPUnit

#### Scenario: An upgrade moves the source trust into the consumer

- GIVEN an enabled source of a webhook's legacy type with a webhook secret
- WHEN the app is upgraded
- THEN one consumer of that webhook exists with that secret, scheme and header, and no account
- AND a second upgrade creates nothing
- @e2e exclude repair step: covered by PHPUnit and the live upgrade

#### Scenario: One consumer per webhook

- GIVEN a `peppol-webhook` consumer
- WHEN an administrator saves a second `peppol-webhook` consumer
- THEN the save is refused with an error saying only one Peppol connection is allowed
- @e2e exclude backend save guard: covered by PHPUnit

### Requirement: An administrator chooses each webhook's account (REQ-CM-021)

The integriq admin settings MUST list every webhook in `WebhookProfiles`, with whether it is configured, whether a secret is set and its account's state, and MUST never return a secret. An administrator MUST be able to save a webhook's scheme, secret, header and account. A blank secret MUST keep the stored one. An account that does not exist, is disabled or lacks the webhook's rights on its schema MUST be refused with a field error. An administrator account MUST save with a warning.

#### Scenario: The settings list every webhook without its secret

- GIVEN webhook consumers with secrets
- WHEN an administrator opens the webhook connections section
- THEN every webhook is listed with its account state
- AND no secret appears in the response
- @e2e exclude admin settings payload: covered by PHPUnit and the live settings GET

#### Scenario: An administrator chooses a webhook's account

- GIVEN a webhook consumer without an account
- WHEN an administrator chooses an account that may write the webhook's schema and saves
- THEN the consumer names that account and keeps its secret
- @e2e exclude admin settings payload: covered by PHPUnit and the live settings PUT

#### Scenario: An account that cannot write is refused

- GIVEN a webhook whose schema the account may not create in
- WHEN an administrator chooses that account
- THEN the save is refused with a field error naming the missing right
- @e2e exclude admin settings payload: covered by PHPUnit and the live settings PUT
