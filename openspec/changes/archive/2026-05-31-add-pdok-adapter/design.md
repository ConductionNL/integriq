# Design: add-pdok-adapter

> Cross-repo architecture, the canonical address schema, caching flow,
> and the write-through / migration story all live in the umbrella spec:
> `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`
>
> This design document covers only openconnector-specific implementation details.

## Class Structure

### PdokConnector (`lib/Connectors/PdokConnector.php`)

Implements openconnector's existing connector contract interface (check
`lib/Connectors/` for the interface/abstract class name — use whatever existing
connectors implement). Constructor injects `IClientService`, `ICache`, and
`LoggerInterface`.

Key methods:

| Method | Signature | Purpose |
|---|---|---|
| `callPdok` | `callPdok(string $endpoint, array $params): array` | HTTP GET via `IClientService`; handles 429 retry + circuit breaker |
| `normalize` | `normalize(array $pdokDoc): array` | Maps PDOK fields → canonical PostalAddress shape |
| `suggest` | `suggest(string $q): array` | APCu read-before / write-after (300s TTL) |
| `lookup` | `lookup(string $id): array` | APCu (3600s TTL) + OR write-through |
| `free` | `free(string $q, int $rows = 10, int $start = 0): array` | APCu (300s TTL) |
| `reverse` | `reverse(float $lat, float $lng): array` | APCu (3600s TTL) + OR write-through |
| `writeThrough` | `writeThrough(array $normalizedAddress): void` | Upsert into OR `addresses` register by `pdokId` |

### PdokController (`lib/Controller/PdokController.php`)

Four action methods: `suggestAction`, `lookupAction`, `freeAction`, `reverseAction`.
All use `requireLogin()`. Parameter validation returns 400 on missing required params.
Each action delegates to `PdokConnector` and returns `JSONResponse`.

## Route Registration

Four GET routes in `appinfo/routes.php`:

```
GET /api/pdok/suggest   → PdokController::suggestAction
GET /api/pdok/lookup    → PdokController::lookupAction
GET /api/pdok/free      → PdokController::freeAction
GET /api/pdok/reverse   → PdokController::reverseAction
```

These are the only routes introduced by this change. All four use `requireLogin()`.
No CORS OPTIONS routes are needed (endpoints are same-origin Nextcloud calls).
Route registration follows ADR-016: `appinfo/routes.php` is the only registration path.

## DI Registration

Register `PdokConnector` in `lib/AppInfo/Application.php` and add an entry to the
integration registry (ADR-019). Check existing connectors for the registration pattern
(constructor injection via `IServerContainer::get()` / closure, and the registry call).

## PDOK Field → Canonical Field Mapping (`normalize()`)

| PDOK field | Canonical field | Notes |
|---|---|---|
| `id` | `pdokId` | PDOK's own identifier |
| `weergavenaam` | `displayName` | Human-readable full address |
| `straatnaam` | `streetAddress` | Street name |
| `huisnummer` + `huisletter` | `houseNumber` | Concatenated (e.g. "14h") |
| `huisnummertoevoeging` | `houseNumberAddition` | Optional suffix |
| `postcode` | `postalCode` | Stored as-is |
| `woonplaatsnaam` | `addressLocality` | City name |
| `provincienaam` | `addressRegion` | Province |
| `nummeraanduiding_id` | `bagAddressId` | 16-digit BAG ID |
| `pandid` | `bagBuildingId` | 16-digit BAG building ID |
| `centroide_ll` | `location` | WKT "POINT(lng lat)" → GeoJSON Point `[lng, lat]` |

Missing fields map to `null` (never absent from the output object). Always sets
`source: "pdok"`. Sets `fetchedAt` to the current ISO 8601 timestamp for lookup and
reverse results. `centroide_ll` WKT parsing: split on space inside parentheses, emit
`{"type": "Point", "coordinates": [lng, lat]}` per RFC 7946.

## Caching and Write-Through Flow

See umbrella design for the full flow diagram. Summary for this repo:

1. Compute `cache_key = "pdok_connector::{endpoint}::{sha256(json_encode(ksort($params)))}"`
2. Check APCu — if hit within TTL: return cached result (no log entry emitted for
   APCu-only hits, per observability spec)
3. On APCu miss: check circuit breaker state at APCu key `"pdok_connector::circuit"`
4. Circuit OPEN → return 503 immediately; emit error log
5. Call PDOK upstream via `callPdok()`
6. Success: normalize → cache in APCu → for lookup/reverse: write-through to OR
7. `writeThrough`: check OR `addresses` by `pdokId`; if absent create; if present and
   `fetchedAt` older than 1 hour update; concurrent-call safety via check-then-upsert
   (single OR API interaction)

## Rate-Limit and Circuit Breaker

See umbrella design. Implementation summary:
- **429 backoff:** `2^attempt × 200ms` (±10% jitter), cap 5000ms, max 3 retries
- **Circuit:** APCu key `"pdok_connector::circuit"` = `{state, failures, opened_at}`
- Open after 5 consecutive 5xx/timeout; half-open probe after 30s; close on probe success

## i18n Keys

Introduce in `l10n/en.js` and `l10n/nl.js`:
- `pdok.unavailable` — "Address lookup temporarily unavailable" / "Adresopzoeking tijdelijk niet beschikbaar"
- `pdok.error.missing_query` — "Query parameter q is required" / "Parameter q is vereist"
- `pdok.error.not_found` — "Address not found" / "Adres niet gevonden"
- `pdok.error.missing_coordinates` — "Parameters lat and lng are required" / "Parameters lat en lng zijn vereist"

## Observability

One structured log entry per upstream PDOK call (not per controller call — APCu-only
cache hits do not generate log entries). Log fields: `endpoint`, `cache_hit` (bool),
`or_hit` (bool), `upstream_latency_ms` (int), `http_status` (int|null), `circuit_state`
(`closed`|`open`|`half-open`), `write_through` (bool). Log levels: `debug` for
cache/OR hits and 2xx; `warning` for 429; `error` for 5xx, timeout, circuit-open.

## Seed Data

No new OR schemas or registers are introduced by this change — those live in the
`openregister/openspec/changes/add-addresses-register/` sibling spec.

Test fixtures for openconnector unit tests live in `tests/fixtures/pdok/`:
- `fixture-lauriergracht.json` — raw PDOK response before normalization (Conduction HQ)
- `fixture-stadhuisplein-tilburg.json` — raw PDOK response (Tilburg Stadhuis)
- `fixture-woonplaats-tilburg.json` — raw PDOK response without `postcode`/`huisnummer`
  (tests null-mapping in `normalize()`)

The canonical normalized shapes (after `normalize()`) are defined in the umbrella design's
Seed Data section and verified by unit tests. PHPUnit normalization tests load the raw
fixture files.

For the write-through integration tests, the OR `addresses` register is the write target.
These tests run against a live OR instance or use a mock OR API client — consistent with
how other openconnector integration tests handle external dependencies.
