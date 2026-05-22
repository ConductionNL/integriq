# Tasks: stuf-bg-zkn-bg-koppelvlak

## Tasks

### OC-1. StUF Adapter Infrastructure (M)

- [ ] OC-1.1 Create the StUFAdapterConfig openregister schema with fields:
  - id, slug, sectormodel (enum: BG|ZKN|EF), schema_version, endpoint_url, ontvanger_organisatie,
    ontvanger_applicatie, zender_organisatie, zender_applicatie, transport_profile (enum: plain_https|digikoppeling_wus|digikoppeling_ebms),
    auth_profile_ref, reliability_profile_ref, xsd_bundle_ref, retention_days, testbed_mode
  - **Acceptance:** Schema is registered in openregister; all fields are present and typed correctly; config can be created and retrieved.

- [ ] OC-1.2 Create the StUFBericht openregister schema with fields:
  - id, adapter_id, direction (inbound|outbound), interaction (kennisgeving|vraag|antwoord|fout), berichtsoort, entiteittype,
    referentienummer, tijdstipBericht, crossRefnummer, raw_envelope_ref, parsed_payload, soap_action, response_to, created_at
  - **Acceptance:** Schema is registered; all fields typed; bericht creation/retrieval works.

- [ ] OC-1.3 Create the StUFAbonnement openregister schema with fields:
  - id, adapter_id, entiteittype, mutatiefilters (array), callback_endpoint, status (active|paused|cancelled),
    created, last_delivery
  - **Acceptance:** Schema registered; abonnement creation and status updates work.

- [ ] OC-1.4 Extend CallLog schema to support StUFFout (fault tracking) with fields:
  - foutcode, ernst (info|waarschuwing|fout), omschrijving, plek, gerelateerd_xpath
  - **Acceptance:** CallLog can store StUF faults with all fields; faults are queryable by foutcode.

### OC-2. XSD Bundle Management (S)

- [ ] OC-2.1 Bundle published VNG XSD files into the codebase:
  - `lib/Resources/xsd/stuf-bg-0310/` — StUF-BG sector model 0310
  - `lib/Resources/xsd/stuf-bg-0204/` — StUF-BG sector model 0204 (legacy fallback)
  - `lib/Resources/xsd/stuf-zkn-0310/` — StUF-ZKN sector model 0310
  - `lib/Resources/xsd/stuf-zkn-0301/` — StUF-ZKN sector model 0301 (legacy fallback)
  - `lib/Resources/xsd/stuf-ef/` — StUF-EF sector model (formulier, bijlage)
  - **Acceptance:** All XSD files present; no external downloads at runtime; each bundle is version-pinned in git.

- [ ] OC-2.2 Create an XSD loader/validator class that:
  - Caches parsed XSD bundles in memory per (sectormodel, schema_version)
  - Validates SOAP bodies against the loaded bundle on every bericht (inbound or outbound)
  - Returns detailed error location and message on validation failure (typed StUFValidationError)
  - **Acceptance:** PHPUnit tests confirm: caching works, validation rejects malformed bodies with error location, valid bodies pass.

### OC-3. SOAP Envelope Construction (M)

- [ ] OC-3.1 Create a SOAP envelope builder that:
  - Generates stuurgegevens block with injected zender/ontvanger organisatie + applicatie from StUFAdapterConfig
  - Generates fresh referentienummer (UUIDv4) per bericht
  - Stamps tijdstipBericht (now in yyyyMMddHHmmssSSS format)
  - Validates that all stuurgegevens fields are non-empty at config-activation time
  - **Acceptance:** Envelope builder passes PHPUnit tests; missing stuurgegevens trigger config-validation error; 5 valid envelopes have different referentienummers and timestamps.

- [ ] OC-3.2 Implement vraag envelope construction for StUF-BG (npsLv01):
  - Parameters: gelijk-vergelijking (e.g., inp.bsn=...), sortering, indicatorVervolgvraag
  - Scope (requested fields)
  - **Acceptance:** PHPUnit test confirms envelope structure; field order and namespaces match XSD; validation succeeds.

- [ ] OC-3.3 Implement vraag envelope construction for StUF-ZKN (zakLv01):
  - Parameters: gelijk-vergelijking (e.g., zk.zaakidentificatie=...), sortering
  - Scope (requested zaken fields)
  - **Acceptance:** PHPUnit test; ZKN envelope structure is correct per XSD.

- [ ] OC-3.4 Implement kennisgeving reception: parse inbound La01/Kv01 envelopes:
  - Extract entiteittype, berichtsoort, referentienummer, tijdstipBericht, parsed payload
  - **Acceptance:** Parser handles both Lv01 responses and inbound kennisgevingen; parsed payload is structurally correct.

### OC-4. HTTP Transport and SOAP Action Headers (S)

- [ ] OC-4.1 Create an HTTP client wrapper that:
  - POSTs SOAP envelope to configured endpoint_url
  - Sets SOAPAction header per sectormodel and version (e.g., `http://www.egem.nl/StUF/sector/bg/0310/npsLv01`)
  - Sets Content-Type: application/soap+xml (SOAP 1.2) or text/xml (SOAP 1.1) per config
  - Handles timeouts (configurable, default 30s)
  - Supports optional Basic-Auth (from auth_profile_ref)
  - **Acceptance:** PHPUnit test with mocked HTTP client confirms correct headers; timely timeout returns error.

- [ ] OC-4.2 For plain_https transport: direct HTTPS POST to endpoint_url
  - **Acceptance:** plain_https config routes to direct HTTP client; Digikoppeling configs delegate.

- [ ] OC-4.3 For digikoppeling_wus/ebms transport: delegate to digikoppeling-adapter
  - Wrap SOAP envelope and submit via digikoppeling-adapter interface
  - Return response envelope from adapter to StUF parser
  - **Acceptance:** digikoppeling configs call digikoppeling-adapter; plain_https does not.

### OC-5. Request-Response Correlation (S)

- [ ] OC-5.1 Implement vraag/antwoord matching via referentienummer and crossRefnummer:
  - Outbound vraag gets referentienummer R1
  - Inbound antwoord carries crossRefnummer = R1
  - Link inbound to outbound via response_to field
  - Emit `stuf.vraag.answered` domain event with both bericht IDs
  - Resolve in-flight promise waiting on R1
  - **Acceptance:** PHPUnit test: vraag gets referentienummer; matching antwoord resolves promise; unmatched antwoord is flagged.

- [ ] OC-5.2 Handle unmatched antwoorden (unknown crossRefnummer):
  - Persist to StUFBericht but flag as unmatched
  - Surface to operator for review (log + CallLog entry)
  - **Acceptance:** Unmatched antwoord is persisted; test suite marks it as unmatched.

### OC-6. SOAP Fault Parsing and Error Mapping (S)

- [ ] OC-6.1 Create SOAP fault parser that extracts:
  - Fault code (SOAP 1.1: faultcode, SOAP 1.2: Code)
  - Fault string (message)
  - Detail block (StUF-specific Fo02 body with foutcode, omschrijving, plek)
  - **Acceptance:** PHPUnit test with StUF fault fixtures confirms extraction; both SOAP 1.1 and 1.2 handled.

- [ ] OC-6.2 Map SOAP fault to typed StUFFault error:
  - Extract foutcode (e.g., StUF016, Fo01)
  - Derive ernst level from foutcode (lookup table: StUF016 → fout, StUF067 → waarschuwing)
  - Persist to CallLog as StUFFault entry
  - Return typed error to caller (not generic HTTP 5xx)
  - **Acceptance:** PHPUnit test: Fo02 with StUF016 maps to ernst=fout; retry rules do NOT apply to StUF faults.

### OC-7. Kennisgeving Reception and Callback Endpoint (M)

- [ ] OC-7.1 Create a kennisgeving callback endpoint at `/api/stuf/kennisgeving/{adapter_slug}`:
  - Accept inbound SOAP envelope POSTed by source system
  - Validate envelope against XSD
  - Extract referentienummer, tijdstipBericht, berichtsoort, entiteittype, parsed payload
  - Persist StUFBericht (direction=inbound, interaction=kennisgeving)
  - Return Bv01 (bevestigingsbericht) acknowledgement to sender
  - **Acceptance:** Integration test: POST valid kennisgeving to endpoint; 200 + Bv01 returned; bericht persisted.

- [ ] OC-7.2 Emit domain event `stuf.bericht.received` with parsed payload:
  - Subscribed downstream consumers (openzaak, openklant, valtimo) react to the event
  - **Acceptance:** Event listener test confirms domain event is emitted and receivable.

- [ ] OC-7.3 Implement deduplication within 24h window:
  - Detect duplicate by (referentienummer, sender, berichtsoort) within 24 hours
  - Return previously-sent Bv01 without re-emitting domain event
  - Increment `stuf.duplicate.suppressed` counter
  - **Acceptance:** PHPUnit test: send same kennisgeving twice; second call returns 200 but no domain event emitted; counter incremented.

### OC-8. Abonnement Management (M)

- [ ] OC-8.1 Implement StUFAbonnement creation API:
  - Consumer POSTs StUFAbonnement with entiteittype, mutatiefilters, callback_endpoint
  - openconnector constructs abonnement-bericht per sectormodel (e.g., abreq for BG)
  - POSTs abonnement-bericht to source's abonnement-endpoint
  - Captures returned abonnement-id from response
  - Stores StUFAbonnement record with status=active
  - **Acceptance:** Integration test: POST abonnement; openconnector POSTs to source; abonnement stored with ID.

- [ ] OC-8.2 Implement mutatiefilter encoding:
  - Map logical filters (overlijden, verhuizing, naamswijziging) to VNG mutatiesoort codes (Wmu, Wvh, Wnw, ...)
  - Encode into abonnement-bericht per sectormodel spec
  - **Acceptance:** PHPUnit test: filters=[overlijden, verhuizing] encode to correct mutatiesoort codes.

- [ ] OC-8.3 Implement abonnement cancellation:
  - Consumer DELETEs StUFAbonnement by ID
  - openconnector POSTs abreq-bericht to source
  - Updates StUFAbonnement status to cancelled
  - **Acceptance:** Integration test: DELETE abonnement; source receives abreq; status = cancelled.

- [ ] OC-8.4 Track last_delivery timestamp:
  - Update StUFAbonnement.last_delivery on every successful kennisgeving delivery
  - Surface to operator via API (abonnement health)
  - **Acceptance:** PHPUnit test: kennisgeving delivery updates last_delivery; operator can query abonnement status.

### OC-9. Schema Version Negotiation (S)

- [ ] OC-9.1 Detect unsupported schema version faults (StUF067):
  - On first vraag failure with foutcode StUF067
  - Log `schema.unsupported` warning with recommended version
  - Do NOT auto-downgrade (would silently change semantics)
  - Surface foutmelding to operator with remediation path
  - **Acceptance:** PHPUnit test: StUF067 fault logs warning and surfaces message; config is NOT auto-updated.

### OC-10. Bericht Retention and PII Redaction (M)

- [ ] OC-10.1 Implement retention lifecycle:
  - Store StUFBericht with full parsed_payload and raw_envelope_ref on creation
  - After retention_days (default 90) elapses, trigger redaction job
  - Preserve metadata: referentienummer, tijdstipBericht, berichtsoort, sender, receiver, outcome
  - Redact PII fields: BSN, names, addresses, family relations (per AVG dataminimalisatie)
  - **Acceptance:** PHPUnit test: bericht created; after retention window, redaction job runs; PII fields are null/stripped; audit fields remain.

- [ ] OC-10.2 Create a cron job to run redaction on schedule:
  - Query all berichten with created_at older than (now - retention_days)
  - Apply redaction transformation
  - Persist redacted state
  - **Acceptance:** Cron job runs on schedule; PHPUnit confirms redaction is applied per-bericht.

### OC-11. Testbed Mode and Conformance Testing (S)

- [ ] OC-11.1 Detect testbed_mode = true in StUFAdapterConfig:
  - Route to VNG StUF-Testplatform endpoint instead of configured endpoint_url
  - **Acceptance:** Config with testbed_mode routes to testplatform; production config routes to endpoint_url.

- [ ] OC-11.2 Implement conformance test suite execution:
  - Emit all required test berichten in prescribed order (per VNG published conformance scenarios)
  - Capture response shapes and validate each round-trip
  - Generate machine-readable conformance report (XML + HTML)
  - Surface pass/fail per scenario
  - **Acceptance:** Testbed mode executes test suite; report is generated and uploadable to VNG portal.

### OC-12. Domain Event Emission and Downstream Integration (S)

- [ ] OC-12.1 Define domain events:
  - `stuf.bericht.received` — inbound kennisgeving with parsed payload
  - `stuf.vraag.answered` — outbound vraag was answered (links vraag + antwoord IDs)
  - `stuf.duplicate.suppressed` — duplicate kennisgeving within 24h window
  - **Acceptance:** Events are defined in domain event registry; consumers can subscribe.

- [ ] OC-12.2 For sector-specific entity mapping (ZAK, NPS, EDC):
  - Emit typed event `stuf.entiteittype.received` (e.g., `stuf.zak.received`) with parsed entity
  - Downstream consumers (openzaak sidecar) map to OR schema and persist
  - **Acceptance:** Sidecar integration test confirms mapping and OR persist.

### OC-13. Structured Observability (S)

- [ ] OC-13.1 Emit structured log entry per bericht interaction (not per cache hit):
  - Fields: adapter_id, interaction, berichtsoort, entiteittype, direction, upstream_latency_ms (nullable for inbound),
    http_status, soap_status (success|fault), foutcode (nullable), transport_profile
  - Levels: debug for 2xx + success, warning for schema mismatch, error for SOAP faults and transport errors
  - **Acceptance:** PHPUnit test with mocked logger confirms fields and levels for 3 scenarios: cold-hit, fault, transport error.

### OC-14. REST Controller and Route Registration (S)

- [ ] OC-14.1 Create `lib/Controller/StUFController.php` with action methods:
  - `requestAction(slug, entiteittype, params)` — submit vraag and await antwoord
  - `callbackAction(slug)` — receive inbound kennisgeving (POST)
  - All methods use `requireLogin()`
  - **Acceptance:** Controller class exists; routes are registered; requireLogin() guards; parameter validation works.

- [ ] OC-14.2 Register routes in `appinfo/routes.php`:
  - `POST /api/stuf/{slug}/request` → StUFController::requestAction (vraag/antwoord)
  - `POST /api/stuf/kennisgeving/{slug}` → StUFController::callbackAction (kennisgeving reception)
  - **Acceptance:** Routes are in routes.php; integration test confirms both routes work.

### OC-15. Configuration and Activation (S)

- [ ] OC-15.1 Implement StUFAdapterConfig validation at activation time:
  - All stuurgegevens fields must be non-empty
  - endpoint_url must be valid HTTPS (or http for dev)
  - XSD bundle ref must match a bundled version
  - transport_profile must be one of the three enums
  - **Acceptance:** PHPUnit test: empty stuurgegevens field raises config-validation error; valid config activates successfully.

### OC-16. PHPUnit Tests and Quality Gate (M)

- [ ] OC-16.1 Write comprehensive PHPUnit test suite:
  - `tests/Unit/Connectors/StUFConnectorTest.php` — envelope construction, XSD validation, vraag/antwoord flow, kennisgeving reception, fault mapping, deduplication
  - `tests/Unit/Controller/StUFControllerTest.php` — missing params return 400, unauthenticated returns 401, valid request returns 200
  - `tests/Integration/StUFAdapterTest.php` — end-to-end vraag/antwoord with mocked source, kennisgeving callback with domain events
  - **Acceptance:** All tests pass; `composer check:strict` reports zero PHPCS, PHPMD, Psalm, PHPStan errors in new files.

- [ ] OC-16.2 Verify XSD validation with invalid and valid fixtures:
  - Create `tests/fixtures/stuf/` with sample envelopes (valid and invalid per sectormodel)
  - Validation tests confirm invalid envelopes are rejected with error location
  - **Acceptance:** Fixture files present; validation tests pass; invalid fixture is caught with clear error.
