---
status: done
---

# pdok-adapter Specification

## Purpose
Provides a PDOK Locatieserver connector that exposes four authenticated REST endpoints (`/api/pdok/suggest`, `/lookup`, `/free`, `/reverse`) and normalizes PDOK responses into the canonical OpenRegister PostalAddress shape with GeoJSON coordinates. It caches responses in APCu with per-endpoint TTLs, writes looked-up addresses through to the OR addresses register, and stays resilient under load via exponential backoff on rate limits, a circuit breaker, stale-result graceful degradation, and structured per-call observability logging.

@e2e exclude backend PDOK connector PHP class (HTTP adapter, no browser UI) — covered by PHPUnit/Newman

## Requirements
### Requirement: PDOK Connector Class

openconnector MUST ship a `PdokConnector` PHP class at `lib/Connectors/PdokConnector.php`
that implements openconnector's existing connector contract interface. The class MUST
inject `IClientService`, `ICache`, and `LoggerInterface` and MUST be registered in the
DI container (`lib/AppInfo/Application.php`) and in the integration registry (ADR-019).

#### Scenario: PdokConnector is registered in the integration registry

- GIVEN openconnector is installed
- WHEN the integration registry list is queried
- THEN `PdokConnector` SHALL appear as a registered connector entry
- AND it SHALL implement the same interface as existing connectors

#### Scenario: No prior PDOK connector conflicts exist

- GIVEN the openconnector codebase has been searched for existing PDOK or geo connectors
- WHEN `PdokConnector` is created
- THEN the search result SHALL be documented in a code comment in the new class file
- AND no conflicting connector class SHALL be found or, if found, the conflict SHALL be
  resolved and documented

### Requirement: Four REST Endpoints at `/api/pdok/*`

The openconnector PDOK adapter SHALL expose four GET routes registered in
`appinfo/routes.php` at `/api/pdok/suggest`, `/api/pdok/lookup`, `/api/pdok/free`, and
`/api/pdok/reverse`. All four routes MUST use `requireLogin()` — any authenticated
Nextcloud user may call them; no admin gate is added by this adapter. Route registration
MUST follow ADR-016.

#### Scenario: All four routes are reachable by an authenticated user

- GIVEN openconnector is installed and a user is authenticated in Nextcloud
- WHEN the user sends a valid GET to each of the four `/api/pdok/*` endpoints
- THEN each endpoint SHALL return a 2xx response with a JSON body
- AND no additional permission check beyond `requireLogin()` SHALL be applied

#### Scenario: Unauthenticated request is rejected by requireLogin

- GIVEN a request arrives at any `/api/pdok/*` endpoint without a valid Nextcloud session
- WHEN openconnector processes the request
- THEN openconnector SHALL return HTTP 401 via the standard Nextcloud `requireLogin()` guard
- AND no PDOK upstream call SHALL be made

#### Scenario: Missing required parameter returns 400

- GIVEN the openconnector PDOK adapter is installed
- WHEN a frontend sends `GET /api/pdok/suggest` with no `q` parameter
- THEN openconnector SHALL return HTTP 400 with `message_key: "pdok.error.missing_query"`

### Requirement: Response Transformation to Canonical PostalAddress Shape

The openconnector PDOK adapter MUST implement a `normalize(array $pdokDoc): array` method
that maps PDOK Locatieserver response fields to the canonical OR PostalAddress schema.
Missing upstream fields MUST map to `null` in the output — they SHALL NOT be absent from
the object. The transformation MUST always set `source: "pdok"` and MUST parse
`centroide_ll` WKT into a GeoJSON Point with `[lng, lat]` coordinate order per RFC 7946.

#### Scenario: WKT centroide_ll is parsed into a GeoJSON Point

- GIVEN PDOK returns `"centroide_ll": "POINT(4.88525 52.37025)"` for a lookup
- WHEN the adapter normalizes the response
- THEN `location` SHALL equal `{"type": "Point", "coordinates": [4.88525, 52.37025]}`
- AND longitude SHALL be the first element per RFC 7946

#### Scenario: Missing upstream fields are null, not absent

- GIVEN PDOK returns a woonplaats record without `postcode`, `straatnaam`, or
  `huisnummer`
- WHEN the adapter normalizes the response
- THEN `postalCode`, `streetAddress`, and `houseNumber` SHALL be present in the output
  with value `null` rather than being omitted

#### Scenario: Fixture normalization is verified by unit tests

- GIVEN the three seed fixtures in `tests/fixtures/pdok/` are loaded
- WHEN `normalize()` is called on each fixture
- THEN the output for `fixture-lauriergracht.json` SHALL have all address fields set
- AND the output for `fixture-woonplaats-tilburg.json` SHALL have `postalCode: null`,
  `houseNumber: null`, and `streetAddress: null`

### Requirement: Caching with Per-Endpoint TTLs

The openconnector PDOK adapter SHALL cache upstream PDOK responses in Nextcloud's
APCu-backed `ICache`. Cache keys MUST be derived from the endpoint name and a SHA-256
hash of the sorted query parameters. The TTL for `/lookup` and `/reverse` responses
SHALL be 3600 seconds (1 hour). The TTL for `/suggest` and `/free` responses SHALL be
300 seconds (5 minutes). If `ICache` is unavailable, the adapter MUST proceed uncached
and log a warning rather than throwing an error.

#### Scenario: Repeated lookup is served from APCu within TTL

- GIVEN a lookup call for an ID has been served and its result cached in APCu
- WHEN the same request arrives within 1 hour
- THEN the response SHALL be served from APCu without calling PDOK upstream
- AND the APCu-only hit SHALL NOT generate a structured log entry (per observability spec)

#### Scenario: ICache unavailable falls through uncached

- GIVEN `ICache` is null or unavailable at adapter initialization
- WHEN any proxy method is called
- THEN the adapter SHALL call PDOK upstream normally (uncached path)
- AND a `warning`-level log entry SHALL be emitted
- AND no exception SHALL be thrown

### Requirement: Write-Through to OR Addresses Register

The openconnector PDOK adapter SHALL upsert successfully fetched and normalized addresses
into the OR `addresses` register for `lookup` and `reverse` calls only. Write-through
MUST use an upsert pattern: check by `pdokId` first; if absent create; if present and
`fetchedAt` older than 1 hour update. Concurrent lookups for the same `pdokId` MUST
result in exactly one OR object, not two.

#### Scenario: First lookup creates an OR address object

- GIVEN no OR address object exists for a given `pdokId`
- WHEN openconnector successfully fetches and normalizes that address from PDOK
- THEN openconnector SHALL create a new OR object in the `addresses` register with
  `source: "pdok"` and `fetchedAt` set to the current timestamp

#### Scenario: APCu miss but fresh OR record avoids PDOK call

- GIVEN APCu has expired for a `pdokId` but an OR address object exists with
  `fetchedAt` less than 1 hour ago
- WHEN openconnector receives the lookup request
- THEN openconnector SHALL return the OR object without calling PDOK upstream
- AND SHALL re-populate the APCu entry

### Requirement: Rate-Limit Handling with Exponential Backoff

The openconnector PDOK adapter SHALL implement exponential backoff when PDOK returns
HTTP 429. The retry sequence MUST be: wait `2^attempt × 200ms` (±10% jitter) capped at
5000ms, up to 3 retry attempts total. After 3 failed attempts the adapter MUST return
HTTP 503 to the caller with `message_key: "pdok.unavailable"`.

#### Scenario: Three consecutive 429 responses exhaust retries

- GIVEN PDOK Locatieserver returns HTTP 429 on all three retry attempts
- WHEN the adapter exhausts its retry budget
- THEN openconnector SHALL return HTTP 503 with `message_key: "pdok.unavailable"`
- AND the circuit breaker failure counter SHALL be incremented

### Requirement: Circuit Breaker

The openconnector PDOK adapter MUST implement a circuit breaker stored in APCu under
key `"pdok_connector::circuit"` holding `{state, failures, opened_at}`. The circuit
MUST open after 5 consecutive upstream failures (HTTP 5xx or connection timeout).
When open, all requests MUST return HTTP 503 immediately without calling PDOK. After
30 seconds, the circuit enters half-open state; a successful probe closes it; a failed
probe resets the 30-second window.

#### Scenario: 5 consecutive 5xx responses open the circuit breaker

- GIVEN 5 consecutive upstream calls to PDOK all return HTTP 500
- WHEN a 6th request arrives at the adapter
- THEN the circuit breaker SHALL be open
- AND openconnector SHALL return HTTP 503 with `message_key: "pdok.unavailable"`
  without calling PDOK upstream

#### Scenario: Circuit state persists across PHP requests via APCu

- GIVEN the circuit breaker opened due to upstream failures
- WHEN a new PHP request arrives within the 30-second open window
- THEN the circuit state SHALL be read from APCu key `"pdok_connector::circuit"`
- AND the request SHALL return HTTP 503 without calling PDOK

### Requirement: Graceful Degradation

The openconnector PDOK adapter SHALL return HTTP 503 with body
`{"error": "pdok_unavailable", "message_key": "pdok.unavailable"}` when PDOK is
unreachable, the circuit is open, or retries are exhausted AND no cached or stored
result is available. When a stale result exists in APCu or OR, the adapter MUST return
the stale result with response header `X-Cache-Stale: true` rather than 503.

#### Scenario: PDOK unreachable and no cache — returns 503

- GIVEN PDOK Locatieserver is unreachable, the circuit is open, and no APCu or OR
  result exists for the requested identifier
- WHEN a frontend calls the lookup endpoint
- THEN openconnector SHALL return HTTP 503 with
  `{"error": "pdok_unavailable", "message_key": "pdok.unavailable"}`

#### Scenario: Stale OR result returned with X-Cache-Stale header

- GIVEN PDOK Locatieserver is unreachable and an OR address object exists for the
  requested `pdokId` (even though `fetchedAt` is older than the TTL)
- WHEN a frontend calls the lookup endpoint for that `pdokId`
- THEN openconnector SHALL return HTTP 200 with the OR address object
- AND the response SHALL include the header `X-Cache-Stale: true`

### Requirement: Observability

The openconnector PDOK adapter SHALL emit one structured Nextcloud log entry per upstream
PDOK call. APCu-only cache hits SHALL NOT generate log entries. Each log entry MUST
include `endpoint`, `cache_hit` (bool), `or_hit` (bool), `upstream_latency_ms` (int),
`http_status` (int|null), `circuit_state` (string), and `write_through` (bool). Log
levels: `debug` for cache/OR hits and 2xx; `warning` for 429; `error` for 5xx, timeouts,
and circuit-open states.

#### Scenario: Successful cold-cache upstream call generates a debug log entry

- GIVEN a request reaches the lookup endpoint and both APCu and OR miss
- WHEN the adapter calls PDOK upstream and receives HTTP 200
- THEN a log entry at level `debug` SHALL be written containing `endpoint: "lookup"`,
  `cache_hit: false`, `or_hit: false`, a non-negative `upstream_latency_ms`,
  `http_status: 200`, `circuit_state: "closed"`, and `write_through: true`

#### Scenario: Circuit-open state generates an error log entry

- GIVEN the circuit breaker is open
- WHEN a request arrives at any `/api/pdok/*` endpoint
- THEN a log entry at level `error` SHALL be written with `circuit_state: "open"`
  before the 503 response is returned

