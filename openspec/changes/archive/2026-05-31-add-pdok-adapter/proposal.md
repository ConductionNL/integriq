# Add PDOK Adapter

## Why

This change implements the `[openconnector]` subset of the Hydra-level umbrella change
`shared-pdok-via-openconnector`. Today, `procest`'s frontend calls `api.pdok.nl` directly
from the browser — a pattern that violates ADR-022 and that every future consumer app
would reproduce. openconnector is the correct host for the server-side PDOK Locatieserver
proxy: it already has the connector contract, DI patterns, `IClientService`, `ICache`,
and integration registry. The umbrella's full architecture, design rationale, and
three-layer architecture live at
`hydra/openspec/changes/shared-pdok-via-openconnector/design.md`.

## What

- A new `PdokConnector` PHP class (`lib/Connectors/PdokConnector.php`) implementing the
  existing connector contract interface; registered in `Application.php` and the
  integration registry.
- Four upstream proxy methods (`suggest`, `lookup`, `free`, `reverse`) mapping to PDOK
  Locatieserver v3.1 endpoints.
- A `normalize()` method that transforms PDOK response fields into the canonical OR
  PostalAddress schema shape.
- APCu caching via `ICache` with per-endpoint TTLs (3600s lookup/reverse, 300s suggest/free).
- Write-through to the OR `addresses` register on successful `lookup` and `reverse` fetches
  (upsert by `pdokId`, refresh if `fetchedAt` older than 1 hour).
- Exponential backoff on HTTP 429 (up to 3 retries, cap 5000ms, ±10% jitter).
- Circuit breaker in APCu (5 consecutive failures → open, 30s, half-open probe).
- Graceful degradation: HTTP 503 + `message_key: "pdok.unavailable"` when no cached or
  OR result exists; `X-Cache-Stale: true` when stale result is returned.
- A new `PdokController` (`lib/Controller/PdokController.php`) with four action methods
  guarded by `requireLogin()`.
- Four GET routes registered in `appinfo/routes.php` at `/api/pdok/{suggest|lookup|free|reverse}`.
- Structured observability log entries per upstream call (ADR-006).
- i18n keys in `l10n/en.js` and `l10n/nl.js`.
- PHPUnit unit tests for normalization, caching, circuit breaker, rate-limit, write-through,
  controller parameter validation.
- Test fixtures in `tests/fixtures/pdok/` (raw PDOK response shapes, before normalization).

## Capabilities

### New Capabilities

- `pdok-adapter`: Server-side PDOK Locatieserver v3.1 connector for openconnector —
  proxies suggest, lookup, free-text, and reverse-geocode endpoints; transforms PDOK
  response shape into the canonical OR PostalAddress schema; writes fetched addresses
  through to OR's `addresses` register; caches per-endpoint with defined TTLs; handles
  rate-limiting, retries, and circuit-breaker; emits structured observability logs.

## Affected Repos

openconnector only.

## References

- Umbrella spec:
  `hydra/openspec/changes/shared-pdok-via-openconnector/`
- Umbrella design (canonical architecture, caching flow, rate-limit / circuit-breaker):
  `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`
- OR addresses register (write-through target):
  `openregister/openspec/changes/add-addresses-register/`
  — `openregister` MUST ship before write-through can be fully exercised;
  each task describes the OR contract against the documented schema so that
  openconnector can be implemented and tested independently.

## Out of Scope

- The OR `addresses` register definition — covered by sibling spec
  `openregister/openspec/changes/add-addresses-register/`.
- The `procest` frontend shim migration — covered by sibling spec
  `procest/openspec/changes/migrate-pdok-to-openconnector/`.
- Configurable circuit breaker thresholds or cache TTLs — hardcoded for this spec;
  a follow-up can add admin UI knobs.
- Other geocoders (Esri, Google, OSM) — separate adapters when needed.
- decidesk / zaakafhandelapp / pipelinq migration — separate per-app specs.
