# Tasks — Peppol E-Invoicing Adapter

## Phase 1: Service Provider Mode Foundation

### Task 1.1: Database migrations and schema setup

- **spec_ref**: REQ-001, REQ-005, REQ-006, REQ-009, REQ-010
- **files**: `migrations/Version*.php` (new), `lib/Entity/{Factuur,Factuurregel,FactuurStatus,PeppolDeelnemer}.php` (new)
- **acceptance_criteria**:
  - [ ] Migrations create tables: `Factuur`, `Factuurregel`, `FactuurStatus`, `PeppolDeelnemer` with correct columns and types (see design.md Data Model table)
  - [ ] `Factuur` has unique constraint on `(richting, factuurnummer, afzender_kvk)` to prevent duplicates from shillinq and inbound ingestion
  - [ ] `FactuurStatus` is append-only (no UPDATE, only INSERT) per ADR-003 pattern
  - [ ] `PeppolDeelnemer` has TTL column `laatstGecheckt` (datetime) for 24h cache invalidation
  - [ ] All entity classes use OpenRegister abstractions for schema definition (if available), or Doctrine ORM if not
  - [ ] `composer doctrine:migrations:migrate` runs cleanly on both MySQL and PostgreSQL
  - [ ] Rollback migration drops all four tables cleanly

### Task 1.2: UblGenerator service — UBL 2.1 serialization

- **spec_ref**: REQ-001
- **files**: `lib/Service/Peppol/UblGenerator.php` (new), `tests/Unit/Service/Peppol/UblGeneratorTest.php` (new)
- **acceptance_criteria**:
  - [ ] `UblGenerator::generate(Factuur $factuur): string` returns valid UBL 2.1 XML
  - [ ] Serialization includes all mandatory Peppol BIS elements: `cac:SupplierParty`, `cac:CustomerParty`, `cac:InvoiceLine`, `cac:TaxTotal`, `cac:LegalMonetaryTotal`
  - [ ] VAT categories are correctly mapped: `btwCategorie` → `cac:ClassifiedTaxCategory.cbc:ID` (S, Z, E, AE, etc.)
  - [ ] Credit note invoices generate `CreditNote` XML (not Invoice) when `InvoiceTypeCode=381`
  - [ ] Reverse-charge invoices (category AE) include `cbc:TaxExemptionReasonCode` and `cbc:TaxExemptionReason`
  - [ ] Unit tests include 5+ fixtures: standard Dutch→EU, reverse-charge, credit-note, embedded-PDF, missing-optional-field
  - [ ] All generated UBLs pass Schematron validation (see task 1.3)

### Task 1.3: UblValidator service — Schematron + custom validation

- **spec_ref**: REQ-001, REQ-008
- **files**: `lib/Service/Peppol/UblValidator.php` (new), `lib/Resources/peppol-bis-billing-3.0-schematron.xsd` (bundled), `tests/Unit/Service/Peppol/UblValidatorTest.php` (new)
- **acceptance_criteria**:
  - [ ] Bundle OpenPeppol Schematron XSD file (v3.0.x) under `lib/Resources/`
  - [ ] `UblValidator::validate(string $ublXml): ValidationResult` runs Schematron validation
  - [ ] Custom validators added: VAT number Modulus 11 check, amount reconciliation (1-cent tolerance per BR-CO-16), required field presence
  - [ ] Returns structured error list: `[{code: 'BR-CO-09', message: '...', field: 'afzender.btwNummer', value: 'NL000000000B01'}, ...]`
  - [ ] Schematron hash recorded in `CallLog` on first run after upgrade (ADR-003)
  - [ ] Unit tests cover: valid invoice, VAT-check failure, amount-mismatch tolerance (1 cent OK, 2 cents rejected), missing field, invalid currency code
  - [ ] Handles both Invoice and CreditNote document types

### Task 1.4: StorecoveGateway service — Storecove REST API integration

- **spec_ref**: REQ-002, REQ-003
- **files**: `lib/Service/Peppol/Gateways/IPeppolGateway.php` (interface, new), `lib/Service/Peppol/Gateways/StorecoveGateway.php` (new), `tests/Unit/Service/Peppol/Gateways/StorecoveGatewayTest.php` (new)
- **acceptance_criteria**:
  - [ ] `IPeppolGateway` interface defines: `send(string $ublXml, UblEnvelope $envelope): GatewayResult`, `handleWebhook(array $payload): ?InboundInvoice`, `discoverCapabilities(string $peppolId): CapabilityList`
  - [ ] `StorecoveGateway` implements interface
  - [ ] `send()` performs `POST https://api.storecove.com/api/v2/document_submissions` with Storecove request shape: `{document: {documentRaw: base64($ublXml)}, routing: {eIdentifiers: [{scheme: '0106', identifier: $receiverKvk}]}, legalEntityId: $legalEntityId}`
  - [ ] On success (200 OK), returns `GatewayResult{transmissionId: $guid, status: 'sent'}`
  - [ ] On transient failure (5xx, timeout), returns `GatewayResult{status: 'transient_failure', retryable: true}`
  - [ ] On permanent failure (4xx except 429), returns `GatewayResult{status: 'permanent_failure', message: '...'}`
  - [ ] `handleWebhook()` validates HMAC signature (Storecove-specific header), parses inbound UBL, returns `InboundInvoice` object
  - [ ] Unit tests mock Storecove API; integration test hits Storecove sandbox and confirms receipt within 5 minutes
  - [ ] Every HTTP call logged to `CallLog` per ADR-003

### Task 1.5: GatewayRouter service — Service Provider routing

- **spec_ref**: REQ-002, REQ-003
- **files**: `lib/Service/Peppol/GatewayRouter.php` (new), `tests/Unit/Service/Peppol/GatewayRouterTest.php` (new)
- **acceptance_criteria**:
  - [ ] `GatewayRouter::send(Factuur $factuur, Source $source): GatewayResult` selects the correct gateway based on `Source.configuration.gateway_type`
  - [ ] Supports routing to: `StorecoveGateway` (type='storecove'), future `PageroGateway` (type='pagero'), etc.
  - [ ] If multiple sources available for a receiver, picks based on `Source.configuration.priority` (higher = preferred)
  - [ ] Implements retry logic: on transient failure, queues for retry with exponential backoff (30s, 5m, 30m, 4h)
  - [ ] On max retries exceeded (4 attempts), marks `Factuur` as `status=rejected`, details='max_retries_exceeded'
  - [ ] Unit tests verify: correct gateway selection, priority ordering, retry backoff, final rejection

### Task 1.6: SmpSmlService — Peppol Participant Lookup and Caching

- **spec_ref**: REQ-005
- **files**: `lib/Service/Peppol/SmpSmlService.php` (new), `tests/Unit/Service/Peppol/SmpSmlServiceTest.php` (new)
- **acceptance_criteria**:
  - [ ] `SmpSmlService::discover(string $peppolId): CapabilityList` performs SML lookup (DNS `*.<peppolId>.ehealth.nl` or equivalent)
  - [ ] On cache hit (< 24h old), returns cached `PeppolDeelnemer.documenten` immediately
  - [ ] On cache miss or stale (> 24h), queries SMP and returns result; updates `PeppolDeelnemer.laatstGecheckt = now()`
  - [ ] Implements stale-while-revalidate: on stale cache, serves stale data immediately and triggers async background refresh
  - [ ] `validateProcessSupport(Factuur $factuur, PeppolDeelnemer $deelnemer): bool` checks if receiver's SMP entry includes BIS Billing 3.0 process
  - [ ] If process not supported, throws `PeppolException::PROCESS_NOT_SUPPORTED` before network call; includes supported-process list
  - [ ] Unit tests mock SML/SMP DNS + HTTP responses; integration test uses OpenPeppol test SML
  - [ ] Caching verified: second lookup within 24h does not trigger SML/SMP HTTP calls (logged to `CallLog`)

### Task 1.7: InboundIngestionService — UBL parsing and Factuur creation

- **spec_ref**: REQ-006
- **files**: `lib/Service/Peppol/InboundIngestionService.php` (new), `tests/Unit/Service/Peppol/InboundIngestionServiceTest.php` (new)
- **acceptance_criteria**:
  - [ ] `InboundIngestionService::parse(string $ublXml): Factuur` parses UBL 2.1 and creates `Factuur(richting=inkomend)`
  - [ ] Every `cac:InvoiceLine` mapped to `Factuurregel` with: regelnummer, omschrijving, aantal, eenheid, prijsPerEenheid, btwTarief, btwCategorie, regelTotaal
  - [ ] `originalUbl` stores raw UBL verbatim
  - [ ] `originalPdf` extracted from `cac:AdditionalDocumentReference` if present (Base64-decoded)
  - [ ] `peppolEnvelope` populated: `senderId`, `receiverId`, `processId`, `documentType`, `transmissionId`, `transmittedAt`
  - [ ] Amount reconciliation: sum(regels.regelTotaal) ≈ totaalIncBtw within 1 cent tolerance
  - [ ] Receiver endpoint (`cac:CustomerParty.cbc:EndpointID`) matched against configured `Source.configuration.receive_from_peppolIds` to route to correct inbox
  - [ ] If unrouted (sender not configured), parks as `status=unrouted` with human-readable reason
  - [ ] Credit notes (InvoiceTypeCode=381) linked to original invoice via `referentieOrderId`/`BillingReference`
  - [ ] Unit tests: standard invoice, reverse-charge, credit-note, embedded-PDF, missing-optional-fields
  - [ ] Integration test: parse real Storecove-formatted UBL

### Task 1.8: StatusRoundTripService — MLR/IRR handling

- **spec_ref**: REQ-007
- **files**: `lib/Service/Peppol/StatusRoundTripService.php` (new), `tests/Unit/Service/Peppol/StatusRoundTripServiceTest.php` (new)
- **acceptance_criteria**:
  - [ ] `StatusRoundTripService::generateAndSendMlr(Factuur $factuur): void` on inbound invoice validation success, generates MLR with `RE` (Receipt Acknowledgement), signs if own-AP, sends back to sender via same gateway within 30 seconds
  - [ ] `handleDispute(Factuur $factuur, string $reason): void` when consumer app marks inbound invoice `status=disputed`, generates IRR with `ResponseCode=CON` (Conditionally accepted) + reason text
  - [ ] `handleInboundIrr(string $irl_xml): void` on inbound IRR from receiver, matches to original outbound `Factuur`, creates `FactuurStatus(status=accepted, kanaal=peppol-irr, correlatieId=...)`
  - [ ] Emits events: `openconnector.factuur.accepted` on IRR success, `openconnector.factuur.dispute-received` on inbound dispute
  - [ ] Unit tests: MLR generation, IRR parsing, correlation matching via `MessageID`/`DocumentReference/ID`
  - [ ] Every MLR/IRR logged to `CallLog`

### Task 1.9: Event emission and webhooks

- **spec_ref**: REQ-009
- **files**: `lib/Event/{FactuurSentEvent,FactuurDeliveredEvent,FactuurAcceptedEvent,FactuurRejectedEvent,FactuurPaidEvent,FactuurDisputedEvent}.php` (new), `lib/Webhooks/PeppolWebhookHandler.php` (new)
- **acceptance_criteria**:
  - [ ] Event classes defined for all 6 status transitions: sent, delivered, accepted, rejected, paid, disputed
  - [ ] Each event includes payload: `{factuur_id: UUID, status: string, timestamp: ISO8601, details: {...}}`
  - [ ] Every `FactuurStatus` INSERT (new status event) triggers corresponding event via openconnector event-bus
  - [ ] Webhook handler registered at `/connector/webhook/peppol/*` for receiving inbound invoices and MLR/IRR
  - [ ] Webhook handler validates Service Provider signature (HMAC, Storecove-specific)
  - [ ] Integration test: emit event, verify subscribed listener receives payload

### Task 1.10: Validation hook registration

- **spec_ref**: REQ-008
- **files**: `lib/Validator/PeppolValidator.php` (new, if openconnector has a validator registry; otherwise inline in UblValidator)
- **acceptance_criteria**:
  - [ ] Before every send in `GatewayRouter::send()`, run `UblValidator::validate()` on the generated UBL
  - [ ] If validation fails, return structured error to caller (shillinq) without network call
  - [ ] Validation error includes: error code (BR-CO-09, etc.), message, field, suggested fix
  - [ ] Consumer app displays error to user for correction

### Task 1.11: ArchivalService — 7-year retention and export

- **spec_ref**: REQ-010
- **files**: `lib/Service/Peppol/ArchivalService.php` (new), `lib/Command/PeppolArchiveCommand.php` (new), `tests/Unit/Service/Peppol/ArchivalServiceTest.php` (new)
- **acceptance_criteria**:
  - [ ] `ArchivalService::archiveExpiredInvoices(int $retentionDays = 2555): void` identifies `Factuur` objects older than 7 years (2555 days)
  - [ ] For each expired invoice: exports to ZIP folder structure `{year}/{month}/{factuurnummer}/`, includes `invoice.ubl`, `invoice.pdf`, `status-log.json`, `submission-receipt.txt`
  - [ ] Moves ZIP to Nextcloud Files `/archive/peppol-invoices/{year}/`
  - [ ] Updates `Factuur.archived = true`, `archived_at = now()`
  - [ ] OCC command `openconnector:peppol:export --start=YYYY-MM-DD --end=YYYY-MM-DD` exports invoices in range to single ZIP: `peppol-export-{start}_{end}.zip`
  - [ ] Export ZIP includes manifest: invoice count, export timestamp, checksum
  - [ ] Archived invoices remain queryable via OpenRegister API with `archived=true` flag (read-only)
  - [ ] Unit tests: date-range filtering, ZIP structure, manifest validation
  - [ ] Integration test: archive 10 fixture invoices, verify ZIP contents, verify query-with-archived-flag

### Task 1.12: Consumer app integration points — shillinq

- **spec_ref**: REQ-009
- **files**: (shillinq change, out of scope; openconnector task: document the event contract)
- **acceptance_criteria**:
  - [ ] Document in `docs/integrations/peppol-e-invoicing.md` the event types that shillinq should subscribe to: `openconnector.factuur.received`, `openconnector.factuur.delivered`, `openconnector.factuur.accepted`, `openconnector.factuur.paid`
  - [ ] Provide example event payload for each
  - [ ] Provide example subscriber code (pseudo-code or shillinq-specific)

### Task 1.13: Testing and quality gates

- **spec_ref**: All requirements
- **files**: (throughout)
- **acceptance_criteria**:
  - [ ] `composer test` passes all tests (Unit + Integration)
  - [ ] `composer phpstan` achieves level 9
  - [ ] `composer phpcs` passes without violations (ADR-008 sniffs per openconnector config)
  - [ ] Coverage: UblGenerator, UblValidator, StorecoveGateway, SmpSmlService, InboundIngestionService, StatusRoundTripService, ArchivalService all >80% line coverage
  - [ ] Integration tests: end-to-end send (via Storecove sandbox), inbound receipt, MLR/IRR round-trip, status event emission, archival export
  - [ ] Schematron validation passes on 15+ fixture invoices (Dutch→EU, reverse-charge, credit-note, embedded-PDF, unrouted, etc.)

---

## Phase 2: Own Access Point Mode (Separate Change)

*The following tasks are for Phase 2 and will be tracked in a separate change.*

### Task 2.1: OwnApGateway service — AS4/ebMS3 transport

- **spec_ref**: REQ-004
- **files**: (Phase 2)
- **acceptance_criteria**: (Phase 2)

### Task 2.2: SML/SML lookup for own-AP mode

- **spec_ref**: REQ-005 (extension: SMP lookup for endpoint resolution)
- **files**: (Phase 2)
- **acceptance_criteria**: (Phase 2)

### Task 2.3: XAdES signing

- **spec_ref**: REQ-004
- **files**: (Phase 2)
- **acceptance_criteria**: (Phase 2)

---

## Cross-Cutting Follow-Ups

### Task 3.1: Documentation — Peppol E-Invoicing Integration Guide

- **spec_ref**: ADR-030 (journeydoc)
- **files**: `docs/integrations/peppol-e-invoicing.md` (new), `docs/images/peppol-*.png` (screenshots)
- **acceptance_criteria**:
  - [ ] Integration guide covers: getting started, creating a Source, configuring Storecove API key, testing inbound receipt
  - [ ] Screenshots of: Source creation UI, Storecove webhook registration, sample invoice sent/received flow
  - [ ] Code examples: shillinq outbound send (`openconnector.send('peppol', $factuur)`), inbound subscription (`openconnector.subscribe('openconnector.factuur.received', callback)`)
  - [ ] Compliance notes: 7-year retention, Schematron validation, audit export
  - [ ] Troubleshooting section: common validation errors, webhook failures, SMP/SML lookup latency

### Task 3.2: Sample Peppol invoices for testing

- **spec_ref**: REQ-001, REQ-006, REQ-008
- **files**: `tests/Fixtures/peppol/*.ubl` (UBL 2.1 XML files)
- **acceptance_criteria**:
  - [ ] 15+ fixture UBL files covering: standard Dutch→EU, reverse-charge, credit-note, embedded-PDF, missing-optional-field, invalid-vat, amount-mismatch, unrouted, Storecove-formatted, OpenPeppol-test-SMP
  - [ ] Each fixture includes metadata comment: `<!-- Type: Standard Invoice, Sender: NL, Receiver: BE, Expected: VALID -->`
  - [ ] All valid fixtures pass Schematron; all invalid fixtures fail as expected

### Task 3.3: Storecove integration documentation

- **spec_ref**: REQ-002, REQ-003
- **files**: `docs/integrations/peppol-storecove.md` (per-adapter, in follow-up change)
- **acceptance_criteria**: (Follow-up `add-peppol-storecove-gateway` change)

### Task 3.4: Operator runbook — Peppol SLA monitoring

- **spec_ref**: REQ-009, mydash consumption
- **files**: `docs/operations/peppol-sla-dashboard.md` (new)
- **acceptance_criteria**:
  - [ ] Guide for operators to set up mydash Peppol SLA dashboard per Source: delivery times (p50, p95, p99), rejection rates (by error code), AP/SP cost tracking
  - [ ] Sample queries: "Invoices sent in last 7 days", "Delivery time distribution", "Top 10 rejection reasons"
  - [ ] Alerting thresholds: delivery SLA >2 hours, rejection rate >5%, SMP/SML lookup latency >500ms

---

## Verification

- [ ] All Section 1 tasks (Phase 1) completed
- [ ] All unit tests passing (`composer test`)
- [ ] All integration tests passing (Storecove sandbox, OpenPeppol test SML, archival export)
- [ ] Code quality gates passing (`composer phpstan`, `composer phpcs`)
- [ ] Coverage >80% for all Peppol service classes
- [ ] Schematron validation verified on 15+ fixture invoices
- [ ] Documentation complete (integration guide, troubleshooting, sample invoices)
- [ ] Ready for consumer app (shillinq, pipelinq, procest, scholiq) integration

---

## Tracking

| Task | Status | Owner | ETA | Notes |
|---|---|---|---|---|
| 1.1 Database migrations | [ ] | | | |
| 1.2 UblGenerator | [ ] | | | |
| 1.3 UblValidator | [ ] | | | |
| 1.4 StorecoveGateway | [ ] | | | |
| 1.5 GatewayRouter | [ ] | | | |
| 1.6 SmpSmlService | [ ] | | | |
| 1.7 InboundIngestionService | [ ] | | | |
| 1.8 StatusRoundTripService | [ ] | | | |
| 1.9 Event emission | [ ] | | | |
| 1.10 Validation hook | [ ] | | | |
| 1.11 ArchivalService | [ ] | | | |
| 1.12 shillinq integration doc | [ ] | | | |
| 1.13 Testing & quality gates | [ ] | | | |
| 3.1 Integration guide | [ ] | | | |
| 3.2 Fixture invoices | [ ] | | | |
| 3.4 Operator runbook | [ ] | | | |
