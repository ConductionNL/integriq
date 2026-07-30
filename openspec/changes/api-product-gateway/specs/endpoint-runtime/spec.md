# endpoint-runtime Specification (Delta)

## ADDED Requirements

### Requirement: Deprecated-product-version dispatch attaches Sunset/Deprecation headers (REQ-EP-008)

The system MUST, via `EndpointService::handleRequest()`'s existing
header-merge choke point (the same one that attaches the
`RateLimit-*`/`Retry-After` headers per `consumer-management`
`REQ-CON-RL-003`), when the dispatched endpoint belongs to an `api_product`
whose `status` is `deprecated`, merge a `Deprecation: true` header and a
`Sunset` header (RFC 8594, HTTP-date format derived from the product's
`sunsetDate`) into the response, for every method and every dispatch path
(simple fast-path `REQ-EP-002` and full pipeline `REQ-EP-003` alike). This
is the runtime mechanism backing `api-product-gateway` `REQ-APG-006`; this
requirement documents where in the dispatch pipeline it is wired, not the
product-level contract itself.

@e2e exclude backend header attachment — covered by Newman, no browser UI

#### Scenario: the fast path also carries deprecation headers

- GIVEN a "simple" endpoint (`REQ-EP-002`) that belongs to a `deprecated` `api_product`
- WHEN a GET request is served via the fast path
- THEN the response carries `Deprecation: true` and `Sunset` despite bypassing the full rule pipeline

#### Scenario: the full pipeline path also carries deprecation headers

- GIVEN a non-simple endpoint (`REQ-EP-003`) that belongs to a `deprecated` `api_product`
- WHEN a request runs the full before/dispatch/after pipeline
- THEN the response carries `Deprecation: true` and `Sunset` alongside any rule-produced headers

#### Scenario: an endpoint in no product carries neither header

- GIVEN an endpoint that belongs to no `api_product`
- WHEN a request is dispatched
- THEN the response carries neither `Deprecation` nor `Sunset` — no change from pre-change behaviour

### Requirement: Inbound observability logging for API-product-scoped endpoints (REQ-EP-009)

The system MUST, for a request dispatched through an `Endpoint` that
belongs to at least one `api_product`, persist a `direction: inbound`
`call_log` row on completion (success or error) carrying the resolved
`product` uuid,
the dispatched `endpoint` uuid, the final `statusCode`, and a `responseTime`
in milliseconds measured from dispatch start to response ready — extending
the existing 429-only inbound logging (`consumer-management`
`REQ-CON-RL-004`) to every outcome, but scoped to product-attached endpoints
only (an endpoint in no `api_product` continues to log inbound rows only on
429, exactly as today). The write MUST be best-effort: a logging failure
MUST NOT block or alter the response (same pattern as
`recordInboundThrottle()`).

@e2e exclude backend inbound logging — covered by PHPUnit, no browser UI

#### Scenario: a successful product-scoped request is logged with duration

- GIVEN an endpoint that belongs to an `api_product`
- WHEN a request to it completes with HTTP 200 in 42ms
- THEN a `call_log` row is persisted with `direction: inbound`, `statusCode: 200`, `product: <uuid>`, `endpoint: <uuid>`, and `responseTime: 42`

#### Scenario: an errored product-scoped request is logged too

- GIVEN an endpoint that belongs to an `api_product`
- WHEN a request to it fails with HTTP 500
- THEN a `call_log` row is persisted with `direction: inbound`, `statusCode: 500`, and the product/endpoint linkage, so it counts toward that product's error rate

#### Scenario: a non-product endpoint's successful requests are still not logged

- GIVEN an endpoint that belongs to no `api_product`
- WHEN a request to it completes with HTTP 200
- THEN no `call_log` row is persisted for it (unchanged from pre-change behaviour; only its 429s would be, per `REQ-CON-RL-004`)

#### Scenario: a logging failure never blocks the response

- GIVEN the `call_log` write raises an exception (e.g. OpenRegister temporarily unavailable)
- WHEN a product-scoped request otherwise succeeds
- THEN the response is still returned successfully and the logging failure is recorded only in the application log
