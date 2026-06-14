# ibabs-notubiz-connector Specification

## Purpose
TBD - created by archiving change ibabs-notubiz-connector. Update Purpose after archive.
## Requirements
### Requirement: iBabs REST API Connection (REQ-RIS-001)

The connector MUST establish authenticated connections to the iBabs REST API using API key authentication. The connection is configured as an OpenConnector Source entity of type `json` with auth method `apikey`. The source stores the iBabs API URL (typically `https://api.ibabs.eu`), API key, and organisatie-ID. All API calls are routed through CallService which logs each request in the CallLog for audit and debugging.

#### Scenario: Persist iBabs source configuration
- **WHEN** an administrator creates a new Source with type "json" and auth "apikey" for iBabs, enters the iBabs API URL, API key, and organisatie-ID in the configuration, and saves the source
- **THEN** the Source entity is persisted with the iBabs-specific configuration in the `configuration` JSON field and the source is marked as enabled

#### Scenario: Test connection to iBabs
- **WHEN** an iBabs source is configured and the administrator clicks "Test Connection"
- **THEN** CallService makes a lightweight GET request to the iBabs API (e.g., listing vergaderingen) and the response status is shown -- 200 OK confirms connectivity, 401 indicates invalid API key

#### Scenario: Update rate limit fields from response headers
- **WHEN** an iBabs API call returns rate limit headers (`X-RateLimit-Remaining`, `X-RateLimit-Reset`) and CallService processes the response
- **THEN** the Source entity's rate limit fields are updated automatically (existing CallService.sourceRateLimit() behavior) preventing excessive API calls

#### Scenario: Handle expired or revoked API key
- **WHEN** the iBabs API key expires or is revoked and the next API call returns HTTP 401
- **THEN** the CallLog records the failure, the Source status is updated to "error", and a Nextcloud notification is sent to the administrator

### Requirement: Collegevoorstel Push to iBabs (REQ-RIS-002)

The connector MUST push a collegevoorstel (advies document plus bijlagen) from Procest to iBabs as a vergaderstuk. The push extracts the voorstel document from Nextcloud Files, converts to PDF if needed via Docudesk, and uploads it to iBabs with metadata (onderwerp, portefeuillehouder, zaaktype).

#### Scenario: Push voorstel with metadata
- **WHEN** a zaak "Bestemmingsplan Centrum" has a voorstel document in Nextcloud Files and the connector pushes the voorstel to iBabs
- **THEN** the document is uploaded via the iBabs document API with metadata fields: onderwerp from zaak omschrijving, portefeuillehouder from zaak-eigenschap, and zaaktype from Procest

#### Scenario: Convert DOCX to PDF before push
- **WHEN** the voorstel document is a DOCX file and the connector prepares the push
- **THEN** Docudesk converts the DOCX to PDF before uploading to iBabs

#### Scenario: Upload bijlagen linked to vergaderstuk
- **WHEN** the zaak has 3 bijlagen (advies, tekening, financieel overzicht) and the connector pushes the voorstel
- **THEN** all bijlagen are uploaded to iBabs linked to the same vergaderstuk

#### Scenario: Handle payload too large on upload
- **WHEN** document upload to iBabs fails with HTTP 413 (payload too large) and the connector handles the error
- **THEN** a CallLog entry is created with the error, the sync record is set to "failed", and the behandelaar receives a notification suggesting document compression

#### Scenario: Mark geheimhouding documents as vertrouwelijk
- **WHEN** the voorstel has a geheimhouding flag set on the zaak and the connector pushes to iBabs
- **THEN** the document is marked as vertrouwelijk in the iBabs API metadata

### Requirement: Agendapunt Creation in iBabs (REQ-RIS-003)

The connector MUST create or update an agendapunt in iBabs linked to the uploaded collegevoorstel. The target vergadering is determined by configuration: either the next upcoming collegevergadering (auto-select), a specific vergadering selected by the behandelaar, or a default vergadering type configured in the source settings.

#### Scenario: Auto-select next collegevergadering
- **WHEN** a voorstel is pushed to iBabs, the source configuration specifies auto-select for the next collegevergadering, and the connector creates the agendapunt
- **THEN** the iBabs API is queried for upcoming vergaderingen, the next one is selected, and the agendapunt is created with the voorstel linked

#### Scenario: Use behandelaar-selected vergadering
- **WHEN** the behandelaar selects a specific vergadering for the voorstel and the connector creates the agendapunt
- **THEN** it uses the selected vergadering ID from the zaak-eigenschap

#### Scenario: No upcoming vergadering available
- **WHEN** no upcoming vergadering exists in iBabs and the connector attempts to create an agendapunt
- **THEN** a warning is logged and the sync record is set to "pending" until a vergadering becomes available

### Requirement: Besluit Retrieval from iBabs (REQ-RIS-004)

The connector MUST retrieve besluiten from iBabs after vergaderbehandeling. Retrieval happens via polling (configurable interval, default 15 minutes) or webhook if available. The besluit status (aangenomen, verworpen, aangehouden, doorgeschoven) is mapped to a Procest zaak status update.

#### Scenario: Retrieve aangenomen besluit
- **WHEN** a voorstel was pushed to iBabs for zaak "Bestemmingsplan Centrum", the college has the voorstel aangenomen, and the inbound poll retrieves the besluit
- **THEN** the zaak status in Procest is updated to reflect "Besluit: aangenomen" and the besluitdatum is recorded

#### Scenario: Retrieve verworpen besluit
- **WHEN** the college has the voorstel verworpen and the besluit is retrieved
- **THEN** the zaak status is updated to "Besluit: verworpen" and a notification is sent to the behandelaar and portefeuillehouder

#### Scenario: Retrieve aangehouden besluit
- **WHEN** the voorstel is aangehouden (deferred to a future vergadering) and the besluit is retrieved
- **THEN** the zaak status is updated to "Besluit: aangehouden" and the connector watches for the rescheduled vergadering

#### Scenario: Retrieve modified voorstel
- **WHEN** the college modifies the voorstel before besluit (e.g., amendement) and the besluit is retrieved with modifications
- **THEN** the modifications are noted in the sync record and the behandelaar is notified of the discrepancy

### Requirement: Besluitenlijst Retrieval (REQ-RIS-005)

The connector MUST retrieve the besluitenlijst (PDF/document) from iBabs after vergaderbehandeling, store it in Nextcloud Files, and link it to the source zaak.

#### Scenario: Download and link besluitenlijst
- **WHEN** a collegevergadering has concluded and the connector polls for the besluitenlijst
- **THEN** the besluitenlijst PDF is downloaded, stored in `/RIS-besluiten/{year}/{vergadering-datum}/`, and linked to all zaken that had voorstellen in that vergadering

#### Scenario: Retry until besluitenlijst published
- **WHEN** the besluitenlijst is not yet published in iBabs (vergadering just ended) and the connector polls
- **THEN** it retries at the configured interval until the besluitenlijst becomes available

#### Scenario: Link besluitenlijst to multiple zaken
- **WHEN** the besluitenlijst contains entries for 12 voorstellen from 12 different zaken and the connector processes the besluitenlijst
- **THEN** each relevant zaak receives a link to the besluitenlijst document

### Requirement: NotuBiz API Connection (REQ-RIS-020)

The connector MUST connect to the NotuBiz API with OAuth2 or API key authentication. The connection is configured as an OpenConnector Source entity with NotuBiz-specific configuration including organisatie-ID and default vergadertype. Authentication supports both OAuth2 (via AuthenticationService's existing client_credentials flow) and API key methods.

#### Scenario: Configure NotuBiz OAuth2 source
- **WHEN** an administrator creates a NotuBiz source with OAuth2 authentication and configures the client_id, client_secret, and token endpoint
- **THEN** the Source entity uses the existing AuthenticationService OAuth2 flow to obtain and refresh access tokens automatically

#### Scenario: Test NotuBiz connectivity
- **WHEN** a NotuBiz source is configured and the administrator tests connectivity
- **THEN** a lightweight API call verifies the connection and returns organisatie details from NotuBiz

#### Scenario: Refresh expired OAuth2 token
- **WHEN** the NotuBiz OAuth2 token expires and the next API call is made
- **THEN** AuthenticationService automatically refreshes the token using the stored credentials before retrying the call

### Requirement: Vergaderstuk Push to NotuBiz (REQ-RIS-021)

The connector MUST push vergaderstukken (voorstel plus bijlagen) to NotuBiz for vergaderbehandeling. The push supports multiple event types: collegevergadering, raadsvergadering, and commissievergadering.

#### Scenario: Push stukken for raadsbehandeling
- **WHEN** a zaak requires raadsbehandeling after collegebesluit and the connector pushes stukken to NotuBiz
- **THEN** vergaderstukken are uploaded with the correct vergadertype (raadsvergadering) and metadata

#### Scenario: Route via commissievergadering before raad
- **WHEN** a voorstel requires commissiebehandeling before raadsbehandeling and the connector pushes to NotuBiz
- **THEN** the vergaderstukken are first linked to the commissievergadering, and after commissiebehandeling, forwarded to the raadsvergadering

#### Scenario: Update existing vergaderstuk with new version
- **WHEN** a document pushed to NotuBiz needs to be updated (nieuwe versie) and the behandelaar uploads a revised document
- **THEN** the connector updates the existing vergaderstuk in NotuBiz with the new version, preserving the agendapunt link

### Requirement: Status-Based Outbound Sync (REQ-RIS-030)

The connector MUST trigger outbound sync when a zaak reaches the configurable status "Ter besluitvorming" in Procest. The trigger is implemented via OpenConnector's EventService which listens for zaak status change events from Procest. Only zaken with completed parafering (all required parafen collected) are eligible for push.

#### Scenario: Trigger push on Ter besluitvorming with complete parafering
- **WHEN** a zaak "Subsidieregeling Cultuur" reaches status "Ter besluitvorming", all required paraferingen are completed, and the status change event fires
- **THEN** the connector automatically pushes the voorstel to the configured RIS (iBabs or NotuBiz) and creates a sync record with status "synced"

#### Scenario: Block push on incomplete parafering
- **WHEN** a zaak reaches "Ter besluitvorming" but parafering is incomplete and the status change event fires
- **THEN** the connector blocks the push, sets the sync record to "pending", and notifies the behandelaar that parafering must be completed first

#### Scenario: Push to iBabs then NotuBiz for college plus raad
- **WHEN** both iBabs and NotuBiz sources are configured, the zaak requires both college and raadsbehandeling, and the outbound sync triggers
- **THEN** the connector pushes to iBabs for collegebesluit first, and after college aanname, pushes to NotuBiz for raadsbehandeling

### Requirement: Inbound Besluit Sync (REQ-RIS-031)

The connector MUST poll or receive webhooks for besluit updates from the configured RIS and update the source zaak in Procest. Polling uses JobService background jobs at configurable intervals (default: 15 minutes). Each poll checks all sync records with status "synced" (outbound push completed) for besluit responses.

#### Scenario: Poll finds aangenomen status
- **WHEN** a voorstel was pushed to iBabs 3 hours ago and the background poll job runs
- **THEN** it queries the iBabs API for the agendapunt status, finds "aangenomen", and updates the zaak status in Procest

#### Scenario: Poll finds no besluit yet
- **WHEN** the poll finds no besluit yet (vergadering has not occurred) and polling runs
- **THEN** the sync record remains "synced" and the poll continues at the next interval

#### Scenario: RIS API unavailable during poll
- **WHEN** the RIS API is temporarily unavailable during polling and the poll encounters an HTTP 503
- **THEN** a CallLog error is recorded and the poll retries at the next scheduled interval

### Requirement: Sync Audit Trail (REQ-RIS-033)

The connector MUST log all sync operations as OpenRegister objects for a complete audit trail. Each sync record captures direction (push/pull), timestamp, status, document IDs, and error details. The audit trail enables compliance with the Archiefwet requirement for traceability of bestuurlijke besluitvorming.

#### Scenario: Record outbound push to iBabs
- **WHEN** a voorstel push to iBabs succeeds and the sync completes
- **THEN** a sync record is created with: zaakId, risType "ibabs", direction "outbound", status "synced", syncedAt timestamp, and document references (Nextcloud file ID mapped to iBabs document ID)

#### Scenario: Record inbound besluit from NotuBiz
- **WHEN** a besluit retrieval from NotuBiz succeeds and the sync completes
- **THEN** a sync record is created with direction "inbound", the besluit document reference, and the mapped zaak status

#### Scenario: Query sync history for a zaak
- **WHEN** an auditor queries the sync history for a specific zaak and filters sync records by zaakId
- **THEN** they see the complete chronological history of all outbound pushes and inbound pulls with timestamps and statussen

### Requirement: Retry with Configurable Backoff (REQ-RIS-034)

The connector MUST retry failed sync operations with configurable backoff intervals (default: 3 retries at 5, 15, and 60 minutes). Retries use the JobService to schedule future attempts. After all retries are exhausted, the sync record MUST be set to "failed" and a notification MUST be sent.

#### Scenario: First retry after timeout
- **WHEN** a voorstel push fails due to an iBabs API timeout and the first retry triggers after 5 minutes
- **THEN** the push is reattempted with the same payload and credentials

#### Scenario: All retries exhausted
- **WHEN** the first and second retries also fail and the third retry at 60 minutes also fails
- **THEN** the sync record status is set to "failed" with the accumulated error messages, and a notification is sent to the behandelaar with a manual retry option

#### Scenario: Retry succeeds
- **WHEN** the second retry succeeds and the push completes successfully
- **THEN** the sync record status is updated to "synced" and no further retries are scheduled

### Requirement: Document Flow Management (REQ-RIS-040)

The connector MUST manage bidirectional document flow: outbound documents are exported from Nextcloud Files, converted to PDF via Docudesk if needed, and pushed to the RIS. Inbound documents (besluit, besluitenlijst) are downloaded from the RIS, stored in Nextcloud Files, and linked to the zaak. Document metadata (onderwerp, datum, portefeuillehouder, zaaktype, geheimhouding) is mapped bidirectionally.

#### Scenario: Export and convert outbound documents
- **WHEN** a zaak has 5 documents in Nextcloud Files (voorstel, 3 bijlagen, conceptbesluit) and the outbound sync triggers
- **THEN** all documents are exported, non-PDF documents are converted via Docudesk, and uploaded to the RIS with metadata derived from zaak-eigenschappen

#### Scenario: Store vertrouwelijk inbound document with restricted permissions
- **WHEN** a document in the RIS is marked as vertrouwelijk and the inbound sync retrieves it
- **THEN** the document is stored in Nextcloud Files with restricted permissions matching the zaak's geheimhouding level

#### Scenario: Store non-standard besluit format
- **WHEN** the RIS returns a besluit document in a non-standard format and the connector downloads it
- **THEN** it is stored as-is in Nextcloud Files with the original format, and a PDF conversion is attempted via Docudesk for display purposes

### Requirement: Parafering Tracking (REQ-RIS-050)

The connector MUST track parafering status within Procest before allowing push to the RIS. The parafering route follows the standard municipal chain: steller, adviseur, parafeerder, portefeuillehouder, secretariaat. Only after all required paraferingen are completed is the push enabled. The parafering route is configurable per zaaktype (sequential, parallel, or mixed).

#### Scenario: Block push on incomplete sequential parafering
- **WHEN** a zaak requires sequential parafering steller > adviseur > parafeerder > portefeuillehouder, and the steller and adviseur have parafen but the parafeerder has not
- **THEN** the connector blocks outbound push and shows parafering progress (2/4 completed) in the sync status

#### Scenario: Proceed after parallel parafering
- **WHEN** a zaaktype is configured with parallel parafering for adviseur and juridisch adviseur and both adviseurs have parafen
- **THEN** the parafering proceeds to the next sequential step (parafeerder)

#### Scenario: Transition to Ter besluitvorming after final paraaf
- **WHEN** all required paraferingen are completed and the secretariaat adds the final paraaf
- **THEN** the zaak automatically transitions to "Ter besluitvorming" and the outbound sync triggers

### Requirement: OpenConnector Endpoint Registration (REQ-RIS-060)

The connector MUST be registered as OpenConnector endpoint types with separate configurations for iBabs and NotuBiz. Connection settings include API URL, authentication credentials, organisatie-ID, and default vergadertype. Health checks validate API connectivity and authentication.

#### Scenario: Configure separate iBabs and NotuBiz sources
- **WHEN** an administrator wants to connect both iBabs and NotuBiz and creates two separate Source entities
- **THEN** each source has its own configuration (API URL, credentials, organisatie-ID) and can be used independently or together for college+raad workflows

#### Scenario: Reference source from n8n workflow
- **WHEN** an iBabs source is configured and an n8n workflow references the source
- **THEN** it can trigger custom B&W-besluitvorming workflows including document preparation, parafering reminders, and besluit notifications

#### Scenario: Health check reports degraded on auth failure
- **WHEN** the health check runs on the NotuBiz source and the API responds but authentication fails
- **THEN** the health check reports "degraded" with the specific authentication error

