---
status: draft
spec: stuf-bg-zkn-bg-koppelvlak
app: openconnector
owner: openconnector-core
depends_on:
  - openconnector-base
  - digikoppeling-adapter
---

# StUF BG/ZKN Koppelvlak

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Adapters > Adapter-catalogus (Overheid-NL) + used in Verbindingen wizard / Adapters

**Rationale:** Adapter type, surfaces in catalogue + new-connection picker  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

StUF (Standaard Uitwisselings Formaat) remains the dominant SOAP-based exchange standard between Dutch municipal back-offices, despite the steady migration to REST-based Haal Centraal and ZGW APIs. Every municipality in the Netherlands still operates one or more StUF-based connections — typically to the BRP (basisregistratie personen) via StUF-BG, to the zaaksysteem via StUF-ZKN, and to e-formulier engines via StUF-EF. Suppliers that cannot speak StUF cannot reach the installed base, and even municipalities migrating to ZGW-REST keep StUF running in parallel for years during transition. Procurement scoring documents from VNG Realisatie still treat StUF-BG 0310 and StUF-ZKN 0310 conformance as hard pass/fail criteria for any system claiming municipal-back-office integration.

This spec adds a generic StUF adapter family to openconnector covering the three most-used sectormodellen: **StUF-BG** (basisgegevens — personen, niet-natuurlijke personen, adressen, gerelateerden), **StUF-ZKN** (zaken — zaken, zaaktypen, statussen, documenten, betrokkenen), and **StUF-EF** (electronisch formulier — inkomende en uitgaande formulieren). All three are SOAP 1.1/1.2 envelopes with sector-specific bodies governed by published XSDs from VNG Realisatie. The adapter ships the version 0310 XSD bundles by default with 0204 available for legacy systems; XSDs are version-pinned, cached in memory, and validated on every bericht to guarantee that openconnector never silently sends malformed envelopes.

The adapter supports both interaction patterns defined in StUF: **kennisgeving** (asynchronous push from source system to consumer, typically wijzigingsberichten on natural-person mutations triggered by registratie-mutations in the BRP) and **vraag/antwoord** (synchronous pull, e.g. `npsLv01` to fetch a person record, `zakLv01` to fetch a case). For kennisgeving, openconnector exposes an HTTPS callback endpoint per adapter, parses inbound envelopes, fans the parsed event out to subscribed downstream consumers, and returns a `Bv01` acknowledgement to the sender. For vraag/antwoord, the adapter constructs the request envelope with the configured stuurgegevens (zender/ontvanger organisatie + applicatie), POSTs to the source endpoint with the correct SOAPAction header, and awaits the matching `La01` response with crossRefnummer linkage. Schema validation is enforced per sectormodel against the official XSDs; SOAP faults map to typed openconnector errors carrying the original StUF foutcode (`StUF016`, `Fo01`, `Fo02`, ...) and the gerelateerd-element pointer for actionable debugging.

The adapter is transport-agnostic at the SOAP layer but designed to ride on top of Digikoppeling for production deployments (WUS for synchronous, ebMS for asynchronous, Grote Berichten profile for payloads exceeding 20 MB), inheriting mTLS, signing, and reliable-messaging from that lower-level adapter. For development and integration testing, a plain HTTPS transport is supported with optional Basic-Auth. The adapter integrates fully with the ipaas-reliability tier (retry/circuit-breaker/DLQ apply to transport failures but not to permanent StUF fauts) and with the auth-protocol-suite (mTLS profiles, WS-Security key management).

## Data Model

- **StUFAdapterConfig** (openregister schema): id, slug, sectormodel (BG|ZKN|EF), schema_version (e.g. `0310`, `0204`), endpoint_url, ontvanger_organisatie, ontvanger_applicatie, zender_organisatie, zender_applicatie, transport_profile (digikoppeling_wus | digikoppeling_ebms | plain_https), auth_profile_ref, reliability_profile_ref, xsd_bundle_ref, retention_days.
- **StUFBericht** (openregister, append-only): id, adapter_id, direction (inbound|outbound), interaction (kennisgeving|vraag|antwoord|fout), berichtsoort (e.g. `Lv01`, `La01`, `Bv01`, `Kv01`), entiteittype (e.g. `NPS`, `ZAK`, `EDC`), referentienummer, tijdstipBericht, crossRefnummer, raw_envelope_ref, parsed_payload, soap_action, response_to (StUFBericht ref).
- **StUFAbonnement** (openregister): id, adapter_id, entiteittype, mutatiefilters[], callback_endpoint, status (active|paused|cancelled), created, last_delivery.
- **StUFFout** (CallLog extension): + foutcode, ernst (info|waarschuwing|fout), omschrijving, plek, gerelateerd_xpath.

## Requirements

### REQ-001: Sectormodel selection drives XSD validation

- **GIVEN** a StUFAdapterConfig with `sectormodel = ZKN` and `schema_version = 0310`
- **WHEN** an outbound bericht is constructed or an inbound bericht is received
- **THEN** openconnector loads the matching XSD bundle (sector ZKN, version 0310) from the configured xsd_bundle_ref, validates the SOAP body against the imported schemas, and rejects non-conformant berichten with a typed `StUFValidationError` carrying the XSD error location and message; XSD bundles are version-pinned and cached in memory

### REQ-002: Vraag/antwoord synchronous flow (`Lv01` → `La01`)

- **GIVEN** a consumer requests a person record via the StUF-BG adapter with BSN 123456782
- **WHEN** the adapter builds the request
- **THEN** openconnector constructs a `npsLv01` envelope with stuurgegevens (berichtcode=Lv01, zender, ontvanger, referentienummer=UUID, tijdstipBericht=now), parameters (sortering, indicatorVervolgvraag=false), gelijk-vergelijking on `inp.bsn=123456782`, the requested scope (`<gelijk>` block), POSTs to the endpoint with `SOAPAction: "http://www.egem.nl/StUF/sector/bg/0310/npsLv01"`, awaits the `npsLa01` response, validates it, and returns the parsed person object

### REQ-003: Kennisgeving asynchronous flow (`La01` push consumption)

- **GIVEN** the adapter has an active abonnement on entiteittype `NPS` and receives an inbound `npsLa01` kennisgeving
- **WHEN** the bericht arrives at the configured callback_endpoint
- **THEN** openconnector validates the envelope against the XSD, persists a StUFBericht entry (direction=inbound, interaction=kennisgeving, berichtsoort=La01), emits a `stuf.bericht.received` domain event with the parsed payload, returns a `Bv01` (bevestigingsbericht) acknowledgement to the sender, and surfaces the bericht to subscribed downstream consumers (e.g. openzaak, openklant)

### REQ-004: SOAP fault to typed error mapping

- **GIVEN** an outbound bericht receives a SOAP fault response containing a StUF `<Fo02>` body with foutcode `StUF016` and omschrijving "Object niet gevonden"
- **WHEN** the adapter parses the fault
- **THEN** openconnector raises a typed `StUFFault` error with fields foutcode=StUF016, ernst=fout, omschrijving="Object niet gevonden", plek=<adapter_id>, gerelateerd_xpath=<fault XPath>; the original envelope is written to the CallLog; reliability_profile retry rules apply only to transport-level errors, not to StUF fauts (which are permanent by contract)

### REQ-005: StUF-EF formulier round-trip

- **GIVEN** a StUFAdapterConfig with `sectormodel = EF` and an inbound electronic form submission
- **WHEN** the form is delivered to the configured endpoint
- **THEN** openconnector validates the envelope against the StUF-EF XSD, extracts the formulier metadata (formuliernaam, versie, indiener) and bijlagen (base64-encoded), persists each bijlage as an openregister-managed file, links them to the StUFBericht, and emits `stuf.formulier.ontvangen` so workflow apps can act on it

### REQ-006: Abonnement management

- **GIVEN** an integration engineer needs to subscribe to mutations on natural persons in a specific gemeente
- **WHEN** they create a StUFAbonnement via API
- **THEN** openconnector POSTs a StUF abonnement-bericht (sectormodel-specific construction) to the source's abonnement-endpoint, captures the returned abonnement-id, stores the StUFAbonnement record with status=active, and registers the callback_endpoint to receive subsequent kennisgevingen; unsubscribe is symmetric

### REQ-007: Digikoppeling transport binding

- **GIVEN** a StUFAdapterConfig with `transport_profile = digikoppeling_wus` referencing a digikoppeling-adapter source
- **WHEN** an outbound bericht is sent
- **THEN** openconnector delegates transport to the digikoppeling-adapter, which wraps the SOAP envelope in the WUS profile (WS-Security signing, mTLS, WS-Addressing), POSTs to the gateway, and returns the response envelope to the StUF adapter for parsing; for ebMS the adapter delegates the same envelope as a Message Service Handler payload

### REQ-008: Bericht correlation via referentienummer

- **GIVEN** an outbound vraag with referentienummer R1
- **WHEN** the antwoord arrives carrying `crossRefnummer = R1`
- **THEN** openconnector links the inbound StUFBericht to the outbound one via response_to, emits a `stuf.vraag.answered` event with both bericht IDs, and resolves any in-flight promise waiting on R1; antwoorden with unknown crossRefnummer are still persisted but flagged `unmatched` for operational review

### REQ-009: Schema version negotiation

- **GIVEN** a source system supports StUF-BG versions 0204 and 0310 and the adapter is configured for 0310
- **WHEN** the first vraag fails with `StUF067` (unsupported schema version)
- **THEN** openconnector logs a `schema.unsupported` warning, does NOT automatically downgrade (would silently change semantics), and surfaces the foutmelding to the operator with a clear remediation path; configuration must be explicitly changed to 0204

### REQ-010: Sector-specific entity registry

- **GIVEN** the adapter handles entiteittype `ZAK` (zaak) from StUF-ZKN
- **WHEN** a ZAK kennisgeving arrives
- **THEN** openconnector maps the parsed ZAK to a configured openregister schema (provided by the consumer of this spec, typically the openzaak sidecar), persists or updates the corresponding openregister object via the standard object-write path, and emits `object.created` / `object.updated` so downstream systems (mydash, openconnector synchronisations) react

### REQ-011: Stuurgegevens injection and validation

- **GIVEN** a StUFAdapterConfig with zender_organisatie, zender_applicatie, ontvanger_organisatie, ontvanger_applicatie
- **WHEN** any outbound bericht is constructed
- **THEN** openconnector injects the configured stuurgegevens into every envelope, generates a fresh referentienummer (UUIDv4) per bericht, stamps tijdstipBericht with the current time in StUF format (yyyyMMddHHmmssSSS), and validates that all required stuurgegevens fields are present; missing or empty stuurgegevens cause a config-validation error at adapter activation time, not at first call

### REQ-012: Bericht retention with PII-aware redaction

- **GIVEN** an inbound npsLa01 carrying BSN, NAW, and gerelateerden voor a natural person
- **WHEN** the retention period (configurable, default 90 days) elapses
- **THEN** openconnector preserves the StUFBericht metadata (referentienummer, tijdstipBericht, berichtsoort, sender, receiver, outcome) for audit but redacts BSN and personal-data fields in the persisted body per AVG dataminimalisatie; reidentification is impossible from the redacted form

### REQ-013: Mutatiefilter on abonnement creation

- **GIVEN** an abonnement on entiteittype NPS with mutatiefilters `[overlijden, verhuizing, naamswijziging]`
- **WHEN** the abonnement is sent to the source
- **THEN** openconnector encodes the filters into the StUF abonnement-bericht per VNG-published mutatiesoort codes (Wmu, Wvh, Wnw, ...), submits the abonnement, and only routes incoming kennisgevingen matching those mutatiesoorten to the configured callback; unmatched mutations are silently dropped at the source side (filter is enforced upstream, openconnector does not re-filter)

### REQ-014: Testbed mode for VNG conformance runs

- **GIVEN** a StUFAdapterConfig with `testbed_mode = true` pointed at the VNG StUF-Testplatform endpoint
- **WHEN** the adapter executes its conformance test suite
- **THEN** openconnector emits all required test berichten in the prescribed order (per the published conformance scenarios for the sectormodel and version), captures the expected response shapes, validates each round-trip, generates a machine-readable conformance report (XML + HTML), and surfaces pass/fail per scenario; the report is uploadable to the VNG test portal as evidence

### REQ-015: Grote Berichten payload chunking

- **GIVEN** an outbound bericht (typically a zaak with embedded documents) exceeds 20 MB serialized
- **WHEN** the transport_profile is `digikoppeling_ebms` with Grote Berichten enabled
- **THEN** openconnector delegates to the digikoppeling-adapter which splits the payload per the Grote Berichten profile (reference-bericht pointing to a separate document download URL served by the Grote Berichten server), tracks the reference in the StUFBericht, and on receipt of the bevestiging marks the bericht as delivered; the small reference-bericht travels over ebMS while the large body uses the GB endpoint

### REQ-016: Idempotency on retried kennisgevingen

- **GIVEN** a source system resends the same kennisgeving (same referentienummer, same tijdstipBericht) because its acknowledgement timed out
- **WHEN** the duplicate arrives at the callback endpoint
- **THEN** openconnector detects the duplicate by (referentienummer, sender, berichtsoort) within a 24h window, returns the previously-sent `Bv01` acknowledgement without re-emitting the downstream domain event, and increments a `stuf.duplicate.suppressed` counter for operational visibility

## Standards

- **StUF 03.01** — Standaard Uitwisselings Formaat foundation (VNG Realisatie)
- **StUF-BG 0204 / 0310** — Sectormodel basisgegevens
- **StUF-ZKN 0310 / 0301** — Sectormodel zaken
- **StUF-EF** — Sectormodel electronische formulieren
- **SOAP 1.1** + **SOAP 1.2** — W3C
- **WS-Addressing 1.0**, **WS-Security 1.1**, **WS-ReliableMessaging 1.1**
- **Digikoppeling 3.0** — WUS, ebMS2, Grote Berichten profile
- **NORA** — koppelvlakstandaarden
- **GEMMA 2** — gemeentelijke architectuur referentie

## Cross-app Integration

- **openconnector base** — provides the SOAP transport, CallLog, and adapter framework
- **digikoppeling-adapter** — provides WUS/ebMS transport for production traffic
- **auth-protocol-suite** — provides mTLS and WS-Security key management
- **ipaas-reliability** — applies retry/circuit-breaker/DLQ to StUF transport-level failures
- **openzaak sidecar** — primary consumer of StUF-ZKN berichten, maps zaken into the ZGW data model
- **openklant sidecar** — consumes StUF-BG kennisgevingen on natural-person mutations
- **valtimo sidecar** — orchestrates case workflows triggered by StUF kennisgevingen
- **openregister** — stores StUFAdapterConfig, StUFBericht, StUFAbonnement, and parsed entity objects
- **mydash** — surfaces StUF adapter health, abonnement status, and bericht throughput

## Target Users

- **Dutch municipalities** (gemeenten — all 342 of them) integrating openconnector with legacy zaaksystemen (Atos Cobra, Centric Suite4, PinkRoccade Civision Zaken, Decos JOIN) over StUF; most have at least one StUF-BG abonnement on BRP mutations and one StUF-ZKN connection between e-formulier engines and the case backend
- **Waterschappen and provincies** running the same StUF-ZKN integrations as municipalities for their own case backends
- **VNG Realisatie compliance teams** verifying StUF conformance against published testbeds (StUF-Testplatform, Gemma-Testbed) before sign-off on supplier integrations
- **Integration engineers** at suppliers replacing aging StUF gateways (typically commercial ESBs from the 2010-2015 era — Cordys, Sonic ESB, BizTalk, Mule 2.x — that municipalities are actively trying to decommission)
- **Architects** designing transition paths from StUF-ZKN to the ZGW REST API while running both in parallel for years during the migration window mandated by the Common Ground roadmap
- **Operations teams** monitoring kennisgeving delivery and vraag/antwoord latency against SLA targets defined in the gemeentelijke aansluitvoorwaarden
- **Government procurement** evaluating openconnector against tender requirements that mandate StUF-BG/ZKN/EF coverage with provable testbed certification
- **Common Ground developers** building federated case-handling applications that must speak the older sectormodellen during the transition window
