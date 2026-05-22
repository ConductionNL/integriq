# Design: Berichtenbox MijnOverheid Adapter

## Architecture Overview

The Berichtenbox adapter integrates into OpenConnector as a Job type that handles outbound message send and inbound webhook notification callbacks. The flow is:

1. **Consumer app** calls `openconnector.send('berichtenbox', $bericht)` with a `Bericht` envelope
2. **OpenConnector Job dispatcher** routes to `BerichtenboxAdapter::send()`
3. **Adapter** validates the `Bericht` (schema, attachments, size), checks `Source.configuration.koppelvlak` (SOAP vs REST), authenticates via OIN-mTLS certificate, and either:
   - (SOAP) Serializes to Aanleveren-2.1.5 SOAP envelope, resolves Logius endpoint per Source environment, posts via mTLS, deserializes response
   - (REST) Performs OAuth2 client-credentials handshake, POSTs to REST endpoint, parses JSON response
4. **Rate-limiter** throttles outbound traffic to 10 req/s per OIN using token-bucket
5. **CallLog** records the send attempt (request headers, response code, masked payload) for audit
6. **Webhook receiver** at `/apps/openconnector/api/incoming/berichtenbox/{sourceId}` consumes Logius Notificaties callbacks (BerichtAfgeleverd, BerichtGelezen, BerichtVerlopen, EmailFallbackVerstuurd, etc.)
7. **Webhook handler** validates mTLS certificate, translates to `Berichtstatus(status, tijdstip, details)`, persists to database, and dispatches `openconnector.bericht.*` events
8. **Consumer app** listens for events and reacts (close zaak, mark invoice delivered, start clock for bezwaar, etc.)

**Data flow diagram:**
```
┌──────────────────┐
│  Consumer App    │
│  (e.g. decidesk) │
└────────┬─────────┘
         │ openconnector.send('berichtenbox', $bericht)
         ▼
┌─────────────────────────────┐
│  OpenConnector Job          │
│  Dispatcher                 │
└────────┬────────────────────┘
         │
         ▼
┌──────────────────────────────────┐
│ BerichtenboxAdapter::send()       │
│ - Validate schema & attachments   │
│ - Check Source.koppelvlak        │
│ - Auth via OIN-mTLS cert         │
│ - Call rate-limiter              │
└────────┬─────────────────────────┘
         │
    ┌────┴────┐
    │          │
    ▼          ▼
[SOAP]      [REST]
    │          │
    └────┬─────┘
         │
    ┌────▼──────────────────┐
    │  Logius Berichtenbox   │
    │  / Mijn Zaken voor O.  │
    └────┬──────────────────┘
         │
    ┌────▼──────────────────────────┐
    │ Logius Webhook (Notificaties)  │
    │ BerichtAfgeleverd, Gelezen,... │
    └────┬─────────────────────────┘
         │ mTLS callback
         ▼
┌──────────────────────────────────┐
│  Webhook Receiver                │
│  /api/incoming/berichtenbox/{id} │
│  - Validate mTLS                 │
│  - Parse Notificatie             │
│  - Create Berichtstatus          │
│  - Dispatch openconnector.bericht.*
└──────────────────────────────────┘
         │
    ┌────▼──────────────────────┐
    │ openconnector.bericht.*    │
    │ (delivered, gelezen, etc.) │
    └────┬──────────────────────┘
         │
         ▼
    ┌──────────────────────┐
    │ Consumer App         │
    │ Event Listeners      │
    └──────────────────────┘
```

## API Design

### `POST /apps/openconnector/api/incoming/berichtenbox/{sourceId}`

**Purpose**: Receive Logius Notificaties-1.1 SOAP callbacks and translate to `Berichtstatus` objects.

**Request (SOAP):**
```xml
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <BerichtAfgeleverd xmlns="http://www.logius.nl/..." >
      <berichtId>550e8400-e29b-41d4-a716-446655440000</berichtId>
      <afleverdatum>2025-06-15T10:30:00Z</afleverdatum>
    </BerichtAfgeleverd>
  </soap:Body>
</soap:Envelope>
```

**Response (SOAP):**
```xml
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <Response>
      <status>OK</status>
    </Response>
  </soap:Body>
</soap:Envelope>
```

**Status codes:**
- `200 OK` — Notificatie received and persisted
- `409 Conflict` — berichtId not found in database; written to dead-letter queue
- `401 Unauthorized` — mTLS certificate invalid or untrusted
- `500 Internal Server Error` — Unexpected error; Logius will retry

## Database Changes

### New Tables

**`berichtenbox_afleverkanaal`** (caches voorkeurskanaal check results)

```php
CREATE TABLE `berichtenbox_afleverkanaal` (
  `id` CHAR(36) PRIMARY KEY,
  `bsn_or_kvk` VARCHAR(20) COLLATE utf8_general_ci NOT NULL,
  `adressering_type` ENUM('bsn', 'kvk', 'oin') NOT NULL,
  `heeft_berichtenbox` BOOLEAN NOT NULL,
  `voorkeurskanaal_checked` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `notificatie_email_hash` VARCHAR(64) COLLATE utf8_general_ci,
  `notificatie_mobiel_hash` VARCHAR(64) COLLATE utf8_general_ci,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `ux_bsn_or_kvk_type` (`bsn_or_kvk`, `adressering_type`),
  INDEX `ix_voorkeurskanaal_checked` (`voorkeurskanaal_checked`)
);
```

### Modified Tables

**`oc_openconnector_calllog`** (add berichtenbox-specific columns)

```php
ALTER TABLE `oc_openconnector_calllog`
  ADD COLUMN `bericht_id` CHAR(36) AFTER `job_type`,
  ADD COLUMN `bericht_kenmerk` VARCHAR(255) AFTER `bericht_id`,
  ADD COLUMN `bericht_type` ENUM('informeren', 'beschikken', 'factureren', 'attenderen') AFTER `bericht_kenmerk`,
  ADD COLUMN `geadresseerde_type` ENUM('bsn', 'kvk', 'oin') AFTER `bericht_type`,
  ADD INDEX `ix_bericht_id` (`bericht_id`),
  ADD INDEX `ix_bericht_kenmerk` (`bericht_kenmerk`);
```

**`oc_openconnector_calllog_body`** (already exists; used for masked payloads)

PII redaction on insert: BSN → `XXX-XX-XXXX`, email → hashed, phone → hashed.

## Nextcloud Integration

### Controllers
- **BerichtenboxWebhookController** (`lib/Controller/BerichtenboxWebhookController.php`):
  - `POST /api/incoming/berichtenbox/{sourceId}` — webhook receiver
  - Validates mTLS certificate via Nextcloud request context
  - Dispatches to `BerichtenboxWebhookReceiver`

### Services
- **BerichtenboxAdapter** (`lib/Service/Adapters/BerichtenboxAdapter.php`):
  - `send(Source $source, Bericht $bericht): Berichtstatus|BerichtenboxError`
  - Validates schema, checks voorkeurskanaal, selects SOAP or REST transport
  - Handles rate-limiting via shared OpenConnector token-bucket
  - Logs to CallLog with PII redaction
  
- **BerichtenboxWebhookReceiver** (`lib/Service/Adapters/WebhookReceivers/BerichtenboxReceiver.php`):
  - `receive(Source $source, string $payload): void`
  - Parses SOAP/JSON payload
  - Persists `Berichtstatus`
  - Dispatches `openconnector.bericht.*` events

- **BerichtenboxSoapTransport** (`lib/Service/Transports/BerichtenboxSoapTransport.php`):
  - `send(Bericht $bericht, Source $source): array` (SOAP response)
  - Serializes to Aanleveren-2.1.5 SOAP envelope
  - Performs mTLS handshake via Nextcloud Certificate manager
  - Parses response SOAP

- **BerichtenboxRestTransport** (`lib/Service/Transports/BerichtenboxRestTransport.php`):
  - `send(Bericht $bericht, Source $source): array` (REST response)
  - Performs OAuth2 client-credentials handshake
  - POSTs to REST endpoint
  - Parses JSON response

- **BerichtenboxAfleverkanaalCache** (`lib/Service/BerichtenboxAfleverkanaalCache.php`):
  - `heeftBerichtenbox(string $bsnOrKvk, string $type): bool`
  - Checks local cache first; if < 24h old, returns cached result
  - Otherwise calls Logius `Berichtenbox.heeftBerichtenbox()` or `BerichtenboxVoorBedrijven.heeftBox()`
  - Persists result to `berichtenbox_afleverkanaal` table with timestamp

### Mappers/Entities
- **Bericht** (schema in shared openconnector register — maps to `oc_openconnector_objects` table)
- **Berichtstatus** (schema in shared openconnector register — maps to `oc_openconnector_objects` table)
- **BerichtAfleverkanaal** (entity mapped to `berichtenbox_afleverkanaal` table)

### Events/Hooks
- **openconnector.bericht.delivered** — dispatched when `Berichtstatus(status=afgeleverd)` received
- **openconnector.bericht.gelezen** — dispatched when `Berichtstatus(status=gelezen)` received
- **openconnector.bericht.unaddressable** — dispatched when `Berichtstatus(status=geweigerd, foutCode=NO_BOX)` created
- **openconnector.bericht.email_fallback_verstuurd** — dispatched when `Berichtstatus(status=email_fallback_verstuurd)` received
- **openconnector.bericht.awaiting_response** — dispatched when `Berichtstatus(status=gelezen)` received AND original `Bericht.responseRequired=true`
- **openconnector.source.degraded** — dispatched by adapter if 5 consecutive 5xx responses detected from Logius

## Security Considerations

### Authentication & Authorization
- **OIN-mTLS**: All outbound SOAP/REST requests use PKIoverheid Private Services Server certificate bound to Source OIN. Certificate is validated on `Source` save:
  - Certificate must be valid (not expired)
  - Certificate must be for the configured OIN
  - Certificate chain must trace to Staat der Nederlanden Private Root CA G1
- **Webhook validation**: Inbound webhook callbacks from Logius are validated via mTLS (mutual TLS) — Logius certificate is checked against trusted CA chain
- **Source.active**: Disabled sources cannot send; webhook receiver explicitly checks `Source.active` and rejects if false

### Input Validation
- **Bericht schema**: All fields validated against OpenConnector schema registry before send
- **Attachment MIME types**: Only PDF/A-1, PDF/A-2, JPEG, PNG allowed; validated by file magic number, not extension
- **Attachment size**: Single file max 25MB, total per Bericht max 25MB; validated before network call
- **Body size**: HTML body max 1MB; validated before network call
- **PII in CallLog**: BSN masked to `XXX-XX-XXXX`, email hashed with SHA256, phone hashed with SHA256 before insert into `CallLogBody`

### Transport Security
- **mTLS for all outbound**: Every SOAP/REST request to Logius uses OIN-bound client certificate
- **mTLS for webhook**: Logius callbacks must present valid certificate; verified via trusted CA chain
- **HTTPS enforced**: No HTTP fallback; all Logius endpoints are HTTPS-only
- **Certificate expiry**: Checked in-memory; on expiry, fail fast with critical log and alert

### Rate-Limiting & DoS
- **Token-bucket limiter**: Shared with all OpenConnector Jobs; enforces 10 req/s per OIN, 100 burst
- **Backoff on 429**: Exponential backoff (2s, 4s, 8s, capped 60s); never drop message
- **Webhook idempotence**: Status updates are idempotent (same `berichtId` + `status` + `tijdstip` = no-op on retry); prevents duplicate event dispatch

### Compliance & Audit
- **CallLog retention**: Berichtenbox CallLog rows excluded from default 90-day purge; retained for 7 years per Archiefwet (archives law)
- **PII redaction**: All CallLog entries redact BSN, email, phone before persistence
- **Audit trail**: Every send → delivery → read → fallback transition recorded with timestamp and event dispatched to consumer app
- **Environment isolation**: `Source.environment` (dev, test, production) enforced; preprod URL selected for non-production, certificate validation prevents prod cert in test

## NL Design System

The Berichtenbox adapter does not expose user-facing UI; configuration is handled within OpenConnector's existing Source/Settings interface. However, if future admin dashboards expose Berichtenbox metrics (delivered rate, fallback rate, average time-to-delivery), they MUST use NL Design System components:
- `NcCard` for metric cards
- `NcButton` for action buttons (e.g., retry failed send)
- `NcModal` for confirmation dialogs
- Dutch language labels per ADR-007 hydra i18n standard

## File Structure

```
lib/
  Controller/
    BerichtenboxWebhookController.php        [new] Webhook endpoint handler
  Service/
    Adapters/
      BerichtenboxAdapter.php               [new] Main adapter orchestrator
      WebhookReceivers/
        BerichtenboxReceiver.php            [new] Webhook payload parser & responder
    Transports/
      BerichtenboxSoapTransport.php         [new] SOAP/WUS koppelvlak implementation
      BerichtenboxRestTransport.php         [new] REST koppelvlak implementation
    BerichtenboxAfleverkanaalCache.php      [new] voorkeurskanaal check cache manager
  Db/
    BerichtAfleverkanaal.php                [new] Entity mapper for cache table
    BerichtAfleverkanaalMapper.php          [new] Database mapper
  Migration/
    Version20250620000000CreateBerichtenboxTables.php [new] Schema migration

tests/
  Unit/
    Service/Adapters/BerichtenboxAdapterTest.php        [new]
    Service/Transports/BerichtenboxSoapTransportTest.php [new]
    Service/Transports/BerichtenboxRestTransportTest.php [new]
    Service/BerichtenboxAfleverkanaalCacheTest.php       [new]
  Integration/
    BerichtenboxWebhookTest.php              [new]
```

## Seed Data

### Schema: `Bericht` (3 examples in openconnector register)

| Field | Voorbeeld 1: Beschikking | Voorbeeld 2: Factuur | Voorbeeld 3: Informatie |
|-------|----------|----------|----------|
| `id` | `550e8400-e29b-41d4-a716-446655440001` | `550e8400-e29b-41d4-a716-446655440002` | `550e8400-e29b-41d4-a716-446655440003` |
| `afzenderOIN` | `00000001003214345000` (RvIG) | `00000001003214345001` (Belastingdienst) | `00000001003214345002` (Gemeente Amsterdam) |
| `geadresseerde.type` | `bsn` | `bsn` | `bsn` |
| `geadresseerde.waarde` | `123456789` | `987654321` | `111222333` |
| `berichtType` | `beschikken` | `factureren` | `informeren` |
| `onderwerp` | `Toekenning uitkering PAS 2025` | `Aanslag inkomstenbelasting 2024` | `Wijziging openingstijden gemeente` |
| `bericht` | `<html><body><h1>Toekenning</h1><p>U bent goedgekeurd voor...</p></body></html>` | `<html><body><h1>Factuur</h1><p>Verschuldigd bedrag: €450,00</p></body></html>` | `<html><body><h1>Mededeling</h1><p>Per 1 juli zijn we gesloten...</p></body></html>` |
| `bijlagen` | `[{naam: "beschikking.pdf", mimeType: "application/pdf", inhoud: "...base64..."}]` | `[{naam: "factuur.pdf", mimeType: "application/pdf", inhoud: "...base64..."}]` | `[]` |
| `referentie` | `BESLUIT-2025-001234` | `INVOICE-2025-56789` | `MELDING-2025-99999` |
| `kenmerk` | `UITK/PAS/2025/1` | `INKOMSTENBELASTING/2024/1` | `GEMEENTE/OPENINGSTIJDEN/2025/1` |
| `publicatiedatum` | `2025-06-15` | `2025-06-20` | `2025-06-10` |
| `vervalDatum` | `2031-06-15` | `2031-06-20` | `2031-06-10` |
| `responseRequired` | `false` | `true` (payment confirmation) | `false` |
| `attachmentRetentionDays` | `90` | `2555` (7 years) | `90` |
| `notificatieAdresseringsType` | `standaard` | `standaard` | `geen` |
| `@self.register` | `openconnector` | `openconnector` | `openconnector` |
| `@self.schema` | `Bericht` | `Bericht` | `Bericht` |
| `@self.slug` | `beschikking-pas-2025-1` | `factuur-inkomstenbelasting-2024-1` | `melding-openingstijden-2025-1` |

**Related items per example:**
- **Beschikking**: zaak (in zaakafhandelapp), betrokken persoon (burger)
- **Factuur**: invoice object (in shillinq), gemeente account/contact
- **Informatie**: informatieserie, medewerker (author)

### Schema: `Berichtstatus` (4 examples per Bericht, simulating lifecycle)

For `Beschikking (550e8400-e29b-41d4-a716-446655440001)`:

| Event | `status` | `tijdstip` | `details` |
|-------|----------|----------|----------|
| Sent & accepted | `aangeboden` | `2025-06-15T10:00:00Z` | `{}` |
| Delivered to box | `afgeleverd` | `2025-06-15T10:30:00Z` | `{}` |
| Opened by citizen | `gelezen` | `2025-06-16T14:15:00Z` | `{}` |
| (Archived after 6 years) | `verlopen` | `2031-06-15T23:59:59Z` | `{}` |

For `Factuur (550e8400-e29b-41d4-a716-446655440002)`:

| Event | `status` | `tijdstip` | `details` |
|-------|----------|----------|----------|
| Sent & accepted | `aangeboden` | `2025-06-20T09:00:00Z` | `{}` |
| Delivered to box | `afgeleverd` | `2025-06-20T09:45:00Z` | `{}` |
| Unread for 5 days | `email_fallback_verstuurd` | `2025-06-25T06:00:00Z` | `{emailAdres: "hashed_email"}` |
| Opened after fallback | `gelezen` | `2025-06-26T11:20:00Z` | `{}` |

For `Informatie (550e8400-e29b-41d4-a716-446655440003)`:

| Event | `status` | `tijdstip` | `details` |
|-------|----------|----------|----------|
| Sent & accepted | `aangeboden` | `2025-06-10T08:00:00Z` | `{}` |
| Delivered to box | `afgeleverd` | `2025-06-10T08:30:00Z` | `{}` |

### Schema: `BerichtAfleverkanaal` (3 cache entries)

| Field | Example 1 | Example 2 | Example 3 |
|-------|----------|----------|----------|
| `bsn_or_kvk` | `123456789` | `12345678` | `00000001003214345000` |
| `adressering_type` | `bsn` | `kvk` | `oin` |
| `heeft_berichtenbox` | `true` | `false` (KvK not yet enrolled) | `true` |
| `voorkeurskanaal_checked` | `2025-06-15T10:00:00Z` | `2025-06-14T15:30:00Z` | `2025-06-15T09:45:00Z` |
| `notificatie_email_hash` | `abc123def456...` | (null) | `xyz789uvw123...` |
| `notificatie_mobiel_hash` | `(null)` | (null) | `pqr456stu789...` |

## Trade-offs

1. **SOAP vs REST**: SOAP is mature and proven (Aanleveren-2.1.5 is stable); REST is newer (Logius pilot). We support both via feature flag to allow parallel operation; this adds transport complexity but zero breaking change for consumers.

2. **Voorkeurskanaal cache TTL**: 24h is aggressive but necessary to avoid hammering Logius with checks on every send. Trade-off: stale cache means we might mark a send unaddressable when the citizen has since activated their box. Mitigation: consumer app falls back to alternative channel (email/physical) and can retry later.

3. **PII redaction in CallLog**: Full masking (not just hashing) of BSN in CallLog makes audit trail less precise but legally required. Admin accessing CallLog can round-trip the masked value to the source Bericht if needed, but cannot reconstruct raw BSN.

4. **7-year retention**: Legal requirement per Archiefwet; this inflates our database footprint significantly vs the 90-day default for other job types. Mitigation: partition CallLog by year to allow efficient deletion and archival to cold storage.

5. **Rate-limiter shared with all Jobs**: Berichtenbox sits on the same 10 req/s per OIN as other OpenConnector jobs. If another job is volume-heavy, Berichtenbox sends get queued. Mitigation: Logius rate limits are per-OIN, not per-job, so this is correct; consumer apps can provision multiple OINs if throughput needs exceed 10 req/s.
