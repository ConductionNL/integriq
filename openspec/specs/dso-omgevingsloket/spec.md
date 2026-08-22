# dso-omgevingsloket Specification

## Purpose
TBD - created by archiving change dso-omgevingsloket. Update Purpose after archive.
## Requirements
### Requirement: STAM Koppelvlak Endpoint Registration (REQ-DSO-001)

The adapter MUST register a STAM-compliant inbound REST endpoint in Integriq that receives vergunningaanvragen, meldingen, and informatieverzoeken pushed from DSO-LV. The endpoint accepts the DSO-verzoek payload (JSON or XML), **cryptographically verifies the request signature against the configured PKIoverheid certificate chain (or HMAC shared secret in pre-production mode) via `DSOSignatureVerifierService`**, and enqueues it for processing. The endpoint path follows `/api/dso/stam/verzoeken` and returns an HTTP 202 Accepted with verzoekId confirmation. A request whose signature does not verify against the configured trust chain MUST be rejected before any payload parsing occurs.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Valid vergunningaanvraag accepted and enqueued
- **WHEN** the DSO adapter endpoint is registered in Integriq with valid PKIoverheid certificates and DSO-LV pushes a vergunningaanvraag payload to the STAM endpoint
- **THEN** the adapter cryptographically verifies the webhook signature against the configured certificate chain, returns HTTP 202, and enqueues the verzoek for asynchronous processing

#### Scenario: Invalid webhook signature rejected
- **GIVEN** a request arrives at the STAM endpoint with an `X-DSO-Signature` header that does not verify against the configured PKIoverheid certificate chain (forged, expired certificate, or untrusted issuer)
- **WHEN** the adapter validates the signature
- **THEN** the adapter returns HTTP 401 Unauthorized with a descriptive error message, logs the failed attempt in the CallLog, and does NOT call `DSOParserService::parseVerzoek()`

#### Scenario: Missing signature header rejected
- **GIVEN** a request arrives at the STAM endpoint with no `X-DSO-Signature` header
- **WHEN** the adapter validates the signature
- **THEN** the adapter returns HTTP 401 Unauthorized without evaluating any payload content

#### Scenario: Pre-production request tagged
- **WHEN** the DSO adapter is configured for the pre-production environment (HMAC shared-secret mode) and a request arrives from the DSO-LV test environment with a valid HMAC signature
- **THEN** it is accepted and processed identically to production requests but tagged with `environment: pre-productie` in the verzoek record

#### Scenario: Malformed payload rejected
- **WHEN** DSO-LV sends a payload with a valid signature that does not conform to the STAM schema and schema validation fails
- **THEN** the adapter returns HTTP 400 Bad Request with field-level error details and does not create a verzoek record

#### Scenario: Concurrent verzoeken enqueued independently
- **WHEN** the STAM endpoint receives concurrent verzoeken as multiple DSO-LV pushes arrive simultaneously
- **THEN** each is enqueued independently using the JobService background job mechanism with unique verzoekIds, preventing duplicate processing

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
- **WHEN** a melding contains bijlagen (asbestinventarisatierapport) and the adapter processes the melding
- **THEN** bijlagen are downloaded from DSO-LV and stored in a dedicated Nextcloud Files folder linked to the melding-zaak

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

The adapter MUST download bijlagen (documenten, tekeningen, rapporten, berekeningen) referenced in the DSO-verzoek from DSO-LV and store them in Nextcloud Files. Each bijlage is stored in a zaak-specific folder structure following the pattern `/DSO-verzoeken/{year}/{verzoekId}/bijlagen/`.

@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI

#### Scenario: Multiple bijlagen downloaded and linked
- **WHEN** a verzoek references 5 bijlagen including PDFs, DWG drawings, and a structural calculation and the adapter processes the verzoek
- **THEN** each bijlage is downloaded via the DSO-LV document API using mTLS, stored in the zaak folder, and linked to the zaak via Docudesk

#### Scenario: Bijlage download retried and flagged on failure
- **WHEN** a bijlage download fails due to a network timeout and the adapter retries (up to 3 attempts with exponential backoff)
- **THEN** on persistent failure the zaak is created with a "bijlage ontbreekt" warning and a notification is sent to the behandelaar

#### Scenario: Oversized bijlage rejected
- **WHEN** a bijlage exceeds the configured maximum file size (default: 100MB) and the download is attempted
- **THEN** the adapter rejects the file, stores a placeholder reference, and flags the zaak for manual bijlage handling

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

The adapter MUST authenticate with DSO-LV using PKIoverheid certificates for mutual TLS. It MUST validate incoming DSO-LV webhook signatures and support both pre-production and production certificate chains. Certificates are stored securely via Nextcloud's credential store.
#### Scenario: Certificate used for outbound mTLS call
- **WHEN** a PKIoverheid certificate and private key are uploaded via the Integriq admin UI and the adapter makes an outbound call to DSO-LV
- **THEN** the certificate is written to a temporary file by CallService.getCertificate(), used for mTLS, and cleaned up after the request

#### Scenario: Expiring certificate triggers warning
- **WHEN** the PKIoverheid certificate expires in 30 days and the daily health check runs
- **THEN** a warning notification is sent to the Nextcloud admin with the certificate expiry date and renewal instructions

#### Scenario: Incoming webhook signature validated
- **WHEN** an incoming webhook from DSO-LV includes a signature header and the adapter validates the signature against the DSO-LV public certificate
- **THEN** requests with valid signatures are processed and requests with invalid signatures are rejected with HTTP 401

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

