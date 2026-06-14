---
status: proposed
---

# DSO / Omgevingsloket Adapter

## Purpose

Provides integration with the Digitaal Stelsel Omgevingswet (DSO) Landelijke Voorziening for receiving and processing vergunningaanvragen, meldingen, and informatieverzoeken from the Omgevingsloket. Required by 32% of tenders (all VTH-related). The adapter receives DSO-verzoeken via the STAM koppelvlak, parses them into zaak objects in Procest, maps activiteiten to zaaktypen, and supports samenwerking met bevoegd gezag via DSO-SWF (SamenWerkingsFunctionaliteit). Replaces the legacy OLO (Omgevingsloket Online) integration.

## ADDED Requirements

### Requirement: STAM Koppelvlak Endpoint Registration (REQ-DSO-001)

The adapter MUST register a STAM-compliant inbound REST endpoint in OpenConnector that receives vergunningaanvragen, meldingen, and informatieverzoeken pushed from DSO-LV. The endpoint accepts the DSO-verzoek payload (JSON or XML), validates the request signature, and enqueues it for processing. The endpoint path follows `/api/dso/stam/verzoeken` and returns an HTTP 202 Accepted with verzoekId confirmation.

#### Scenario: Valid vergunningaanvraag accepted and enqueued
- **WHEN** the DSO adapter endpoint is registered in OpenConnector with valid PKIoverheid certificates and DSO-LV pushes a vergunningaanvraag payload to the STAM endpoint
- **THEN** the adapter validates the webhook signature, returns HTTP 202, and enqueues the verzoek for asynchronous processing

#### Scenario: Invalid webhook signature rejected
- **WHEN** a DSO-LV request arrives at the STAM endpoint and the webhook signature is invalid
- **THEN** the adapter returns HTTP 401 Unauthorized with a descriptive error message and logs the failed attempt in the CallLog

#### Scenario: Pre-production request tagged
- **WHEN** the DSO adapter is configured for the pre-production environment and a request arrives from the DSO-LV test environment
- **THEN** it is accepted and processed identically to production requests but tagged with `environment: pre-productie` in the verzoek record

#### Scenario: Malformed payload rejected
- **WHEN** DSO-LV sends a malformed payload that does not conform to the STAM schema and schema validation fails
- **THEN** the adapter returns HTTP 400 Bad Request with field-level error details and does not create a verzoek record

#### Scenario: Concurrent verzoeken enqueued independently
- **WHEN** the STAM endpoint receives concurrent verzoeken as multiple DSO-LV pushes arrive simultaneously
- **THEN** each is enqueued independently using the JobService background job mechanism with unique verzoekIds, preventing duplicate processing

### Requirement: Melding Reception (REQ-DSO-002)

The adapter MUST support receiving meldingen (notifications of activities not requiring a permit) from DSO-LV via the same STAM endpoint. Meldingen follow a simplified flow: they create a zaak in Procest with a "Melding" zaaktype but do not require a vergunningbesluit response.

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

#### Scenario: Aanvrager block mapped via BRP mapping
- **WHEN** a DSO-verzoek payload contains an aanvrager with BSN, naam, adres, and contactgegevens and the parser extracts the aanvrager block
- **THEN** each field is mapped to the corresponding OpenRegister object property using the configured BRP-to-zaak mapping

#### Scenario: Locatie validated and geometry converted
- **WHEN** a verzoek payload contains a locatie with BAG-adresgegevens and GML-geometrie and the parser extracts locatie data
- **THEN** the BAG-adres is validated against the BAG register (via OpenConnector source), the GML geometry is converted to GeoJSON, and both are stored on the zaak

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

#### Scenario: Zaak creation dispatches OpenConnector event
- **WHEN** a zaak is successfully created and creation completes
- **THEN** an OpenConnector event is dispatched (EventService) enabling n8n workflows to trigger intake processing such as legesberekening, team-toewijzing, and automatische termijnbewaking

### Requirement: DSO-SWF Samenwerking (REQ-DSO-030)

The adapter MUST support coordination with other bevoegde gezagen (provincies, waterschappen, omgevingsdiensten) via the DSO-SWF (SamenWerkingsFunctionaliteit). This includes sending adviesverzoeken to ketenpartners, receiving adviezen, and tracking samenwerking status per zaak.

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
- **WHEN** a PKIoverheid certificate and private key are uploaded via the OpenConnector admin UI and the adapter makes an outbound call to DSO-LV
- **THEN** the certificate is written to a temporary file by CallService.getCertificate(), used for mTLS, and cleaned up after the request

#### Scenario: Expiring certificate triggers warning
- **WHEN** the PKIoverheid certificate expires in 30 days and the daily health check runs
- **THEN** a warning notification is sent to the Nextcloud admin with the certificate expiry date and renewal instructions

#### Scenario: Incoming webhook signature validated
- **WHEN** an incoming webhook from DSO-LV includes a signature header and the adapter validates the signature against the DSO-LV public certificate
- **THEN** requests with valid signatures are processed and requests with invalid signatures are rejected with HTTP 401

### Requirement: OpenConnector Source Registration (REQ-DSO-060)

The adapter MUST be registered as an OpenConnector source type with DSO-LV-specific configuration fields. Connection settings include: DSO-LV API URL, PKIoverheid certificates, organisatie OIN, bevoegd-gezag code, and STAM API version. The source supports health checks validating connectivity and certificate validity.

#### Scenario: DSO source created with configuration
- **WHEN** an administrator creates a new source of type "dso" and fills in the DSO-LV API URL, uploads PKIoverheid certificates, and enters the organisatie OIN
- **THEN** a Source entity is created with type "dso" and DSO-specific configuration fields stored in the `configuration` JSON column

#### Scenario: Test connection probes STAM API
- **WHEN** a DSO source is configured and the administrator clicks "Test Connection"
- **THEN** the adapter makes a lightweight STAM API probe (e.g., a capability request) using mTLS and reports success/failure with certificate validity details

#### Scenario: n8n workflow uses DSO source credentials
- **WHEN** a DSO source is configured and an n8n workflow references the DSO source
- **THEN** it can trigger verzoek polling, status pushes, or bijlagen downloads using the source credentials

## Data Model

### DSO-Verzoek (stored in OpenRegister before zaak creation)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| verzoekId | string | Yes | DSO-LV unique verzoek identifier |
| bronorganisatie | string | Yes | OIN of the submitting DSO-LV instance |
| type | string (enum) | Yes | `aanvraag`, `melding`, `informatieverzoek`, `vooroverleg` |
| indieningsdatum | datetime | Yes | Date/time of submission in DSO-LV |
| aanvrager | object | Yes | Initiatiefnemer: BSN/KVK, naam, adres, contactgegevens |
| locatie | object | Yes | BAG-adres, kadastrale aanduiding, GML-geometrie |
| activiteiten | array | Yes | List of DSO activiteiten with codes and omschrijvingen |
| bouwkosten | decimal | No | Opgegeven bouwkosten (for legesberekening) |
| bijlagen | array | No | References to downloaded documents in Nextcloud Files |
| zaakId | string (UUID) | No | Created Procest zaak reference (set after processing) |
| status | string (enum) | Yes | `ontvangen`, `verwerkt`, `fout` |
| environment | string (enum) | No | `productie`, `pre-productie` |
| stamApiVersion | string | No | STAM API version used for this verzoek |

### Activiteiten-Mapping (stored in OpenRegister)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| dsoActiviteitCode | string | Yes | DSO activiteit code (e.g., "bouwen-01") |
| dsoActiviteitOmschrijving | string | Yes | Human-readable activiteit description |
| zaaktypeIdentificatie | string | Yes | Target Procest zaaktype identificatie |
| samenloopStrategie | string (enum) | No | `deelzaken` or `gecombineerd` (default: `deelzaken`) |
| behandelendeAfdeling | string | No | Default afdeling for routing |
| isActief | boolean | Yes | Whether this mapping is currently active |

## Dependencies

- **OpenConnector**: Source registration and connection management (Source entity, CallService, EndpointService)
- **OpenRegister**: Verzoek and mapping table storage
- **Procest**: Zaak creation and lifecycle management
- **Docudesk**: PDF generation for beschikkingen pushed to DSO-LV
- **DSO-LV STAM API**: External service (Kadaster/RWS)
- **PKIoverheid certificates**: For mTLS authentication
- **BAG/BRK services**: For locatie-validatie (via OpenConnector)

### Using Mock Register Data

The **DSO** mock register provides test data for developing the DSO adapter without requiring access to the DSO-LV production/test environment.

**Loading the register:**
```bash
# Load DSO register (53 records, register slug: "dso", schemas: "activiteit", "locatie", "omgevingsdocument", "vergunningaanvraag")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json
```

**Test data for this spec's use cases:**
- **Activiteiten-to-zaaktype mapping (REQ-DSO-010)**: 20+ activiteit records (bouwen, kappen, uitrit aanleggen, etc.) -- test mapping configuration
- **Vergunningaanvraag parsing (REQ-DSO-004)**: 10+ vergunningaanvraag records with activiteiten, locatie, and aanvrager data
- **Samenloop testing (REQ-DSO-011)**: Vergunningaanvragen referencing multiple activiteiten -- test single-zaak vs multi-deelzaak creation

## Current Implementation Status

### Implemented
- **None of the DSO-specific requirements are implemented.** There is no DSO adapter, STAM endpoint, activiteiten-mapping, or DSO-SWF integration in the codebase.

### Partially relevant existing infrastructure
- **SOAP engine** (`lib/Service/SOAPService.php`): A generic SOAP client exists that can call SOAP sources using WSDL, Guzzle HTTP, and the `php-soap` extension. It already handles SOAP 1.1/1.2, cookie management, WSDL caching, and binary data encoding. This could serve as a foundation for DSO-LV STAM SOAP communication.
- **Source entity** (`lib/Db/Source.php`, `src/entities/source/source.types.ts`): Sources support types `json`, `xml`, `soap`, `ftp`, `sftp` with configurable authentication (`apikey`, `jwt`, `username-password`, `oauth`, etc.). A new `dso` source type would need to be added.
- **CallService** (`lib/Service/CallService.php`): Routes SOAP-type sources to the SOAPService (line ~466). Already supports certificate file writing to disk for mTLS connections via `getCertificate()` and cleanup via `removeFiles()`.
- **SynchronizationService** (`lib/Service/SynchronizationService.php`): Full sync framework with contracts, logging, and mapping between external and internal objects. Could be leveraged for DSO-verzoek sync.
- **AuthenticationService** (`lib/Service/AuthenticationService.php`): Has certificate handling logic and supports JWT, OAuth, API key, and password authentication methods that could be extended for PKIoverheid mTLS.
- **EndpointService** (`lib/Service/EndpointService.php`): Manages endpoint routing with target types including `source`, `register/schema`, `job`, and `synchronization`. DSO inbound endpoints can leverage this routing.
- **EventService** (`lib/Service/EventService.php`): Event dispatching for workflow triggering via n8n or other subscribers.
- **JobService** (`lib/Service/JobService.php`): Background job execution for asynchronous processing and retry logic.

### Not implemented
- DSO-LV STAM koppelvlak endpoint (inbound REST/SOAP receiver)
- DSO verzoek parsing (XML/JSON payload to structured data)
- Activiteiten-to-zaaktype mapping table and UI
- Samenloop handling (multiple deelzaken from one verzoek)
- DSO-SWF samenwerking (adviesverzoeken, adviezen)
- Status push back to DSO-LV (outbound)
- PKIoverheid certificate validation chain
- DSO-LV webhook signature verification
- DSO-specific source type registration
- Bijlagen download and Nextcloud Files storage
- All zaak creation logic (depends on Procest)

## Standards & References

- **DSO-LV STAM koppelvlak**: REST API specification maintained by Kadaster/RWS for the Digitaal Stelsel Omgevingswet. Defines the verzoek intake interface.
- **Omgevingswet (2024)**: The Dutch Environment and Planning Act that replaced the Wabo/Wro, effective January 1, 2024.
- **DSO-SWF**: SamenWerkingsFunctionaliteit -- the collaboration API within the DSO-LV for coordinating between bevoegd gezag and ketenpartners.
- **PKIoverheid**: Dutch government PKI for mutual TLS authentication (PKIO Server 2020 certificate chain).
- **BAG (Basisregistratie Adressen en Gebouwen)**: National address registry, used for locatie-validatie.
- **BRK (Basisregistratie Kadaster)**: Cadastral registry for kadastrale aanduidingen.
- **GML (Geography Markup Language)**: OGC standard for geospatial data encoding, used for locatie geometrie.
- **OIN (Organisatie-Identificatienummer)**: Unique identifier for Dutch government organizations.
