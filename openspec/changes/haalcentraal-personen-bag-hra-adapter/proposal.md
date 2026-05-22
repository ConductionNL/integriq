# HaalCentraal Personen/BAG/HR/KvK unified adapter

## Why

Dutch municipalities and government agencies today reinvent OAuth2-mTLS client credentials, 
certificate rotation, request throttling, response caching, and legal-basis logging every 
time they consume a VNG Realisatie HaalCentraal API — whether BRP Personen (citizens), BAG 
(addresses), Handelsregister (company non-public data), or KvK Public (company public data).

This creates risk: 
- Each app implements its own autorisatie validation, risking GDPR / Wet BRP violations 
  if a BSN outside the allowed set is queried.
- Each app implements its own doelbinding logging, making it impossible for a privacy 
  officer to audit who queried which BSN for which purpose.
- Each app solves OAuth2 token rotation and mTLS cert handling independently, 
  multiplying configuration surfaces and support burden.
- Conduction operators manage identical rate-limit queues, cache invalidation, and stale-while-revalidate 
  fallbacks per app instead of fleet-wide.

The HaalCentraal adapter centralizes all four APIs (BRP Personen, BAG, HR, KvK-public) 
behind a single openconnector source type with shared caching, shared throttling, shared 
audit, and a consistent response shape. The adapter enforces autorisatie and doelbinding 
BEFORE the call leaves the Nextcloud installation, making it impossible for consuming 
apps to violate the legal framework. Every query is logged in an immutable audit trail 
that RvIG can inspect for compliance.

The adapter ships as the second of three bedrock specs in this batch (after `digikoppeling-adapter` 
and before `digid-eherkenning-auth-adapter`).

## What

### New OpenRegister Schemas

Five new schemas in a dedicated `openconnector-haalcentraal` register:

- **HaalCentraalSource**: Configuration for one of the four upstream APIs (BRP-Personen, 
  BAG, Handelsregister-HR, KvK-public). Fields include `apiType`, `baseUrl`, `authMode` 
  (oauth2-mtls, oauth2-bearer, api-key), OAuth2 token URL and credentials, mTLS certificate 
  reference, API key, cache TTL, rate limits, autorisatie reference, wettelijke grondslag, 
  and enabled flag.

- **HaalCentraalQuery**: Write-once audit log of every upstream query. Fields include 
  source reference, API type, request path, method, fields requested, response status, 
  cache key, latency, user context, job reference, wettelijke grondslag, doelbinding, 
  encrypted BSN (BRP only), KvK/BAG identifiers, error message, and timestamp.

- **HaalCentraalCacheEntry**: Cached upstream responses. Fields include cache key, response 
  body, response hash, etag, cache timestamp, expiry, and hit count. Entries exceed TTL 
  are retained as stale-while-revalidate fallback for one extra cycle before garbage collection.

- **HaalCentraalAutorisatie**: A single autorisatie granted by RvIG for BRP access. Fields 
  include source reference, autorisatie ID, validity dates, allowed field paths, allowed 
  BSN set (all / own-municipality-citizens / specific-list), purpose, wettelijke grondslag, 
  and PDF reference to the signed autorisatiebesluit.

- **HaalCentraalAuditEvent**: Append-only compliance audit. Fields include event type 
  (query-allowed, query-rejected-by-autorisatie, query-rejected-by-doelbinding, cache-hit, 
  cache-miss, upstream-error, autorisatie-loaded, autorisatie-expired), query reference, 
  actor, timestamp, and details. Retention: 5 years for BRP, 1 year for BAG/HR/KvK.

### API Methods

The adapter exposes the following methods through OpenConnector:

- **BRP Personen lookup by BSN** — `getPersoonByBsn(bsn, fields, doelbinding, wettelijkeGrondslag)`. 
  Enforces autorisatie (checks BSN in allowed set, checks fields in allowed projection). 
  Returns cached response if fresh, otherwise OAuth2-mTLS fetch from upstream, persists 
  cache and query log, writes audit event.

- **BRP search by criteria** — `searchPersonen(criteria, fields, doelbinding)`. POST to 
  upstream `/personen`. Results cached individually by BSN for follow-up lookups. Search 
  itself not cached (criteria too varied). Each individual result wrapped in a separate 
  query log row.

- **BAG address lookup** — `getAddressByPostcodeHuisnummer(postcode, huisnummer, huisletter, 
  toevoeging)`. No autorisatie requirement (public data). TTL default 30 days (BAG stable). 
  404 responses cached short-term (1 hour) to avoid hammering non-existent addresses.

- **Handelsregister HR lookup** — `getInschrijvingByKvkNummer(kvkNummer, fields)`. OAuth2 
  client-credentials (non-mTLS). TTL 7 days. No doelbinding check but logs calling user 
  and job for KvK abonnement billing reconciliation.

- **KvK Public lookup** — `getPublicInschrijving(kvkNummer)`. API-key auth. TTL 7 days. 
  Public-subset only. Preferred when only public fields requested, falls back to full HR 
  when non-public fields explicitly requested.

### Cache Invalidation and Stale-While-Revalidate

- Per-cache-entry invalidation (set `expiresAt = now()`, retain for one extra TTL cycle 
  as stale fallback).
- Bulk invalidation by type (e.g., all BAG entries after a BAG change notification).
- Stale-while-revalidate fallback when upstream is down (return stale entry with 
  `Warning: 110 - Response is stale` flag).

### Throttling

- Per-source request rate limits (minute and daily ceiling).
- Excess requests queued FIFO; requests waiting >30 seconds receive structured `throttled` 
  error; optional retry policy left to caller.
- Queue depth visible in mydash widget.

### Autorisatie Lifecycle and Validation

- Admin uploads autorisatie-besluit (PDF + structured metadata) via OpenConnector admin UI.
- Structured fields persisted as HaalCentraalAutorisatie row; PDF attached as Nextcloud 
  Files reference.
- Every BRP query validated against active autorisatie before upstream call.
- Expiry monitoring: 30-day warning notification, automatic rejection on `geldigTot` passed.

### Response Normalisation

- Normalised response shape for each apiType hides upstream version differences.
- BRP v1 vs v2 field quirks (e.g., `geheimhouding` structure, `verblijfplaats` shape) 
  transparently translated.
- Raw upstream response available on `_raw` for introspection.

### Doelbinding Registry

- Admin-configurable registry of pre-approved doelbinding strings.
- Each entry linked to wettelijke grondslag and approving role.
- BRP queries with doelbinding NOT in registry rejected unless calling user in 
  `doelbinding_freevorm` group (ad-hoc casework exemption, logged for later audit review).

## Capabilities

### New Capabilities

- `haalcentraal-brp-personen`: BRP Personen adapter — lookup by BSN, search by criteria, 
  field projection, autorisatie enforcement, doelbinding logging, OAuth2-mTLS, cache with 
  stale-while-revalidate, rate-limiting, write-once audit trail.

- `haalcentraal-bag`: BAG adapter — address lookup by postcode + huisnummer, cache with 
  30-day TTL, 404 caching, no autorisatie burden.

- `haalcentraal-hr`: Handelsregister adapter — KvK-number lookup, non-public data, 
  OAuth2 (non-mTLS), TTL 7 days, no doelbinding check, usage logging for billing.

- `haalcentraal-kvk-public`: KvK Public adapter — public-subset lookup, API-key auth, 
  TTL 7 days, preferred when public fields only.

## Affected Repos

- openconnector (primary)
- openregister (schemas: HaalCentraalSource, HaalCentraalQuery, HaalCentraalCacheEntry, 
  HaalCentraalAutorisatie, HaalCentraalAuditEvent; BSN-at-rest encryption; append-only 
  audit-event enforcement)
- digikoppeling-adapter (shares PKIoverheid certificate schema and management)
- procest (workflow steps can pre-fill doelbinding from case context)
- zaakafhandelapp (automatic per-zaak doelbinding on citizen intake)
- opencatalogi (transparency: organisation autorisaties as catalog entries)
- docudesk (address validation against BAG before document signing)
- softwarecatalog (supplier validation against HR/KvK Public)
- mydash (per-source dashboard: volume, cache hit rate, autorisatie countdown, 
  throttle queue, doelbinding violations)

## References

### Upstream Standards & Sources

- **VNG Realisatie** — HaalCentraal API specifications (BRP Personen Bevragen 2.0, BAG 2.0, 
  HR 1.x, KvK Public)
- **Forum Standaardisatie** — REST-API Design Rules + ADR (the basis for VNG HaalCentraal style)
- **RvIG** — Logisch Ontwerp BRP + Autorisatie-besluit format
- **Wet BRP** — artikel 1.3 (rights), artikel 3.2 (retrievals), artikel 3.9 (logging)
- **AVG / GDPR** — art. 5 (doelbinding, minimalization), art. 6 (legal basis), art. 30 
  (processing register)
- **Kadaster** — BAG Catalogus 2018 (canonical BAG data model)
- **KvK** — Handelsregisterwet 2007 + Handelsregisterbesluit; KvK API terms
- **BIO** — access security and logging measures
- **OAuth 2.0** (RFC 6749), **OAuth 2.0 Mutual-TLS Client Authentication** (RFC 8705)
- **Logius** — PKIoverheid certificate usage in OAuth2-mTLS scenarios (same PvE as 
  digikoppeling-adapter)

### Sibling Specs

- `digikoppeling-adapter` — precursor, shares PKIoverheid certificate infrastructure
- `digid-eherkenning-auth-adapter` — follow-on in the bedrock batch

## Out of Scope

- **Data synthesis**: The adapter does not synthesize data or reshape genealogical 
  relations (partner, kinderen, ouders deep relations from BRP Bevragingen 2.0 are 
  exposed as-is; consuming apps handle flattening/re-shaping).
- **Write-back paths**: The basisregistraties are read-only for non-bronhouders.
- **Legacy StUF-BG SOAP profile**: Apps needing StUF-BG for backwards compatibility 
  use a future `stuf-bg-adapter` (composes on digikoppeling).
- **Per-app migration**: Migration of zaakafhandelapp / decidesk / pipelinq to use the 
  adapter is a separate per-app spec; this change provides the adapter only.
- **Configurable circuit breaker / cache TTL thresholds**: Hardcoded in this release; 
  admin UI knobs added in a follow-up if needed.
