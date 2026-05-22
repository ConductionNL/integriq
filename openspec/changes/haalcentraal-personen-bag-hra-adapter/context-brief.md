---
status: draft
---
# HaalCentraal Personen/BAG/HR/KvK unified adapter

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Adapters > Adapter-catalogus (Overheid-NL) / Adapters

**Rationale:** Unified data-source adapter  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

HaalCentraal is the umbrella programme run by VNG Realisatie that exposes the Dutch national basisregistraties as modern REST/JSON APIs, replacing the legacy StUF-BG SOAP/XML stack that has dominated Dutch government integration for two decades. Four of those APIs cover almost every lookup a Conduction app ever has to make: BRP Persoonsgegevens (citizens), BAG (addresses and buildings), Handelsregister-HR (chamber-of-commerce data including non-public information), and the KvK Public API (the subset of HR data that needs no autorisatie). Today every Conduction app that needs to validate an address, look up a citizen by BSN, or check whether a KvK number is still active, ends up reinventing HTTP clients, OAuth2 token rotation, mTLS certificate handling, cache invalidation, doelbinding logging, and response-shape normalisation. This spec captures a single adapter — exposed through OpenConnector — that wraps all four APIs behind one configuration surface with shared caching, shared throttling, shared audit, and a consistent response shape so consuming apps can switch between them without touching their domain code.

The adapter is the second of three bedrock specs in this batch (after `digikoppeling-adapter` and before `digid-eherkenning-auth-adapter`). Unlike Digikoppeling, HaalCentraal does not use ebMS3 or PKIoverheid mTLS — it uses OAuth2 client-credentials with mTLS for the BRP variants (autorisatie via Logius / RvIG) and plain OAuth2 + API-key for the public KvK and BAG variants. The transport is therefore much lighter, but the autorisatie and doelbinding obligations are much stricter: every BRP call must be tied to a wettelijke grondslag (legal basis) and a doelbinding (purpose limitation), and every retrieval must be logged in a way that lets RvIG audit which Conduction installation pulled which BSN for which purpose. The adapter encapsulates that obligation so consuming apps cannot accidentally violate AVG / GDPR or the Wet BRP.

Out of scope: the adapter does not synthesise data, does not act as a write-back path (the basisregistraties are read-only for non-bronhouders), and does not implement the older StUF-BG SOAP profile. Apps that need StUF-BG for legacy reasons go through the future `stuf-bg-adapter` which composes on Digikoppeling. Out of scope also: the BRP Bevragingen 2.0 specific extensions for `partner`, `kinderen`, `ouders` deep relations are exposed as-is from the upstream API but the adapter does not flatten or re-shape the genealogical tree — consuming apps handle that themselves.

## Data Model

The adapter introduces five new OpenRegister schemas in a dedicated `openconnector-haalcentraal` register, plus uses two existing OpenConnector schemas (Source and Job).

**HaalCentraalSource** — the configuration for one of the four upstream APIs. Fields: `apiType` (enum: brp-personen / bag / handelsregister-hr / kvk-public), `baseUrl`, `apiVersion` (e.g., `v2`, `v1`), `authMode` (enum: oauth2-mtls / oauth2-bearer / api-key), `oauth2TokenUrl`, `oauth2ClientId`, `oauth2ClientSecretRef` (secret-store reference), `mtlsCertificateRef` (link to a DigikoppelingCertificate row reused for client mTLS — same PKIoverheid certificate infrastructure), `apiKeyRef`, `defaultTtlSeconds` (cache TTL, defaults: BRP 86400, BAG 2592000, HR 604800, KvK-public 604800), `maxRequestsPerMinute`, `maxRequestsPerDay`, `autorisatieReferentie` (the RvIG-issued autorisatie ID for BRP, identifying which fields and which BSN sets this source is allowed to read), `wettelijkeGrondslag` (default legal basis applied when the consuming job does not provide one), `enabled`.

**HaalCentraalQuery** — every request that hits an upstream API is persisted as a query row. Fields: `sourceRef`, `apiType`, `requestPath` (the URL path with placeholders replaced, e.g., `/ingeschrevenpersonen/123456789`), `requestMethod` (almost always GET), `requestFieldsRequested` (the `fields` query parameter — BRP and HR both use field-projection to enforce minimalisation), `responseStatus`, `responseBodyHash` (SHA-256 of the JSON body for cache integrity), `responseFromCache` (boolean), `cacheKey`, `cacheExpiresAt`, `latencyMs`, `triggeredByUser` (the Nextcloud user the call was made on behalf of), `triggeredByJobRef`, `wettelijkeGrondslag` (must be present for BRP queries), `doelbinding` (free-text purpose, e.g., "verificatie inschrijving subsidieaanvraag #12345"), `bsn` (encrypted at rest — only populated for BRP queries), `kvkNummer`, `bagId`, `errorMessage` (for failures), `createdAt`.

**HaalCentraalCacheEntry** — the actual cached responses. Separate schema from the query log so cache invalidation does not touch the audit trail. Fields: `cacheKey` (composite of `apiType`+`requestPath`+`fields`), `responseBody` (JSON), `responseHash`, `etag` (returned from upstream when supported), `cachedAt`, `expiresAt`, `hitCount`. Cache entries that exceed `expiresAt` are not deleted immediately — they are kept for one extra TTL cycle as a stale-while-revalidate fallback when upstream is down, then garbage-collected.

**HaalCentraalAutorisatie** — represents a single autorisatie granted by RvIG for the BRP. Fields: `sourceRef`, `autorisatieReferentie`, `geldigVan`, `geldigTot`, `toegestaneVelden` (array of BRP field paths the autorisatie covers, e.g., `naam.geslachtsnaam`, `verblijfplaats.adres`), `toegestaneBsnSet` (enum: alle / eigen-burgers-gemeente / specifieke-lijst, with optional CSV-imported BSN list for the third case), `doelOmschrijving`, `rechtsgrond` (the wettelijke grondslag, e.g., "art. 1.3 Wet BRP"), `documentatieRef` (link to the signed autorisatiebesluit PDF in Nextcloud Files). The adapter checks every outbound BRP query against the active autorisatie and rejects queries that request fields or BSNs outside the allowed set BEFORE the call leaves the Nextcloud installation.

**HaalCentraalAuditEvent** — append-only audit for autorisatie-relevant events. Fields: `eventType` (enum: query-allowed / query-rejected-by-autorisatie / query-rejected-by-doelbinding / cache-hit / cache-miss / upstream-error / autorisatie-loaded / autorisatie-expired), `queryRef`, `actor`, `timestamp`, `details`. Retention: minimum 5 years for BRP-related events (per Wet BRP), 1 year for BAG/HR/KvK queries.

The two reused schemas: **Source** (OpenConnector's generic source) is extended via a discriminator to point at a HaalCentraalSource for type=`haalcentraal`. **Job** is unchanged — HaalCentraal lookups are invoked as standard OpenConnector jobs with a job-type of `haalcentraal-query`.

## Requirements

### REQ-001: BRP Personen lookup by BSN
The adapter SHALL fetch a single persoon from the BRP Personen API by BSN, enforcing autorisatie and doelbinding.

**GIVEN** a configured BRP `HaalCentraalSource` with a valid `HaalCentraalAutorisatie`, and a consuming job that calls `getPersoonByBsn(bsn, fields, doelbinding, wettelijkeGrondslag)`
**WHEN** the adapter is invoked
**THEN** it checks that `bsn` is in the autorisatie's `toegestaneBsnSet` and that every requested field is in `toegestaneVelden`, looks up the cache by `cacheKey`, returns the cached response if `expiresAt > now()`, otherwise sends an OAuth2-mTLS GET to `{baseUrl}/ingeschrevenpersonen/{bsn}?fields={fields}`, persists the response in `HaalCentraalCacheEntry` with `expiresAt = now() + defaultTtlSeconds`, writes a `HaalCentraalQuery` row with the BSN encrypted, writes a `query-allowed` audit event, and returns the normalised response.

**GIVEN** a job calls `getPersoonByBsn` with a `bsn` outside the autorisatie's allowed set OR a `fields` value outside `toegestaneVelden`
**WHEN** the adapter evaluates the autorisatie check
**THEN** it does NOT send the request upstream, writes a `query-rejected-by-autorisatie` audit event with the reason, and returns a structured error containing the violated rule so the consuming app can show a friendly message to the user without leaking which BSN was attempted.

### REQ-002: BRP search by criteria
The adapter SHALL support the BRP `POST /personen` search endpoint with field projection.

**GIVEN** a job calls `searchPersonen({geslachtsnaam, geboortedatum, gemeenteVanInschrijving, ...}, fields, doelbinding)`
**WHEN** the adapter is invoked
**THEN** it sends a POST to `{baseUrl}/personen` with the criteria + `fields` array, the response (which is a list of up to N matching personen, each with the projected fields) is returned to the caller, each individual persoon in the response is cached separately by BSN so a subsequent `getPersoonByBsn` for one of them is served from cache, and the search itself is NOT cached (search criteria are too varied to make caching worthwhile, and caching searches would bypass doelbinding logging on subsequent searches).

### REQ-003: BAG address lookup
The adapter SHALL fetch addresses, panden, verblijfsobjecten, openbare ruimten, and woonplaatsen from the BAG API.

**GIVEN** a configured BAG `HaalCentraalSource` and a job that calls `getAddressByPostcodeHuisnummer(postcode, huisnummer, huisletter, toevoeging)`
**WHEN** the adapter is invoked
**THEN** it constructs `GET {baseUrl}/adressen?postcode={pc}&huisnummer={hn}&huisletter={hl}&huisnummertoevoeging={tv}`, returns the cached response if fresh (TTL default 30 days because BAG data is structurally stable), and on cache miss fetches from upstream, persists the cache entry, writes a `HaalCentraalQuery` row (no doelbinding requirement for BAG — public data), and returns the response.

**GIVEN** the upstream BAG API returns a 404 (address does not exist)
**WHEN** the adapter receives the 404
**THEN** it caches the 404 with a short TTL (default 1 hour) so a subsequent lookup for the same non-existent address does not hammer upstream, and returns a domain error `address-not-found` to the caller.

### REQ-004: Handelsregister HR lookup (non-public)
The adapter SHALL fetch full HR data including non-public fields by KvK number or vestigingsnummer, using KvK-issued OAuth2 credentials.

**GIVEN** a configured HR `HaalCentraalSource` with valid OAuth2 credentials and an active KvK abonnement
**WHEN** a job calls `getInschrijvingByKvkNummer(kvkNummer, fields)`
**THEN** the adapter performs the client-credentials OAuth2 token flow (with token caching for the token's lifetime minus a 5-minute safety margin), sends a GET to the HR endpoint, caches the response with TTL 7 days (HR data changes regularly enough that longer caches risk staleness), persists a query log row with `kvkNummer`, and returns the response. The adapter SHALL NOT enforce a doelbinding check on HR queries (HR is a commercial register, not a personenregister), but SHALL log the calling user and job for billing reconciliation against the KvK abonnement.

### REQ-005: KvK Public lookup
The adapter SHALL fetch the public subset of HR data via the KvK Public API for use cases that do not justify the cost or autorisatie burden of a full HR abonnement.

**GIVEN** a configured KvK-public `HaalCentraalSource` with an API key and a job that calls `getPublicInschrijving(kvkNummer)`
**WHEN** the adapter is invoked
**THEN** it sends a GET to the KvK Public endpoint with the API key in the header, caches the response for 7 days, persists a query log row, and returns the response. If both an HR source and a KvK-public source are configured, the adapter SHALL prefer the public source for queries that only request public fields, falling back to HR when non-public fields are explicitly requested.

### REQ-006: Cache invalidation and stale-while-revalidate
The adapter SHALL support per-cache-entry invalidation, bulk invalidation by type, and stale-while-revalidate behaviour when upstream is down.

**GIVEN** an admin or a downstream event (e.g., a BAG change notification subscription) triggers cache invalidation for a specific entry
**WHEN** the invalidation is received
**THEN** the cache entry's `expiresAt` is set to `now()` (but the entry is retained as stale), the next request for that key fetches fresh data from upstream, and if upstream is unavailable the stale entry is returned with a `Warning: 110 - Response is stale` flag on the response so the caller can decide whether to accept it.

**GIVEN** the upstream API returns a 5xx or times out
**WHEN** the adapter has a stale cache entry available (expired but within one extra TTL cycle)
**THEN** the stale entry is returned with the stale flag, a `cache-hit-stale` audit event is written, and the failed upstream call is recorded as `upstream-error` for monitoring.

### REQ-007: Throttling
The adapter SHALL enforce per-source request rate limits to stay within the upstream API's contractual ceiling.

**GIVEN** a source configured with `maxRequestsPerMinute=600` and `maxRequestsPerDay=100000`
**WHEN** the consuming jobs exceed those limits
**THEN** excess requests are queued (with a per-source FIFO queue) and released as the rate window opens, jobs waiting longer than 30 seconds for a slot receive a structured `throttled` error so they can decide whether to retry later or escalate, and the `mydash` widget shows the current queue depth per source.

### REQ-008: Autorisatie lifecycle
The adapter SHALL load, validate, and monitor BRP autorisaties so a consuming app can never make a call that the autorisatie does not cover.

**GIVEN** an admin uploads an autorisatie-besluit (PDF + structured metadata) via the OpenConnector admin UI
**WHEN** the autorisatie is stored
**THEN** the structured fields are persisted as a `HaalCentraalAutorisatie` row, the PDF is attached as a Nextcloud Files reference, an `autorisatie-loaded` audit event is written, and from that point onwards every BRP query is validated against the active autorisatie.

**GIVEN** an autorisatie's `geldigTot` has passed
**WHEN** the daily autorisatie-check job runs
**THEN** an `autorisatie-expired` audit event is written, every BRP query against the associated source fails immediately with a clear error pointing at the renewal procedure, and a notification is sent to the `haalcentraal_admin` group 30 days before expiry as a reminder.

### REQ-009: Response normalisation
The adapter SHALL expose a normalised response shape for each apiType that hides upstream version differences from consuming apps.

**GIVEN** the BRP Personen API has a `v1` and a `v2` endpoint with subtly different response shapes (v2 introduces `geheimhouding` as a structured object instead of a boolean, restructures `verblijfplaats`)
**WHEN** the adapter returns a persoon response
**THEN** the returned shape conforms to the adapter's documented normalised schema regardless of which upstream version is configured on the source, version-specific quirks are translated by an internal adapter layer, and the upstream raw response is also available on `_raw` for apps that need it.

### REQ-010: Doelbinding registry
The adapter SHALL maintain a registry of acceptable doelbinding strings so consuming apps cannot invent freeform purposes that bypass governance review.

**GIVEN** an admin configures the registry with a list of pre-approved doelbinding entries (each linked to a wettelijke grondslag and an approving role)
**WHEN** a job submits a BRP query with a doelbinding NOT in the registry
**THEN** the adapter rejects the query with a `query-rejected-by-doelbinding` audit event UNLESS the calling job belongs to a Nextcloud user in the `doelbinding_freevorm` group (intended for ad-hoc casework where the purpose cannot be pre-registered), in which case the freeform doelbinding is logged verbatim for later audit review.

## Standards & Sources

- VNG Realisatie — HaalCentraal API specifications (BRP Personen Bevragen 2.0, BAG 2.0, HR 1.x, KvK Public).
- Forum Standaardisatie — REST-API Design Rules + ADR (API Design Rules), the basis for VNG's HaalCentraal style.
- RvIG (Rijksdienst voor Identiteitsgegevens) — Logisch Ontwerp BRP + Autorisatie-besluit format.
- Wet BRP (Wet basisregistratie personen) — artikel 1.3 (rechten), artikel 3.2 (verstrekkingen), artikel 3.9 (logging).
- AVG / GDPR — art. 5 (doelbinding, minimalisering), art. 6 (rechtsgrond), art. 30 (verwerkingsregister).
- Kadaster — BAG Catalogus 2018 (the canonical BAG data model).
- KvK — Handelsregisterwet 2007 + Handelsregisterbesluit; KvK API gebruiksvoorwaarden.
- BIO — toegangsbeveiliging en logging maatregelen.
- OAuth 2.0 (RFC 6749), OAuth 2.0 Mutual-TLS Client Authentication (RFC 8705).
- Logius — gebruik van PKIoverheid-certificaten in OAuth2-mTLS scenario's (zelfde PvE als digikoppeling-adapter).

## Cross-app integration

- **openconnector base** — adapter ships inside openconnector, reuses Source/Job, and surfaces in the standard admin UI as a discriminated source type.
- **digikoppeling-adapter** — shares the PKIoverheid certificate schema and certificate-management machinery; the mTLS client cert for BRP-OAuth2 is the same PKIoverheid certificate that a digikoppeling-adapter setup already manages.
- **procest** — workflow steps can drop in a "HaalCentraal lookup" action with type=BRP/BAG/HR/KvK and the doelbinding pre-filled from the procest's case context (the case itself documents the wettelijke grondslag).
- **zaakafhandelapp** — every zaak that involves a citizen pulls the BRP record at intake; this is where the bulk of BRP volume comes from, and the per-zaak doelbinding is automatic.
- **opencatalogi** — can describe an organisation's HaalCentraal autorisaties as catalogi-entries for transparency and accountability.
- **docudesk** — address fields on outgoing documents are validated against BAG before the document is signed.
- **softwarecatalog** — supplier organisaties in the software catalogue are validated against HR/KvK Public.
- **openregister** — provides the schemas, the file-attachment for autorisatie PDFs, the BSN-at-rest encryption, and the append-only audit-event enforcement.
- **mydash** — shows per-source request volume, cache hit rate, autorisatie countdown, throttling queue depth, and doelbinding violation count.

## Target users

- **NL gemeenten** — every municipality needs BRP-Personen (their core obligation), BAG (every address validation), and HR/KvK (every aanvraag from a company). This is the single highest-volume adapter in the fleet.
- **Uitvoeringsorganisaties** — UWV, SVB, DUO, RVO, RDW, CJIB, all consume BRP at scale.
- **Waterschappen, provincies, ministeries** — similar consumption pattern but lower volume.
- **Semi-publieke organisaties met BRP-autorisatie** — pensioenfondsen, woningcorporaties, zorgverzekeraars in beperkte mate.
- **Conduction app teams** — every Conduction app that processes a Dutch citizen or company touches one of these four APIs; this adapter prevents each team from re-implementing OAuth2-mTLS and doelbinding handling.
- **Privacy officers en FG'ers** — the doelbinding registry, the per-query audit log, and the autorisatie validator give the FG a one-stop verwerkingsregister entry per consuming app.
- **Operations / SRE** — the rate-limit queues, the stale-while-revalidate cache, and the per-source dashboard make BRP/BAG/HR availability a first-class operational concern rather than a per-app concern.
