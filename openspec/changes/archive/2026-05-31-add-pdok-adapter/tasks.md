# Tasks: add-pdok-adapter

> This change implements the `[openconnector]` subset of the Hydra-level umbrella
> `shared-pdok-via-openconnector`. The full architecture, design rationale,
> normalized response schema, and migration story live in the umbrella.
> See `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`.

## Tasks

### OC-1. Connector scaffold (S)

- [x] OC-1.1 Check existing openconnector connector classes for any PDOK or
  geo-related connector; confirm no prior implementation conflicts.
  - **Acceptance:** Search result documented in a code comment in the new file;
    no conflicting connector found, or conflict resolution noted.

- [x] OC-1.2 Create `lib/Connectors/PdokConnector.php` with EUPL-1.2 SPDX header
  inside the class docblock (per SPDX-in-docblock convention), implementing the existing
  connector contract interface. Inject `IClientService`, `ICache`, and `LoggerInterface`.
  - **Acceptance:** File compiles without errors; SPDX header present inside docblock;
    implements the same interface as existing connectors.

- [x] OC-1.3 Register `PdokConnector` in the DI container (`lib/AppInfo/Application.php`)
  and add it to the integration registry (ADR-019).
  - **Acceptance:** `Application.php` registers `PdokConnector`; integration registry
    entry present.

### OC-2. Upstream HTTP and response transformation (M)

- [x] OC-2.1 Implement `callPdok(string $endpoint, array $params): array` using
  `IClientService`; do not use raw `curl` or `file_get_contents`.
  - **Acceptance:** PHPUnit unit test with a mocked `IClientService` confirms a GET is
    issued to the correct PDOK Locatieserver v3.1 URL.

- [x] OC-2.2 Implement the four upstream proxy methods: `suggest(string $q): array`,
  `lookup(string $id): array`, `free(string $q, int $rows = 10, int $start = 0): array`,
  `reverse(float $lat, float $lng): array`, each mapping to the correct PDOK
  Locatieserver v3.1 endpoint URL.
  - **Acceptance:** Each method maps to the correct PDOK endpoint; verified by unit test.

- [x] OC-2.3 Implement `normalize(array $pdokDoc): array` — maps PDOK fields to
  canonical PostalAddress shape per the field mapping table in design.md. Missing fields
  map to `null` (never absent). Always sets `source: "pdok"`. Parses `centroide_ll`
  WKT `"POINT(lng lat)"` → GeoJSON Point `[lng, lat]` per RFC 7946.
  - **Acceptance:** PHPUnit unit tests using the three seed fixtures confirm each field
    maps correctly; missing-field fixture confirms `null` present not absent; `location`
    coordinates are `[lng, lat]` order.

### OC-3. Caching layer (S)

- [x] OC-3.1 Implement APCu cache read-before-call and write-after-call around all four
  proxy methods. Cache key = `"pdok_connector::{endpoint}::{sha256(json_encode(ksort($params)))}"`
  (use ksort to normalise parameter order). TTLs: 3600s for lookup/reverse, 300s for
  suggest/free.
  - **Acceptance:** PHPUnit confirms cache hit path skips `callPdok()`; cache key
    format verified; TTLs correct per endpoint.

- [x] OC-3.2 Add graceful fallback if `ICache` is unavailable: log a `warning` and
  proceed uncached without throwing.
  - **Acceptance:** PHPUnit test with null `ICache` confirms uncached path executes
    without exception; warning logged.

### OC-4. Write-through to OR addresses register (M)

- [x] OC-4.1 Implement `writeThrough(array $normalizedAddress): void` — on successful
  lookup or reverse fetch, check OR `addresses` register for an existing object by
  `pdokId`; if absent create; if present and `fetchedAt` older than 1 hour update.
  Also check OR before calling PDOK on APCu miss (OR hit within TTL avoids upstream
  call and re-populates APCu).
  - **Acceptance:** PHPUnit integration test (or Newman) confirms: first lookup creates
    OR object; second lookup within TTL returns OR object without PDOK call; lookup
    after TTL expiry calls PDOK and updates OR object with new `fetchedAt`.

- [x] OC-4.2 Ensure the OR upsert uses check-then-update inside a single OR API
  interaction to avoid constraint violation on concurrent lookups with the same
  `bagAddressId`.
  - **Acceptance:** Concurrent-call test (two simultaneous lookups for the same
    `pdokId`) results in exactly one OR object created, not two.

### OC-5. Rate-limit handling (S)

- [x] OC-5.1 Implement exponential backoff in `callPdok()` on HTTP 429: retry up to
  3 times with delays `2^attempt × 200ms` (±10% jitter), capped at 5000ms; after 3
  failures return a 503-derived exception.
  - **Acceptance:** PHPUnit test with mocked 429 responses confirms retry sequence;
    three 429s result in 503; delay values are within ±10% of formula.

### OC-6. Circuit breaker (S)

- [x] OC-6.1 Implement circuit breaker state in APCu under key
  `"pdok_connector::circuit"` storing `{state, failures, opened_at}`; open after 5
  consecutive 5xx/timeout; half-open probe after 30s; close on probe success; reset
  failure counter on any 2xx.
  - **Acceptance:** PHPUnit test confirms: 5 consecutive 500 responses open the circuit;
    6th call returns 503 without upstream call; after 30s simulation, probe success
    closes circuit.

### OC-7. Graceful degradation (S)

- [x] OC-7.1 Return HTTP 503 with `{"error": "pdok_unavailable", "message_key": "pdok.unavailable"}`
  when circuit is open AND no APCu or OR result is available. Return the stale result
  with `X-Cache-Stale: true` header when a stale OR result exists.
  - **Acceptance:** PHPUnit confirms 503 body and both fields present when no cache
    exists; `X-Cache-Stale: true` header returned when stale OR result exists.

- [x] OC-7.2 Define i18n error keys in `l10n/en.js` and `l10n/nl.js`: `pdok.unavailable`,
  `pdok.error.missing_query`, `pdok.error.not_found`, `pdok.error.missing_coordinates`.
  - **Acceptance:** Both locale files contain all four keys; Dutch translation is
    human-readable (not a machine-generated placeholder).

### OC-8. REST controller and route registration (S)

- [x] OC-8.1 Create `lib/Controller/PdokController.php` with four action methods
  (`suggestAction`, `lookupAction`, `freeAction`, `reverseAction`) that use
  `requireLogin()`, validate required parameters (return 400 on missing `q`, `id`, or
  coordinates), delegate to `PdokConnector`, and return `JSONResponse`.
  - **Acceptance:** Missing-param tests return 400; valid-param tests return 200 with
    normalized shape; controller uses `requireLogin()`.

- [x] OC-8.2 Register four GET routes in `appinfo/routes.php` at `/api/pdok/suggest`,
  `/api/pdok/lookup`, `/api/pdok/free`, `/api/pdok/reverse` with `requireLogin()`.
  - **Acceptance:** Routes file has all four entries; integration test confirms each
    route returns 401 for unauthenticated and 200 for authenticated.

### OC-9. Observability (S)

- [x] OC-9.1 Inject `LoggerInterface` and emit one structured log entry per upstream
  call with fields: `endpoint`, `cache_hit`, `or_hit`, `upstream_latency_ms`,
  `http_status`, `circuit_state`, `write_through`. Log levels: debug for 2xx/cache/OR
  hits, warning for 429, error for 5xx/circuit-open.
  - **Acceptance:** PHPUnit test with mocked logger confirms correct fields and levels
    for three scenarios: cold-cache PDOK hit, OR-hit, circuit-open.

### OC-10. Seed data fixtures (S)

- [x] OC-10.1 Create `tests/fixtures/pdok/` with `fixture-lauriergracht.json`,
  `fixture-stadhuisplein-tilburg.json`, `fixture-woonplaats-tilburg.json` containing
  raw PDOK response shapes (before normalization). The woonplaats fixture MUST have no
  `postcode` or `huisnummer` to test null-mapping.
  - **Acceptance:** Three fixture files present; PHPUnit normalization tests load these
    fixtures; woonplaats fixture has no `postcode` or `huisnummer` fields.

### OC-11. PHPUnit tests and quality gate (M)

- [x] OC-11.1 Write `tests/Unit/Connectors/PdokConnectorTest.php` covering:
  normalization of each seed fixture, cache hit path (APCu), OR-hit path (OR within
  TTL), circuit breaker open path, 429 retry path (success on 3rd attempt), 429
  exhaustion (3 failures → 503), write-through create, write-through update
  (stale `fetchedAt`).
  - **Acceptance:** All unit test cases pass; `composer check:strict` reports zero
    PHPCS, PHPMD, Psalm, PHPStan errors in new files.

- [x] OC-11.2 Write `tests/Unit/Controller/PdokControllerTest.php` covering: missing
  `q` returns 400, missing `id` returns 400, missing coordinates returns 400,
  unauthenticated returns 401, valid suggest returns 200 with results array.
  - **Acceptance:** All controller test cases pass; no quality violations.
