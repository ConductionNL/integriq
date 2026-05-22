# Design: digikoppeling-adapter

## Overview

The Digikoppeling adapter is the foundation transport layer for all Conduction app-to-government messaging. It wraps four transport profiles (WUS / OUS / Grote Berichten / Best Effort) behind a single configuration surface and manages the PKIoverheid certificate lifecycle, endpoint registry, retry policies, and audit trails.

Integration model: composition, never coupling. The adapter is payload-agnostic. Higher-level adapters (StUF, ZGW, Berichtenbox) compose on top without requiring changes to the Digikoppeling layer.

## Data Model

### DigikoppelingEndpoint

Represents a remote party's published endpoint in the Digikoppeling Service Registry.

| Field | Type | Constraints | Purpose |
|---|---|---|---|
| `aansluitnummer` | string (5 digits) | unique per `oin` | Logius-assigned remote party ID |
| `oin` | string (20 digits) | natural key | Organisatie-Identificatienummer |
| `name` | string | | Human-readable name from service registry |
| `profile` | enum | WUS / OUS / GroteBerichten / BestEffort | Transport profile |
| `endpointUrl` | string (URL) | https required | Target service URL |
| `wsdlUrl` | string (URL) | optional | For WUS endpoints only |
| `cpaId` | string (UUID) | optional | Collaboration Protocol Agreement ID for ebMS3 |
| `serviceNamespace` | string (URI) | | XML namespace for operations |
| `messageTypes` | array (URIs) | | Supported message-type URIs |
| `pkiCertificateChain` | text | PEM encoded | Remote party's signing cert + intermediates |
| `validFrom` | datetime | | Certificate chain valid start |
| `validUntil` | datetime | | Certificate chain valid end |
| `maxConcurrentRequests` | integer | default 5 | Concurrency limit |
| `requestsPerMinute` | integer | default 60 | Throughput limit |
| `lastSyncedFromRegistry` | datetime | | Last fetch from Logius service registry |

### DigikoppelingCertificate

Represents a PKIoverheid certificate owned by the local party (Nextcloud instance).

| Field | Type | Constraints | Purpose |
|---|---|---|---|
| `aansluitnummer` | string (5 digits) | unique | Our Logius aansluitnummer |
| `oin` | string (20 digits) | unique | Our OIN |
| `certificateType` | enum | signing / encryption / TLS-client | Certificate usage |
| `pemCertificate` | text | PEM encoded | Public certificate |
| `pemPrivateKeyRef` | string | reference to secret store | Key never in schema |
| `pemChain` | text | PEM encoded | Intermediate + root certs |
| `validFrom` | datetime | | Certificate valid start |
| `validUntil` | datetime | | Certificate valid end |
| `subjectDN` | string | | Distinguished name of subject |
| `issuerDN` | string | | Distinguished name of issuer |
| `serialNumber` | string | | Certificate serial number |
| `rotationReminderDays` | integer | default 60 | Days before expiry to warn |

### DigikoppelingMessage

Every message sent or received gets exactly one row. This is the core audit hook.

| Field | Type | Constraints | Purpose |
|---|---|---|---|
| `direction` | enum | outbound / inbound | Send or receive direction |
| `profile` | enum | WUS / OUS / GroteBerichten / BestEffort | Profile used |
| `localEndpointRef` | UUID | | This app's endpoint identifier |
| `remoteEndpointRef` | UUID | foreign key | Linked DigikoppelingEndpoint |
| `messageId` | string | unique | WS-Addressing or ebMS3 MessageId |
| `conversationId` | string | | Groups request + response + callbacks |
| `messageType` | string (URI) | | StUF / ZGW / business type |
| `payloadHash` | string (SHA-256) | | Canonicalised payload integrity |
| `payloadFileRef` | string | NextCloud Files ref | Keeps large payloads off DB |
| `payloadSizeBytes` | integer | | Payload size for monitoring |
| `status` | enum | pending / sent / delivered / acknowledged / failed / expired | Current state |
| `statusReason` | text | | Error details or fault code |
| `attemptCount` | integer | | Number of send attempts |
| `nextAttemptAt` | datetime | | Scheduled next retry |
| `firstAttemptAt` | datetime | | Original send time |
| `lastAttemptAt` | datetime | | Last retry time |
| `acknowledgedAt` | datetime | | Response received at (OUS callback) |
| `expiresAt` | datetime | | When message expires per retry policy |
| `signingCertificateRef` | UUID | foreign key | Which cert was used for signing |
| `correlatesTo` | UUID | self-referencing | Links response to request |
| `triggeredByJobRef` | UUID | | OpenConnector job that initiated send |

### DigikoppelingRetryPolicy

Per-profile retry configuration. Defaults match official Logius table.

| Field | Type | Constraints | Purpose |
|---|---|---|---|
| `profile` | enum | WUS / OUS / GroteBerichten / BestEffort | Profile identifier |
| `initialDelaySeconds` | integer | | Delay before 1st retry |
| `maxAttempts` | integer | | Max send attempts |
| `backoffMultiplier` | float | default 2.0 | Exponential backoff factor |
| `maxDelaySeconds` | integer | | Cap on backoff delay |
| `expireAfterHours` | integer | | Total lifetime before expiry |

**Default values per Logius specification:**
- WUS: no automatic retry (sync only; 1 attempt, expire 1 hour)
- OUS: 7 attempts, 4 hours, 120s initial delay, 2x backoff, 3600s cap
- Grote Berichten/AS4: 14 attempts, 24 hours, 600s initial delay, 2x backoff, 7200s cap
- Best Effort: 3 attempts, 1 hour, 30s initial delay, 2x backoff, 300s cap

### DigikoppelingAuditEvent

Append-only audit trail. Never updated or deleted. Retention ≥7 years (Archiefwet).

| Field | Type | Constraints | Purpose |
|---|---|---|---|
| `messageRef` | UUID | foreign key | Links to DigikoppelingMessage |
| `eventType` | enum | created / signed / sent / wire-error / response-received / signature-verified / acknowledged / retry-scheduled / expired / archived | State transition |
| `actor` | string | | Nextcloud user or system account |
| `timestamp` | datetime | immutable | When the event occurred |
| `details` | JSON | | Attempt-specific context (HTTP status, SOAP fault, sig validation result, ebMS3 receipt ID) |

### DigikoppelingServiceRegistryCache

Local mirror of the Logius Digikoppeling Service Registry. Refreshed daily via system job.

| Field | Type | Constraints | Purpose |
|---|---|---|---|
| `aansluitnummer` | string (5 digits) | unique | Remote party ID |
| `oin` | string (20 digits) | natural key | Organisation ID |
| `organisatienaam` | string | | Organisation name |
| `services` | JSON array (URIs) | | Published service endpoints |
| `cpaDocuments` | JSON array | | CPA XML blobs for ebMS3 |
| `lastFetchedAt` | datetime | | When registry was last synced |

## Architecture

### Class Structure

**DigikoppelingAdapter** (main service)
- Injection: `IClientService`, `EncryptionService`, `DigikoppelingRepository`, `LoggerInterface`, `ICache`
- Public methods:
  - `send(DigikoppelingMessage $msg, array $payload): array|string` — orchestrates WUS/OUS/Grote Berichten send
  - `handleCallback(string $soapEnvelope): void` — processes OUS/AS4 callbacks
  - `retryPending(): void` — scheduler task for failed messages
  - `syncServiceRegistry(): void` — daily sync of Logius registry
  - `checkCertificateExpiry(): void` — daily certificate monitoring

**DigikoppelingConnector** (protocol implementations)
- `sendWUS(DigikoppelingEndpoint $ep, array $payload, DigikoppelingCertificate $cert): array` — synchronous SOAP
- `sendOUS(DigikoppelingEndpoint $ep, array $payload, DigikoppelingCertificate $cert): string` — async with reply-to
- `sendGroteBerichten(DigikoppelingEndpoint $ep, array $payload, DigikoppelingCertificate $cert, string $cpa): bool` — ebMS3/AS4
- `verifySoapSignature(string $envelope, DigikoppelingEndpoint $ep): bool` — WS-Security verification
- `verifyAS4Receipt(string $receipt, DigikoppelingMessage $msg): bool` — ebMS3 receipt verification

**DigikoppelingRepository** (data access)
- `createMessage()`, `updateMessage()`, `getMessage()`, `getPendingMessages()`, `getMessagesByConversationId()`
- `createAuditEvent()`, `getAuditTrail()` — append-only audit access
- `createCertificate()`, `getCertificate()`, `listCertificatesNearExpiry()`
- `upsertServiceRegistryEntry()`, `getServiceRegistryEntry()` — cache CRUD

**DigikoppelingController** (REST API)
- `POST /api/digikoppeling/send` — initiate outbound message send
- `POST /api/digikoppeling/callback` — receive inbound callback (OUS/AS4)
- `GET /api/digikoppeling/messages/{id}` — query message status
- `GET /api/digikoppeling/audit/{conversationId}` — retrieve audit trail for conversation

### Payload Handling

Payloads (XML, JSON, binary) are stored outside the database:
1. Compute SHA-256 hash of payload
2. Write payload to Nextcloud Files under `.digikoppeling/payloads/{messageId}`
3. Store reference + hash in `DigikoppelingMessage.payloadFileRef` + `payloadHash`
4. On retrieval: fetch from Files, verify hash, stream to caller

This avoids bloating Postgres with 50 MB SOAP envelopes while maintaining audit trail integrity.

### Retry Scheduler

System job runs every 5 minutes:
1. Query `DigikoppelingMessage` with `status IN (failed, pending)` and `nextAttemptAt <= now()`
2. For each message, check concurrency and throughput limits on remote endpoint
3. If slot available: re-invoke send with current attempt count
4. On success: mark `status=delivered|acknowledged`, write `acknowledged` audit event
5. On failure (transient): reschedule at `now() + delay`, write `retry-scheduled` event
6. On failure (permanent) or `expireAfterHours` exceeded: mark `status=expired`, write `expired` event, notify sender

### OpenConnector Integration

The adapter is consumed via OpenConnector's job system:
- A Digikoppeling endpoint appears in OpenConnector as a special source type
- Jobs reference a DigikoppelingEndpoint and pass a payload
- Job lifecycle events trigger Digikoppeling message creation, signing, and send
- Responses (WUS) or callbacks (OUS/Grote Berichten) trigger domain events for consuming workflows

## Seed Data

### DigikoppelingCertificate Examples

```json
[
  {
    "aansluitnummer": "00000",
    "oin": "00000001234567890",
    "certificateType": "signing",
    "pemCertificate": "-----BEGIN CERTIFICATE-----\nMIIDfTCC...",
    "pemChain": "-----BEGIN CERTIFICATE-----\nMIIDgTCC...",
    "validFrom": "2026-01-01T00:00:00Z",
    "validUntil": "2027-01-01T00:00:00Z",
    "subjectDN": "C=NL, O=Gemeente Amsterdam, CN=signing.gemeente-amsterdam.nl",
    "issuerDN": "C=NL, O=Logius, CN=PKIoverheid Sub CA Level 2",
    "serialNumber": "1234567890ABCDEF",
    "rotationReminderDays": 60
  }
]
```

### DigikoppelingEndpoint Examples

```json
[
  {
    "aansluitnummer": "00001",
    "oin": "00000002345678901",
    "name": "Gemeente Utrecht - Zaaksysteem",
    "profile": "WUS",
    "endpointUrl": "https://zaaksysteem.gemeente-utrecht.nl/digikoppeling/wus",
    "wsdlUrl": "https://zaaksysteem.gemeente-utrecht.nl/digikoppeling/wus?wsdl",
    "cpaId": null,
    "serviceNamespace": "http://www.stufzaken.nl/schemas/stuf-zaken",
    "messageTypes": ["stuf-zkn:bv03Bericht"],
    "pkiCertificateChain": "-----BEGIN CERTIFICATE-----\nMIIDfTCC...",
    "validFrom": "2026-01-01T00:00:00Z",
    "validUntil": "2027-01-01T00:00:00Z",
    "maxConcurrentRequests": 5,
    "requestsPerMinute": 60,
    "lastSyncedFromRegistry": "2026-05-22T10:00:00Z"
  },
  {
    "aansluitnummer": "00002",
    "oin": "00000003456789012",
    "name": "SVB - Berichtenbox",
    "profile": "OUS",
    "endpointUrl": "https://berichtenbox.svb.nl/digikoppeling/ous",
    "wsdlUrl": null,
    "cpaId": null,
    "serviceNamespace": "http://www.berichtenbox.nl/schemas/berichtenwisseling",
    "messageTypes": ["berichtenbox:uitkeringsverzoek", "berichtenbox:beschikking"],
    "pkiCertificateChain": "-----BEGIN CERTIFICATE-----\nMIIDgTCC...",
    "validFrom": "2026-01-01T00:00:00Z",
    "validUntil": "2027-01-01T00:00:00Z",
    "maxConcurrentRequests": 3,
    "requestsPerMinute": 30,
    "lastSyncedFromRegistry": "2026-05-22T10:00:00Z"
  },
  {
    "aansluitnummer": "00003",
    "oin": "00000004567890123",
    "name": "RDW - Grote Berichten Archive",
    "profile": "GroteBerichten",
    "endpointUrl": "https://archive.rdw.nl/digikoppeling/as4",
    "wsdlUrl": null,
    "cpaId": "urn:cpa:rdw-gemeente:1.0",
    "serviceNamespace": "http://www.rdw.nl/schemas/gbx4",
    "messageTypes": ["gbx4:voertuiggegevens"],
    "pkiCertificateChain": "-----BEGIN CERTIFICATE-----\nMIIDhTCC...",
    "validFrom": "2026-01-01T00:00:00Z",
    "validUntil": "2027-01-01T00:00:00Z",
    "maxConcurrentRequests": 2,
    "requestsPerMinute": 10,
    "lastSyncedFromRegistry": "2026-05-22T10:00:00Z"
  }
]
```

### DigikoppelingMessage Examples

```json
[
  {
    "direction": "outbound",
    "profile": "WUS",
    "localEndpointRef": "uuid-of-our-app",
    "remoteEndpointRef": "uuid-of-gemeente-utrecht-endpoint",
    "messageId": "msg-20260522-001",
    "conversationId": "conv-20260522-001",
    "messageType": "stuf-zkn:bv03Bericht",
    "payloadHash": "abc123def456...",
    "payloadFileRef": "files://admin/digikoppeling/payloads/msg-20260522-001",
    "payloadSizeBytes": 2048,
    "status": "delivered",
    "statusReason": null,
    "attemptCount": 1,
    "nextAttemptAt": null,
    "firstAttemptAt": "2026-05-22T10:15:00Z",
    "lastAttemptAt": "2026-05-22T10:15:30Z",
    "acknowledgedAt": "2026-05-22T10:15:45Z",
    "expiresAt": "2026-05-22T11:15:00Z",
    "signingCertificateRef": "uuid-of-our-signing-cert",
    "correlatesTo": null,
    "triggeredByJobRef": "job-procest-zaken-doorgezet"
  },
  {
    "direction": "outbound",
    "profile": "OUS",
    "localEndpointRef": "uuid-of-our-app",
    "remoteEndpointRef": "uuid-of-svb-endpoint",
    "messageId": "msg-20260522-002",
    "conversationId": "conv-20260522-002",
    "messageType": "berichtenbox:uitkeringsverzoek",
    "payloadHash": "def456abc123...",
    "payloadFileRef": "files://admin/digikoppeling/payloads/msg-20260522-002",
    "payloadSizeBytes": 4096,
    "status": "acknowledged",
    "statusReason": null,
    "attemptCount": 1,
    "nextAttemptAt": null,
    "firstAttemptAt": "2026-05-22T11:00:00Z",
    "lastAttemptAt": "2026-05-22T11:00:10Z",
    "acknowledgedAt": "2026-05-22T11:15:20Z",
    "expiresAt": "2026-05-23T03:00:00Z",
    "signingCertificateRef": "uuid-of-our-signing-cert",
    "correlatesTo": null,
    "triggeredByJobRef": "job-zaakafhandelapp-verzoek-svb"
  }
]
```

### DigikoppelingRetryPolicy (Defaults)

```json
[
  {
    "profile": "WUS",
    "initialDelaySeconds": 0,
    "maxAttempts": 1,
    "backoffMultiplier": 1.0,
    "maxDelaySeconds": 0,
    "expireAfterHours": 1
  },
  {
    "profile": "OUS",
    "initialDelaySeconds": 120,
    "maxAttempts": 7,
    "backoffMultiplier": 2.0,
    "maxDelaySeconds": 3600,
    "expireAfterHours": 4
  },
  {
    "profile": "GroteBerichten",
    "initialDelaySeconds": 600,
    "maxAttempts": 14,
    "backoffMultiplier": 2.0,
    "maxDelaySeconds": 7200,
    "expireAfterHours": 24
  },
  {
    "profile": "BestEffort",
    "initialDelaySeconds": 30,
    "maxAttempts": 3,
    "backoffMultiplier": 2.0,
    "maxDelaySeconds": 300,
    "expireAfterHours": 1
  }
]
```

## Observability

One structured log entry per upstream message send (not per controller call):
- `messageId`, `profile`, `remoteEndpointRef`, `messageType`
- `status` (success / transient-error / permanent-error)
- `attemptCount`, `upstreamLatencyMs`
- `httpStatus`, `soapFaultCode` (if applicable)
- `signatureVerified`, `receiptAcknowledged` (for async profiles)

Log levels: `debug` for 2xx, `warning` for transient errors (429, 5xx), `error` for permanent failures, `critical` for cert expiry.
