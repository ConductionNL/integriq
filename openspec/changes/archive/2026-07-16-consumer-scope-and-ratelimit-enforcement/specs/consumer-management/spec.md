# consumer-management — Delta: enforce Consumer source scope (`ips`/`domains`)

## Purpose

Closes a **fabricated control**. The `consumer` schema advertises `domains`
("Allowed source domains") and `ips` ("Allowed source IP addresses"), and this
spec already documented the scenario *"unlisted domain is forbidden → HTTP 403"*
under `REQ-CON-001`. Verified at HEAD, **nothing read either field** — the only
occurrences in the repo were the 2024 migration columns. An operator could
configure an IP allowlist, have the UI accept it, and be silently unprotected.

This delta adds `REQ-CON-SCOPE-001` as the enforcing requirement, moves the
orphaned scenario onto it, and clarifies `REQ-CON-RL-002`'s consumer-resolution
boundary (the audit's suspected apiKey rate-limit bypass did **not** reproduce —
`REQ-CON-001`'s apiKey resolution, shipped by ocon#188, already makes the limiter
apply uniformly; this delta pins that in a scenario so it cannot regress).

## ADDED Requirements

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

## MODIFIED Requirements

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
request (so its `rateLimit`/`quota` apply per `REQ-CON-RL-002`, and its source
allowlist applies per `REQ-CON-SCOPE-001`). A presented key
that matches no such consumer (and no rule-inline key) SHALL be rejected
fail-closed; an empty presented key SHALL never match. This enforcement is
additive to, and does not regress, the pre-existing rule-inline key path.

Source-scope restriction of an authenticated consumer is NOT part of this
requirement; it is specified by `REQ-CON-SCOPE-001`, which runs after this one.

> The scenario formerly recorded here as *"unlisted domain is forbidden"* moves to
> `REQ-CON-SCOPE-001`, which actually enforces it. It sat under this requirement
> with no implementing code.

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

#### Scenario: rate-limit enforcement runs after authentication

- **GIVEN** an endpoint with an authentication rule
- **WHEN** an unauthenticated caller exceeds any limit
- **THEN** the response is the auth failure (401/403), never 429
- @e2e exclude backend ordering — covered by PHPUnit
