# Tasks: haalcentraal-personen-bag-hra-adapter

## Tasks

### OC-1. Service scaffold and DI registration (S)

- [ ] OC-1.1 Create the following service classes with EUPL-1.2 SPDX headers inside class docblocks:
  - `lib/Service/HaalCentraalSourceService.php` — loads and validates source config per apiType
  - `lib/Service/HaalCentraalCacheService.php` — cache key derivation, get/put, stale fallback
  - `lib/Service/HaalCentraalThrottleService.php` — rate-limit checking and FIFO queuing
  - `lib/Service/HaalCentraalAutorisatieService.php` — autorisatie load, validate, expiry check
  - `lib/Service/HaalCentraalDoelbindingService.php` — doelbinding registry validation
  - **Acceptance:** All files compile without errors; SPDX headers present in class docblocks.

- [ ] OC-1.2 Register all service classes in DI container (`lib/AppInfo/Application.php`) using constructor injection closures for `IClientService`, `ICache`, `LoggerInterface`, `EncryptionService`, `IQueryBuilder`.
  - **Acceptance:** `Application.php` registers all services; no circular dependency errors; unit tests can instantiate each service via the container.

- [ ] OC-1.3 Add integration registry entries (ADR-019) for each adapter variant: `haalcentraal-brp-personen`, `haalcentraal-bag`, `haalcentraal-hr`, `haalcentraal-kvk-public`.
  - **Acceptance:** `openspec admin` or `integration-registry list` shows all four variants.

### OC-2. HaalCentraalConnector class (M)

- [ ] OC-2.1 Create `lib/Connectors/HaalCentraalConnector.php` implementing openconnector's existing connector contract interface. Inject all services from OC-1.
  - **Acceptance:** File compiles; implements same interface as existing connectors.

- [ ] OC-2.2 Implement `getPersoonByBsn(string $bsn, array $fields = [], string $doelbinding = '', string $grondslag = ''): array|Error` with autorisatie + doelbinding checks before upstream call.
  - **Acceptance:** Unit test confirms autorisatie check is called before `IClientService`; rejects queries outside autorisatie scope without upstream call; accepted queries log to HaalCentraalQuery.

- [ ] OC-2.3 Implement `searchPersonen(array $criteria, array $fields = [], string $doelbinding = ''): array` with POST to BRP `/personen`; individual result caching by BSN; no search caching.
  - **Acceptance:** Unit test confirms search results are cached individually; subsequent `getPersoonByBsn` for a result BSN is served from cache; search itself not cached.

- [ ] OC-2.4 Implement `getAddressByPostcodeHuisnummer(string $postcode, int $huisnummer, string $huisletter = '', string $toevoeging = ''): array|Error` with 30-day default TTL and 404 short-term caching (1 hour).
  - **Acceptance:** Unit test confirms GET to correct BAG endpoint; 404 cached short-term; 200 responses cached 30 days; normalized response includes all address fields (null-filled for missing).

- [ ] OC-2.5 Implement `getInschrijvingByKvkNummer(string $kvkNummer, array $fields = []): array` with OAuth2 bearer token refresh and HR endpoint GET.
  - **Acceptance:** Unit test confirms OAuth2 token refresh is called if near expiry; token cached for its lifetime minus 5-min safety margin; no doelbinding check; user/job logged for billing.

- [ ] OC-2.6 Implement `getPublicInschrijving(string $kvkNummer): array` with API-key auth to KvK Public endpoint.
  - **Acceptance:** Unit test confirms API key included in request header; response cached 7 days; falls back to HR if non-public fields requested.

### OC-3. Response normalization and version translation (M)

- [ ] OC-3.1 Implement `normalizeResponse(array $raw, string $apiType, string $apiVersion): array` method in HaalCentraalConnector that translates upstream version quirks into canonical shape per design.md.
  - **Acceptance:** Unit tests using real upstream response fixtures (3+ per API type) confirm field mapping; v1 vs v2 quirks (BRP geheimhouding, verblijfplaats) handled; missing fields present as null; source set to apiType.

- [ ] OC-3.2 For BRP responses, handle v1 boolean `geheimhouding: true` → v2 structure `{indicatie: "G", omschrijving: "GEHEIM"}`.
  - **Acceptance:** Unit test with v1 fixture confirms geheimhouding translated correctly.

- [ ] OC-3.3 Implement WKT centroide_ll parsing → GeoJSON Point with `[lng, lat]` order per RFC 7946 for BAG and HR location fields.
  - **Acceptance:** Unit test confirms `"POINT(4.88525 52.37025)"` parses to `{"type": "Point", "coordinates": [4.88525, 52.37025]}`.

### OC-4. Autorisatie enforcement and lifecycle (M)

- [ ] OC-4.1 Implement `enforceAutorisatie(HaalCentraalAutorisatie $aut, array $requestedFields, string $bsn = ''): bool|Error` in HaalCentraalConnector that checks:
  - Autorisatie not expired (`geldigTot > now()`)
  - BSN in `toegestaneBsnSet` (alle / eigen-burgers-gemeente with gemeentecode / specifieke-lijst)
  - All requested fields in `toegestaneVelden`
  - **Acceptance:** Unit test confirms each check is performed; violations reject request without upstream call; structured error includes violated rule without leaking BSN value.

- [ ] OC-4.2 Implement `HaalCentraalAutorisatieService::loadAutorisatie()` to parse uploaded PDF + metadata; validate all required fields present; create HaalCentraalAutorisatie row; store PDF as Nextcloud Files reference; emit `autorisatie-loaded` audit event.
  - **Acceptance:** Unit test confirms metadata validation; autorisatie persisted with correct fields; PDF reference stored; audit event written.

- [ ] OC-4.3 Implement `HaalCentraalAutorisatieService::checkExpiry()` as daily cron job that:
  - Iterates all BRP sources with active autorisaties
  - Emits `autorisatie-expired` audit event if `geldigTot < now()`
  - Sends 30-day warning notification to `haalcentraal_admin` group if `geldigTot - now() == 30 days`
  - **Acceptance:** Unit test with mocked clock confirms expiry detection; notification sent at 30-day threshold; expired autorisatie blocks BRP queries.

### OC-5. Doelbinding registry validation (S)

- [ ] OC-5.1 Implement `HaalCentraalDoelbindingService::loadRegistry()` and `validateDoelbinding(string $doelbinding, string $user): bool|Error` that:
  - Checks if doelbinding is in admin-configured registry
  - If not in registry, checks if user is in `doelbinding_freevorm` group
  - Returns error if neither condition met; allows if either is true
  - **Acceptance:** Unit test confirms approved doelbinding passes; unapproved doelbinding rejected unless user in freevorm group; freevorm group membership bypasses registry check.

### OC-6. Caching layer (M)

- [ ] OC-6.1 Implement `HaalCentraalCacheService::buildCacheKey(string $apiType, string $requestPath, array $fields): string` that produces `{apiType}::{requestPath}::{sha256(ksort(fields))}`.
  - **Acceptance:** Unit test confirms cache key format; field order does not affect key (ksort normalizes).

- [ ] OC-6.2 Implement cache get/put in HaalCentraalConnector around all proxy methods: `cacheGet()` checks APCu before upstream; `cachePut()` stores response after upstream success.
  - **Acceptance:** Unit test confirms cache hit skips upstream call; cache miss calls upstream; cache key matches buildCacheKey output.

- [ ] OC-6.3 Implement per-apiType TTLs: BRP=86400s, BAG=2592000s, HR=604800s, KvK-public=604800s (overridable per source config).
  - **Acceptance:** Unit test confirms TTL values are applied per apiType; cached response expires correctly.

- [ ] OC-6.4 Implement stale-while-revalidate fallback: if upstream fails (5xx/timeout) and a stale cache entry exists (expired but within one extra TTL cycle), return stale entry with `Warning: 110 - Response is stale` header.
  - **Acceptance:** Unit test confirms: upstream 500 returns stale entry; warning header present; `cache-hit-stale` audit event written; upstream-error event written separately.

- [ ] OC-6.5 Implement cache invalidation methods: `invalidateByKey(string $cacheKey)` sets `expiresAt = now()` but retains entry for stale fallback; `invalidateByType(string $apiType)` invalidates all entries of a type.
  - **Acceptance:** Unit test confirms invalidation marks entries as expired; stale entries within one extra TTL still available for fallback.

### OC-7. Rate-limiting and throttling (M)

- [ ] OC-7.1 Implement `HaalCentraalThrottleService::checkLimit(HaalCentraalSource $source): bool|Error` that:
  - Counts requests in current minute (sliding window)
  - Counts requests in current day
  - Returns `true` if both counts under ceiling
  - Returns error `daily-limit-exceeded` if daily limit reached
  - Returns error `throttled` if minute limit reached and request queued >30s
  - **Acceptance:** Unit test confirms limits enforced; requests under limit pass through; requests over minute limit are queued; queued requests wait or timeout after 30s.

- [ ] OC-7.2 Implement `HaalCentraalThrottleService::enqueue()` and `dequeue()` for per-source FIFO queue. Queue holds: sourceRef, requestPath, enqueuedAt, enqueuingUser.
  - **Acceptance:** Unit test confirms FIFO ordering; requests dequeued as rate window opens; queue depth available for monitoring.

- [ ] OC-7.3 Implement queue depth reporting for mydash widget: `getQueueDepth(HaalCentraalSource $source): int`.
  - **Acceptance:** Unit test confirms queue depth accurate; mydash widget query returns per-source depths.

### OC-8. HaalCentraalController and route registration (S)

- [ ] OC-8.1 Create `lib/Controller/HaalCentraalController.php` with five action methods (persoonByBsn, searchPersonen, address, inschrijvingByKvk, publicInschrijving). All use `requireLogin()`. Parameter validation returns 400 on missing required params.
  - **Acceptance:** All routes respond with 400 for missing params; 401 for unauthenticated requests; 200 for valid calls.

- [ ] OC-8.2 Register five GET/POST routes in `appinfo/routes.php`:
  - GET /api/haalcentraal/persoon/{bsn}
  - POST /api/haalcentraal/personen
  - GET /api/haalcentraal/adres (params: postcode, huisnummer, huisletter, toevoeging)
  - GET /api/haalcentraal/inschrijving-kvk/{kvkNummer}
  - GET /api/haalcentraal/inschrijving-kvk-public/{kvkNummer}
  - **Acceptance:** All routes are reachable; return appropriate HTTP status codes per spec scenarios.

### OC-9. Query audit logging (S)

- [ ] OC-9.1 Implement `logQuery(HaalCentraalQuery $query): void` in HaalCentraalConnector that writes one row per upstream call (or rejection). Fields: sourceRef, apiType, requestPath, method, requestFieldsRequested, responseStatus, responseBodyHash, responseFromCache, cacheKey, cacheExpiresAt, latencyMs, triggeredByUser, triggeredByJobRef, wettelijkeGrondslag, doelbinding, bsn (encrypted), kvkNummer, bagId, errorMessage, createdAt.
  - **Acceptance:** Unit test confirms row persisted per call; BSN encrypted per ADR-016; all fields populated correctly; no row written for cache hits (first-layer APCu only).

- [ ] OC-9.2 Implement `logAuditEvent(HaalCentraalAuditEvent $event): void` that appends write-once event rows with type, queryRef, actor, timestamp, details. Enforce immutability (no updates/deletes on existing rows).
  - **Acceptance:** Unit test confirms events are append-only; audit table enforces no-update/no-delete constraints (DB check or app-layer validation); all event types (query-allowed, query-rejected-by-autorisatie, etc.) logged correctly.

- [ ] OC-9.3 Implement retention policy: BRP events retained 5 years; BAG/HR/KvK events retained 1 year. Add scheduled job to cleanup expired logs.
  - **Acceptance:** Unit test confirms old logs cleaned up per retention policy; retention dates configurable per ADR-004.

### OC-10. OAuth2 token management (S)

- [ ] OC-10.1 Implement `HaalCentraalSourceService::refreshOAuth2Token(HaalCentraalSource $source): string` that:
  - Checks cache for non-expired token
  - If expired or missing, invokes client-credentials flow: POST to `oauth2TokenUrl` with `client_id`, `client_secret` (and mTLS cert if `authMode: oauth2-mtls`)
  - Caches token for `expires_in - 300` seconds (5-min safety margin)
  - Returns token for Authorization header
  - **Acceptance:** Unit test confirms token fetch, caching, automatic refresh near expiry; mTLS flow includes cert; bearer flow omits cert.

### OC-11. Encryption of BSN at rest (S)

- [ ] OC-11.1 Implement BSN encryption in `logQuery()` using EncryptionService (ADR-016): `$query->bsn = $encryptionService->encrypt($bsn)` before DB write.
  - **Acceptance:** Unit test confirms BSN encrypted on write; `IQueryBuilder` decrypts on read transparently; raw DB inspection shows ciphertext not plaintext.

### OC-12. Structured error responses (S)

- [ ] OC-12.1 Implement structured error responses for all failure scenarios: autorisatie-violated, doelbinding-not-approved, source-disabled, throttled, daily-limit-exceeded, upstream-error. Each error includes error code, message_key (for i18n), and optional details.
  - **Acceptance:** Unit test confirms error structure matches spec; all error types return appropriate HTTP status (400/403/429/503).

### OC-13. i18n keys (S)

- [ ] OC-13.1 Add i18n keys to `l10n/en.js` and `l10n/nl.js`:
  - `haalcentraal.error.autorisatie-violated` — "Query falls outside valid autorisatie scope" / "Zoekopdracht valt buiten geldig autorisatiebereik"
  - `haalcentraal.error.field-not-allowed` — "Field {field} is not allowed by autorisatie" / "Veld {field} is niet toegestaan door autorisatie"
  - `haalcentraal.error.bsn-not-allowed` — "BSN outside autorisatie allowed set" / "BSN buiten toegestane set autorisatie"
  - `haalcentraal.error.doelbinding-not-approved` — "Doelbinding not in approved registry" / "Doelbinding niet in goedgekeurd register"
  - `haalcentraal.error.source-disabled` — "This API source is disabled" / "Deze API-bron is uitgeschakeld"
  - `haalcentraal.error.throttled` — "Request rate limit exceeded; retry after {seconds} seconds" / "Aanvraagfrequentielimiet overschreden; opnieuw proberen na {seconds} seconden"
  - `haalcentraal.error.daily-limit-exceeded` — "Daily request limit exceeded; retry after 24 hours" / "Dagelijkse aanvraaglimiet overschreden; opnieuw proberen na 24 uur"
  - `haalcentraal.error.upstream-error` — "Upstream API temporarily unavailable" / "Upstream API tijdelijk niet beschikbaar"
  - `haalcentraal.error.autorisatie-expired` — "Autorisatie has expired; contact administrator" / "Autorisatie is verlopen; neem contact op met beheerder"
  - `haalcentraal.warning.response-stale` — "Response is stale (upstream unavailable)" / "Reactie is verouderd (upstream niet beschikbaar)"
  - **Acceptance:** All keys present in both locales; used by controller error returns; i18n lookup does not fail.

### OC-14. Admin UI for sources and autorisaties (M)

- [ ] OC-14.1 Create admin UI page for HaalCentraal source management. Form includes: apiType dropdown (brp-personen, bag, hr, kvk-public), baseUrl, apiVersion, authMode dropdown with conditional fields (oauth2 URL/credentials for OAuth2, cert reference for mTLS, API key for api-key).
  - **Acceptance:** Admin can create/edit/delete sources; form validates required fields; auth credentials encrypted per ADR-016; sources appear in integration registry.

- [ ] OC-14.2 Create admin UI page for autorisatie-besluit upload. Form includes: PDF file upload, structured metadata fields (autorisatieReferentie, geldigVan, geldigTot, toegestaneVelden as textarea/json, toegestaneBsnSet enum + optional CSV list). On submit: validate fields, persist HaalCentraalAutorisatie, store PDF as Nextcloud Files reference, emit audit event.
  - **Acceptance:** Admin can upload autorisatie; metadata validated; PDF stored; subsequent BRP queries enforce the uploaded autorisatie.

- [ ] OC-14.3 Create admin UI page for doelbinding registry management. Table interface: add/edit/delete registry entries. Each entry has: doelbinding (string), wettelijke grondslag (dropdown or freetext), approver role (dropdown). Entries saved to openconnector config.
  - **Acceptance:** Admin can manage registry; entries persist; BRP queries validate against registry.

### OC-15. mydash widget integration (S)

- [ ] OC-15.1 Create mydash widget for HaalCentraal metrics. Widget fetches per-source data:
  - API type, requests/minute (current minute), requests/day (current day), cache hit rate (%), autorisatie expiry countdown (days), throttle queue depth, doelbinding violation count (rejections/hour)
  - **Acceptance:** Widget queries HaalCentraalConnector/HaalCentraalCacheService/HaalCentraalThrottleService; displays data in table per source; updates at 30-second intervals.

### OC-16. Unit tests (M)

- [ ] OC-16.1 PHPUnit tests for HaalCentraalConnector: BRP lookup, search, BAG lookup, HR lookup, KvK-public lookup, autorisatie enforcement, doelbinding validation, cache hit/miss, stale fallback, rate-limit queuing.
  - **Acceptance:** All core methods have unit tests with mocked upstream responses; coverage >80%.

- [ ] OC-16.2 Unit tests for HaalCentraalSourceService: load by apiType, validate config, refresh OAuth2 token, token caching.
  - **Acceptance:** All methods tested; OAuth2 flow mocked; token caching verified.

- [ ] OC-16.3 Unit tests for HaalCentraalCacheService: cache key derivation, get/put, stale fallback, invalidation.
  - **Acceptance:** All cache scenarios tested; stale fallback verified.

- [ ] OC-16.4 Unit tests for HaalCentraalThrottleService: rate-limit checking, queuing, queue depth reporting.
  - **Acceptance:** Minute/day limits enforced; queue FIFO ordering verified.

- [ ] OC-16.5 Unit tests for HaalCentraalAutorisatieService: autorisatie load, validation, expiry check.
  - **Acceptance:** Autorisatie enforcement verified; expiry detection works; notifications sent at 30-day threshold.

- [ ] OC-16.6 Unit tests for HaalCentraalDoelbindingService: registry validation, freevorm group bypass.
  - **Acceptance:** Registry check passes/fails correctly; freevorm group exemption verified.

### OC-17. Integration tests (M)

- [ ] OC-17.1 Newman/Postman integration test suite covering full workflows:
  - BRP lookup with valid autorisatie → 200 response, query logged, audit event written
  - BRP lookup with invalid autorisatie → 403 error, no upstream call, rejection logged
  - BAG address lookup → 200 response, cached 30 days, subsequent hit from cache
  - Upstream 500 with stale cache → 200 response with stale warning, both audit events written
  - Throttled request queued >30s → 429 error with retryAfter
  - **Acceptance:** All workflows pass; responses match spec structure; DB audit tables populated correctly.

### OC-18. Test fixtures (S)

- [ ] OC-18.1 Create test fixtures in `tests/fixtures/haalcentraal/`:
  - `fixture-brp-persoon-v1.json` — raw BRP v1 response (for normalization tests)
  - `fixture-brp-persoon-v2.json` — raw BRP v2 response with v2-specific fields
  - `fixture-bag-address.json` — raw BAG address response with all fields
  - `fixture-bag-address-missing-fields.json` — BAG response with some fields absent (tests null-filling)
  - `fixture-hr-inschrijving.json` — raw HR response
  - `fixture-kvk-public-inschrijving.json` — raw KvK Public response
  - **Acceptance:** Fixtures are valid JSON; normalization tests load fixtures and verify field mapping.

### OC-19. CallLog integration (S)

- [ ] OC-19.1 Ensure every upstream HTTP call is logged via `CallService` (ADR-003) with structured fields: endpoint, method, http_status, latency_ms, cache_status, queue_depth.
  - **Acceptance:** CallLog table has entry for every upstream call; APCu-only cache hits do not generate CallLog entries; structured fields present.

### OC-20. Documentation and OpenSpec finalization (S)

- [ ] OC-20.1 Verify design.md, spec.md, and proposal.md are complete and consistent. All class names, method signatures, and requirements are accurate.
  - **Acceptance:** Design/spec/proposal reviewed for consistency; no TODO markers; all scenarios in spec have corresponding design details.

- [ ] OC-20.2 Add example curl commands to design.md documenting each endpoint (BRP lookup, search, BAG lookup, HR lookup, KvK lookup) with sample parameters and expected response structure.
  - **Acceptance:** Examples are curl-executable; responses match normalized shapes from design seed data.

### OC-21. Schema definitions in OpenRegister (S)

- [ ] OC-21.1 Coordinate with OpenRegister: ensure five new schemas (HaalCentraalSource, HaalCentraalQuery, HaalCentraalCacheEntry, HaalCentraalAutorisatie, HaalCentraalAuditEvent) are defined in openregister before openconnector implementation is complete.
  - **Acceptance:** Schemas exist in OR with correct field definitions; openconnector can reference them via IObjectService.

### OC-22. Digikoppeling certificate schema reuse (S)

- [ ] OC-22.1 Verify that the PKIoverheid mTLS certificate schema from digikoppeling-adapter is available and that HaalCentraalSource can reference it via foreign key for `mtlsCertificateRef`.
  - **Acceptance:** HaalCentraalSource mTLS config can point to an existing PKIoverheid certificate row; connector loads cert and uses it for OAuth2-mTLS client auth.
