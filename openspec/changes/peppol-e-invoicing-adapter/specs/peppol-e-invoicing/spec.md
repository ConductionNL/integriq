# Spec: Peppol E-Invoicing

**Status**: Proposed  
**Scope**: openconnector  
**Tier**: adapter  
**Depends on**: openconnector CallLog service, openconnector event-bus (ADR-013), openconnector Source registry, openregister audit-trail, Peppol BIS Billing 3.0 standard, OpenPeppol SMP/SML infrastructure, EU ViDA directive (2025–2028), Dutch tax law art. 52 (7-year retention)

---

## REQ-001: UBL 2.1 generation conforming to Peppol BIS Billing 3.0

The adapter MUST generate UBL 2.1 invoices that pass the official Peppol BIS Billing 3.0 validation artefacts (Schematron XSD + code lists) without any errors or warnings.

### Scenario: Standard Dutch invoice to Belgian company

- **GIVEN** a `Factuur` object with mandatory fields: `factuurnummer`, `factuurdatum`, `subtotaalExBtw=1000`, `btwBedrag=210`, `totaalIncBtw=1210`, `afzender={naam: 'Acme BV', kvk: '12345678', btwNummer: 'NL123456789B01', peppolId: '0106:12345678', adres: {...}}`, `ontvanger={naam: 'Brussels Corp', kvk: 'BE987654321', btwNummer: 'BE987654321', peppolId: '0106:987654321', adres: {...}}`, `regels=[{omschrijving: 'Consulting services', aantal: 1, prijsPerEenheid: 1000, btwTarief: 21, btwCategorie: 'S', regelTotaal: 1000}]`
- **WHEN** `UblGenerator::generate($factuur)` serialises it to UBL 2.1
- **THEN** the resulting XML validates cleanly against OpenPeppol Schematron v3.0.x with zero errors, zero warnings.

### Scenario: VAT reverse charge (intra-EU category AE)

- **GIVEN** a `Factuur` where the receiver is in Austria, the line item carries `btwCategorie='AE'` (reverse charge), and the sending company is Dutch
- **WHEN** serialised to UBL
- **THEN** the UBL includes `cac:ClassifiedTaxCategory` with `cbc:ID = 'AE'`, `cbc:TaxExemptionReasonCode`, and `cbc:TaxExemptionReason` per Schematron rule BR-IG-10; validation passes.

### Scenario: Credit note (document type code 381)

- **GIVEN** a `Factuur` with `richting='uitgaand'` and `InvoiceTypeCode=381` (credit note) referencing an original `referentieOrderId` or `referentieContractId`
- **WHEN** serialised to UBL
- **THEN** the adapter generates a CreditNote document (not Invoice), includes `cac:BillingReference` linking to the original, and routes to the BIS Billing 3.0 CreditNote process.

### Reviewer confirms: grep `UblGenerator::class` in `Application.php` confirms it is DI-registered; `composer test` on UblGenerator unit tests confirms Schematron validation passes on 10+ fixture invoices (Dutch→EU, reverse-charge, credit-note variants).

---

## REQ-002: Service Provider mode — Storecove REST API integration

The adapter MUST integrate with Storecove's REST API as the reference Service Provider implementation, hiding SP-specific details behind the `IPeppolGateway` interface.

### Scenario: Send a UBL invoice via Storecove

- **GIVEN** a `Source` of type `peppol-storecove` with `configuration.api_key`, `configuration.legalEntityId`, and `configuration.webhook_url`
- **WHEN** the adapter sends an outbound `Factuur` via `GatewayRouter::send($factuur, $source)`
- **THEN** it routes to `StorecoveGateway::send($ublXml, $envelope)`, which performs `POST https://api.storecove.com/api/v2/document_submissions` with request body: `{document: {documentRaw: base64($ublXml)}, routing: {eIdentifiers: [{scheme: '0106', identifier: $receiverKvk}]}, legalEntityId: $legalEntityId}`, receives `200 OK {guid: 'xyz'}`, and records `$factuur->peppolEnvelope->transmissionId = 'xyz'` plus `FactuurStatus(status=sent, kanaal=peppol-mlr)`.

### Scenario: Storecove webhook receipt of inbound invoice

- **GIVEN** Storecove sends a webhook POST to `{webhook_url}` with `{event_type: 'INVOICE_RECEIVED', invoice: {guid: '...', document_raw: base64(ublXml)}, timestamp: ...}`
- **WHEN** the adapter verifies the HMAC signature (Storecove-specific header), parses the UBL, and validates against Schematron
- **THEN** it creates a new `Factuur(richting=inkomend)` from the parsed UBL, stores `originalUbl`, stores `peppolEnvelope.transmissionId = guid`, generates an MLR acknowledgement, sends the MLR back to Storecove via `POST .../message_responses`, and emits `openconnector.factuur.received` event.

### Scenario: Storecove transient failure (500, timeout)

- **GIVEN** Storecove returns `500 Server Error` or the connection times out mid-request
- **WHEN** `StorecoveGateway::send()` encounters the failure
- **THEN** it returns `GatewayResult.transient_failure`, the adapter queues the send for retry with exponential backoff (4 attempts: 30s, 5m, 30m, 4h), and logs to `CallLog` with `http_status=500`, `retry_attempt=1`, etc.

### Reviewer confirms: `tests/Unit/Service/Peppol/Gateways/StorecoveGatewayTest.php` contains mocked Storecove API calls and HMAC validation; integration test sends a fixture invoice against Storecove sandbox and confirms receipt within 5 minutes.

---

## REQ-003: Service Provider mode — pluggable gateway interface

The adapter MUST expose an `IPeppolGateway` interface so additional Service Providers can be added in follow-up changes without modifying core code.

### Scenario: Add a new Service Provider (Pagero)

- **GIVEN** a developer writes `lib/Service/Peppol/Gateways/PageroGateway.php implements IPeppolGateway` conforming to the interface contract: `send(string $ublXml, UblEnvelope $envelope): GatewayResult`, `handleWebhook(array $payload): InboundInvoice`, `discoverCapabilities(string $peppolId): CapabilityList`
- **WHEN** they register the class as a DI service with tag `<tag name="PeppolGateway" value="pagero"/>`
- **THEN** operators can create a `Source(type=peppol-pagero, configuration.gateway_type='pagero')` and the adapter automatically routes sends to `PageroGateway::send()` without any changes to `GatewayRouter` or core classes.

### Scenario: Multiple Service Providers in one openconnector instance

- **GIVEN** two `Source` records: one of type `peppol-storecove`, another of type `peppol-pagero`, with `Source.configuration.priority` set to `[storecove: 10, pagero: 5]` (higher = preferred)
- **WHEN** the adapter sends a `Factuur` whose receiver is accredited with both SPs
- **THEN** `GatewayRouter` picks Storecove (priority 10) based on `Source.configuration.priority` and the receiver's `peppolId`. If Storecove is unavailable, the router tries Pagero (priority 5).

### Scenario: Service Provider returns transient failure; retry with exponential backoff

- **GIVEN** a gateway returns `GatewayResult.transient_failure` (e.g. SP rate limit, 503 Service Unavailable)
- **WHEN** the adapter's retry handler sees the failure
- **THEN** it queues the send for 4 retry attempts: 30 seconds, 5 minutes, 30 minutes, 4 hours. On the 4th retry failure, it marks the `Factuur` as `FactuurStatus(status=rejected, details='max_retries_exceeded')` and emits `openconnector.factuur.send-failed`.

### Reviewer confirms: Interface `IPeppolGateway` is defined at `lib/Service/Peppol/Gateways/IPeppolGateway.php`; `StorecoveGateway` and (in follow-up) `PageroGateway` implement it; `GatewayRouter::selectGateway($source)` uses a service locator pattern to resolve the implementation by `gateway_type`.

---

## REQ-004: Own Access Point mode — AS4/ebMS3 transport (Phase 2)

*(Deferred to Phase 2; documented here for completeness)*

The adapter SHALL support direct AS4 transmission to receiver Access Points when configured with a Peppol AP certificate accredited by OpenPeppol.

### Scenario: Send via own Access Point

- **GIVEN** a `Source` of type `peppol-own-ap` with `configuration.certificate_path` pointing to a production Peppol AP certificate (loaded from Nextcloud Vault per ADR-007), and a `Factuur` whose receiver `peppolId=0106:987654321` is resolvable via SML/SMP
- **WHEN** the adapter sends via `OwnApGateway::send($ublXml, $envelope)`
- **THEN** it performs SML lookup on `987654321` to find the SMP, queries SMP for the BIS Billing 3.0 document-type endpoint, constructs an AS4/ebMS3 user message, signs the payload with XAdES, encrypts the message per the receiver's certificate, and POSTs to the resolved endpoint URL with `Content-Type: application/soap+xml`.

### Reviewer confirms: Phase 2 change will include `OwnApGateway` with AS4/ebMS3 implementation and integration tests against OpenPeppol test AP.

---

## REQ-005: SMP/SML caching and document-type discovery

The adapter MUST cache SMP lookup results for 24 hours per `peppolId` and surface the receiver's supported document types, allowing the caller to validate process support before sending.

### Scenario: Discover Peppol participant capabilities

- **GIVEN** a `peppolId=0106:12345678` not present in the `PeppolDeelnemer` cache
- **WHEN** the caller invokes `SmpSmlService::discover($peppolId)` or the adapter encounters the peppolId during send
- **THEN** the adapter performs the SML→SMP lookup (DNS query + HTTP GET), caches the result in `PeppolDeelnemer` with `laatstGecheckt = now()`, and returns the array of supported document-type identifiers (e.g. `['urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2']`).

### Scenario: Stale-while-revalidate for cached entries

- **GIVEN** a `PeppolDeelnemer` entry for `peppolId=0106:12345678` with `laatstGecheckt` 25 hours ago
- **WHEN** the adapter encounters the peppolId during send
- **THEN** it serves the stale data immediately (no wait), logs the send, and triggers an async background refresh to update `PeppolDeelnemer.documenten` and `laatstGecheckt`.

### Scenario: Process not supported validation

- **GIVEN** a `Factuur` whose receiver does not support the `urn:oasis:names:specification:ubl:schema:xsd:Invoice-2` process per their SMP entry
- **WHEN** the adapter validates before send
- **THEN** it rejects the send with `PeppolError.PROCESS_NOT_SUPPORTED` BEFORE any network call, returns the supported process list to the caller, and logs to `CallLog` with `error_code=PROCESS_NOT_SUPPORTED`, `supported_processes=[...]`.

### Reviewer confirms: `PeppolDeelnemer` table has columns `peppolId`, `naam`, `documenten` (JSON), `laatstGecheckt` (datetime); `SmpSmlService::discover()` is unit-tested with mocked SML/SMP responses; integration test uses OpenPeppol test SML.

---

## REQ-006: Inbound invoice ingestion

The adapter MUST translate inbound Peppol UBL invoices (from Service Provider webhooks or own-AP receipt) into canonical `Factuur(richting=inkomend)` objects with full line-item mapping and attachment extraction.

### Scenario: Parse inbound UBL and create Factuur

- **GIVEN** an inbound UBL invoice received via Storecove webhook, with `cac:SupplierParty.cbc:EndpointID=0106:abcdef`, `cac:Lines.Item` array, embedded PDF in `cac:AdditionalDocumentReference`, and valid ISO 8601 timestamps
- **WHEN** `InboundIngestionService::parse($ublXml)` processes it
- **THEN** it creates a new `Factuur(richting=inkomend)` with:
  - Every `cac:InvoiceLine` mapped to a `Factuurregel` entry (regelnummer, omschrijving, aantal, eenheid, prijsPerEenheid, btwTarief, btwCategorie, regelTotaal)
  - `originalUbl` set to the raw UBL XML
  - `originalPdf` extracted from `cac:AdditionalDocumentReference` (Base64-decoded)
  - `peppolEnvelope` populated with `senderId=0106:abcdef`, `documentType`, `transmissionId`, `transmittedAt`
  - All amounts reconciled (sum of lines ≈ invoice total within 1 cent tolerance)

### Scenario: Unrouted invoice triage

- **GIVEN** an inbound UBL invoice where the sender `peppolId=0106:unknown` does not match any configured `Source.configuration.receive_from_peppolIds`
- **WHEN** `InboundIngestionService::parse()` completes
- **THEN** the invoice is parked as `Factuur(status=unrouted)` with a human-readable reason in `details="Sender 0106:unknown not in configured sources"`, and the adapter emits `openconnector.factuur.unrouted` event for operator triage.

### Scenario: Credit note linking to original

- **GIVEN** an inbound credit note (InvoiceTypeCode=381) with `cac:BillingReference.cbc:ID=INV-2024-001` referencing the original invoice
- **WHEN** parsed and ingested
- **THEN** the adapter creates a `Factuur(richting=inkomend, InvoiceTypeCode=381)` with `referentieOrderId=INV-2024-001`, queries the openconnector register for the original `Factuur` by `factuurnummer=INV-2024-001`, links them, and emits `openconnector.factuur.credit-note` to the consumer app (e.g. shillinq AR).

### Reviewer confirms: `InboundIngestionService` is unit-tested with 5+ UBL variants (standard, reverse-charge, credit-note, embedded-PDF, missing-line-item); amounts are reconciled per BR-CO-16 (1-cent tolerance); unrouted invoices reach the inbox with `status=unrouted`.

---

## REQ-007: Status round-trips via MLR (Message Level Response) and IRR (Invoice Response)

The adapter MUST send and receive Peppol Message Level Responses (MLR) and Invoice Responses (IRR) to communicate processing and business status, enabling senders to track delivery and receivers to signal disputes.

### Scenario: Acknowledge inbound invoice receipt (MLR)

- **GIVEN** an inbound `Factuur` that passes Schematron validation and is successfully ingested into the inbox
- **WHEN** `InboundIngestionService::parse()` completes
- **THEN** the adapter generates a valid MLR document per Peppol BIS Messaging specification with `RE` (Receipt Acknowledgement), signs it (if own-AP mode), and sends it back to the sender through the same gateway (`StorecoveGateway`, `OwnApGateway`, etc.) within 30 seconds. The MLR includes `MessageID` and `Timestamp` for round-trip correlation.

### Scenario: Consumer app disputes an inbound invoice

- **GIVEN** an inbound `Factuur` whose status the consumer app (e.g. shillinq AP) marks as `status=disputed` with `details="Goods not received as specified; requesting 50% credit"`
- **WHEN** `StatusRoundTripService::handleDispute($factuur)` is called
- **THEN** the adapter generates an IRR (Invoice Response) per BIS Invoice Response 3.0 with `ResponseCode=CON` (Conditionally accepted) + the reason text from `details`, signs it, and sends it back to the sender via the same gateway. The IRR includes the original invoice's `DocumentReference/ID` for correlation.

### Scenario: Receive acknowledgement of outbound invoice (IRR)

- **GIVEN** an inbound IRR from the receiver acknowledging an outbound `Factuur` (our prior `richting=uitgaand` invoice) with `ResponseCode=RE` (Acknowledged)
- **WHEN** the webhook handler receives and parses the IRR
- **THEN** the adapter matches the IRR to the original outbound `Factuur` via `DocumentReference/ID` or `MessageID`, creates `FactuurStatus(status=accepted, kanaal=peppol-irr, correlatieId=ReceiptID)`, and emits `openconnector.factuur.accepted` so the consumer app (shillinq dunning workflow) can pause payment follow-ups.

### Reviewer confirms: MLR generation per `tests/Unit/Service/Peppol/StatusRoundTripServiceTest.php`; IRR parsing verified against OpenPeppol sample IRR documents; round-trip correlation via `MessageID` confirmed in integration test.

---

## REQ-008: Validation hooks

The adapter MUST run every outbound UBL through the bundled Peppol BIS Billing 3.0 validator before sending and reject locally on any error, providing structured error details to the caller.

### Scenario: Invalid VAT number (Modulus 11 check fails)

- **GIVEN** a `Factuur` with `afzender.btwNummer='NL000000000B01'` (Modulus 11 check fails)
- **WHEN** `UblValidator::validate($ublXml)` runs Schematron validation
- **THEN** it rejects the UBL with `ValidationError { code: 'BR-CO-09', message: 'Seller VAT identifier must have valid ISO 3166-1 prefix and check digit', field: 'afzender.btwNummer', value: 'NL000000000B01' }`, the send is aborted before the network call, and the error is returned to the caller (e.g. shillinq) for user display.

### Scenario: Amount reconciliation mismatch >1 cent

- **GIVEN** a `Factuur` with `subtotaalExBtw=1000`, `btwBedrag=210`, but `totaalIncBtw=1209` (should be 1210; discrepancy = 1 cent)
- **WHEN** `UblValidator::validate()` runs custom amount-reconciliation logic
- **THEN** it allows the invoice (1-cent tolerance per BR-CO-16), logs the rounding to `CallLog`, and sends. If discrepancy > 1 cent, it rejects with `ValidationError { code: 'BR-CO-16', message: 'Invoice total must equal sum of lines within 1 cent tolerance', field: 'totaalIncBtw' }`.

### Scenario: Schematron artefacts updated (quarterly OpenPeppol release)

- **GIVEN** OpenPeppol releases Peppol BIS Billing 3.0 v3.1.0 Schematron on 2026-07-01, and openconnector is upgraded to bundle the new XSD
- **WHEN** `UblValidator` runs for the first time after the upgrade
- **THEN** it logs to `CallLog` with `{ event: 'schematron_upgraded', old_hash: 'abc123', new_hash: 'def456', timestamp: now() }` for audit trail, and all subsequent UBLs are validated against the new Schematron. Prior validation hashes are queryable from `CallLog` for historical audit.

### Reviewer confirms: `tests/Unit/Service/Peppol/UblValidatorTest.php` includes fixtures for VAT-check failure, amount-mismatch tolerance, and Schematron XSD loading; `composer test` verifies all scenario assertions.

---

## REQ-009: Status webhooks to consumer apps

Every state transition on a `Factuur` MUST emit a typed OpenConnector event so consumer apps can subscribe without polling.

### Scenario: Invoice delivery confirmation

- **GIVEN** shillinq subscribes to `openconnector.factuur.delivered` event via the event-bus
- **WHEN** a `Factuur(richting=uitgaand)` for which an inbound MLR from the receiver AP is received, transitioning from `status=sent` to `status=delivered`
- **THEN** `StatusRoundTripService` emits the event with payload `{ factuur_id: UUID, status: 'delivered', timestamp: ISO8601, details: { mlr_id: '...', receipt_timestamp: '...' } }`, and shillinq receives the event and updates its own invoice-status UI accordingly.

### Scenario: Shared event subscription across two consumer apps

- **GIVEN** both shillinq (AR module) and pipelinq (POS invoicing) subscribe to `openconnector.factuur.paid`
- **WHEN** an inbound Mollie payment webhook from `mollie-stripe-payment-adapter` links `FactuurStatus(status=paid, kanaal=payment-webhook)` to a `Factuur.betaalReferentie`
- **THEN** the event-bus emits `openconnector.factuur.paid` with the `Factuur.id`, and both shillinq AND pipelinq receive the event and update their respective payment-tracking workflows in parallel.

### Reviewer confirms: Event definitions are declared in `lib/schemas/factuur-status.schema.yaml` with event types (`sent`, `delivered`, `accepted`, `rejected`, `paid`, `disputed`); every status transition in `StatusRoundTripService`, `ArchivalService`, etc. calls `EventService::emit()` with the correct event type; integration test verifies event dispatch to subscribed listeners.

---

## REQ-010: Compliance and archival

The adapter MUST satisfy the 7-year invoice retention requirement under Dutch tax law (Algemene wet inzake rijksbelastingen art. 52) and provide an export-ready archive for Belastingdienst audit requests.

### Scenario: 7-year archival to cold storage

- **GIVEN** a `Factuur` whose `factuurdatum` is more than 7 years in the past (e.g. 2019-01-01)
- **WHEN** the `ArchivalService::archiveExpiredInvoices()` cron job runs (daily or weekly)
- **THEN** the invoice and all related records (`Factuurregel`, `FactuurStatus` timeline, `CallLog` entries) are exported to a deterministic ZIP file with folder structure `{year}/{month}/{factuurnummer}/`, moved to Nextcloud Files archive folder (`/archive/peppol-invoices/{year}/`), and the active `Factuur` record is marked `archived=true` with `archived_at=now()`. The original `Factuur` row remains queryable but `archived=true` signals read-only view to consumer apps.

### Scenario: Belastingdienst audit export

- **GIVEN** a Belastingdienst audit request for invoices between 2023-01-01 and 2023-12-31, and an admin runs `openconnector:peppol:export --start=2023-01-01 --end=2023-12-31`
- **WHEN** the OCC command executes
- **THEN** it exports a ZIP file containing:
  - One folder per invoice: `{factuurnummer}/`
  - Inside each folder: `invoice.ubl` (original UBL XML), `invoice.pdf` (original PDF if present), `status-log.json` (full `FactuurStatus` timeline), `submission-receipt.txt` (SP submission receipt metadata or AS4 receipt)
  - Manifest file: `manifest.json` with invoice count, export timestamp, date range, checksum
  - ZIP is deterministically named: `peppol-export-2023-01-01_to_2023-12-31.zip` and can be transmitted to Belastingdienst or archived for 7 years.

### Scenario: Archived invoice remains queryable

- **GIVEN** a `Factuur` that was archived 5 years ago, marked `archived=true` and moved to cold storage
- **WHEN** a consumer app (shillinq) queries it via OpenRegister API (e.g. `GET /openregister/factuur/{id}`)
- **THEN** it returns the full `Factuur` object with `archived=true` flag, and the consumer app MUST display it in read-only mode (no edit buttons, no send/resend). The consumer app MAY choose to fetch the full PDF from the archive ZIP if needed for display.

### Reviewer confirms: `ArchivalService` is tested with fixture invoices spanning 7+ years; archival ZIP structure matches Belastingdienst audit specifications; `openconnector:peppol:export` OCC command produces a valid ZIP with all expected files; integration test verifies archived invoices are queryable and flagged `archived=true`.

---

## Verification Checklist

- [ ] All 10 requirements (REQ-001..010) are implemented
- [ ] Unit tests for each service class (UblGenerator, UblValidator, StorecoveGateway, SmpSmlService, InboundIngestionService, StatusRoundTripService, ArchivalService)
- [ ] Integration tests for end-to-end flows: outbound send → inbound receipt → MLR/IRR → status update → event emission
- [ ] Schematron validation passes on 15+ fixture invoices (Dutch→EU, reverse-charge, credit-note, embedded-PDF variants)
- [ ] Storecove sandbox integration test completes in <5 minutes
- [ ] OpenPeppol test SML/SMP lookup verified
- [ ] 7-year archival ZIP export verified against Belastingdienst format spec
- [ ] Event-bus subscription tests confirm status events reach all subscribed consumer apps (shillinq, pipelinq, procest, scholiq)
- [ ] `composer test` passes all tests
- [ ] `composer phpstan` level 9 or higher
- [ ] No phpcs violations (ADR-008 sniffs)
