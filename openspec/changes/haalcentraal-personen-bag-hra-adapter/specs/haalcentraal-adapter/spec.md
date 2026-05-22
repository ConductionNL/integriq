---
status: draft
---
# openconnector HaalCentraal Unified Adapter

## Purpose

The openconnector HaalCentraal adapter centralizes server-side access to all four VNG 
Realisatie HaalCentraal APIs: BRP Personen (citizens), BAG (addresses), Handelsregister 
(company non-public data), and KvK Public (company public data). It enforces 
autorisatie and doelbinding BEFORE the call leaves Nextcloud, logs every query in 
an immutable audit trail, handles rate-limiting and caching fleet-wide, and ensures 
no consuming app can violate Wet BRP or GDPR constraints.

## ADDED Requirements

### Requirement: HaalCentraal Source Configuration

The openconnector MUST support configuration of up to four HaalCentraalSource objects 
(one per `apiType`: brp-personen, bag, handelsregister-hr, kvk-public). Each source 
MUST store `baseUrl`, `apiVersion`, `authMode` (oauth2-mtls / oauth2-bearer / api-key), 
OAuth2 endpoints and credentials, mTLS certificate reference, API key, cache TTL, 
rate-limit ceilings, autorisatie reference (BRP only), default wettelijke grondslag 
(BRP only), and enabled flag.

#### Scenario: BRP source with OAuth2-mTLS is configured

- GIVEN an admin uploads BRP Personen source config with OAuth2-mTLS auth
- WHEN the config is stored
- THEN the source SHALL persist with all auth fields encrypted per ADR-016
- AND the mTLS certificate reference SHALL resolve to an existing PKIoverheid certificate
- AND a subsequent API call SHALL use that cert for mTLS handshake with the upstream OAuth2 endpoint

#### Scenario: BAG source with API-key auth is configured

- GIVEN an admin uploads BAG source config with API-key auth
- WHEN the config is stored
- THEN the API key SHALL be encrypted at rest per ADR-016
- AND a subsequent lookup SHALL include the API key in the request header without plaintext exposure in logs

#### Scenario: Inactive source does not receive requests

- GIVEN a source has `enabled: false`
- WHEN a consuming job attempts to use that source
- THEN openconnector SHALL return a structured error (`source-disabled`) without attempting upstream call

### Requirement: BRP Personen Lookup by BSN

The openconnector MUST implement `getPersoonByBsn(bsn, fields, doelbinding, grondslag)` 
enforcing autorisatie validation before the upstream call.

#### Scenario: Valid BRP lookup returns cached response if fresh

- GIVEN a BRP source is configured with a valid autorisatie
- AND a previous lookup for the same BSN with the same fields was cached within TTL
- WHEN getPersoonByBsn is called
- THEN the cached response SHALL be returned without upstream call
- AND the response body SHALL include the original timestamp (`cachedAt`) in a `_cache_info` field
- AND a HaalCentraalQuery row SHALL NOT be written (cache hits do not audit)

#### Scenario: Valid BRP lookup enforces autorisatie before upstream call

- GIVEN a BRP source autorisatie covers `toegestaneVelden: [naam, geboorte, ...]`
- AND a job requests `fields: [naam, financialCreditLimit]` where `financialCreditLimit` is NOT in allowed fields
- WHEN getPersoonByBsn is invoked
- THEN openconnector SHALL NOT send the request upstream
- AND openconnector SHALL return a structured error: `{"error": "autorisatie-violated", "violatedRule": "field-not-allowed", "field": "financialCreditLimit"}`
- AND a HaalCentraalAuditEvent SHALL be written with type `query-rejected-by-autorisatie`

#### Scenario: BSN validation against autorisatie allowed set

- GIVEN a BRP source autorisatie with `toegestaneBsnSet: "eigen-burgers-gemeente"` and `gemeentecode: "0363"` (Tilburg)
- AND a job calls getPersoonByBsn with a BSN from a different municipality
- WHEN the lookup is processed
- THEN openconnector SHALL validate the BSN against the gemeente-specific list
- AND if the BSN is not in the list, return a structured error without upstream call
- AND emit a `query-rejected-by-autorisatie` audit event

#### Scenario: Successful BRP lookup creates query log and audit event

- GIVEN all autorisatie checks pass
- WHEN openconnector fetches from upstream and receives a 200 response
- THEN a HaalCentraalQuery row SHALL be written with encrypted BSN, request path, response status, latency, triggering user, and doelbinding
- AND a HaalCentraalAuditEvent SHALL be written with type `query-allowed`
- AND the response SHALL be cached with TTL=86400s (1 day) by default
- AND the normalized response SHALL be returned to the caller

#### Scenario: Upstream error returns stale cache if available

- GIVEN a previous successful lookup cached a response
- AND the cache entry's TTL has expired
- AND openconnector attempts an upstream call but receives HTTP 500
- WHEN the request fails
- THEN openconnector SHALL return the stale cache entry with `Warning: 110 - Response is stale` header
- AND a HaalCentraalAuditEvent SHALL be written with type `cache-hit-stale`
- AND a separate `upstream-error` audit event SHALL be written

#### Scenario: Response is normalized to canonical shape

- GIVEN BRP Personen v2 response with `geheimhouding: {indicatie: "G", omschrijving: "..."}` (v2 structured format)
- WHEN the response is normalized for a v2-configured source
- THEN the normalized output SHALL preserve the v2 structure per contract
- AND the raw upstream response SHALL be available on `_raw` field for introspection

### Requirement: BRP Search by Criteria

The openconnector MUST support POST search to the BRP `/personen` endpoint with field 
projection and individual-result caching.

#### Scenario: Search returns list of matching personen; each cached individually by BSN

- GIVEN a job calls searchPersonen with criteria `{geslachtsnaam: "Jansen", geboortedatum: "1980-05-20"}`
- WHEN the adapter sends POST to `/personen` with field projection
- THEN the response SHALL be a list of up to N matching records
- AND each individual record in the list SHALL be cached separately by `cacheKey = brp:{bsn}:{fields_hash}`
- AND a subsequent `getPersoonByBsn` for one of the returned BSNs SHALL be served from cache
- AND the search itself SHALL NOT be cached (search criteria variance too high)

#### Scenario: Search results do not bypass doelbinding logging

- GIVEN a search returns 5 matching personen
- WHEN the list is returned to the caller
- THEN the HaalCentraalQuery log SHALL have one row per search result
- AND each row SHALL include the same doelbinding and wettelijke grondslag as the original search call
- AND each row SHALL log the individual BSN (encrypted)

### Requirement: BAG Address Lookup

The openconnector MUST support BAG address lookup by postcode + huisnummer with no 
autorisatie requirement.

#### Scenario: Address lookup by postcode and huisnummer

- GIVEN a BAG source is configured
- WHEN a job calls getAddressByPostcodeHuisnummer("1016 RH", 31, "", "")
- THEN openconnector SHALL construct GET `{baseUrl}/adressen?postcode=1016%20RH&huisnummer=31`
- AND the response from upstream SHALL be normalized and cached with TTL=2592000s (30 days)
- AND the normalized address SHALL include all fields: bagAddressId, streetAddress, postcode, houseNumber, location (GeoJSON)

#### Scenario: Non-existent address is cached short-term to avoid hammering

- GIVEN a BAG lookup for a non-existent address returns HTTP 404
- WHEN the response is received
- THEN openconnector SHALL cache the 404 with TTL=3600s (1 hour)
- AND a subsequent lookup for the same non-existent address within 1 hour SHALL return the cached 404
- AND the HaalCentraalQuery log SHALL mark `responseStatus: 404` and `responseFromCache: true` on cache hit

#### Scenario: Missing address fields map to null

- GIVEN a BAG response includes some fields but not others
- WHEN the address is normalized
- THEN ALL expected address fields SHALL be present in the output with value `null` if absent upstream

### Requirement: Handelsregister HR Lookup

The openconnector MUST support full HR data lookup by KvK number with OAuth2 credentials.

#### Scenario: HR lookup uses OAuth2 bearer token with implicit refresh

- GIVEN an HR source is configured with OAuth2-bearer auth
- WHEN a job calls getInschrijvingByKvkNummer("12345678", ["kvkNummer", "handelsnaam"])
- THEN openconnector SHALL refresh the OAuth2 token if cached token is near expiry (within 5-minute safety margin)
- AND openconnector SHALL send GET `{baseUrl}/...?kvkNummer=12345678&fields=...` with Bearer token in Authorization header
- AND the response SHALL be normalized and cached with TTL=604800s (7 days)

#### Scenario: HR lookup does not enforce doelbinding check (commercial data)

- GIVEN an HR lookup completes successfully
- WHEN the HaalCentraalQuery log is written
- THEN `doelbinding` field SHALL be nullable (not required for HR)
- AND the calling user and job reference SHALL be logged for billing reconciliation against KvK abonnement

### Requirement: KvK Public Lookup

The openconnector MUST support KvK Public API access with API-key auth, preferred 
for public-only queries.

#### Scenario: KvK Public lookup uses API key auth

- GIVEN a KvK-public source is configured with API-key auth
- WHEN a job calls getPublicInschrijving("12345678")
- THEN openconnector SHALL send GET `{baseUrl}/...?kvkNummer=12345678` with API key in header
- AND the response SHALL be normalized and cached with TTL=604800s (7 days)

#### Scenario: HR preferred over KvK-public only if non-public fields requested

- GIVEN both HR and KvK-public sources are configured
- AND a job calls getInschrijvingByKvkNummer with only public fields
- THEN openconnector SHALL prefer the KvK-public source (lower cost, simpler auth)
- AND if a job requests non-public fields, openconnector SHALL fall back to HR source

### Requirement: Autorisatie Validation Lifecycle

The openconnector MUST enforce autorisatie validity and prevent query execution 
outside the autorisatie scope.

#### Scenario: Admin uploads autorisatie-besluit via OpenConnector admin UI

- GIVEN an admin user navigates to the HaalCentraal sources admin page
- WHEN they upload an autorisatie-besluit PDF + structured metadata (autorisatieReferentie, geldigVan, geldigTot, toegestaneVelden, toegestaneBsnSet)
- THEN openconnector SHALL validate the metadata fields are present and non-empty
- AND persist a HaalCentraalAutorisatie row with all fields
- AND store the PDF as a Nextcloud Files reference
- AND emit a `autorisatie-loaded` audit event
- AND from that point onwards, all BRP queries against the source SHALL validate against this autorisatie

#### Scenario: Expired autorisatie prevents all queries

- GIVEN a HaalCentraalAutorisatie has `geldigTot: "2025-05-21"` (yesterday)
- AND a job attempts a BRP query
- WHEN the adapter checks autorisatie validity
- THEN the query SHALL be rejected with error `autorisatie-expired`
- AND a `query-rejected-by-autorisatie` audit event SHALL be written
- AND a notification SHALL have been sent to the `haalcentraal_admin` group 30 days before expiry

#### Scenario: Accessible fields within autorisatie

- GIVEN autorisatie covers `toegestaneVelden: [naam.voornamen, naam.geslachtsnaam, geboorte.datum]`
- WHEN a BRP query requests exactly those fields
- THEN the query SHALL proceed to upstream validation check

### Requirement: Doelbinding Enforcement

The openconnector MUST enforce a registry of approved doelbinding strings so 
governance and audit trails are consistent.

#### Scenario: Approved doelbinding allows query

- GIVEN the doelbinding registry includes entry `{doelbinding: "burgerzaken-intake", grondslag: "art. 1.3 Wet BRP", approver_role: "burgemeester"}`
- WHEN a BRP query includes `doelbinding: "burgerzaken-intake"`
- THEN the query SHALL proceed (doelbinding check passes)
- AND the HaalCentraalQuery row SHALL log the doelbinding exactly as provided

#### Scenario: Unapproved doelbinding rejected unless user in freevorm group

- GIVEN the doelbinding registry does NOT include `doelbinding: "ad-hoc-casework-xyz"`
- AND a job calls getPersoonByBsn with that doelbinding
- WHEN the adapter evaluates the doelbinding
- THEN if the calling Nextcloud user is NOT in `doelbinding_freevorm` group, the query SHALL be rejected with error `doelbinding-not-approved`
- AND if the user IS in `doelbinding_freevorm`, the query SHALL proceed and the freeform doelbinding SHALL be logged verbatim
- AND a `query-rejected-by-doelbinding` audit event SHALL be written for rejections

### Requirement: Caching with Version-Specific TTLs

The openconnector MUST cache upstream responses per source with source-specific TTLs.

#### Scenario: Cache TTL varies by source

- GIVEN BRP source has `defaultTtlSeconds: 86400` (1 day)
- AND BAG source has `defaultTtlSeconds: 2592000` (30 days)
- WHEN responses are cached
- THEN BRP response cache entries SHALL expire after 86400s from `cachedAt`
- AND BAG response cache entries SHALL expire after 2592000s from `cachedAt`
- AND cache keys SHALL be derived from `{apiType}::{requestPath}::{sha256(fields)}`

#### Scenario: Stale-while-revalidate: stale entries served on upstream 5xx

- GIVEN a cached response has `expiresAt < now()` (expired)
- AND the cached entry is within one extra TTL cycle (not yet garbage-collected)
- AND openconnector attempts upstream but receives HTTP 500
- WHEN the request fails
- THEN the stale entry SHALL be returned with `Warning: 110 - Response is stale` header
- AND a `cache-hit-stale` audit event SHALL be written
- AND an `upstream-error` audit event SHALL be written separately

### Requirement: Rate-Limiting and Throttling

The openconnector MUST enforce per-source rate limits to stay within upstream SLA ceilings.

#### Scenario: Request within limits proceeds immediately

- GIVEN a source has `maxRequestsPerMinute: 600`
- AND the source has received 400 requests in the current minute
- WHEN the 401st request arrives
- THEN the request SHALL proceed immediately (under limit)

#### Scenario: Request exceeding minute ceiling is queued

- GIVEN a source has `maxRequestsPerMinute: 600`
- AND the source has received 600 requests in the current minute
- WHEN the 601st request arrives
- THEN the request SHALL be enqueued in a per-source FIFO queue
- AND the calling job SHALL wait for a slot to open (up to 30 seconds)
- AND if a slot opens within 30 seconds, the request SHALL proceed
- AND if no slot opens after 30 seconds, the request SHALL return a structured error `throttled` with `retryAfter: <seconds-until-next-available-slot>`

#### Scenario: Daily ceiling is enforced separately

- GIVEN a source has `maxRequestsPerDay: 100000`
- AND the source has received 100000 requests today
- WHEN the 100001st request arrives
- THEN the request SHALL be rejected immediately (not queued) with error `daily-limit-exceeded`
- AND `retryAfter` SHALL indicate next UTC day boundary

#### Scenario: Queue depth visible in mydash widget

- GIVEN openconnector has multiple sources with queued requests
- WHEN mydash queries the adapter for dashboard data
- THEN the response SHALL include per-source queue depth and estimated wait times

### Requirement: Immutable Query Audit Trail

The openconnector MUST persist every query attempt (success or rejection) in an 
immutable write-once audit log.

#### Scenario: Successful query writes HaalCentraalQuery row

- GIVEN all validation passes and upstream returns 2xx
- WHEN the query completes
- THEN a HaalCentraalQuery row SHALL be written with: encrypted BSN (BRP only), request path, method, response status, latency, triggering user, triggering job, fields requested, doelbinding, wettelijke grondslag, cache status, cache key

#### Scenario: Rejected query writes HaalCentraalQuery row with error

- GIVEN a BRP query fails autorisatie check
- WHEN the rejection is determined
- THEN a HaalCentraalQuery row SHALL be written with status `null` (never sent upstream) and `errorMessage` describing the violated rule
- AND no encrypted BSN SHALL be present (request was rejected before identifying the BSN)

#### Scenario: Audit events are append-only

- GIVEN multiple events have been written for a single query
- WHEN the audit log is read
- THEN no existing event SHALL be updated or deleted (immutable)
- AND all events SHALL retain original timestamp and actor

### Requirement: Response Normalization Per API Version

The openconnector MUST normalize upstream response shapes so consuming apps see 
a stable contract regardless of upstream API version.

#### Scenario: BRP v1 vs v2 geheimhouding field normalized

- GIVEN a BRP v1 source returns `geheimhouding: true` (boolean)
- AND a BRP v2 source returns `geheimhouding: {indicatie: "G", omschrijving: "..."}` (structured)
- WHEN responses are normalized
- THEN the v1 adapter layer SHALL convert boolean to v2 structure `{indicatie: "G", omschrijving: "GEHEIM"}`
- AND the canonical normalized response shape SHALL be identical for both versions
- AND consuming apps SHALL not see version-specific quirks

#### Scenario: Raw upstream response available on _raw field

- GIVEN a BRP response is normalized
- WHEN the normalized response is returned
- THEN the original upstream response (before normalization) SHALL be available on `_raw` field
- AND apps that need the raw shape can access it without re-querying upstream

### Requirement: Structured Error Responses

All errors returned by the adapter MUST be structured so consuming apps can handle 
them programmatically.

#### Scenario: Autorisatie violation structured error

- GIVEN autorisatie check fails
- WHEN the error is returned
- THEN the response SHALL be: `{"error": "autorisatie-violated", "violatedRule": "field-not-allowed|bsn-not-allowed|no-active-autorisatie", "details": "..."}`
- AND no BSN value SHALL be leaked in the error message (only the rule that was violated)

#### Scenario: Throttled error with retry information

- GIVEN a request is throttled
- WHEN the error is returned
- THEN the response SHALL be: `{"error": "throttled", "retryAfter": <seconds>}`
- AND the HTTP status code SHALL be 429 (Too Many Requests)

#### Scenario: Source disabled error

- GIVEN a source has `enabled: false`
- WHEN a query uses that source
- THEN the response SHALL be: `{"error": "source-disabled"}`
- AND the HTTP status code SHALL be 503 (Service Unavailable)

### Requirement: Encryption of Sensitive Data at Rest

BRP BSN values in the HaalCentraalQuery audit log MUST be encrypted at rest per 
ADR-016 and Wet BRP art. 3.9.

#### Scenario: BSN encrypted on write, decrypted on read

- GIVEN a HaalCentraalQuery row is written with an encrypted BSN
- WHEN the row is stored in the database
- THEN the `bsn` column SHALL contain the encrypted value (ciphertext)
- AND queries selecting the row SHALL return the decrypted BSN to calling code
- AND raw database inspection SHALL NOT leak the BSN value
- AND EncryptionService contract (ADR-016) SHALL be honored: column-level encryption at controller boundaries

### Requirement: Per-Request Structured Logging

Every upstream HTTP call MUST be logged via the CallService (ADR-003) with structured 
fields for observability.

#### Scenario: One CallLog entry per upstream HTTP call

- GIVEN openconnector makes an upstream call to BRP
- WHEN the call completes
- THEN CallService SHALL persist a CallLog row with: endpoint, method, http_status, latency_ms, upstream_response_hash, cache_status (hit/miss/stale), queue_depth, circuit_state, actor

#### Scenario: APCu-only cache hits do not generate CallLog entries

- GIVEN a lookup is served from APCu without upstream call
- WHEN the request completes
- THEN no CallLog entry SHALL be written (cache hit on first layer does not involve "call")

### Requirement: Admin UI for Source and Autorisatie Management

The openconnector admin UI MUST expose pages for configuring sources and managing 
autorisaties.

#### Scenario: Source admin page supports all four API types

- GIVEN an admin navigates to HaalCentraal sources in the openconnector admin UI
- WHEN the source creation form is displayed
- THEN the form SHALL support discriminating between `brp-personen`, `bag`, `handelsregister-hr`, and `kvk-public` via dropdown
- AND conditional fields SHALL appear based on apiType (e.g., mTLS cert reference for BRP, API key for BAG)

#### Scenario: Autorisatie upload page validates metadata structure

- GIVEN an admin uploads an autorisatie-besluit PDF
- WHEN the form is submitted
- THEN openconnector SHALL validate that all required metadata fields (autorisatieReferentie, geldigVan, geldigTot, toegestaneVelden, etc.) are present
- AND if validation fails, return a user-friendly error message
- AND if validation passes, persist the HaalCentraalAutorisatie row and store the PDF

### Requirement: mydash Integration

The mydash widget framework MUST expose per-source HaalCentraal metrics.

#### Scenario: mydash widget shows per-source metrics

- GIVEN mydash is configured for openconnector
- WHEN the HaalCentraal widget is displayed
- THEN it SHALL show per-source tables with columns: API type, requests/minute, requests/day, cache hit %, autorisatie expiry countdown, throttle queue depth, doelbinding violations/hour
