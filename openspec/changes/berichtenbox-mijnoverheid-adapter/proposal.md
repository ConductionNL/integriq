# Proposal: Berichtenbox MijnOverheid Adapter

## Summary

Consolidate Berichtenbox integration for all Conduction apps into a first-class OpenConnector adapter. Eliminates per-app bespoke integrations by providing a single, spec-compliant, auditable koppelvlak that supports both SOAP/WUS (Aanleveren-2.1.5, Notificaties-1.1) and the upcoming REST koppelvlak. Enables formal government correspondence delivery to citizens via MijnOverheid (BSN) and legal persons via Mijn Zaken voor Ondernemers (KvK/OIN) with full lifecycle tracking, e-mail fallback handling, and 7-year audit retention.

## Motivation

Today every Conduction app needing to send formal post (decidesk, shillinq, zaakafhandelapp, procest, scholiq) either builds its own Berichtenbox integration or falls back to physical mail/e-mail. This is wasteful, inconsistent, and dangerous from a compliance perspective:
- Each app independently satisfies the Berichtenbox koppelvlak specification, OIN-based mutual TLS, message-type taxonomy, retention rules, and audit logging
- No shared knowledge of read-receipt handling or rate-limiting best practices
- Legal and compliance burden spread across multiple codebases with no audit consolidation
- Businesses (procest, shillinq) have no consistent channel to legal persons (KvK/OIN)

This adapter consolidates all of that in one place within OpenConnector, the canonical integration hub. Apps call a clean OpenConnector job; the adapter translates to/from the Berichtenbox koppelvlak, handles authentication, rate-limiting, and failure modes, and publishes lifecycle events back as first-class notifications.

## Affected Projects

- [x] Project: `openconnector` — Adapter implementation, webhook receiver, CallLog integration
- [x] Project: `decidesk` — Consume `openconnector.send('berichtenbox', beschikking)` for decision delivery
- [x] Project: `shillinq` — Consume `openconnector.send('berichtenbox', invoice)` for citizen invoices; fall back to Peppol for businesses
- [x] Project: `procest` — Send VTH-beschikkingen via Berichtenbox; use `bericht.gelezen` event to start bezwaartermijn clock
- [x] Project: `zaakafhandelapp` — Send all informeren-type correspondence linked to zaak; use referentie for round-trip linking
- [x] Project: `scholiq` — Send school correspondence (verzuim, inschrijving) to ouders' Berichtenbox
- [x] Project: `mydash` — Surface aggregated Berichtenbox throughput, delivery times, fallback rates per OIN/per app

## Scope

### In Scope

- Outbound `Bericht` envelope definition (OpenConnector schema in shared openconnector register)
- SOAP/WUS Aanleveren-2.1.5 koppelvlak implementation with OIN-based mTLS authentication
- REST koppelvlak support (Logius pilot v1) behind feature flag
- Inbound webhook receiver at `/apps/openconnector/api/incoming/berichtenbox/{sourceId}` for Notificaties-1.1 callbacks
- Status translation: Logius notifications → `Berichtstatus` objects → OpenConnector events
- Voorkeurskanaal (heeftBerichtenbox) checking with 24h caching to stay under Logius rate limits
- Bedrijven addressing (KvK/OIN) via Mijn Zaken voor Ondernemers koppelvlak
- Rate-limiting and backoff (10 req/s sustained, 100 burst per OIN; exponential backoff on 429)
- 6-year retention period and 5-day e-mail fallback handling as first-class events
- Auditable end-to-end logging in OpenConnector CallLog with PII redaction (BSN masked)
- 7-year retention for Berichtenbox traffic per Archiefwet, separate from 90-day default
- Test/preprod environment isolation with environment headers and certificate validation

### Out of Scope

- DRX (digital request/response) handling beyond the generic responseRequired flag
- Message encryption beyond OIN-mTLS mutual authentication
- Custom message templates or internationalization beyond Dutch/English support
- Per-app email fallback configuration (uses Logius default 5-day unread threshold)
- Manual certificate renewal UI (operators use NextcloudVault existing flows)
- Historical data migration from legacy per-app integrations (greenfield only)

## Approach

1. **Schema definition** in shared openconnector register: `Bericht`, `Berichtstatus`, `BerichtAfleverkanaal` published as Tier-4 manifest entries so consumers reference via `{ref: "openconnector:Bericht"}`
2. **Adapter implementation** as OpenConnector Job type (`berichtenbox`) that translates between `Bericht` envelope and SOAP/REST koppelvlak
3. **Authentication** via OIN-bound PKIoverheid certificate per Source, validated on save, checked on each send
4. **Webhook receiver** at published endpoint that consumes Logius Notificaties callbacks, translates to `Berichtstatus`, dispatches `openconnector.bericht.*` events
5. **CallLog integration** for full audit trail with PII redaction
6. **Feature flag** (`berichtenbox.koppelvlak`) to route between SOAP and REST without consumer changes
7. **Rate-limiter** shared with other OpenConnector jobs, respecting Logius 10 req/s + 100 burst per OIN
8. **Test isolation** via Source.environment and certificate validation preventing accidental production sends from dev/test

## New Dependencies

- `phpunit/phpunit` (dev, testing mocks and SOAP translation)
- `guzzlehttp/guzzle` (HTTP client for REST koppelvlak pilot, optional behind feature flag)
- OpenConnector's existing `calllog`, `event` tables and `Source` configuration tables

## Impact

**Code affected:**
- `openconnector/lib/Service/Adapters/BerichtenboxAdapter.php` (new)
- `openconnector/lib/Service/Adapters/WebhookReceivers/BerichtenboxReceiver.php` (new)
- `openconnector/lib/Db/CallLog*` (new columns for berichtenbox-specific metadata)
- `openconnector` register manifest (new Tier-4 schemas)

**APIs affected:**
- New OpenConnector Job type: `openconnector.send('berichtenbox', $bericht)` (new consumer API)
- New webhook endpoint: `POST /apps/openconnector/api/incoming/berichtenbox/{sourceId}` (new receiver)
- New OpenConnector events: `openconnector.bericht.delivered`, `.gelezen`, `.unaddressable`, `.email_fallback_verstuurd`, `.awaiting_response` (new notifications)

**Systems affected:**
- Logius Berichtenbox koppelvlak (outbound sends, inbound webhooks)
- Logius Mijn Zaken voor Ondernemers koppelvlak (KvK/OIN addressing)
- OpenConnector CallLog (audit trail)
- Consumer apps (decidesk, shillinq, procest, zaakafhandelapp, scholiq) — event-driven updates

**Data affected:**
- Three new schemas in openconnector register, with retention per Archiefwet

## Cross-Project Dependencies

- **openconnector**: Adapter runs as OpenConnector Job; consumes Source, CallLog, Event infrastructure
- **decidesk**: Listens for `openconnector.bericht.delivered` event to update zaakstatus
- **shillinq**: Calls `openconnector.send('berichtenbox', invoice)` for citizen invoices; falls back to other adapters for businesses/physical mail
- **procest**: Calls adapter for VTH-beschikkingen; listens for `bericht.gelezen` to start bezwaartermijn clock
- **zaakafhandelapp**: Calls adapter for all informeren-type correspondence linked to zaak
- **scholiq**: Calls adapter for school correspondence to ouders
- **mydash**: Aggregates Berichtenbox metrics from CallLog

## Risks

### Risk 1: Certificate expiration blocks all sends
**Severity:** High — **Mitigation:** Cache certificate validity in memory; on expiry, fail fast with `BerichtenboxError.CERTIFICATE_EXPIRED`, log at critical severity, dispatch alert to admin Talk room via existing openconnector alerting hook. Require manual certificate rotation via NextcloudVault before retry.

### Risk 2: Logius API changes break adapter mid-flight
**Severity:** High — **Mitigation:** Feature flag on `Source.configuration.koppelvlak` allows simultaneous SOAP + REST; deploy REST support before Logius deprecates SOAP. Route selection by `Source.priority` is transparent to consumers.

### Risk 3: Rate-limit miscalculation floods Logius with retries
**Severity:** High — **Mitigation:** Token-bucket limiter shared with other OpenConnector jobs; test at Logius preprod with sustained 200-msg burst before production. Exponential backoff (2s, 4s, 8s, capped 60s) on 429; never drop message.

### Risk 4: Orphan webhook callbacks (berichtId not in database) cause manual dead-letter cleanup
**Severity:** Medium — **Mitigation:** Webhook receiver rejects unknown berichtId with HTTP 409, writes to OpenConnector dead-letter queue. Monitoring on dead-letter growth triggers escalation. Idempotent status updates prevent duplicate event dispatch on retry.

### Risk 5: Audit trail becomes too large within 7-year retention
**Severity:** Medium — **Mitigation:** Partition CallLog by year; retention job explicitly skips berichtenbox rows when deleting < 7 years old. Monitor CallLog growth; if exceeds threshold, archive to cold storage (future ADR).

### Risk 6: PII leakage in CallLog (full BSN, email, phone in SOAP/JSON payloads)
**Severity:** High — **Mitigation:** All CallLogBody entries mask BSN to XXX-XX-XXXX format before insert; email/phone hashed for audit compliance. Admin UI never displays full PII. Redaction tested in unit tests.

## Rollback Strategy

1. **Immediate**: Disable `Source.active` for all berichtenbox sources; this prevents new sends. Consumers fall back to configured fallback adapters (email, physical mail).
2. **Short-term**: Remove webhook receiver endpoint; redirect inbound Logius callbacks to error log. Already-sent messages' statuses freeze at last known state.
3. **Full**: Remove Berichtenbox Job type from OpenConnector; delete adapter code. CallLog rows and `Bericht`/`Berichtstatus` objects persist per 7-year retention policy for audit.

No schema changes to consumer apps needed; they already have fallback adapter chain configured.

## Open Questions

1. **PKIoverheid certificate distribution**: Does Nextcloud have a standardized HSM/vault for storing Private Services Server certificates, or do operators upload to NextcloudVault? Answer affects certificate rotation UX.
2. **Logius preprod webhook URL**: Does Logius preprod expose the same webhook endpoint path, or different host? Affects test isolation validation.
3. **BerichtAfleverkanaal cache TTL**: Is 24h aggressive enough given Logius's own cache (unknown to us), or should it be shorter per Source configuration? Risk of stale BSN→heeftBerichtenbox mappings.
4. **Event ordering on overlapping status updates**: If Logius sends `afgeleverd` and `gelezen` callbacks in rapid succession, do we guarantee strict order in CallLog and event dispatch? Or accept eventual consistency?
