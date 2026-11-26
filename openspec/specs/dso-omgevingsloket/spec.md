# dso-omgevingsloket Specification

## Purpose
Integriq receives permit applications, notifications and their attachments from the DSO Omgevingsloket through the STAM koppelvlak, validates them, maps each activity to a case type, creates the case and pushes its status back, authenticated with PKIoverheid certificates.

## Requirements

### Requirement: STAM Koppelvlak Endpoint Registration (REQ-DSO-001)

The adapter MUST register a STAM-compliant inbound REST endpoint in Integriq that receives vergunningaanvragen, meldingen, and informatieverzoeken pushed from DSO-LV. The endpoint path follows `/api/dso/stam/verzoeken`. The endpoint accepts the DSO-verzoek payload (JSON or XML) and **cryptographically verifies the request signature against the trust configuration of the instance's one `dso-stam` consumer (PKIoverheid certificate chain, or HMAC shared secret in pre-production mode) via `DSOSignatureVerifierService`**. A request whose signature does not verify MUST be rejected with 401 before any payload parsing occurs. A verified request MUST be stored while acting as the consumer's configured Nextcloud account (REQ-DSO-070). The endpoint MUST answer HTTP 202 Accepted with the verzoekId only once the verzoek is stored. When the verzoek is not stored, for any reason other than a bad signature or a malformed payload, the endpoint MUST answer HTTP 503 and log the reason, so that DSO-LV delivers it again. A repeated delivery of a verzoek that is already stored MUST NOT create a second record.

@e2e exclude backend DSO/Omgevingsloket STAM integration: covered by PHPUnit, not browser UI

#### Scenario: Valid vergunningaanvraag accepted and enqueued
- **WHEN** a `dso-stam` consumer with valid trust and a valid account is configured, and DSO-LV pushes a signed vergunningaanvraag to the STAM endpoint
- **THEN** the adapter verifies the signature, stores the verzoek as the configured account, returns HTTP 202, and enqueues the verzoek for asynchronous processing

#### Scenario: Invalid webhook signature rejected
- **GIVEN** a request arrives at the STAM endpoint with an `X-DSO-Signature` header that does not verify against the consumer's trust (forged, expired certificate, or untrusted issuer)
- **WHEN** the adapter validates the signature
- **THEN** the adapter returns HTTP 401 Unauthorized with a descriptive error message, logs the failed attempt, and does NOT call `DSOParserService::parseRequest()`

#### Scenario: Missing signature header rejected
- **GIVEN** a request arrives at the STAM endpoint with no `X-DSO-Signature` header
- **WHEN** the adapter validates the signature
- **THEN** the adapter returns HTTP 401 Unauthorized without evaluating any payload content

#### Scenario: Pre-production request tagged
- **WHEN** the `dso-stam` consumer is in HMAC mode and a request arrives from the DSO-LV test environment with a valid HMAC signature
- **THEN** it is accepted and processed identically to production requests but tagged with `environment: pre-productie` in the verzoek record

#### Scenario: Malformed payload rejected
- **WHEN** DSO-LV sends a payload with a valid signature that does not conform to the STAM schema and schema validation fails
- **THEN** the adapter returns HTTP 400 Bad Request with field-level error details and does not create a verzoek record

#### Scenario: Concurrent verzoeken enqueued independently
- **WHEN** the STAM endpoint receives concurrent verzoeken as multiple DSO-LV pushes arrive simultaneously
- **THEN** each is enqueued independently using the JobService background job mechanism with unique verzoekIds, preventing duplicate processing

#### Scenario: A verzoek that was not stored is answered with 503
- **GIVEN** a signed, valid verzoek
- **WHEN** OpenRegister refuses the save, or ingest returns no stored object
- **THEN** the endpoint answers HTTP 503 with error `verzoek_not_stored` and no `status: ontvangen`
- **AND** the reason is logged at error level with the verzoekId
- **AND** no attachment job is queued

#### Scenario: No DSO connection configured
- **GIVEN** no `dso-stam` consumer exists
- **WHEN** a push arrives at the STAM endpoint
- **THEN** the endpoint answers HTTP 503 with error `dso_connection_not_configured`, stores nothing, and notifies the administrators

#### Scenario: A repeated delivery creates no second record
- **GIVEN** a verzoek with verzoekId "dso-123" is stored with status `mapped`
- **WHEN** DSO-LV delivers the same verzoek again
- **THEN** the endpoint answers HTTP 202 with the existing record's verzoekId
- **AND** exactly one `dso_verzoek` with verzoekId "dso-123" exists

### Requirement: Melding Reception (REQ-DSO-002)

The adapter MUST support receiving meldingen (notifications of activities not requiring a permit) from DSO-LV via the same STAM endpoint. Meldingen follow a simplified flow: they create a zaak in Procest with a "Melding" zaaktype but do not require a vergunningbesluit response.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Sloopactiviteit melding creates zaak
- **WHEN** an initiatiefnemer submits a melding via het Omgevingsloket for a sloopactiviteit and DSO-LV pushes the melding to the STAM endpoint
- **THEN** the adapter parses the melding, creates a zaak with zaaktype "Melding Sloop", and pushes status "ontvangen" back to DSO-LV

#### Scenario: Combined melding and vergunning components
- **WHEN** a melding is received for an activiteit that has both a melding and a vergunning component and the adapter processes the melding
- **THEN** it creates a melding-zaak for the meldingsplichtige activiteit and flags the vergunningplichtige activiteit for separate aanvraag handling

#### Scenario: Melding bijlagen stored in Files
- **WHEN** a melding contains bijlagen (asbestinventarisatierapport) and the background job runs
- **THEN** the bijlagen are downloaded from DSO-LV and attached as files to the melding's `dso_verzoek` object, as REQ-DSO-005 describes

### Requirement: Informatieverzoek and Vooroverleg Support (REQ-DSO-003)

The adapter MUST support receiving informatieverzoeken (requests for information about applicability of rules) and vooroverleg-aanvragen (pre-application consultations) from DSO-LV. These create lightweight zaak objects in Procest with distinct zaaktypen that do not follow the full vergunningbesluit workflow.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Vooroverleg-aanvraag creates simplified zaak
- **WHEN** a burger submits a vooroverleg-aanvraag via the Omgevingsloket and DSO-LV pushes the vooroverleg to the STAM endpoint
- **THEN** the adapter creates a zaak with zaaktype "Vooroverleg" with a simplified behandelproces (no formal besluit required)

#### Scenario: Informatieverzoek notifies VTH-medewerker
- **WHEN** an informatieverzoek arrives with a locatie and activiteit query and the adapter processes it
- **THEN** it creates a lightweight zaak and notifies the VTH-medewerker to provide advies

#### Scenario: Vooroverleg links to follow-up aanvraag
- **WHEN** a vooroverleg-aanvraag transitions to a formal vergunningaanvraag and the initiatiefnemer submits a follow-up aanvraag referencing the vooroverleg
- **THEN** the adapter links the new zaak to the original vooroverleg-zaak via the DSO verzoekId chain

### Requirement: Verzoek Payload Parsing (REQ-DSO-004)

The adapter MUST parse the DSO-verzoek XML/JSON payload into structured data including aanvrager (initiatiefnemer), locatie, activiteiten, bijlagen, and projectbeschrijving. Parsing uses configurable mapping rules stored as OpenRegister mapping objects so municipalities can adapt field extraction to their internal data model.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Aanvrager block mapped via BRP mapping
- **WHEN** a DSO-verzoek payload contains an aanvrager with BSN, naam, adres, and contactgegevens and the parser extracts the aanvrager block
- **THEN** each field is mapped to the corresponding OpenRegister object property using the configured BRP-to-zaak mapping

#### Scenario: Locatie validated and geometry converted
- **WHEN** a verzoek payload contains a locatie with BAG-adresgegevens and GML-geometrie and the parser extracts locatie data
- **THEN** the BAG-adres is validated against the BAG register (via Integriq source), the GML geometry is converted to GeoJSON, and both are stored on the zaak

#### Scenario: Activiteiten array tagged with zaaktypen
- **WHEN** a verzoek payload contains multiple activiteiten with DSO activiteitcodes and omschrijvingen and the parser processes the activiteiten array
- **THEN** each activiteit is looked up in the activiteiten-mapping table and tagged with its corresponding zaaktype

#### Scenario: Projectbeschrijving stored with extracted references
- **WHEN** a verzoek contains a `projectbeschrijving` free-text field with embedded references and the parser processes this field
- **THEN** the text is stored verbatim as a zaak-eigenschap and references are extracted as linked metadata

#### Scenario: STAM version mismatch auto-detected
- **WHEN** the DSO payload format changes between STAM API versions and the adapter receives a payload with a version mismatch
- **THEN** it attempts parsing with the configured version, falls back to auto-detection, and logs a version warning if parsing succeeds on a different version

### Requirement: Bijlagen Download and Storage (REQ-DSO-005)

The adapter MUST download every bijlage a DSO-verzoek references from DSO-LV in a background job after intake, and MUST attach each one as a file to the request's `dso_verzoek` object through OpenRegister, stored in Nextcloud Files under the object's access rights. It MUST record each bijlage's outcome on the request, and MUST NOT write a bijlage anywhere outside Nextcloud Files.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Multiple bijlagen downloaded and linked
- **WHEN** a verzoek references 5 bijlagen including PDFs, DWG drawings, and a structural calculation and the background job runs
- **THEN** each bijlage is downloaded via the DSO-LV document API with the source's authentication (mTLS in production)
- **AND** each is attached to the request object as a file tagged `dso-bijlage`
- **AND** each entry in the request's `attachments` has status `stored` and the stored file's id

#### Scenario: The endpoint does not wait for the bijlagen
- **WHEN** DSO-LV pushes a verzoek with bijlagen to the STAM endpoint
- **THEN** the endpoint answers 202 once the request is saved
- **AND** every entry in the request's `attachments` has status `pending` until the job has run

#### Scenario: Bijlage download retried and flagged on failure
- **WHEN** a bijlage download fails due to a network timeout and the job retries (up to 3 attempts with exponential backoff)
- **THEN** on persistent failure its entry has status `failed` with the last error, and the request is flagged "bijlage ontbreekt" for the behandelaar

#### Scenario: Oversized bijlage rejected
- **WHEN** a bijlage exceeds the configured maximum file size (default: 100MB) and the download is attempted
- **THEN** no file is stored, its entry has status `too-large`, and the request is flagged for manual bijlage handling

#### Scenario: Nothing is written outside Nextcloud Files
- **WHEN** any verzoek with bijlagen is processed
- **THEN** no file is created on the server's filesystem outside Nextcloud's data, and no path such as `/DSO-verzoeken` exists afterwards

#### Scenario: A rerun finishes what a crash left
- **GIVEN** the job stopped after storing 2 of 5 bijlagen
- **WHEN** the job runs again
- **THEN** it downloads only the 3 entries that are not `stored`, and stores no duplicate of the first 2

### Requirement: Verzoek Schema Validation (REQ-DSO-006)

The adapter MUST validate the received verzoek against the DSO-LV STAM schema definition and reject malformed requests with descriptive HTTP 400 error responses. Validation includes required field checks, enum value validation, date format validation, and BSN/KVK check-digit verification.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Missing required activiteiten array rejected
- **WHEN** a verzoek payload is missing the required `activiteiten` array and validation runs
- **THEN** the adapter returns HTTP 400 with error `{"field": "activiteiten", "error": "required_field_missing", "message": "Activiteiten is verplicht"}`

#### Scenario: Invalid BSN check digit rejected
- **WHEN** a verzoek contains a BSN with an invalid check digit and BSN validation runs (11-proef)
- **THEN** the adapter rejects the verzoek with a specific BSN validation error

#### Scenario: Invalid indieningsdatum format rejected
- **WHEN** a verzoek contains an `indieningsdatum` in an invalid date format and date validation runs
- **THEN** the adapter returns a format error specifying the expected ISO 8601 format

### Requirement: Activiteiten-to-Zaaktype Mapping (REQ-DSO-010)

The adapter MUST map DSO activiteiten (bouwen, milieu, kappen, uitrit, etc.) to Procest zaaktypen via a configurable mapping table stored as OpenRegister objects. The mapping supports one-to-one (one activiteit to one zaaktype) and one-to-many (one activiteit generates multiple zaaktypen for different behandelende afdelingen).

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: One-to-one activiteit mapping creates zaak
- **WHEN** the mapping table maps DSO activiteitcode "bouwen-01" to zaaktype "Omgevingsvergunning Bouwen" and a verzoek contains activiteit "bouwen-01"
- **THEN** the adapter creates a zaak with zaaktype "Omgevingsvergunning Bouwen" and populates the zaak-eigenschappen from the verzoek

#### Scenario: One-to-many mapping creates multiple deelzaken
- **WHEN** the mapping table maps activiteitcode "milieu-complexe-inrichting" to both "Omgevingsvergunning Milieu" and "Omgevingsvergunning Bouwen" and a verzoek contains this activiteit
- **THEN** two deelzaken are created, each with its own zaaktype and behandelaar assignment

#### Scenario: Empty mapping table seeds defaults
- **WHEN** the mapping table is empty (fresh install) and an administrator navigates to the DSO-adapter settings
- **THEN** a "Load default mappings" button seeds 25+ common Omgevingswet activiteit-to-zaaktype mappings from the pre-seeded register data

#### Scenario: Modified mapping applied to next verzoek
- **WHEN** an administrator modifies a mapping to change the target zaaktype for "kappen" from "Omgevingsvergunning Kappen" to a custom zaaktype and the next verzoek with activiteit "kappen" arrives
- **THEN** the updated zaaktype is used for zaak creation

### Requirement: Samenloop Handling (REQ-DSO-011)

The adapter MUST support samenloop: when one DSO-verzoek contains multiple activiteiten, the adapter creates either multiple deelzaken under one hoofdzaak or one combined zaak, based on the configured samenloop strategy per activiteitcombinatie.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Deelzaken strategy creates hoofdzaak plus deelzaken
- **WHEN** a verzoek contains activiteiten "bouwen" and "kappen" and samenloop strategy is "deelzaken" and the adapter processes the verzoek
- **THEN** one hoofdzaak is created plus two deelzaken, each following its own behandelproces while sharing aanvrager and locatie data

#### Scenario: Gecombineerd strategy creates one combined zaak
- **WHEN** a verzoek contains activiteiten "bouwen" and "afwijken bestemmingsplan" and samenloop strategy is "gecombineerd" and the adapter processes the verzoek
- **THEN** one combined zaak is created with both activiteiten as zaak-eigenschappen and a combined behandelproces

#### Scenario: Hoofdzaak stays open until all deelzaken besluit
- **WHEN** a verzoek has a samenloop where one deelzaak is afgerond but another is still in behandeling and the behandelaar marks the first deelzaak as "Besluit genomen"
- **THEN** the hoofdzaak status remains "In behandeling" until all deelzaken have a besluit

#### Scenario: Deelzaken routed to configured afdelingen
- **WHEN** samenloop results in deelzaken handled by different afdelingen and deelzaken are created
- **THEN** each deelzaak is routed to its configured afdeling/team via Procest assignment rules

### Requirement: Unmapped Activiteit Fallback (REQ-DSO-013)

The adapter MUST handle unmapped activiteiten gracefully: creating a zaak with a generic "Onbekend DSO-activiteit" zaaktype, flagging it for manual triage, and notifying the configured VTH-behandelaar.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Unmapped activiteit creates triage zaak
- **WHEN** a verzoek contains activiteitcode "experimenteel-gebruik-2025" which has no mapping and the adapter processes the verzoek
- **THEN** a zaak is created with zaaktype "Onbekend DSO-activiteit", the activiteitcode is stored as zaak-eigenschap, and a Nextcloud notification is sent to the configured DSO-triage user

#### Scenario: Mixed mapped and unmapped activiteiten
- **WHEN** a verzoek contains 3 activiteiten of which 2 are mapped and 1 is unmapped and the adapter processes the verzoek
- **THEN** the 2 mapped activiteiten create proper deelzaken and the unmapped activiteit creates a triage-zaak, all linked under the same hoofdzaak

#### Scenario: Unmapped activiteiten summarised on dashboard
- **WHEN** multiple unmapped activiteiten accumulate over a week and an administrator views the DSO dashboard
- **THEN** a summary widget shows unmapped activiteiten with their frequency, enabling the admin to add mappings for recurring activiteiten

### Requirement: Automatic Zaak Creation (REQ-DSO-020)

The adapter MUST automatically create a zaak in Procest for each received DSO-verzoek. The zaak includes all parsed data: aanvrager mapped to the zaak (BSN/KVK-nummer, naam, adres, contactgegevens), locatie (BAG-adres, kadastrale aanduiding, GML-geometrie), startdatum set to DSO-verzoek indieningsdatum, linked bijlagen, and the original DSO-verzoek reference (verzoekId, bronorganisatie).

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Parsed vergunningaanvraag creates fully populated zaak
- **WHEN** a valid vergunningaanvraag is received and parsed and the adapter creates the zaak in Procest
- **THEN** the zaak has: zaaktype from the activiteiten-mapping, aanvrager from the verzoek, locatie with BAG-adres and geometrie, startdatum equal to indieningsdatum, all bijlagen linked, and verzoekId stored as external reference

#### Scenario: KVK-registered bedrijf mapped to bedrijf fields
- **WHEN** the verzoek aanvrager is a KVK-registered bedrijf and the zaak is created
- **THEN** the bedrijfsnaam, KVK-nummer, and vestigingsnummer are mapped to the zaak initiatiefnemer fields instead of BSN-based person fields

#### Scenario: GML geometry stored as geospatial eigenschap
- **WHEN** the verzoek locatie contains GML-geometrie (polygon) and the zaak is created
- **THEN** the GML is parsed to GeoJSON, validated against the BAG register, and stored as a geospatial zaak-eigenschap enabling map-based visualization

#### Scenario: Bouwkosten stored for legesberekening
- **WHEN** the verzoek contains optional bouwkosten and the zaak is created
- **THEN** bouwkosten are stored as a zaak-eigenschap for use in legesberekening workflows

#### Scenario: Zaak creation dispatches Integriq event
- **WHEN** a zaak is successfully created and creation completes
- **THEN** an Integriq event is dispatched (EventService) enabling n8n workflows to trigger intake processing such as legesberekening, team-toewijzing, and automatische termijnbewaking

### Requirement: DSO-SWF Samenwerking (REQ-DSO-030)

The adapter MUST support coordination with other bevoegde gezagen (provincies, waterschappen, omgevingsdiensten) via the DSO-SWF (SamenWerkingsFunctionaliteit). This includes sending adviesverzoeken to ketenpartners, receiving adviezen, and tracking samenwerking status per zaak.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Adviesverzoek sent to waterschap
- **WHEN** a vergunningaanvraag requires advies from the waterschap and the behandelaar marks the zaak for samenwerking
- **THEN** the adapter sends an adviesverzoek to the waterschap via DSO-SWF with the relevant zaak-documenten and a termijn for response

#### Scenario: Advies received and linked
- **WHEN** an adviesverzoek was sent to the provincie and the provincie sends back an advies via DSO-SWF and the adapter receives the advies
- **THEN** it is stored as a document linked to the zaak, the samenwerkingsstatus is updated to "Advies ontvangen", and the behandelaar receives a notification

#### Scenario: Samenwerking tab shows per-partner status
- **WHEN** a zaak involves 3 ketenpartners and the behandelaar views the samenwerking tab
- **THEN** it shows per partner: organisatienaam, OIN, adviesverzoek-datum, termijn, advies-status (verzonden/ontvangen/termijn verlopen), and linked documenten

### Requirement: Status Push to DSO-LV (REQ-DSO-040)

The adapter MUST push zaak status updates back to DSO-LV so that the aanvrager can track progress via the Omgevingsloket. Status mapping translates Procest zaak statussen to DSO-LV statuscodes. The vergunningbesluit (verleend, geweigerd, buiten behandeling) and the beschikking PDF are also pushed to DSO-LV.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: In-behandeling status pushed
- **WHEN** a zaak originated from a DSO-verzoek and the zaak status changes to "In behandeling" in Procest and the status transition event fires
- **THEN** the adapter pushes status "in behandeling" to DSO-LV via the STAM API using the stored verzoekId

#### Scenario: Verleend besluit pushed with beschikking PDF
- **WHEN** the vergunning is verleend and the zaak status changes to "Besluit genomen"
- **THEN** the adapter pushes besluitstatus "verleend" to DSO-LV and uploads the beschikking PDF (generated by Docudesk) for publication in the Omgevingsloket

#### Scenario: Buiten behandeling status pushed with reden
- **WHEN** the aanvraag is buiten behandeling gesteld (e.g., incomplete aanvulling) and the zaak is afgesloten
- **THEN** the adapter pushes status "buiten behandeling" with a reden to DSO-LV

#### Scenario: Failed status push retried
- **WHEN** a status push to DSO-LV fails and the adapter encounters an HTTP 5xx from DSO-LV
- **THEN** the push is retried 3 times with exponential backoff, and on persistent failure a manual-retry task is created and the behandelaar is notified

#### Scenario: Rapid transitions queued in order
- **WHEN** a zaak goes through multiple status transitions rapidly and statussen change faster than DSO-LV can process
- **THEN** the adapter queues status pushes and sends them in chronological order, skipping intermediate statussen if configured to do so

### Requirement: PKIoverheid Certificate Authentication (REQ-DSO-050)

The adapter MUST authenticate with DSO-LV using PKIoverheid certificates for mutual TLS. It MUST validate incoming DSO-LV webhook signatures and support both pre-production and production certificate chains. The outbound certificates are stored on the DSO `source`. The inbound trust (HMAC secret or PKIoverheid chain) MUST be stored on the instance's one `dso-stam` consumer, write-only, and MUST NOT be read from app config once the migration of REQ-DSO-070 has run.

#### Scenario: Certificate used for outbound mTLS call
- **WHEN** a PKIoverheid certificate and private key are uploaded via the Integriq admin UI and the adapter makes an outbound call to DSO-LV
- **THEN** the certificate is written to a temporary file by CallService.getCertificate(), used for mTLS, and cleaned up after the request
- @e2e exclude mTLS with a real client certificate, and a signed inbound webhook. The e2e instance holds no DSO certificate and receives no signed callback, so neither side of the exchange can be driven from a browser session

#### Scenario: Expiring certificate triggers warning
- **WHEN** the PKIoverheid certificate expires in 30 days and the daily health check runs
- **THEN** a warning notification is sent to the Nextcloud admin with the certificate expiry date and renewal instructions
- @e2e exclude mTLS with a real client certificate, and a signed inbound webhook. The e2e instance holds no DSO certificate and receives no signed callback, so neither side of the exchange can be driven from a browser session

#### Scenario: Incoming webhook signature validated
- **WHEN** an incoming webhook from DSO-LV includes a signature header and the adapter validates it against the `dso-stam` consumer's trust configuration
- **THEN** requests with valid signatures are processed and requests with invalid signatures are rejected with HTTP 401
- @e2e exclude mTLS with a real client certificate, and a signed inbound webhook. The e2e instance holds no DSO certificate and receives no signed callback, so neither side of the exchange can be driven from a browser session

### Requirement: Integriq Source Registration (REQ-DSO-060)

The adapter MUST be registered as an Integriq source type with DSO-LV-specific configuration fields. Connection settings include: DSO-LV API URL, PKIoverheid certificates, organisatie OIN, bevoegd-gezag code, and STAM API version. The source supports health checks validating connectivity and certificate validity.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: DSO source created with configuration
- **WHEN** an administrator creates a new source of type "dso" and fills in the DSO-LV API URL, uploads PKIoverheid certificates, and enters the organisatie OIN
- **THEN** a Source entity is created with type "dso" and DSO-specific configuration fields stored in the `configuration` JSON column

#### Scenario: Test connection probes STAM API
- **WHEN** a DSO source is configured and the administrator clicks "Test Connection"
- **THEN** the adapter makes a lightweight STAM API probe (e.g., a capability request) using mTLS and reports success/failure with certificate validity details

#### Scenario: n8n workflow uses DSO source credentials
- **WHEN** a DSO source is configured and an n8n workflow references the DSO source
- **THEN** it can trigger verzoek polling, status pushes, or bijlagen downloads using the source credentials

### Requirement: Scenario-Level Test Traceability (excluding REQ-DSO-050)

Every `#### Scenario:` in this capability MUST carry either an `@e2e` reference to a
browser test, or a reason-bearing `@e2e exclude <reason>` line — except the REQ-DSO-050
(PKIoverheid Certificate Authentication) scenarios, which are tracked separately.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Backend-only scenario carries an exclude reason

- GIVEN a scenario describes STAM koppelvlak HTTP/XML wire behavior with no Vue UI
  surface (confirmed: no `src/**/*dso*` or `*omgevingsloket*` Vue files exist)
- WHEN the scenario is reviewed for e2e traceability
- THEN it MUST carry `@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI`

#### Scenario: REQ-DSO-050 scenarios are exempt from this change

- GIVEN a scenario belongs to REQ-DSO-050 (PKIoverheid Certificate Authentication)
- WHEN this change's annotation pass runs
- THEN those scenarios are left unannotated here and are owned by
  `dso-stam-pkioverheid-signature-verification` instead

### Requirement: Verzoeken are open to the intake account, the handlers and administrators only (REQ-DSO-072)

`dso_verzoek` holds BSNs. Its OpenRegister authorization block MUST grant `create` and `update` to the group `dso-intake`, `read` and `update` to the group `dso-behandelaars`, and nothing to anyone else. `delete` MUST be granted to nobody. Administrators keep every action. The account of the DSO connection MUST be a member of `dso-intake`: choosing it in the DSO connection settings MUST add it and remove the previous account, and a repair step MUST add the current account on upgrade. The group names are fixed.

#### Scenario: An ordinary account gets no verzoeken

- GIVEN an account that is in neither group and is not an administrator
- WHEN it reads, creates or updates a `dso_verzoek` through the OpenRegister API
- THEN every request is refused
- @e2e exclude backend RBAC: covered by the register test and the live proof per role

#### Scenario: A DSO handler reads and updates

- GIVEN an account in `dso-behandelaars`
- WHEN it reads and updates a verzoek
- THEN both succeed
- AND it cannot create one
- @e2e exclude backend RBAC: covered by the live proof per role

#### Scenario: The chosen intake account joins the intake group

- GIVEN an administrator in the DSO connection section
- WHEN they choose an account and save
- THEN the account is a member of `dso-intake`
- AND the account chosen before is no longer a member
- AND an account the save refuses is not left in the group
- @e2e exclude group membership: covered by PHPUnit

### Requirement: A case type entry of a mapping row may name its variant (REQ-DSO-013)

A `dso_activity_mapping` case type entry MAY carry `variant`, the name of the route of that case type a case from the activity takes. The mapper MUST hand the variant on with the case type in `mappedActivities[].caseTypes`. An entry without a variant MUST map as before.

#### Scenario: A row maps an activity to a case type route
- **GIVEN** a row mapping "Kappen van een houtopstand" to Omgevingsvergunning with variant "regulier"
- **WHEN** a verzoek with that activity is mapped
- **THEN** its case type entry carries `variant: "regulier"`

### Requirement: Administrators read the activities with when each last arrived (REQ-DSO-014)

`GET /api/admin/dso-activities` MUST be admin only and MUST return `results`: every `dso_activity_mapping` row with `lastReceivedAt`, the newest `receivedAt` among the latest 500 verzoeken whose mapped activity matched that row (null when none did), followed by one row per unmapped activity of REQ-DSO-012 with `unmapped: true`, `waiting` (how many verzoeken carried it) and `lastReceivedAt` (when it was last seen).

#### Scenario: The overview
- **GIVEN** two rows, one matched by a verzoek received on 6 October, and one unmapped activity seen once
- **WHEN** an administrator reads the overview
- **THEN** the first row has `lastReceivedAt` 6 October, the second null, and the last row is the unmapped activity with `waiting: 1`

### Requirement: The STAM intake acts as the DSO connection's account (REQ-DSO-070)

Every OpenRegister write of the STAM intake and of its attachment job MUST run as the Nextcloud account named in the `dso-stam` consumer's `userId`, inside OpenRegister's `ObjectService::runAs()`. The intake MUST check that the account exists, is enabled, and holds `create` and `update` on `dso_verzoek` before its first write, and MUST answer HTTP 503 and notify the administrators when it does not. The intake and the job MUST NOT write with `_rbac: false` and MUST NOT call `runAsSystem()`. The account MUST be set by an administrator and validated when it is saved. A repair step MUST migrate an existing app-config trust configuration into the consumer and MUST leave the account empty.

@e2e exclude backend identity resolution of an anonymous signed push and a cron job: covered by PHPUnit and the live proof in tasks.md, no browser surface

#### Scenario: The verzoek is stored as the configured account
- **GIVEN** the `dso-stam` consumer names account `dso-intake`, which holds `create` and `update` on `dso_verzoek`
- **WHEN** DSO-LV pushes a signed verzoek without a Nextcloud session
- **THEN** the `dso_verzoek` is created and updated as `dso-intake`
- **AND** OpenRegister's audit trail names `dso-intake` for both writes
- **AND** the record's `receivedVia` holds the consumer's uuid and `dso-intake`
- **AND** after the request the active user is what it was before

#### Scenario: A missing account fails loud
- **GIVEN** the `dso-stam` consumer has an empty `userId`, or names a user that does not exist or is disabled
- **WHEN** DSO-LV pushes a signed verzoek
- **THEN** the endpoint answers HTTP 503 with error `dso_account_unavailable`
- **AND** nothing is written to OpenRegister
- **AND** the administrators get one notification naming the problem

#### Scenario: An account without rights writes nothing half
- **GIVEN** the account holds `create` but not `update` on `dso_verzoek`
- **WHEN** DSO-LV pushes a signed verzoek
- **THEN** the endpoint answers HTTP 503 with error `dso_account_lacks_rights`
- **AND** no `dso_verzoek` is created

#### Scenario: The attachment job runs as the account that stored the verzoek
- **GIVEN** a verzoek with bijlagen was stored as `dso-intake`
- **WHEN** cron runs the queued `FetchDsoAttachmentsJob`
- **THEN** the downloads and the outcome save run as `dso-intake`
- **AND** the job reads the DSO source as an engine read, so `dso-intake` needs no admin rights

#### Scenario: The attachment job refuses a vanished account
- **GIVEN** a queued `FetchDsoAttachmentsJob` whose account was deleted
- **WHEN** cron runs it
- **THEN** the job writes nothing, logs an error naming the verzoek and the account, and notifies the administrators
- **AND** every entry stays `pending`

#### Scenario: Existing configuration migrates without an account
- **GIVEN** an instance with the `dso_pki_*` app config keys set and no `dso-stam` consumer
- **WHEN** the repair step runs
- **THEN** one `dso-stam` consumer exists with that trust configuration and an empty `userId`
- **AND** the administrators get one notification asking them to choose the account
- **AND** running the repair step again creates nothing

### Requirement: The DSO connection's account is chosen and checked by an administrator (REQ-DSO-071)

The DSO connection settings in the Integriq admin section MUST let an administrator choose the account the STAM intake acts as. On save they MUST refuse a user that does not exist, a disabled user, and a user without `create` and `update` on `dso_verzoek`, each with a field error. They MUST warn, without refusing, when the user is a member of the `admin` group. The section MUST show which account the intake acts as, or that pushes are refused because no account is set.

#### Scenario: Choosing a valid account
- **GIVEN** an administrator opens the DSO connection settings
- **WHEN** they choose account `dso-intake`, which holds the required rights, and save
- **THEN** the setting is stored and the section reads "Intake acts as dso-intake"
- @e2e tests/e2e/dso-connection-settings.spec.ts

#### Scenario: An account without rights is refused
- **GIVEN** an administrator opens the DSO connection settings
- **WHEN** they choose a user without `create` on `dso_verzoek` and save
- **THEN** the save is refused with a field error naming the missing right
- **AND** the stored account is unchanged
- @e2e tests/e2e/dso-connection-settings.spec.ts

#### Scenario: No account set is shown plainly
- **GIVEN** the `dso-stam` consumer has no account
- **WHEN** an administrator opens the DSO connection settings
- **THEN** the section reads "No account set: DSO-LV pushes are refused with 503"
- @e2e tests/e2e/dso-connection-settings.spec.ts
