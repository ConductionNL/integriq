# Berichtenbox Adapter Specification

**Status**: planned  
**Scope**: openconnector  
**OpenSpec changes**: berichtenbox-mijnoverheid-adapter

## Purpose

Provide a consolidated, auditable OpenConnector adapter that translates outbound `Bericht` envelopes into Logius Berichtenbox/Mijn Zaken voor Ondernemers koppelvlak calls and inbound Notificaties callbacks into first-class `Berichtstatus` events. Eliminates per-app bespoke integrations while maintaining spec compliance, rate-limiting discipline, and 7-year audit retention per Dutch law.

Ref: [proposal.md](proposal.md), [design.md](design.md)

## ADDED Requirements

### REQ-001: OIN-based mTLS authentication

The adapter MUST authenticate all outbound SOAP and REST requests to the Berichtenbox koppelvlak using a PKIoverheid Private Services Server certificate bound to the Source OIN, and MUST validate certificate validity and expiry on every send.

#### Scenario: Valid certificate sends successfully
- GIVEN a `Source` of type `berichtenbox` with `oin = "00000001003214345000"` and a valid, non-expired PKIoverheid certificate loaded from NextcloudVault
- WHEN the adapter sends a `Bericht`
- THEN the outbound SOAP/REST request includes that certificate in the TLS handshake and the OIN appears in the WS-Addressing `From` header (SOAP) or `X-OIN` request header (REST)
- AND a `CallLog` row is created with `oin = "00000001003214345000"` and `tls_certificate_thumbprint = <hash>`

#### Scenario: Expired certificate fails fast
- GIVEN a `Source` configured with an expired PKIoverheid certificate
- WHEN the adapter attempts to send a `Bericht`
- THEN the send fails immediately with error code `BerichtenboxError.CERTIFICATE_EXPIRED` (before any network call)
- AND a `CallLog` entry is created with `response_code = 999` (client-side error), `error_message = "Certificate expired on 2025-05-01"`
- AND the failure is logged at severity `critical`
- AND a notification is dispatched to the configured admin Talk room via openconnector's existing alerting hook

#### Scenario: Wrong OIN in certificate fails
- GIVEN a `Source` with `oin = "00000001003214345000"` but a certificate bound to a different OIN `00000001003214345999`
- WHEN the adapter attempts to send
- THEN the validation fails on `Source` save with error message "Certificate OIN does not match Source OIN"
- AND no `CallLog` is created (configuration error, not runtime)

#### Scenario: Production vs preprod isolation
- GIVEN a `Source` configured with `environment = "test"` and a production PKIoverheid certificate
- WHEN the adapter attempts to send
- THEN the send is rejected with error code `BerichtenboxError.CERTIFICATE_MISMATCH_ENVIRONMENT`
- AND the error message states "Certificate is production, but Source environment is test"

### REQ-002: Outbound message send via SOAP/WUS koppelvlak

The adapter MUST translate a complete `Bericht` object into a valid `aanleverenRequest` per Aanleveren-2.1.5 WSDL, and MUST validate all attachments and size limits before any network call.

#### Scenario: Valid beschikking with PDF attachment serializes correctly
- GIVEN a complete `Bericht` with:
  - `berichtType = "beschikken"`
  - `onderwerp = "Toekenning uitkering"`
  - `bericht = <HTML body, 500 bytes>`
  - One attachment: `{naam: "beschikking.pdf", mimeType: "application/pdf", inhoud: "base64-encoded PDF/A-2"}`
- WHEN `BerichtenboxAdapter::send()` is called
- THEN the SOAP envelope contains:
  - `<Berichtkenmerk>` element with `Bericht.kenmerk`
  - `<BerichtBetreft>` element with `Bericht.onderwerp`
  - `<MimeBerichtInhoud>` element with base64-encoded HTML body
  - `<Bijlage>` element with base64-encoded PDF and declared `mimeType = "application/pdf"`
- AND the envelope is valid according to Logius XSD schema (validateable by external tool)
- AND a `CallLog` entry is created with the entire request (redacted of PII) and `response_code = 200`

#### Scenario: Non-PDF-A attachment rejected client-side
- GIVEN a `Bericht` with an attachment `{naam: "scan.pdf", mimeType: "application/pdf", inhoud: "base64-encoded standard PDF (not PDF/A)"}`
- WHEN the adapter attempts to send
- THEN the send fails immediately (before mTLS handshake) with error code `BerichtenboxError.INVALID_ATTACHMENT_FORMAT`
- AND the error message includes the specific failing attachment name: "Attachment 'scan.pdf' is not PDF/A-1 or PDF/A-2"
- AND a `CallLog` entry is created with `response_code = 999` and the error details

#### Scenario: Oversized attachment rejected client-side
- GIVEN a `Bericht` with one 30MB PDF attachment
- WHEN the adapter attempts to send
- THEN the send fails immediately with error code `BerichtenboxError.ATTACHMENT_TOO_LARGE`
- AND the error message states "Attachment exceeds 25MB limit: 30MB"

#### Scenario: Total payload exceeds limit rejected client-side
- GIVEN a `Bericht` with `bericht` field = 2MB (exceeds 1MB limit)
- WHEN the adapter attempts to send
- THEN the send fails immediately with error code `BerichtenboxError.BODY_TOO_LARGE`
- AND the error message states "Body size 2MB exceeds 1MB limit"

### REQ-003: REST koppelvlak fallback

The adapter MUST support the upcoming REST/JSON Berichtenbox koppelvlak (Logius pilot v1) via feature flag, without requiring changes to consumer apps.

#### Scenario: Feature flag selects REST transport
- GIVEN `Source.configuration.koppelvlak = "rest-v1"` and a valid OAuth2 client-credentials key pair stored in NextcloudVault
- WHEN the adapter sends a `Bericht`
- THEN the adapter performs an OAuth2 client-credentials token exchange at the Logius auth endpoint
- AND a POST request is sent to `https://.../v1/berichten` (endpoint varies by Source.environment) with JSON payload:
  ```json
  {
    "afzenderOIN": "00000001003214345000",
    "geadresseerde": {"type": "bsn", "waarde": "123456789"},
    "berichtType": "beschikken",
    ...
  }
  ```
- AND the response JSON is parsed and mapped onto the same `Berichtstatus` schema as the SOAP path produces
- AND a `CallLog` entry is created with `koppelvlak = "rest-v1"` and the response code

#### Scenario: Both koppelvlakken configured, routing by priority
- GIVEN two Sources for the same OIN:
  - Source 1: `koppelvlak = "soap"`, `priority = 10`
  - Source 2: `koppelvlak = "rest-v1"`, `priority = 1`
- WHEN a consumer app calls `openconnector.send('berichtenbox', $bericht)` with `afzenderOIN` matching both sources
- THEN the adapter routes to Source 1 (higher priority) using SOAP
- AND if Source 1 returns HTTP 5xx, the adapter retries once on Source 2 (REST)
- AND the consumer app receives no indication of transport fallover (fully transparent)

### REQ-004: Voorkeurskanaal (heeftBerichtenbox) check

The adapter MUST consult the Berichtenbox.heeftBerichtenbox() service before sending and gracefully handle citizens without an active Berichtenbox.

#### Scenario: BSN has active Berichtenbox, send succeeds
- GIVEN a `Bericht` addressed to BSN `123456789` with `geadresseerde.type = "bsn"`
- WHEN the adapter checks `Berichtenbox.heeftBerichtenbox(123456789)` and receives `true`
- THEN the send proceeds normally
- AND a `BerichtAfleverkanaal` cache entry is created with `bsn_or_kvk = "123456789"`, `heeft_berichtenbox = true`, `voorkeurskanaal_checked = <now>`

#### Scenario: BSN has no Berichtenbox, unaddressable error recorded
- GIVEN a `Bericht` addressed to BSN `987654321` with no active Berichtenbox
- WHEN the adapter checks `Berichtenbox.heeftBerichtenbox(987654321)` and receives `false`
- THEN a `Berichtstatus` is created with:
  - `status = "geweigerd"`
  - `foutCode = "NO_BOX"`
  - `details = {}`
- AND the adapter dispatches an `openconnector.bericht.unaddressable` event so the originating app can fall back to physical mail or email
- AND the send is not attempted to Logius (client-side rejection)

#### Scenario: Cached voorkeurskanaal result reused within 24h
- GIVEN a `BerichtAfleverkanaal` cache entry for BSN `123456789` with `voorkeurskanaal_checked = <2 hours ago>`
- WHEN the adapter processes a second `Bericht` to the same BSN
- THEN the adapter skips the live check and immediately uses the cached `heeft_berichtenbox` result
- AND no network call to Logius `Berichtenbox.heeftBerichtenbox()` is made
- AND a `CallLog` entry is created with `cache_hit = true`

#### Scenario: Cached result expires after 24h, live check performed
- GIVEN a `BerichtAfleverkanaal` cache entry for BSN `123456789` with `voorkeurskanaal_checked = <25 hours ago>`
- WHEN the adapter processes a `Bericht` to that BSN
- THEN the adapter ignores the stale cache and calls Logius `Berichtenbox.heeftBerichtenbox()` again
- AND the cache entry is updated with the fresh result and new timestamp

#### Scenario: KvK addressing uses Mijn Zaken voor Ondernemers
- GIVEN a `Bericht` with `geadresseerde.type = "kvk"` and `kvk = "12345678"`
- WHEN the adapter checks for box availability
- THEN the adapter calls `BerichtenboxVoorBedrijven.heeftBox(12345678)` instead of the BSN endpoint
- AND the resulting send routes through the Mijn Zaken voor Ondernemers koppelvlak
- AND a `BerichtAfleverkanaal` entry is created with `adressering_type = "kvk"` and the KvK number

#### Scenario: OIN addressing uses direct OIN-on-OIN profile
- GIVEN a `Bericht` with `geadresseerde.type = "oin"` and `oin = "00000001003214345999"`
- WHEN the adapter processes the send
- THEN the adapter routes via the Digikoppeling OIN-on-OIN mTLS profile (both sender and receiver authenticate via OIN certificates)
- AND no voorkeurskanaal check is performed (OIN targets are always reachable)

### REQ-005: Status webhooks and notification ingestion

The adapter MUST expose a webhook endpoint that receives Notificaties-1.1 SOAP callbacks from Logius and translates them into `Berichtstatus` objects with corresponding OpenConnector events.

#### Scenario: BerichtAfgeleverd notification creates delivered status
- GIVEN a Logius `BerichtAfgeleverd` callback for a known `berichtId = "550e8400-e29b-41d4-a716-446655440001"`
- WHEN received at `/apps/openconnector/api/incoming/berichtenbox/{sourceId}` and mTLS certificate is validated
- THEN a `Berichtstatus` is persisted with:
  - `berichtId = "550e8400-e29b-41d4-a716-446655440001"`
  - `status = "afgeleverd"`
  - `tijdstip = <callback timestamp>`
  - `details = {}`
- AND the endpoint responds with HTTP 200 and a SOAP response confirming receipt
- AND an `openconnector.bericht.delivered` event is dispatched with payload:
  ```json
  {
    "event": "openconnector.bericht.delivered",
    "berichtId": "550e8400-e29b-41d4-a716-446655440001",
    "referentie": "BESLUIT-2025-001234",
    "tijdstip": "2025-06-15T10:30:00Z"
  }
  ```
- AND consumer apps (decidesk, shillinq, etc.) listening for this event can react

#### Scenario: BerichtGelezen notification with responseRequired triggers awaiting-response event
- GIVEN a `Bericht` was sent with `responseRequired = true`
- AND a Logius `BerichtGelezen` callback is received for that `berichtId`
- WHEN processed
- THEN a `Berichtstatus` is created with `status = "gelezen"`
- AND two events are dispatched:
  - `openconnector.bericht.gelezen`
  - `openconnector.bericht.awaiting_response` (because responseRequired=true)
- AND the original app (e.g., procest) receives the awaiting_response event and can start a bezwaartermijn clock

#### Scenario: Unknown berichtId returns 409 to dead-letter queue
- GIVEN a Logius callback for `berichtId = "99999999-9999-9999-9999-999999999999"` that does not match any known `Bericht`
- WHEN received at the webhook endpoint
- THEN the adapter responds with HTTP 409 Conflict
- AND the orphan callback is written to the OpenConnector dead-letter queue for manual inspection
- AND NO `Berichtstatus` is created (orphan data rejected)
- AND a `CallLog` entry is created with `error_code = 409` and the offending berichtId

#### Scenario: Invalid mTLS certificate rejects callback
- GIVEN a callback from an untrusted TLS certificate (not signed by Staat der Nederlanden CA)
- WHEN received at the webhook endpoint
- THEN the adapter responds with HTTP 401 Unauthorized
- AND the request is not processed further
- AND a `CallLog` entry is created with `response_code = 401` and the error details

### REQ-006: Retention and e-mail fallback handling

The adapter MUST respect the 6-year Berichtenbox retention period and the 5-day "unread → e-mail fallback" behavior, surfacing both as first-class events.

#### Scenario: Email fallback notification after 5 days unread
- GIVEN a `Bericht` was delivered but not read for 5 days
- AND Logius pushes an `EmailFallbackVerstuurd` callback with `{berichtId, emailAdres_hashed, afgeleverdViaEmail: true}`
- WHEN the adapter processes the callback
- THEN a `Berichtstatus` is created with:
  - `status = "email_fallback_verstuurd"`
  - `tijdstip = <Logius timestamp>`
  - `details = {emailAdresHashed: "...", reason: "unread_5_days"}`
- AND an `openconnector.bericht.email_fallback_verstuurd` event is dispatched
- AND the originating app receives the event and can adjust its dunning/reminder strategy (e.g., don't send another reminder if already in email)

#### Scenario: Expiration after 6 years triggers retention event
- GIVEN a `Bericht` was delivered and exists in Berichtenbox for 6 years
- AND Logius pushes a `BerichtVerlopen` callback
- WHEN processed
- THEN a `Berichtstatus` is created with `status = "verlopen"`
- AND an `openconnector.bericht.expired` event is dispatched
- AND the originating app triggers archival/cleanup for its own records (body retained in app, not in box)

#### Scenario: Attachment retention override respected
- GIVEN a `Bericht` was sent with `attachmentRetentionDays = 30`
- AND 30 days have passed since delivery
- WHEN a nightly retention job runs
- THEN the adapter MUST call `BerichtenboxVerwijderBijlagen()` to delete attachments server-side while keeping metadata in OpenRegister
- AND a `CallLog` entry is created with the deletion confirmation

### REQ-007: Auditable end-to-end logging

Every Berichtenbox interaction MUST be logged in OpenConnector's `CallLog` and `CallLogBody` tables with PII redaction, enabling full audit trails.

#### Scenario: Outbound send logged with redacted PII
- GIVEN a `Bericht` with BSN `123456789`, email `john@example.com`, phone `0612345678`
- WHEN the adapter sends the `Bericht`
- THEN a `CallLog` row is created with:
  - `job_type = "berichtenbox"`
  - `bericht_id = <UUID>`
  - `bericht_kenmerk = "REFUSAL-2025-001"`
  - `geadresseerde_type = "bsn"`
  - `oin = "00000001003214345000"`
  - `response_code = 200`
- AND a corresponding `CallLogBody` is created with the SOAP/JSON payload where:
  - BSN is redacted to `XXX-XX-XXXX` (first 3 + last 2 digits visible)
  - Email is hashed to SHA256: `hash(john@example.com)`
  - Phone is hashed to SHA256: `hash(0612345678)`
- AND the original unredacted data is never stored in the database

#### Scenario: Admin can view audit timeline
- GIVEN multiple CallLog entries for a single `berichtId`:
  - `send` (request + response)
  - `afgeleverd` (webhook callback)
  - `gelezen` (webhook callback)
- WHEN an admin queries the OpenConnector CallLog UI for that `berichtId`
- THEN all entries are returned in chronological order with:
  - Timestamps for each step
  - Response codes (200, 200, 200)
  - Redacted payloads (BSN masked, email/phone hashed)
- AND the admin can trace the full lifecycle without seeing raw PII

#### Scenario: 7-year retention enforced for Berichtenbox
- GIVEN a `CallLog` entry for a Berichtenbox send dated 2018-06-15
- WHEN the nightly retention job runs on 2025-06-20
- THEN the entry is NOT deleted (7 years = 2025-06-15)
- AND when the job runs again on 2025-06-16, the entry IS deleted

#### Scenario: Non-Berichtenbox entries respect 90-day default
- GIVEN a `CallLog` entry for another job type (e.g., `koppeling`) dated 2025-03-15
- WHEN the nightly retention job runs on 2025-06-15 (>90 days old)
- THEN the entry IS deleted per default retention policy
- AND Berichtenbox entries are unaffected by this batch delete

### REQ-008: Rate-limit and backoff handling

The adapter MUST respect Logius rate limits (10 req/s sustained, 100 burst per OIN) and back off intelligently without losing messages.

#### Scenario: Token-bucket limits throughput to 10 req/s
- GIVEN a burst of 200 outbound Berichtenbox sends queued at once, all with the same OIN `00000001003214345000`
- WHEN the adapter processes them
- THEN the rate limiter throttles to ≤ 10 req/s per OIN using a token-bucket algorithm
- AND the remaining messages are queued in-memory with a backoff, not dropped
- AND all 200 messages eventually send within ~20 seconds (200 msgs ÷ 10 req/s)
- AND OpenConnector's existing queue guarantees no loss (if process crashes, queue persists)

#### Scenario: 429 response triggers exponential backoff
- GIVEN a send returns HTTP 429 (Too Many Requests) from Logius
- WHEN the adapter receives this response
- THEN the message is requeued with an exponential backoff:
  - 1st retry: wait 2 seconds
  - 2nd retry: wait 4 seconds
  - 3rd retry: wait 8 seconds
  - 4th+ retries: wait 60 seconds (capped)
- AND the message is never dropped; it will retry until success
- AND a `CallLog` entry is created with `response_code = 429` and `retry_count = 0` (next attempt increments it)

#### Scenario: Sustained outage detected and paused
- GIVEN 5 consecutive sends return HTTP 5xx (500, 502, 503, 504, 503)
- WHEN the 5th error is recorded
- THEN the adapter pauses the entire queue for the affected OIN
- AND an `openconnector.source.degraded` event is dispatched
- AND a notification is sent to the admin Talk room
- AND the adapter begins periodic probes (e.g., every 30 seconds) to check if Logius recovers
- AND once a probe succeeds (2xx response), the queue resumes automatically

### REQ-009: Bedrijven (KvK/OIN) addressing

The adapter MUST support sending to legal persons via the Mijn Zaken voor Ondernemers koppelvlak when addressing by KvK or OIN.

#### Scenario: KvK message routes via Mijn Zaken endpoint
- GIVEN a `Bericht` with `geadresseerde.type = "kvk"` and `kvk = "12345678"`
- WHEN the adapter sends
- THEN the outbound request routes to the Mijn Zaken voor Ondernemers koppelvlak endpoint (not standard Berichtenbox)
- AND the `Source.configuration.koppelvlak_bedrijven` URL is used (separate from citizen endpoint)
- AND the OIN-mTLS mutual authentication is still performed (both sender and receiver authenticate)

#### Scenario: OIN-to-OIN message uses direct profile
- GIVEN a `Bericht` with `geadresseerde.type = "oin"` and `oin = "00000001003214345999"` (sender OIN is different)
- WHEN the adapter sends
- THEN the adapter routes via the Digikoppeling OIN-on-OIN profile
- AND both parties authenticate via OIN-bound certificates (mutual TLS with OIN validation)
- AND no voorkeurskanaal check is performed (OINs are always reachable)

#### Scenario: KvK not enrolled in Mijn Zaken returns unaddressable
- GIVEN a `Bericht` with `geadresseerde.type = "kvk"` and `kvk = "87654321"` (not yet enrolled)
- WHEN the adapter checks `BerichtenboxVoorBedrijven.heeftBox(87654321)` and receives `false`
- THEN a `Berichtstatus` is created with `status = "geweigerd"`, `foutCode = "NO_BOX_KVK"`
- AND the send is rejected client-side

### REQ-010: Test/Preprod isolation

The adapter MUST prevent accidental production sends from dev/test environments via environment-aware configuration and certificate validation.

#### Scenario: Non-production environment forced to preprod endpoint
- GIVEN `Source.environment = "test"` and `Source.configuration.koppelvlak = "soap"`
- WHEN the adapter sends a `Bericht`
- THEN the outbound URL is forced to the preprod Logius endpoint: `https://wus-preprod-mo.procura.nl/...` (not production)
- AND an `X-Environment: test` header is added to the request for Logius logging
- AND a `CallLog` entry is created with `environment = "test"` for traceability

#### Scenario: Production certificate in test environment rejected
- GIVEN a `Source` with `environment = "test"` and a PKIoverheid certificate bound to production
- WHEN the adapter attempts to send
- THEN validation fails with error code `BerichtenboxError.CERTIFICATE_MISMATCH_ENVIRONMENT`
- AND the error message explicitly states "Production certificate cannot be used in test environment"
- AND no `CallLog` is created (configuration error on save, not runtime)

#### Scenario: UI warning for dev instances
- GIVEN an OpenConnector instance running in a Nextcloud with `config.systemTag = "dev"`
- WHEN a user adds a new Berichtenbox `Source`
- THEN the UI displays a persistent amber banner: "This is a development instance. Ensure you are using a test certificate and preprod endpoints."
- AND the Source creation UI defaults `environment` to `test` with a required confirmation to change to production
- AND if the user attempts to select `environment = "production"` with a non-production cert, validation fails

## Non-Functional Requirements

- **Performance:** A send + delivery notification cycle (send request → Logius response → webhook callback → event dispatch) MUST complete within 5 seconds under normal load. Target p95 < 2 seconds per send.
- **Reliability:** Messages queued for send MUST be retried indefinitely (with exponential backoff) until success; no silent failures.
- **Compliance:** All Berichtenbox CallLog entries MUST be retained for 7 years per Archiefwet. PII (BSN, email, phone) MUST be masked/hashed in all logged payloads.
- **Accessibility:** Not applicable (no user-facing UI).
- **Internationalization:** Dutch and English MUST be supported in error messages and event payloads per ADR-007. Admin UI defaults to Dutch.

## Acceptance Criteria

- [ ] All 10 requirements pass scenario testing (unit + integration)
- [ ] CallLog entries for 50+ outbound sends with various `berichtType` values
- [ ] Webhook receiver handles 50+ status callbacks without data loss or duplicate events
- [ ] PII redaction verified: BSN masked, email/phone hashed in CallLog
- [ ] Rate-limiter tested: 200-message burst throttles to ≤ 10 req/s
- [ ] Exponential backoff verified: 429 responses trigger backoff sequence
- [ ] Certificate validation tested: expired/mismatched OIN/wrong environment all rejected
- [ ] Voorkeurskanaal cache: 24h TTL verified, live check on expiry
- [ ] 7-year retention: Berichtenbox CallLog excluded from 90-day purge
- [ ] Attachment validation: non-PDF-A, oversized, invalid MIME types all rejected client-side
- [ ] SOAP serialization passes Logius XSD validation (external tool verification)
- [ ] REST path routing verified: feature flag selects transport without consumer changes
- [ ] Bedrijven (KvK/OIN) addressing tested: different endpoints used, voorkeurskanaal skipped for OIN
- [ ] Environment isolation: preprod URL forced for test sources, prod cert rejected in test
- [ ] All manual testing against acceptance criteria documented in test-plan.md

## Notes

- **Standards**: Logius Berichtenbox Aanleveren-2.1.5 WSDL, Notificaties-1.1 WSDL, REST v1 (pilot), Digikoppeling 3.5, PKIoverheid, BIO, Archiefwet 1995
- **Related ADRs**: [openconnector ADR-003: CallLog as primary observability](../../../architecture/adr-003-calllog-primary-observability-surface.md), [ADR-005: Source Synchronization Contract](../../../architecture/adr-005-source-synchronization-contract-triad.md), [ADR-013: Event Bus](../../../architecture/adr-013-event-bus-model.md)
- **Consumer integration**: Decidesk (zaakstatus updates), Shillinq (invoice delivery), Procest (bezwaartermijn), Zaakafhandelapp (correspondence), Scholiq (school notices), Mydash (metrics)
- **Open question**: Is Logius preprod webhook URL identical to production (just different host), or completely different endpoint? Affects test isolation strategy.
