# Design: haalcentraal-personen-bag-hra-adapter

## Class Structure

### HaalCentraalConnector (`lib/Connectors/HaalCentraalConnector.php`)

Implements openconnector's existing connector contract interface. Constructor injects 
`IClientService`, `ICache`, `LoggerInterface`, `EncryptionService`, and `IQueryBuilder` 
(for query audit logging).

Key methods:

| Method | Signature | Purpose |
|--------|-----------|---------|
| `getPersoonByBsn` | `getPersoonByBsn(string $bsn, array $fields = [], string $doelbinding = '', string $grondslag = ''): array\|Error` | Lookup BRP person by BSN with autorisatie + doelbinding enforcement |
| `searchPersonen` | `searchPersonen(array $criteria, array $fields = [], string $doelbinding = ''): array` | POST search to BRP `/personen` endpoint; results cached individually by BSN |
| `getAddressByPostcodeHuisnummer` | `getAddressByPostcodeHuisnummer(string $postcode, int $huisnummer, string $huisletter = '', string $toevoeging = ''): array\|Error` | BAG address lookup by postal code + house number |
| `getInschrijvingByKvkNummer` | `getInschrijvingByKvkNummer(string $kvkNummer, array $fields = []): array` | HR full data lookup (non-public); requires OAuth2 token |
| `getPublicInschrijving` | `getPublicInschrijving(string $kvkNummer): array` | KvK Public lookup; preferred for public-only queries |
| `enforceAutorisatie` | `enforceAutorisatie(HaalCentraalAutorisatie $aut, array $requestedFields, string $bsn = ''): bool\|Error` | Validate BRP query against active autorisatie before upstream call |
| `cacheGet` | `cacheGet(string $cacheKey): array\|null` | Fetch from cache (APCu) if fresh; return null if expired or unavailable |
| `cachePut` | `cachePut(string $cacheKey, array $response, int $ttl): void` | Store response in cache with specified TTL |
| `throttleCheck` | `throttleCheck(HaalCentraalSource $source): bool\|Error` | Check rate limits before request; queue if needed; error if queue-wait >30s |
| `logQuery` | `logQuery(HaalCentraalQuery $query): void` | Write query audit log row (write-once) |
| `logAuditEvent` | `logAuditEvent(HaalCentraalAuditEvent $event): void` | Append audit event (write-once, immutable) |
| `normalizeResponse` | `normalizeResponse(array $raw, string $apiType, string $apiVersion): array` | Translate upstream version quirks into canonical response shape |

### HaalCentraalController (`lib/Controller/HaalCentraalController.php`)

REST endpoint dispatcher. Constructor injects `HaalCentraalConnector` and `SourceService`.

Action methods: `persoonByBsnAction`, `searchPersonenAction`, `addressAction`, 
`inschrijvingByKvkAction`, `publicInschrijvingAction`. All use `requireLogin()`. 
Parameter validation returns 400 on missing required params. Each action delegates to 
`HaalCentraalConnector` and returns `JSONResponse`.

### HaalCentraalSourceService (`lib/Service/HaalCentraalSourceService.php`)

Manages HaalCentraalSource configuration. Methods:

| Method | Purpose |
|--------|---------|
| `loadByApiType` | Fetch active source by apiType (brp-personen / bag / hr / kvk-public) |
| `getAutorisatie` | Fetch active HaalCentraalAutorisatie for a BRP source |
| `validateSourceConfiguration` | Check that auth credentials, URLs, and autorisatie are present and non-expired |
| `refreshOAuth2Token` | Invoke client-credentials OAuth2 flow; cache token for its lifetime minus 5-min safety margin |

### HaalCentraalCacheService (`lib/Service/HaalCentraalCacheService.php`)

Cache management. Methods:

| Method | Purpose |
|--------|---------|
| `buildCacheKey` | Compute `{apiType}::{requestPath}::{fields_sha256}` |
| `getCacheEntry` | Fetch HaalCentraalCacheEntry if `expiresAt > now()`; return null if expired or stale |
| `putCacheEntry` | Store HaalCentraalCacheEntry with `cachedAt = now()` and `expiresAt = now() + ttl` |
| `staleEntry` | Fetch expired-but-within-one-extra-TTL-cycle entry for stale-while-revalidate |
| `invalidateByKey` | Set `expiresAt = now()` on specific entry (retain for stale fallback) |
| `invalidateByType` | Set `expiresAt = now()` on all entries matching `apiType` |
| `cleanExpired` | Garbage-collect entries where `expiresAt < now() - ttl` |

### HaalCentraalThrottleService (`lib/Service/HaalCentraalThrottleService.php`)

Rate-limit enforcement. Methods:

| Method | Purpose |
|--------|---------|
| `checkLimit` | Check per-source minute and daily ceilings; return bool (pass/fail) or enqueue |
| `enqueue` | FIFO queue entry for source; wait up to 30s; error if timeout |
| `dequeue` | Release waiting request when rate window opens |
| `getQueueDepth` | Return current queue length per source (for mydash widget) |

### HaalCentraalAutorisatieService (`lib/Service/HaalCentraalAutorisatieService.php`)

Autorisatie lifecycle. Methods:

| Method | Purpose |
|--------|---------|
| `loadAutorisatie` | Parse uploaded autorisatie-besluit PDF and structured metadata; create HaalCentraalAutorisatie row; emit `autorisatie-loaded` event |
| `validateAutorisatie` | Check BSN in `toegestaneBsnSet`; check requested fields in `toegestaneVelden`; return bool or detailed error |
| `checkExpiry` | Daily job: emit `autorisatie-expired` event when `geldigTot` passed; send 30-day warning notification |
| `getActiveAutorisatie` | Fetch non-expired HaalCentraalAutorisatie for source |

### HaalCentraalDoelbindingService (`lib/Service/HaalCentraalDoelbindingService.php`)

Doelbinding registry. Methods:

| Method | Purpose |
|--------|---------|
| `loadRegistry` | Fetch all registered doelbinding entries from config |
| `validateDoelbinding` | Check doelbinding in registry; allow freeform if user in `doelbinding_freevorm` group |
| `listRegistry` | Return all approved doelbinding entries for UI dropdown |

## Route Registration

Five GET routes in `appinfo/routes.php`:

```
GET /api/haalcentraal/persoon/{bsn}               → HaalCentraalController::persoonByBsnAction
POST /api/haalcentraal/personen                   → HaalCentraalController::searchPersonenAction
GET /api/haalcentraal/adres                       → HaalCentraalController::addressAction
GET /api/haalcentraal/inschrijving-kvk/{kvkNr}   → HaalCentraalController::inschrijvingByKvkAction
GET /api/haalcentraal/inschrijving-kvk-public/{kvkNr} → HaalCentraalController::publicInschrijvingAction
```

All routes use `requireLogin()`. No CORS OPTIONS routes (same-origin Nextcloud calls).

## DI Registration

Register all service classes in `lib/AppInfo/Application.php`. Use constructor injection 
via `IServerContainer::get()` closures. Add entries to the integration registry (ADR-019) 
for each adapter variant (brp-personen, bag, hr, kvk-public).

## OAuth2 Token Caching

**For BRP (mTLS)** and **HR (bearer)**:
- Compute cache key: `haalcentraal_oauth2_token::{sourceId}`
- On first call or cache miss: invoke client-credentials flow (POST to `oauth2TokenUrl` 
  with `client_id`, `client_secret`, and mTLS client cert)
- Cache token for `expires_in - 300` seconds (5-min safety margin)
- Refresh transparently before expiry on next request

**mTLS Client Certificate**:
- Reference same PKIoverheid certificate row used by digikoppeling-adapter
- Load cert + private key at adapter init; inject into `IClientService`
- No per-request cert setup (inefficient); cert bound to connector instance

## Response Normalization Logic

Each `apiType` has version-specific quirks. The connector ships an internal `normalize()` 
method per apiType:

### BRP v1 vs v2 differences:

| Field | v1 | v2 | Canonical |
|-------|----|----|-----------|
| `geheimhouding` | boolean | structured object | object (per v2) |
| `verblijfplaats.inwonerDeel` | top-level field | nested under `adresnummeraanduiding` | top-level field (per v1) |
| `naam.voorvoegsel` | absent | present | include in v1 responses if absent |

The normalize layer absorbs these differences; consuming apps see a stable contract 
regardless of configured upstream version.

## Seed Data

### Example HaalCentraalSource entries (for design review, not persisted in seed):

```json
{
  "id": 1,
  "apiType": "brp-personen",
  "baseUrl": "https://brp-api.logius.nl/api/v2",
  "apiVersion": "v2",
  "authMode": "oauth2-mtls",
  "oauth2TokenUrl": "https://authenticatie-api.logius.nl/digid/oauth/token",
  "oauth2ClientId": "gemeente-tilburg",
  "mtlsCertificateRef": 42,
  "defaultTtlSeconds": 86400,
  "maxRequestsPerMinute": 600,
  "maxRequestsPerDay": 100000,
  "autorisatieReferentie": "RvIG-AUTO-2024-00123",
  "wettelijkeGrondslag": "art. 1.3 Wet BRP",
  "enabled": true
}
```

```json
{
  "id": 2,
  "apiType": "bag",
  "baseUrl": "https://api.bag.basisregistraties.overheid.nl/edr/bevragen/v2",
  "apiVersion": "v2",
  "authMode": "api-key",
  "apiKeyRef": "bag-api-key-vault-ref",
  "defaultTtlSeconds": 2592000,
  "maxRequestsPerMinute": 1000,
  "maxRequestsPerDay": 500000,
  "enabled": true
}
```

```json
{
  "id": 3,
  "apiType": "handelsregister-hr",
  "baseUrl": "https://kvk.apis.data.amsterdam.nl/hr",
  "apiVersion": "v1",
  "authMode": "oauth2-bearer",
  "oauth2TokenUrl": "https://auth.kvk.nl/oauth/token",
  "oauth2ClientId": "gemeente-tilburg-hr",
  "defaultTtlSeconds": 604800,
  "maxRequestsPerMinute": 300,
  "maxRequestsPerDay": 50000,
  "enabled": true
}
```

### Example HaalCentraalAutorisatie (BRP):

```json
{
  "id": 1,
  "sourceRef": 1,
  "autorisatieReferentie": "RvIG-AUTO-2024-00123",
  "geldigVan": "2024-01-01",
  "geldigTot": "2026-12-31",
  "toegestaneVelden": [
    "burgerservicenummer",
    "naam.voornamen",
    "naam.geslachtsnaam",
    "geboorte.datum",
    "geboorte.plaats",
    "verblijfplaats.adres",
    "verblijfplaats.postcode",
    "verblijfplaats.woonplaatsnaam"
  ],
  "toegestaneBsnSet": "alle",
  "doelOmschrijving": "Gemeente Tilburg burgerzaken intake",
  "rechtsgrond": "art. 1.3 Wet BRP",
  "documentatieRef": "nc-file-id:12345"
}
```

### Example BRP Persoon Response (normalized):

```json
{
  "burgerservicenummer": "123456789",
  "naam": {
    "voornamen": "Jan",
    "geslachtsnaam": "Jansen",
    "voorvoegsel": "van",
    "naamgebruik": "eigen"
  },
  "geboorte": {
    "datum": "1980-05-20",
    "plaats": "Amsterdam"
  },
  "verblijfplaats": {
    "adres": "Lauriergracht 31",
    "postcode": "1016 RH",
    "woonplaatsnaam": "Amsterdam"
  },
  "_raw": { ... original upstream v2 response ... }
}
```

### Example BAG Address Response (normalized):

```json
{
  "bagAddressId": "0363010000000001",
  "bagBuildingId": "0363100000000001",
  "postcode": "1016 RH",
  "houseNumber": "31",
  "houseLetter": "",
  "houseNumberAddition": "",
  "streetAddress": "Lauriergracht",
  "addressLocality": "Amsterdam",
  "addressRegion": "Noord-Holland",
  "displayName": "Lauriergracht 31, 1016 RH Amsterdam",
  "location": {
    "type": "Point",
    "coordinates": [4.88525, 52.37025]
  },
  "_raw": { ... original upstream response ... }
}
```

### Example KvK/HR Inschrijving Response (normalized):

```json
{
  "kvkNummer": "12345678",
  "vestigingsnummer": "000000000001",
  "handelsnaam": "Acme BV",
  "rechtsvorm": "Besloten vennootschap",
  "datumAanvang": "2015-03-20",
  "isActief": true,
  "adres": {
    "straat": "Lauriergracht",
    "huisnummer": "31",
    "postcode": "1016 RH",
    "plaats": "Amsterdam",
    "land": "Nederland"
  },
  "_raw": { ... original upstream response ... }
}
```

## Observability and Logging

### HaalCentraalQuery Log Rows

Persisted for every upstream call (cache hits do not generate log rows). Fields: 
`sourceRef`, `apiType`, `requestPath`, `requestMethod`, `requestFieldsRequested`, 
`responseStatus`, `responseBodyHash`, `responseFromCache`, `cacheKey`, `cacheExpiresAt`, 
`latencyMs`, `triggeredByUser`, `triggeredByJobRef`, `wettelijkeGrondslag` (BRP only), 
`doelbinding` (BRP/HR contextual), `bsn` (encrypted, BRP only), `kvkNummer`, `bagId`, 
`errorMessage`, `createdAt`.

### HaalCentraalAuditEvent Log (Append-Only)

Events: 
- `query-allowed` — BSN/fields passed autorisatie check, upstream call made
- `query-rejected-by-autorisatie` — request outside autorisatie scope (BSN or field not allowed)
- `query-rejected-by-doelbinding` — doelbinding not in registry and user not in freevorm group
- `cache-hit` — response served from APCu (BRP/BAG/HR/KvK)
- `cache-hit-stale` — stale cache served due to upstream 5xx
- `cache-miss` — cache miss, upstream called
- `upstream-error` — upstream returned 5xx or timed out
- `upstream-rate-limit` — HTTP 429 received; retry scheduled
- `autorisatie-loaded` — new autorisatie uploaded by admin
- `autorisatie-expired` — autorisatie `geldigTot` passed; BRP queries now rejected

### Structured Log Entries (ADR-003 / CallLog)

One CallLog entry per upstream HTTP call (per ADR-003, all outbound HTTP goes through 
CallService). Fields logged include: endpoint, cache_status (hit/miss/stale), 
upstream_latency_ms, http_status, circuit_breaker_state, throttle_queue_depth.

## Admin UI Integration

- **Source admin page**: Standard openconnector source admin UI; HaalCentraalSource 
  discriminator handles BRP/BAG/HR/KvK variant switching.
- **Autorisatie admin page**: File upload (PDF + structured metadata) for 
  HaalCentraalAutorisatie rows; metadata fields pre-filled from PDF scan (RvIG 
  autorisatie-besluit has standard structure).
- **Doelbinding registry admin page**: Table of approved doelbinding entries; 
  link to wettelijke grondslag per row; approver role per row.
- **mydash widget**: Per-source dashboard showing current request volume (req/min, 
  req/day), cache hit %, autorisatie expiry countdown, throttle queue depth, 
  doelbinding violation count (rejections per hour).

## Encryption

BSN fields in HaalCentraalQuery rows are encrypted at rest using EncryptionService 
(ADR-016, per Wet BRP art. 3.9). All other PII (names, addresses, KvK numbers) are 
stored in plaintext in query log and cache entry bodies — consuming apps are responsible 
for further encryption if needed beyond the app-level protections that Nextcloud 
provides.

HaalCentraalAuditEvent rows do NOT encrypt BSN (the logs must be readable for RvIG 
audit; if a specific query was rejected, the audit trail names the reason without 
leaking the attempted BSN itself).

## Cache Invalidation Strategy

**Invalidation triggers**:
1. Admin manual cache clear via mydash widget
2. BAG change-notification subscription (future upstream feature)
3. Per-entry expiry sweep (daily cron job)
4. Stale entry cleanup (daily cron job)

**Stale-while-revalidate**:
- Entry `expiresAt` passed but within one extra TTL: return with `Warning: 110 - Response is stale` header
- Upstream unavailable (5xx): log `upstream-error` and return stale entry
- On stale return, a background refresh is triggered (non-blocking) to re-populate cache 
  before the next stale cycle elapses

## Rate-Limit and Throttling Implementation

Per-source FIFO queue in Redis (or volatile memory if Redis unavailable). Each queued 
request holds: `sourceRef`, `requestPath`, `enqueuedAt`, `enqueuingUser`. Backoff 
calculation per source:
```
now_req_count = requests_in_last_minute
remaining_capacity = source.maxRequestsPerMinute - now_req_count
if remaining_capacity > 0: allow immediately
else: enqueue; wait until next request slot opens (1 minute window slides)
```

Daily limit checked separately; once daily ceiling reached, all excess requests are 
rejected (not queued) with a structured error indicating retry after 24h.

Requests queued longer than 30 seconds receive a `throttled` error; caller decides 
whether to retry exponentially or abandon.
