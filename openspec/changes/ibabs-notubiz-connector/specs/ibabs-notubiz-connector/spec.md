---
status: proposed
---

# iBabs & NotuBiz Connector

## Purpose

Provides bidirectional integration with iBabs and NotuBiz -- the two dominant raadsinformatiesystemen (RIS) used by Dutch municipalities for bestuurlijke besluitvorming (B&W/College). Found as a requirement in 20+ tenders: iBabs in 12+ and NotuBiz in 8+. The connector pushes collegevoorstellen and documents from Procest to the RIS for vergaderbehandeling, and receives besluiten and besluitenlijsten back into the zaak. Implements the standard B&W workflow pattern: documenten heen, besluiten terug.

## Requirements

### REQ-RIS-001: iBabs REST API Connection

The connector MUST establish authenticated connections to the iBabs REST API using API key authentication. The connection is configured as an OpenConnector Source entity of type `json` with auth method `apikey`. The source stores the iBabs API URL (typically `https://api.ibabs.eu`), API key, and organisatie-ID. All API calls are routed through CallService which logs each request in the CallLog for audit and debugging.

**Scenarios:**

1. **GIVEN** an administrator creates a new Source with type "json" and auth "apikey" for iBabs **AND** enters the iBabs API URL, API key, and organisatie-ID in the configuration **WHEN** they save the source **THEN** the Source entity is persisted with the iBabs-specific configuration in the `configuration` JSON field and the source is marked as enabled.

2. **GIVEN** an iBabs source is configured **WHEN** the administrator clicks "Test Connection" **THEN** CallService makes a lightweight GET request to the iBabs API (e.g., listing vergaderingen) and the response status is shown -- 200 OK confirms connectivity, 401 indicates invalid API key.

3. **GIVEN** an iBabs API call returns rate limit headers (`X-RateLimit-Remaining`, `X-RateLimit-Reset`) **WHEN** CallService processes the response **THEN** the Source entity's rate limit fields are updated automatically (existing CallService.sourceRateLimit() behavior) preventing excessive API calls.

4. **GIVEN** the iBabs API key expires or is revoked **WHEN** the next API call returns HTTP 401 **THEN** the CallLog records the failure, the Source status is updated to "error", and a Nextcloud notification is sent to the administrator.

### REQ-RIS-002: Collegevoorstel Push to iBabs

The connector MUST push a collegevoorstel (advies document plus bijlagen) from Procest to iBabs as a vergaderstuk. The push extracts the voorstel document from Nextcloud Files, converts to PDF if needed via Docudesk, and uploads it to iBabs with metadata (onderwerp, portefeuillehouder, zaaktype).

**Scenarios:**

1. **GIVEN** a zaak "Bestemmingsplan Centrum" has a voorstel document in Nextcloud Files **WHEN** the connector pushes the voorstel to iBabs **THEN** the document is uploaded via the iBabs document API with metadata fields: onderwerp from zaak omschrijving, portefeuillehouder from zaak-eigenschap, and zaaktype from Procest.

2. **GIVEN** the voorstel document is a DOCX file **WHEN** the connector prepares the push **THEN** Docudesk converts the DOCX to PDF before uploading to iBabs.

3. **GIVEN** the zaak has 3 bijlagen (advies, tekening, financieel overzicht) **WHEN** the connector pushes the voorstel **THEN** all bijlagen are uploaded to iBabs linked to the same vergaderstuk.

4. **GIVEN** document upload to iBabs fails with HTTP 413 (payload too large) **WHEN** the connector handles the error **THEN** a CallLog entry is created with the error, the sync record is set to "failed", and the behandelaar receives a notification suggesting document compression.

5. **GIVEN** the voorstel has a geheimhouding flag set on the zaak **WHEN** the connector pushes to iBabs **THEN** the document is marked as vertrouwelijk in the iBabs API metadata.

### REQ-RIS-003: Agendapunt Creation in iBabs

The connector MUST create or update an agendapunt in iBabs linked to the uploaded collegevoorstel. The target vergadering is determined by configuration: either the next upcoming collegevergadering (auto-select), a specific vergadering selected by the behandelaar, or a default vergadering type configured in the source settings.

**Scenarios:**

1. **GIVEN** a voorstel is pushed to iBabs **AND** the source configuration specifies auto-select for the next collegevergadering **WHEN** the connector creates the agendapunt **THEN** the iBabs API is queried for upcoming vergaderingen, the next one is selected, and the agendapunt is created with the voorstel linked.

2. **GIVEN** the behandelaar selects a specific vergadering for the voorstel **WHEN** the connector creates the agendapunt **THEN** it uses the selected vergadering ID from the zaak-eigenschap.

3. **GIVEN** no upcoming vergadering exists in iBabs **WHEN** the connector attempts to create an agendapunt **THEN** a warning is logged and the sync record is set to "pending" until a vergadering becomes available.

### REQ-RIS-004: Besluit Retrieval from iBabs

The connector MUST retrieve besluiten from iBabs after vergaderbehandeling. Retrieval happens via polling (configurable interval, default 15 minutes) or webhook if available. The besluit status (aangenomen, verworpen, aangehouden, doorgeschoven) is mapped to a Procest zaak status update.

**Scenarios:**

1. **GIVEN** a voorstel was pushed to iBabs for zaak "Bestemmingsplan Centrum" **AND** the college has the voorstel aangenomen **WHEN** the inbound poll retrieves the besluit **THEN** the zaak status in Procest is updated to reflect "Besluit: aangenomen" and the besluitdatum is recorded.

2. **GIVEN** the college has the voorstel verworpen **WHEN** the besluit is retrieved **THEN** the zaak status is updated to "Besluit: verworpen" and a notification is sent to the behandelaar and portefeuillehouder.

3. **GIVEN** the voorstel is aangehouden (deferred to a future vergadering) **WHEN** the besluit is retrieved **THEN** the zaak status is updated to "Besluit: aangehouden" and the connector watches for the rescheduled vergadering.

4. **GIVEN** the college modifies the voorstel before besluit (e.g., amendement) **WHEN** the besluit is retrieved with modifications **THEN** the modifications are noted in the sync record and the behandelaar is notified of the discrepancy.

### REQ-RIS-005: Besluitenlijst Retrieval

The connector MUST retrieve the besluitenlijst (PDF/document) from iBabs after vergaderbehandeling, store it in Nextcloud Files, and link it to the source zaak.

**Scenarios:**

1. **GIVEN** a collegevergadering has concluded **WHEN** the connector polls for the besluitenlijst **THEN** the besluitenlijst PDF is downloaded, stored in `/RIS-besluiten/{year}/{vergadering-datum}/`, and linked to all zaken that had voorstellen in that vergadering.

2. **GIVEN** the besluitenlijst is not yet published in iBabs (vergadering just ended) **WHEN** the connector polls **THEN** it retries at the configured interval until the besluitenlijst becomes available.

3. **GIVEN** the besluitenlijst contains entries for 12 voorstellen from 12 different zaken **WHEN** the connector processes the besluitenlijst **THEN** each relevant zaak receives a link to the besluitenlijst document.

### REQ-RIS-020: NotuBiz API Connection

The connector MUST connect to the NotuBiz API with OAuth2 or API key authentication. The connection is configured as an OpenConnector Source entity with NotuBiz-specific configuration including organisatie-ID and default vergadertype. Authentication supports both OAuth2 (via AuthenticationService's existing client_credentials flow) and API key methods.

**Scenarios:**

1. **GIVEN** an administrator creates a NotuBiz source with OAuth2 authentication **WHEN** they configure the client_id, client_secret, and token endpoint **THEN** the Source entity uses the existing AuthenticationService OAuth2 flow to obtain and refresh access tokens automatically.

2. **GIVEN** a NotuBiz source is configured **WHEN** the administrator tests connectivity **THEN** a lightweight API call verifies the connection and returns organisatie details from NotuBiz.

3. **GIVEN** the NotuBiz OAuth2 token expires **WHEN** the next API call is made **THEN** AuthenticationService automatically refreshes the token using the stored credentials before retrying the call.

### REQ-RIS-021: Vergaderstuk Push to NotuBiz

The connector MUST push vergaderstukken (voorstel plus bijlagen) to NotuBiz for vergaderbehandeling. The push supports multiple event types: collegevergadering, raadsvergadering, and commissievergadering.

**Scenarios:**

1. **GIVEN** a zaak requires raadsbehandeling after collegebesluit **WHEN** the connector pushes stukken to NotuBiz **THEN** vergaderstukken are uploaded with the correct vergadertype (raadsvergadering) and metadata.

2. **GIVEN** a voorstel requires commissiebehandeling before raadsbehandeling **WHEN** the connector pushes to NotuBiz **THEN** the vergaderstukken are first linked to the commissievergadering, and after commissiebehandeling, forwarded to the raadsvergadering.

3. **GIVEN** a document pushed to NotuBiz needs to be updated (nieuwe versie) **WHEN** the behandelaar uploads a revised document **THEN** the connector updates the existing vergaderstuk in NotuBiz with the new version, preserving the agendapunt link.

### REQ-RIS-030: Status-Based Outbound Sync

The connector MUST trigger outbound sync when a zaak reaches the configurable status "Ter besluitvorming" in Procest. The trigger is implemented via OpenConnector's EventService which listens for zaak status change events from Procest. Only zaken with completed parafering (all required parafen collected) are eligible for push.

**Scenarios:**

1. **GIVEN** a zaak "Subsidieregeling Cultuur" reaches status "Ter besluitvorming" **AND** all required paraferingen are completed **WHEN** the status change event fires **THEN** the connector automatically pushes the voorstel to the configured RIS (iBabs or NotuBiz) and creates a sync record with status "synced".

2. **GIVEN** a zaak reaches "Ter besluitvorming" but parafering is incomplete **WHEN** the status change event fires **THEN** the connector blocks the push, sets the sync record to "pending", and notifies the behandelaar that parafering must be completed first.

3. **GIVEN** both iBabs and NotuBiz sources are configured **AND** the zaak requires both college and raadsbehandeling **WHEN** the outbound sync triggers **THEN** the connector pushes to iBabs for collegebesluit first, and after college aanname, pushes to NotuBiz for raadsbehandeling.

### REQ-RIS-031: Inbound Besluit Sync

The connector MUST poll or receive webhooks for besluit updates from the configured RIS and update the source zaak in Procest. Polling uses JobService background jobs at configurable intervals (default: 15 minutes). Each poll checks all sync records with status "synced" (outbound push completed) for besluit responses.

**Scenarios:**

1. **GIVEN** a voorstel was pushed to iBabs 3 hours ago **WHEN** the background poll job runs **THEN** it queries the iBabs API for the agendapunt status, finds "aangenomen", and updates the zaak status in Procest.

2. **GIVEN** the poll finds no besluit yet (vergadering has not occurred) **WHEN** polling runs **THEN** the sync record remains "synced" and the poll continues at the next interval.

3. **GIVEN** the RIS API is temporarily unavailable during polling **WHEN** the poll encounters an HTTP 503 **THEN** a CallLog error is recorded and the poll retries at the next scheduled interval.

### REQ-RIS-033: Sync Audit Trail

The connector MUST log all sync operations as OpenRegister objects for a complete audit trail. Each sync record captures direction (push/pull), timestamp, status, document IDs, and error details. The audit trail enables compliance with the Archiefwet requirement for traceability of bestuurlijke besluitvorming.

**Scenarios:**

1. **GIVEN** a voorstel push to iBabs succeeds **WHEN** the sync completes **THEN** a sync record is created with: zaakId, risType "ibabs", direction "outbound", status "synced", syncedAt timestamp, and document references (Nextcloud file ID mapped to iBabs document ID).

2. **GIVEN** a besluit retrieval from NotuBiz succeeds **WHEN** the sync completes **THEN** a sync record is created with direction "inbound", the besluit document reference, and the mapped zaak status.

3. **GIVEN** an auditor queries the sync history for a specific zaak **WHEN** they filter sync records by zaakId **THEN** they see the complete chronological history of all outbound pushes and inbound pulls with timestamps and statussen.

### REQ-RIS-034: Retry with Configurable Backoff

The connector MUST retry failed sync operations with configurable backoff intervals (default: 3 retries at 5, 15, and 60 minutes). Retries use the JobService to schedule future attempts. After all retries are exhausted, the sync record MUST be set to "failed" and a notification MUST be sent.

**Scenarios:**

1. **GIVEN** a voorstel push fails due to an iBabs API timeout **WHEN** the first retry triggers after 5 minutes **THEN** the push is reattempted with the same payload and credentials.

2. **GIVEN** the first and second retries also fail **WHEN** the third retry at 60 minutes also fails **THEN** the sync record status is set to "failed" with the accumulated error messages, and a notification is sent to the behandelaar with a manual retry option.

3. **GIVEN** the second retry succeeds **WHEN** the push completes successfully **THEN** the sync record status is updated to "synced" and no further retries are scheduled.

### REQ-RIS-040: Document Flow Management

The connector MUST manage bidirectional document flow: outbound documents are exported from Nextcloud Files, converted to PDF via Docudesk if needed, and pushed to the RIS. Inbound documents (besluit, besluitenlijst) are downloaded from the RIS, stored in Nextcloud Files, and linked to the zaak. Document metadata (onderwerp, datum, portefeuillehouder, zaaktype, geheimhouding) is mapped bidirectionally.

**Scenarios:**

1. **GIVEN** a zaak has 5 documents in Nextcloud Files (voorstel, 3 bijlagen, conceptbesluit) **WHEN** the outbound sync triggers **THEN** all documents are exported, non-PDF documents are converted via Docudesk, and uploaded to the RIS with metadata derived from zaak-eigenschappen.

2. **GIVEN** a document in the RIS is marked as vertrouwelijk **WHEN** the inbound sync retrieves it **THEN** the document is stored in Nextcloud Files with restricted permissions matching the zaak's geheimhouding level.

3. **GIVEN** the RIS returns a besluit document in a non-standard format **WHEN** the connector downloads it **THEN** it is stored as-is in Nextcloud Files with the original format, and a PDF conversion is attempted via Docudesk for display purposes.

### REQ-RIS-050: Parafering Tracking

The connector MUST track parafering status within Procest before allowing push to the RIS. The parafering route follows the standard municipal chain: steller, adviseur, parafeerder, portefeuillehouder, secretariaat. Only after all required paraferingen are completed is the push enabled. The parafering route is configurable per zaaktype (sequential, parallel, or mixed).

**Scenarios:**

1. **GIVEN** a zaak requires sequential parafering: steller > adviseur > parafeerder > portefeuillehouder **WHEN** the steller and adviseur have parafen but the parafeerder has not **THEN** the connector blocks outbound push and shows parafering progress (2/4 completed) in the sync status.

2. **GIVEN** a zaaktype is configured with parallel parafering for adviseur and juridisch adviseur **WHEN** both adviseurs have parafen **THEN** the parafering proceeds to the next sequential step (parafeerder).

3. **GIVEN** all required paraferingen are completed **WHEN** the secretariaat adds the final paraaf **THEN** the zaak automatically transitions to "Ter besluitvorming" and the outbound sync triggers.

### REQ-RIS-060: OpenConnector Endpoint Registration

The connector MUST be registered as OpenConnector endpoint types with separate configurations for iBabs and NotuBiz. Connection settings include API URL, authentication credentials, organisatie-ID, and default vergadertype. Health checks validate API connectivity and authentication.

**Scenarios:**

1. **GIVEN** an administrator wants to connect both iBabs and NotuBiz **WHEN** they create two separate Source entities **THEN** each source has its own configuration (API URL, credentials, organisatie-ID) and can be used independently or together for college+raad workflows.

2. **GIVEN** an iBabs source is configured **WHEN** an n8n workflow references the source **THEN** it can trigger custom B&W-besluitvorming workflows including document preparation, parafering reminders, and besluit notifications.

3. **GIVEN** the health check runs on the NotuBiz source **WHEN** the API responds but authentication fails **THEN** the health check reports "degraded" with the specific authentication error.

## Data Model

### Sync Record (stored in OpenRegister)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| zaakId | string (UUID) | Yes | Source zaak in Procest |
| risType | string (enum) | Yes | `ibabs` or `notubiz` |
| risDocumentId | string | No | Document/agendapunt ID in the RIS |
| risVergaderingId | string | No | Vergadering ID in the RIS |
| direction | string (enum) | Yes | `outbound` (push) or `inbound` (pull) |
| status | string (enum) | Yes | `pending`, `synced`, `failed`, `conflict` |
| syncedAt | datetime | No | Timestamp of last successful sync |
| retryCount | integer | No | Number of retries attempted |
| nextRetryAt | datetime | No | Scheduled time for next retry |
| errorMessage | string | No | Error details if status is `failed` |
| documents | array | No | List of document references (Nextcloud file ID + RIS doc ID) |
| besluitStatus | string (enum) | No | `aangenomen`, `verworpen`, `aangehouden`, `doorgeschoven` |
| paraferingStatus | object | No | Current parafering progress (completed/total, current step) |

## Dependencies

- **OpenConnector**: Source registration and connection management (Source entity, CallService, EndpointService, EventService, JobService)
- **OpenRegister**: Sync record storage and zaak object access
- **Procest**: Zaak lifecycle management and parafering workflow
- **Docudesk**: PDF conversion for outbound documents
- **iBabs REST API**: External service (api.ibabs.eu)
- **NotuBiz API**: External service (api.notubiz.nl)

### Using Mock Register Data

The **ORI** mock register provides test data for developing the iBabs/NotuBiz connector without requiring access to production RIS systems.

**Loading the register:**
```bash
# Load ORI register (115 records, register slug: "ori", schemas: "vergadering", "agendapunt", "raadsdocument", "stemming", "raadslid", "fractie")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/ori_register.json
```

**Test data for this spec's use cases:**
- **Vergadering retrieval (REQ-RIS-003)**: 10+ vergaderingen with dates and types (raadsvergadering, commissievergadering) -- test sync back to ORI register
- **Agendapunt creation (REQ-RIS-003)**: 30+ agendapunten linked to vergaderingen -- test push/pull of agenda items
- **Besluit mapping (REQ-RIS-004)**: Stemmingen with aangenomen/verworpen results -- test besluit status mapping
- **Document handling (REQ-RIS-002)**: 15+ raadsdocumenten (moties, amendementen, besluiten) -- test document upload/download sync

## Current Implementation Status

### Implemented
- **None of the iBabs/NotuBiz-specific requirements are implemented.** There is no iBabs connector, NotuBiz connector, parafering workflow, or RIS sync mechanism in the codebase.

### Partially relevant existing infrastructure
- **Source entity** (`lib/Db/Source.php`, `src/entities/source/source.types.ts`): Supports source types `json`, `xml`, `soap`, `ftp`, `sftp` with multiple auth methods including `apikey`, `jwt`, `oauth`. Both iBabs (REST + API key) and NotuBiz (OAuth2/API key) can be configured as `json`-type sources with appropriate auth.
- **CallService** (`lib/Service/CallService.php`): Generic HTTP client that handles REST calls to configured sources with full request/response logging, rate limiting, retry support, and authentication via Twig template rendering.
- **SynchronizationService** (`lib/Service/SynchronizationService.php`): Full bidirectional sync framework with contracts, logs, and mapping. Supports sync between external sources and OpenRegister objects. This is directly relevant for outbound/inbound sync.
- **AuthenticationService** (`lib/Service/AuthenticationService.php`): Handles OAuth2 client_credentials flow, JWT, API key, and password authentication -- all needed for iBabs/NotuBiz auth methods.
- **EndpointService** (`lib/Service/EndpointService.php`): Manages endpoint configuration and routing with target types.
- **JobService** (`lib/Service/JobService.php`): Background job execution for polling and retry logic.
- **EventService** (`lib/Service/EventService.php`): Event dispatching for zaak status change triggers.

### Not implemented
- iBabs REST API client (document upload, agendapunt creation, besluit retrieval)
- NotuBiz API client (vergaderstuk upload, agendapunt, besluit retrieval)
- Bidirectional sync triggers (status-based outbound push, polling/webhook inbound)
- Sync record storage (the data model described in the spec)
- Conflict detection
- Retry with configurable backoff
- Parafering workflow (entirely within Procest scope)
- Document flow with PDF conversion via Docudesk
- Geheimhouding flag mapping
- RIS-specific source type registration

## Standards & References

- **iBabs REST API**: Proprietary API by iBabs BV (now part of Meeting.nl). Documented at developer.ibabs.eu. Uses API key authentication, REST/JSON format.
- **NotuBiz API**: Proprietary API by NotuBiz BV (part of CMSolutions). Supports OAuth2 and API key auth. REST/JSON format.
- **Gemeentelijke besluitvormingsprocessen**: The B&W-besluitvorming workflow is standardized across Dutch municipalities: steller > adviseur > parafeerder > portefeuillehouder > secretariaat > collegevergadering > besluit.
- **GEMMA procesarchitectuur**: The reference architecture for Dutch municipal decision-making processes.
- **Archiefwet**: Dutch archiving law -- besluitenlijsten and vergaderstukken must be archived according to selectielijsten.
- **ORI (Open Raadsinformatie)**: Open data standard for Dutch council information, maintained by VNG.
