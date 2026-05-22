---
status: draft
---
# Berichtenbox MijnOverheid Adapter

## Purpose

Provide a first-class OpenConnector adapter that allows any Conduction app (or any external system integrated through OpenConnector) to deliver formal government correspondence to Dutch citizens via the MijnOverheid Berichtenbox, and to receive lifecycle events (delivered, read, expired, fallback-sent) back into the originating app. The Berichtenbox is the official secure inbox operated by Logius on behalf of the Dutch government and is the legal equivalent of paper mail for citizens who have opted in. For municipalities, executive agencies (UWV, SVB, RDW, Belastingdienst, DUO), water boards and other public bodies it is increasingly the default channel for informing, deciding and invoicing.

Today every Conduction app that needs to send formal post to a citizen (decidesk for besluiten, shillinq for invoices, zaakafhandelapp for zaak-correspondence, procest for VTH-beschikkingen, scholiq for school correspondence) has to either build its own Berichtenbox integration or fall back to physical mail/e-mail. That is wasteful, inconsistent, and dangerous from a compliance perspective because each app would then have to independently satisfy the Berichtenbox koppelvlak specification, OIN-based mutual TLS, message-type taxonomy, retention rules, read-receipt handling, and audit logging.

This adapter consolidates all of that in one place. It exposes a clean OpenConnector job (mapped onto the canonical `Bericht`/`Notificatie` schemas in OpenRegister) and translates outbound objects into the Berichtenbox WUS/SOAP koppelvlak (currently `Aanleveren-2.1.5` and `Notificaties-1.1`) or the upcoming REST-based koppelvlak that Logius is piloting. Inbound notifications (delivered, opened, expired, e-mail fallback executed) flow back into OpenRegister as `Berichtstatus` objects and are dispatched as OpenConnector events so each consumer app can react (close the zaak, mark the invoice as delivered, trigger a herinnering job, etc.).

The adapter must also cover the parallel channel for legal persons: `Mijn Zaken voor Ondernemers` / `Berichtenbox voor Bedrijven`, addressed by KvK or OIN instead of BSN, because shillinq and procest send to companies more often than to citizens.

## Data Model

The adapter operates on three canonical schemas that live in the shared `openconnector` register (so other apps can consume them without depending on this adapter directly):

- `Bericht` — outbound message envelope. Fields: `id` (UUID), `afzenderOIN` (string, OIN of the public body), `geadresseerde` (object: `{type: "bsn"|"kvk"|"oin", waarde: string}`), `berichtType` (enum: `informeren`, `beschikken`, `factureren`, `attenderen`), `onderwerp` (string ≤200 chars), `bericht` (HTML body, max 1MB), `bijlagen` (array of `{naam, mimeType, inhoud}` — only PDF/A-1, PDF/A-2, JPEG, PNG; max 25MB total), `referentie` (string, used in audit trail), `kenmerk` (string, vrij veld voor afzender), `publicatiedatum` (date, optional future-dated), `vervalDatum` (date, calculated default = publicatiedatum + 6 years), `responseRequired` (bool — does this require digital response via Mijn Berichten reply form), `attachmentRetentionDays` (int, default 90), `notificatieAdresseringsType` (enum: `standaard`, `geen` — for cases where the sender already notifies via another channel).
- `Berichtstatus` — inbound status events. Fields: `berichtId` (FK), `status` (enum: `aangeboden`, `afgeleverd`, `gelezen`, `verlopen`, `email_fallback_verstuurd`, `notificatie_verstuurd`, `notificatie_mislukt`, `geweigerd`), `tijdstip` (ISO8601), `details` (object, may include `emailAdres` for fallback, `foutCode` for refusals).
- `BerichtAfleverkanaal` — addressee resolution cache. Fields: `bsnOrKvk`, `heeftBerichtenbox` (bool), `voorkeurskanaalChecked` (timestamp), `notificatieEmail` (hashed), `notificatieMobiel` (hashed). Cached for ≤ 24h to avoid hammering the `Berichtenbox.heeftBerichtenbox` check on every send.

All three schemas live in the `openconnector` register; the adapter publishes Tier-4 manifest entries (`schemas/Bericht.json`, etc.) so other apps consume them via `{ref: "openconnector:Bericht"}` rather than redefining them.

## Requirements

### REQ-001: OIN-based mTLS authentication
The adapter MUST authenticate to the Berichtenbox koppelvlak using a PKIoverheid Private Services Server certificate bound to the OIN of the sending organisation, configured per `Source` row in OpenConnector.

- GIVEN a `Source` of type `berichtenbox` with `oin = "00000001003214345000"` and a PKIoverheid certificate uploaded to NextcloudVault, WHEN the adapter sends a `Bericht`, THEN the outbound SOAP request uses that certificate for mTLS handshake and the OIN in the WS-Addressing `From` header.
- GIVEN an expired PKIoverheid certificate, WHEN the adapter attempts to send, THEN the send fails fast with `BerichtenboxError.CERTIFICATE_EXPIRED`, the failure is logged with `severity=critical`, and a notification is dispatched to the configured admin Talk room (via openconnector's existing alerting hook).
- GIVEN a `Source` configured for the Logius pre-production environment, WHEN sending, THEN the adapter MUST use `https://wus-preprod-mo.procura.nl/...` and never accidentally hit production; the environment is part of the `Source.configuration` and validated on save.

### REQ-002: Outbound message send via SOAP/WUS koppelvlak
The adapter MUST translate a `Bericht` object into a valid `aanleverenRequest` per the Aanleveren-2.1.5 WSDL.

- GIVEN a complete `Bericht` with `berichtType=beschikken` and one PDF/A attachment, WHEN OpenConnector executes the `berichtenbox.send` job, THEN the SOAP envelope contains `Berichtkenmerk`, `BerichtBetreft`, `MimeBerichtInhoud` and `Bijlage` elements that pass Logius XSD validation.
- GIVEN an attachment that is `application/pdf` but not PDF/A-1/2, WHEN sending, THEN the adapter rejects the send with `BerichtenboxError.INVALID_ATTACHMENT_FORMAT` BEFORE any network call, surfacing the specific failing attachment in the error.
- GIVEN a body that exceeds 1MB or attachments totalling > 25MB, WHEN sending, THEN the request MUST be rejected client-side with a friendly error mentioning the actual size and the limit.

### REQ-003: REST koppelvlak fallback
The adapter MUST be implementable against the upcoming REST/JSON Berichtenbox koppelvlak (currently in pilot at Logius) via a feature flag, without consuming apps needing to change.

- GIVEN `Source.configuration.koppelvlak = "rest-v1"`, WHEN sending the same `Bericht`, THEN the adapter performs an OAuth2 client-credentials handshake, posts to `POST /v1/berichten`, and maps the JSON response onto the same `Berichtstatus` schema as the SOAP path produces.
- GIVEN both koppelvlakken configured across two Sources, WHEN a consumer app calls `openconnector.send('berichtenbox', $bericht)`, THEN the routing decision is made on `Source.configuration.priority` and `Bericht.afzenderOIN` and is fully transparent to the caller.

### REQ-004: Voorkeurskanaal check
The adapter MUST consult `Berichtenbox.heeftBerichtenbox(bsn)` (or KvK equivalent) before sending and gracefully handle citizens who have not activated their Berichtenbox.

- GIVEN a `Bericht` addressed to a BSN with `heeftBerichtenbox=false`, WHEN sending, THEN the adapter records a `Berichtstatus(status=geweigerd, foutCode=NO_BOX)` and emits an `openconnector.bericht.unaddressable` event so the originating app can fall back to physical mail.
- GIVEN a BSN whose `voorkeurskanaalChecked` is < 24h old in the cache, WHEN sending again, THEN the adapter MUST reuse the cached result and skip the live check, to stay under Logius rate limits.
- GIVEN a `Bericht` with `geadresseerde.type=kvk`, WHEN sending, THEN the adapter calls `BerichtenboxVoorBedrijven.heeftBox(kvk)` instead of the BSN endpoint, and routes the message through the Mijn Zaken voor Ondernemers koppelvlak.

### REQ-005: Status webhooks and notification ingestion
The adapter MUST expose a webhook endpoint at `/apps/openconnector/api/incoming/berichtenbox/{sourceId}` that receives Notificaties-1.1 callbacks from Logius and translates them into `Berichtstatus` objects.

- GIVEN a Logius push notification `BerichtAfgeleverd` for a known `berichtId`, WHEN received and mTLS-validated, THEN a `Berichtstatus(status=afgeleverd)` is persisted and the originating app receives an `openconnector.bericht.delivered` event with the original referentie.
- GIVEN a `BerichtGelezen` notification, WHEN received, THEN the same flow records `status=gelezen` AND if the bericht had `responseRequired=true`, an additional `openconnector.bericht.awaiting-response` event is emitted.
- GIVEN a webhook payload whose `berichtId` does not match any known `Bericht`, WHEN received, THEN the request is rejected with HTTP 409 and the orphan event is written to the OpenConnector dead-letter queue for manual inspection.

### REQ-006: Retention and e-mail fallback handling
The adapter MUST respect the 6-year Berichtenbox retention period and the 5-day "ongelezen → e-mail" Logius fallback, surfacing both as first-class events.

- GIVEN a `Bericht` that remains unread for 5 days and Logius fires an `EmailFallbackVerstuurd` notification, WHEN received, THEN a `Berichtstatus(status=email_fallback_verstuurd)` is recorded and the originating app receives an event so it can adjust its dunning / reminder strategy.
- GIVEN a `Bericht` whose `vervalDatum` is reached and Logius pushes `BerichtVerlopen`, WHEN received, THEN the adapter records the status and triggers archival in the originating app (the body itself is retained per app, not in the box).
- GIVEN a consumer app that sets `attachmentRetentionDays=30`, WHEN that period passes after delivery, THEN the adapter MUST issue `verwijderenBijlage` to free Logius-side storage, while keeping the metadata in OpenRegister for audit.

### REQ-007: Auditable end-to-end logging
Every Berichtenbox interaction MUST be logged in OpenConnector's existing `CallLog`/`CallLogBody` tables with PII redaction.

- GIVEN any outbound send or inbound webhook, WHEN executed, THEN a `CallLog` row is created with `oin`, `koppelvlak`, `messageId`, `responseCode`, and a `CallLogBody` row with the redacted SOAP/JSON (BSN masked to first 3 + last 2 digits).
- GIVEN a successful send, WHEN the CallLog is queried via the OpenConnector UI by an admin, THEN the full audit trail (send → afgeleverd → gelezen) is visible as a linked timeline.
- GIVEN log retention policy of 7 years for Berichtenbox traffic (Archiefwet), WHEN nightly retention job runs, THEN `berichtenbox` CallLogs are excluded from the default 90-day purge and only purged after 7 years.

### REQ-008: Rate-limit and backoff handling
The adapter MUST respect Logius rate limits (currently 10 req/s sustained, 100 burst per OIN) and back off intelligently.

- GIVEN a burst of 200 outbound sends queued at once, WHEN dispatched, THEN the adapter throttles to ≤ 10 req/s per OIN using a token-bucket implementation shared with other OpenConnector jobs.
- GIVEN an HTTP 429 / SOAP `tooManyRequests` from Logius, WHEN received, THEN the adapter backs off exponentially (2s, 4s, 8s, capped at 60s) and never drops the message — it is requeued.
- GIVEN sustained Logius outage > 15 min, WHEN detected (5 consecutive 5xx), THEN the adapter pauses the queue, emits an `openconnector.source.degraded` event, and resumes automatically when probes succeed.

### REQ-009: Bedrijven (KvK/OIN) addressing
The adapter MUST support the Berichtenbox voor Bedrijven (renamed to Mijn Zaken voor Ondernemers) for messages to legal persons.

- GIVEN a `Bericht` with `geadresseerde.type=kvk` and `kvk=12345678`, WHEN sent, THEN the adapter routes via the OIN-on-OIN Digikoppeling profile and uses `MijnZakenVoorOndernemers` koppelvlak rather than `Berichtenbox`.
- GIVEN a `Bericht` with `geadresseerde.type=oin`, WHEN sent, THEN the same OIN-on-OIN path is used; this is the only legal channel for B2G correspondence between public bodies.

### REQ-010: Test/Preprod isolation
The adapter MUST prevent accidental production sends from dev/test environments.

- GIVEN `Source.environment != "production"`, WHEN a send is dispatched, THEN the outbound URL MUST point to a preprod endpoint AND a confirming environment header is added; if the configured certificate is a production cert, the send is rejected.
- GIVEN OpenConnector running in a Nextcloud instance with `config.systemTag=dev`, WHEN any Berichtenbox source is added, THEN the UI shows a persistent banner and forces preprod selection.

## Standards & Sources

- Logius Berichtenbox koppelvlak documentation: `https://www.logius.nl/diensten/mijnoverheid/berichtenbox` — Aanleveren WSDL 2.1.5, Notificaties WSDL 1.1, REST koppelvlak pilot v1.
- Digikoppeling 3.5 — WUS, ebMS, OIN-mTLS profiles (`https://www.logius.nl/diensten/digikoppeling`).
- PKIoverheid Private Services Server CA chain (Staat der Nederlanden Private Root CA G1).
- NORA principles on digital delivery to citizens (`afspraak: digitaal tenzij`, Archiefwet 1995, Awb art. 2:14).
- BIO (Baseline Informatiebeveiliging Overheid) for transport security baselines.
- Forum Standaardisatie open standards register (`Berichtenbox koppelvlak` is on the `pas toe of leg uit` list).
- Mijn Zaken voor Ondernemers koppelvlak (`https://www.logius.nl/diensten/mijnzaken-voor-ondernemers`) for KvK/OIN addressing.

## Cross-app integration

- **decidesk** publishes a besluit and calls `openconnector.send('berichtenbox', $bericht)` with `berichtType=beschikken`; the returned status events drive the zaakstatus.
- **shillinq** sends invoices via Berichtenbox to citizens who have a Berichtenbox (with `berichtType=factureren`) and falls back to Peppol (other adapter) for businesses and physical mail for the rest. Invoice payment status feeds back via the `bericht.delivered` event.
- **procest** publishes VTH-beschikkingen (omgevingsvergunning, handhaving) via this adapter and uses the `bericht.gelezen` event to start the bezwaartermijn clock.
- **zaakafhandelapp** uses the adapter to send all `informeren`-type correspondence linked to a zaak; the `referentie` field carries the zaak-UUID for round-trip linking.
- **scholiq** sends school correspondence (verzuim, inschrijving) to ouders' Berichtenbox.
- **mydash** surfaces aggregated Berichtenbox throughput, delivery times, fallback rates per OIN/per app.
- **openconnector itself** uses the `CallLog` produced here as the canonical audit source for any `Bericht`.

## Target users

- **Public-sector developers and IT teams** at municipalities, water boards, executive agencies who currently maintain bespoke Berichtenbox integrations or pay SaaS vendors per-message. They get a vendor-neutral, open-source koppelvlak that ships with their Conduction app fleet.
- **Functional administrators** (functioneel beheerders) who need to configure OINs, certificates, and source rotation without touching code.
- **Compliance officers and FG/CISO** who need the audit log, retention guarantees, and BIO-conformant transport in one auditable place.
- **Burgers and ondernemers** (indirectly) who get one consistent, legally-correct delivery channel regardless of which government back-office sent the message.
