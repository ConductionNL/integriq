---
status: draft
---

# openconnector StUF BG/ZKN Adapter

## Purpose

The openconnector StUF adapter centralizes all openconnector fleet access to Dutch municipal SOAP-based StUF services (BG/ZKN/EF). It provides configurable adapter instances, server-side SOAP envelope construction, schema validation against published XSDs, both asynchronous (kennisgeving) and synchronous (vraag/antwoord) interaction patterns, transport binding to Digikoppeling (WUS/ebMS/Grote Berichten) or plain HTTPS, and SOAP fault to typed error mapping. No openconnector consumer SHALL construct StUF envelopes directly — the StUF adapter is the sole gateway for StUF traffic on a Nextcloud instance.

## ADDED Requirements

### Requirement: Configurable StUF Adapter Instances

openconnector MUST ship a StUFAdapterConfig openregister schema that stores one configurable StUF adapter instance. Configuration MUST include:

- `sectormodel` (enum: BG, ZKN, EF) — which sector model this adapter handles
- `schema_version` (string: 0310 or 0204) — which XSD version to validate against
- `endpoint_url` (string) — the source SOAP endpoint
- `transport_profile` (enum: plain_https, digikoppeling_wus, digikoppeling_ebms) — transport binding
- `stuurgegevens` fields (zender/ontvanger organisatie + applicatie) — SOAP header injection
- `retention_days` (integer, default 90) — how long to retain bericht bodies before PII redaction
- `testbed_mode` (boolean) — if true, use VNG StUF-Testplatform for conformance runs

#### Scenario: Adapter configuration is stored and retrieved

- GIVEN openconnector is installed
- WHEN an integration engineer creates a StUFAdapterConfig for StUF-BG 0310
- THEN the config SHALL be stored in the openregister with all required fields
- AND subsequent reads SHALL return the stored configuration without modification

#### Scenario: Multiple adapter instances can coexist

- GIVEN an openconnector instance runs BRP and zaaksysteem integrations in parallel
- WHEN two StUFAdapterConfig objects are created (one for BG, one for ZKN)
- THEN both adapters SHALL be independently configurable, usable, and logged
- AND each adapter SHALL be identified by its own adapter_id

### Requirement: Sectormodel Selection Drives XSD Validation

Every inbound and outbound SOAP bericht MUST be validated against the appropriate XSD bundle for its sectormodel and schema_version. The XSD bundles are version-pinned (0310 is primary, 0204 available), cached in memory at adapter startup, and loaded per adapter.

#### Scenario: Sectormodel BG selects the correct XSD bundle

- GIVEN a StUFAdapterConfig with `sectormodel = BG` and `schema_version = 0310`
- WHEN an outbound vraag is constructed or an inbound antwoord is received
- THEN openconnector loads the matching XSD bundle from `lib/Resources/xsd/stuf-bg-0310/`
- AND validates the SOAP body against the imported schemas
- AND rejects non-conformant berichten with a typed `StUFValidationError` carrying the XSD error location and message

#### Scenario: Unsupported schema version triggers a warning, not auto-downgrade

- GIVEN a source system supports StUF-BG versions 0204 and 0310 and the adapter is configured for 0310
- WHEN the first vraag fails with `StUF067` (unsupported schema version)
- THEN openconnector logs a `schema.unsupported` warning at the adapter
- AND does NOT automatically downgrade to 0204 (would silently change semantics)
- AND surfaces the foutmelding to the operator with a clear remediation path
- AND configuration must be explicitly changed to 0204 by the operator

### Requirement: Vraag/Antwoord Synchronous Flow

The adapter MUST support synchronous request-response interaction. An outbound vraag (query) is constructed with configured stuurgegevens, posted to the source endpoint, and awaits the matching antwoord (response) correlated via crossRefnummer.

#### Scenario: Vraag/antwoord round-trip for natural person lookup (npsLv01 → npsLa01)

- GIVEN a consumer requests a person record via the StUF-BG adapter with BSN 123456782
- WHEN the adapter builds the request
- THEN openconnector constructs an `npsLv01` envelope with:
  - berichtcode=Lv01
  - configured zender and ontvanger stuurgegevens
  - fresh referentienummer (UUIDv4)
  - tijdstipBericht = now (yyyyMMddHHmmssSSS format)
  - parameters (sortering, indicatorVervolgvraag=false)
  - gelijk-vergelijking on `inp.bsn=123456782`
- AND POSTs to the endpoint with `SOAPAction: "http://www.egem.nl/StUF/sector/bg/0310/npsLv01"`
- AND awaits the `npsLa01` response
- AND validates the response envelope and body
- AND persists both berichten (vraag as outbound, antwoord as inbound with response_to linkage)
- AND returns the parsed person object to the consumer

#### Scenario: Bericht correlation via referentienummer and crossRefnummer

- GIVEN an outbound vraag with referentienummer R1
- WHEN the antwoord arrives carrying `crossRefnummer = R1`
- THEN openconnector links the inbound StUFBericht to the outbound one via `response_to`
- AND emits a `stuf.vraag.answered` event with both bericht IDs
- AND resolves any in-flight promise waiting on R1
- AND antwoorden with unknown crossRefnummer are still persisted but flagged `unmatched` for operational review

### Requirement: Kennisgeving Asynchronous Flow

The adapter MUST support asynchronous push delivery (kennisgeving). Kennisgevingen arrive at a configured HTTPS callback endpoint, are deduplicated within a 24h window, parsed, persisted, and emitted as domain events.

#### Scenario: Kennisgeving reception and deduplication

- GIVEN the adapter has an active abonnement on entiteittype `NPS` and receives an inbound `npsLa01` kennisgeving
- WHEN the bericht arrives at the configured callback_endpoint
- THEN openconnector validates the envelope against the XSD
- AND persists a StUFBericht entry (direction=inbound, interaction=kennisgeving, berichtsoort=La01)
- AND emits a `stuf.bericht.received` domain event with the parsed payload
- AND returns a `Bv01` (bevestigingsbericht) acknowledgement to the sender
- AND surfaces the bericht to subscribed downstream consumers (e.g., openzaak, openklant)

#### Scenario: Duplicate kennisgeving within 24h window is silently suppressed

- GIVEN a source system resends the same kennisgeving (same referentienummer, same tijdstipBericht) because its Bv01 acknowledgement timed out
- WHEN the duplicate arrives at the callback endpoint
- THEN openconnector detects the duplicate by (referentienummer, sender, berichtsoort) within a 24h window
- AND returns the previously-sent `Bv01` acknowledgement without re-emitting the downstream domain event
- AND increments a `stuf.duplicate.suppressed` counter for operational visibility

### Requirement: SOAP Fault to Typed Error Mapping

Any SOAP fault response MUST be parsed, mapped to a typed StUFFault error, and persisted to CallLog (ADR-003). The foutcode and ernst (severity) are extracted from the fault body.

#### Scenario: SOAP fault with StUF-specific error codes is mapped to typed error

- GIVEN an outbound bericht receives a SOAP fault response containing a StUF `<Fo02>` body with foutcode `StUF016` and omschrijving "Object niet gevonden"
- WHEN the adapter parses the fault
- THEN openconnector raises a typed `StUFFault` error with:
  - foutcode = StUF016
  - ernst = fout (derived from foutcode)
  - omschrijving = "Object niet gevonden"
  - plek = adapter_id
  - gerelateerd_xpath = <fault XPath>
- AND the original envelope is written to the CallLog
- AND reliability_profile retry rules apply only to transport-level errors, not to StUF fauts (which are permanent by contract)

### Requirement: Stuurgegevens Injection and Validation

Every outbound bericht MUST inject configured stuurgegevens into the SOAP header and validate that all required fields are present.

#### Scenario: Stuurgegevens fields are injected and validated

- GIVEN a StUFAdapterConfig with zender_organisatie, zender_applicatie, ontvanger_organisatie, ontvanger_applicatie
- WHEN any outbound bericht is constructed
- THEN openconnector injects the configured stuurgegevens into the envelope
- AND generates a fresh referentienummer (UUIDv4) per bericht
- AND stamps tijdstipBericht with the current time in StUF format (yyyyMMddHHmmssSSS)
- AND validates that all required stuurgegevens fields are present
- AND missing or empty stuurgegevens cause a config-validation error at adapter activation time, not at first call

### Requirement: Abonnement Management

The adapter MUST support creating, updating, and deleting subscriptions to kennisgeving flows. An abonnement specifies the entity type to subscribe to and optional mutatiefilters (e.g., overlijden, verhuizing for natural persons).

#### Scenario: Abonnement creation sends subscription request to source

- GIVEN an integration engineer needs to subscribe to mutations on natural persons in a specific gemeente
- WHEN they create a StUFAbonnement via API with entiteittype and mutatiefilters
- THEN openconnector POSTs a StUF abonnement-bericht (sectormodel-specific construction) to the source's abonnement-endpoint
- AND captures the returned abonnement-id
- AND stores the StUFAbonnement record with status=active
- AND registers the callback_endpoint to receive subsequent kennisgevingen
- AND unsubscribe is symmetric (delete abonnement via API → POST abreq to source)

#### Scenario: Mutatiefilter on abonnement creation restricts delivered mutations

- GIVEN an abonnement on entiteittype NPS with mutatiefilters `[overlijden, verhuizing, naamswijziging]`
- WHEN the abonnement is sent to the source
- THEN openconnector encodes the filters into the StUF abonnement-bericht per VNG-published mutatiesoort codes (Wmu, Wvh, Wnw, ...)
- AND submits the abonnement
- AND only routes incoming kennisgevingen matching those mutatiesoorten to the configured callback
- AND unmatched mutations are silently dropped at the source side (filter is enforced upstream, openconnector does not re-filter)

### Requirement: Digikoppeling Transport Binding

For production deployments, the adapter MUST delegate transport to the digikoppeling-adapter, inheriting mTLS, signing, and reliable-messaging.

#### Scenario: WUS transport via digikoppeling-adapter

- GIVEN a StUFAdapterConfig with `transport_profile = digikoppeling_wus` referencing a digikoppeling-adapter source
- WHEN an outbound bericht is sent
- THEN openconnector delegates transport to the digikoppeling-adapter
- AND the digikoppeling-adapter wraps the SOAP envelope in the WUS profile (WS-Security signing, mTLS, WS-Addressing)
- AND POSTs to the gateway
- AND returns the response envelope to the StUF adapter for parsing

#### Scenario: EbMS transport via digikoppeling-adapter

- GIVEN a StUFAdapterConfig with `transport_profile = digikoppeling_ebms`
- WHEN an outbound bericht is sent
- THEN openconnector constructs the SOAP envelope per StUF sectormodel
- AND delegates transport to the digikoppeling-adapter
- AND the digikoppeling-adapter wraps the envelope as a Message Service Handler payload
- AND submits via ebMS2 profile
- AND returns the response to the StUF adapter

### Requirement: Grote Berichten Payload Chunking

For large payloads over digikoppeling-ebms, the adapter MUST support the Grote Berichten profile, which splits payloads and tracks reference documents.

#### Scenario: Grote Berichten payload chunking for large documents

- GIVEN an outbound bericht (typically a zaak with embedded documents) exceeds 20 MB serialized
- WHEN the transport_profile is `digikoppeling_ebms` with Grote Berichten enabled
- THEN openconnector delegates to the digikoppeling-adapter
- AND the digikoppeling-adapter splits the payload per the Grote Berichten profile (reference-bericht pointing to a separate document download URL served by the Grote Berichten server)
- AND tracks the reference in the StUFBericht
- AND on receipt of the bevestiging marks the bericht as delivered

### Requirement: Bericht Retention with PII-Aware Redaction

StUF berichten contain sensitive personal data (BSN, addresses, family relations, etc.). After the retention period elapses, the bericht body MUST be redacted while preserving metadata for audit.

#### Scenario: Bericht retention and PII redaction

- GIVEN an inbound npsLa01 carrying BSN, NAW, and gerelateerden for a natural person
- WHEN the retention period (configurable, default 90 days) elapses
- THEN openconnector preserves the StUFBericht metadata (referentienummer, tijdstipBericht, berichtsoort, sender, receiver, outcome) for audit
- AND redacts BSN and personal-data fields in the persisted body per AVG dataminimalisatie
- AND reidentification is impossible from the redacted form

### Requirement: Testbed Mode for VNG Conformance Runs

For pre-production validation, the adapter MUST support testbed mode, which uses the VNG StUF-Testplatform endpoint and generates conformance reports.

#### Scenario: Testbed mode executes conformance test suite

- GIVEN a StUFAdapterConfig with `testbed_mode = true` pointed at the VNG StUF-Testplatform endpoint
- WHEN the adapter executes its conformance test suite
- THEN openconnector emits all required test berichten in the prescribed order (per the published conformance scenarios for the sectormodel and version)
- AND captures the expected response shapes
- AND validates each round-trip
- AND generates a machine-readable conformance report (XML + HTML)
- AND surfaces pass/fail per scenario
- AND the report is uploadable to the VNG test portal as evidence

### Requirement: Sector-Specific Entity Mapping

Parsed StUF berichten MUST be mappable to consumer-defined openregister schemas. The adapter emits domain events so downstream systems (openzaak, openklant) can map and persist entities.

#### Scenario: ZAK kennisgeving maps to configured openregister schema

- GIVEN the adapter handles entiteittype `ZAK` (zaak) from StUF-ZKN
- WHEN a ZAK kennisgeving arrives
- THEN openconnector maps the parsed ZAK to a configured openregister schema (provided by the consumer of this spec)
- AND persists or updates the corresponding openregister object via the standard object-write path
- AND emits `object.created` / `object.updated` so downstream systems react

### Requirement: Plain HTTPS Transport for Development

For development and integration testing, plain HTTPS transport with optional Basic-Auth MUST be supported.

#### Scenario: Plain HTTPS transport works without mTLS or Digikoppeling

- GIVEN a StUFAdapterConfig with `transport_profile = plain_https`
- WHEN an outbound bericht is sent
- THEN openconnector POSTs directly to the configured endpoint_url via HTTPS
- AND no WUS/ebMS wrapping occurs
- AND optional Basic-Auth (user/pass from auth_profile_ref) is applied if configured
- AND the response is parsed as plain SOAP without Digikoppeling processing

### Requirement: Structured Observability

All StUF interactions MUST emit structured log entries (ADR-003) with key operational fields.

#### Scenario: Structured log entry per bericht interaction

- GIVEN any StUF bericht interaction (vraag, antwoord, kennisgeving, fout)
- WHEN the bericht is processed
- THEN openconnector emits a structured log entry with:
  - adapter_id, interaction, berichtsoort, entiteittype, direction
  - upstream_latency_ms (nullable for inbound)
  - http_status, soap_status, foutcode (if applicable)
  - transport_profile
- AND log level is debug for 2xx + success, warning for non-fatal (schema mismatch), error for SOAP faults and transport failures
