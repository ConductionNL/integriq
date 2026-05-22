---
status: draft
---
# Peppol E-Invoicing Adapter

## Purpose

Deliver a production-grade OpenConnector adapter for sending and receiving electronic invoices over the Peppol network using the Peppol BIS Billing 3.0 specification, so any Conduction app — primarily shillinq (accounts payable/receivable) but also pipelinq (POS/billing), procest (overheidsfacturen) and scholiq (ouderbijdragen) — can comply with the EU and Dutch mandates that take full effect in 2025/2026.

Peppol (Pan-European Public Procurement Online) is the de-facto European standard for B2G and increasingly B2B e-invoicing. In the Netherlands it has been mandatory for invoicing the central government since 2017 and is being extended to all public bodies; the EU's ViDA package and several member states (Belgium, France, Germany, Poland, Italy) make Peppol or a Peppol-aligned channel mandatory for B2B invoicing in the 2025–2028 window. Without a first-class Peppol adapter, every Conduction commercial app that bills customers — and every public-sector deployment that pays suppliers — has to integrate one of the commercial Peppol Service Providers (Storecove, Pagero, Tradeshift, Basware) ad-hoc, in code, per app. That is not sustainable.

This adapter solves it once. It supports two operating modes selectable per `Source`:

1. **Service Provider mode** — talk to a hosted Peppol Access Point (Storecove, Pagero, Basware, Tickstar, Tradeshift). The vast majority of consumers will pick this because it externalises certificate management, SMP lookups and accreditation. The adapter abstracts each SP's REST API behind one `IPeppolGateway` interface.
2. **Own Access Point mode** — talk directly to a self-hosted (or partner-hosted) Peppol AP that the organisation has accredited with OpenPeppol. This is for larger deployments (Logius, central government, big municipalities) that already operate their own AP or use a Dutch partner (e.g. Anachron's Peppol Server, NLDigital members). The adapter implements the AS4 transport profile (ebMS3/AS4 over HTTPS with XAdES-signed payloads) for direct send and SMP/SML lookup for routing.

In both modes the adapter generates and validates UBL 2.1 invoices conforming to Peppol BIS Billing 3.0, handles status round-trips (`sent`, `delivered`, `accepted`, `rejected`, `paid`, `disputed`) via MLR (Message Level Response) and IRR (Invoice Response) documents, and surfaces inbound Peppol invoices as canonical `Factuur` objects in OpenRegister.

## Data Model

Schemas live in the `openconnector` register and are reusable across consumer apps:

- `Factuur` — canonical invoice envelope. Fields: `id` (UUID), `richting` (enum: `uitgaand`, `inkomend`), `factuurnummer`, `factuurdatum`, `vervaldatum`, `valuta` (ISO 4217), `subtotaalExBtw`, `btwBedrag`, `totaalIncBtw`, `betaalReferentie` (Structured Creditor Reference per ISO 11649), `afzender` (object: `{naam, kvk, btwNummer, peppolId, adres}`), `ontvanger` (same shape), `regels` (array of `Factuurregel`), `btwRegels` (array of VAT breakdown lines), `bijlagen` (PDF representations, embedded as Base64 in UBL), `referentieOrderId`, `referentieContractId`, `originalUbl` (full UBL XML for round-trip), `originalPdf`, `peppolEnvelope` (object: `{senderId, receiverId, processId, documentType, transmissionId, transmittedAt}`).
- `Factuurregel` — `regelnummer`, `omschrijving`, `aantal`, `eenheid` (UN/ECE Rec 20), `prijsPerEenheid`, `btwTarief`, `btwCategorie` (UNCL5305), `productCode` (GTIN/eCl@ss), `regelTotaal`.
- `FactuurStatus` — status event log per `Factuur`: `status` (enum above), `tijdstip`, `kanaal` (`peppol-mlr`, `peppol-irr`, `manual`, `payment-webhook`), `details` (raw MLR/IRR XML or JSON payload), `correlatieId`.
- `PeppolDeelnemer` — addressee cache: `peppolId` (e.g. `0106:12345678` for Dutch KvK), `naam`, `documenten` (array of supported document types from SMP lookup), `laatstGecheckt`. TTL 24h to stay within SMP best-practice caching.

The `Factuur` schema is intentionally rich enough to be the canonical invoice across the Conduction fleet — shillinq stores its invoices here, the Peppol adapter consumes/produces from the same table.

## Requirements

### REQ-001: UBL 2.1 generation conforming to Peppol BIS Billing 3.0
The adapter MUST generate UBL 2.1 invoices that pass the official Peppol BIS Billing 3.0 validation artifacts (Schematron + XSD) without any warnings.

- GIVEN a `Factuur` object with all mandatory fields and a Dutch sender and Belgian receiver, WHEN serialised to UBL by the adapter, THEN running the generated XML through the OpenPeppol Schematron set v3.0.x produces zero errors and zero warnings.
- GIVEN a `Factuur` with VAT category `AE` (reverse charge intra-EU), WHEN serialised, THEN the UBL includes the required `cbc:TaxExemptionReasonCode` and `cbc:TaxExemptionReason` and validates per Schematron rule `BR-IG-10`.
- GIVEN a `Factuur` with a credit note (`InvoiceTypeCode = 381`), WHEN serialised, THEN the adapter produces a UBL CreditNote document type (not Invoice) and routes it via the BIS Billing 3.0 CreditNote process.

### REQ-002: Service Provider mode — Storecove
The adapter MUST integrate with Storecove's REST API as the reference SP implementation.

- GIVEN a `Source` of type `peppol-storecove` with an API key, WHEN sending a `Factuur`, THEN the adapter performs `POST https://api.storecove.com/api/v2/document_submissions` with the UBL Base64-encoded in `document.documentRaw`, the correct `routing.eIdentifiers` array, and the `legalEntityId` of the sender.
- GIVEN Storecove returns `200 OK` with `guid`, WHEN persisted, THEN the adapter records `peppolEnvelope.transmissionId = guid` on the `Factuur` and creates a `FactuurStatus(status=sent)`.
- GIVEN a Storecove webhook `INVOICE_RECEIVED` callback, WHEN received and HMAC-validated, THEN the adapter fetches the document, persists a new `Factuur(richting=inkomend)`, and emits an `openconnector.factuur.received` event.

### REQ-003: Service Provider mode — pluggable gateway
The adapter MUST expose an `IPeppolGateway` interface so additional SPs (Pagero, Basware, Tickstar, Anachron) can be added without modifying core code.

- GIVEN a new SP implementing `IPeppolGateway::send($ublXml, $envelope): GatewayResult` and `::handleWebhook($payload): GatewayInbound`, WHEN registered as a service in `services.xml`, THEN the SP appears as a selectable type in the Source-creation UI.
- GIVEN two Sources of different SP types in the same OpenConnector instance, WHEN sending, THEN the router picks the SP based on `Source.configuration.priority` and the sender's `peppolId` (some SPs only accredit certain countries).
- GIVEN a gateway returns `GatewayResult.transient_failure`, WHEN received, THEN the adapter requeues with exponential backoff (4 attempts: 30s, 5m, 30m, 4h) before marking the send as failed.

### REQ-004: Own Access Point mode — AS4 transport
The adapter MUST support direct AS4 transmission to receiver APs when configured with the necessary OpenPeppol AP certificate.

- GIVEN a `Source` of type `peppol-own-ap` with a PEPPOL AP certificate (Test or Production CA, issued by OpenPeppol) loaded from Nextcloud Vault, WHEN sending, THEN the adapter performs SML/SMP lookup on the receiver `peppolId`, constructs an AS4/ebMS3 user message, signs with XAdES, encrypts payload, and POSTs to the resolved endpoint URL.
- GIVEN the SMP lookup fails (DNS or HTTP), WHEN sending, THEN the adapter retries against the SML directly and, on continued failure, marks the `Factuur` as `FactuurStatus(status=rejected, details="receiver_unreachable")` and emits `openconnector.factuur.unaddressable`.
- GIVEN the receiver AP returns an AS4 receipt, WHEN received, THEN the adapter persists `FactuurStatus(status=delivered)` with `correlatieId = ebMS messageId`.

### REQ-005: SMP/SML caching and document-type discovery
The adapter MUST cache SMP lookup results for 24h per peppolId and surface the receiver's supported document types.

- GIVEN a `peppolId=0106:12345678` not in the `PeppolDeelnemer` cache, WHEN any consumer app calls `openconnector.peppol.discover(peppolId)`, THEN the adapter performs the SML→SMP lookup, persists the result, and returns the array of supported document type identifiers.
- GIVEN a cached entry older than 24h, WHEN consulted, THEN the adapter triggers an async refresh while serving the stale data (stale-while-revalidate).
- GIVEN a `Factuur` whose receiver does not support the BIS Billing 3.0 process per SMP, WHEN sending, THEN the adapter rejects the send with `PeppolError.PROCESS_NOT_SUPPORTED` BEFORE network call and returns the supported process list to the caller.

### REQ-006: Inbound invoice ingestion
The adapter MUST translate inbound Peppol UBL invoices (from SP webhooks or own-AP receipt) into canonical `Factuur(richting=inkomend)` objects.

- GIVEN an inbound UBL invoice, WHEN ingested, THEN every line is mapped to a `Factuurregel`, the original UBL is stored verbatim in `originalUbl`, and if a PDF representation is embedded, it is extracted to `originalPdf`.
- GIVEN an inbound invoice whose `cac:AccountingCustomerParty.cbc:EndpointID` does not match any configured `Source`, WHEN ingested, THEN the invoice is parked in the inbox with `status=unrouted` and an `openconnector.factuur.unrouted` event is emitted for manual triage.
- GIVEN an inbound credit note, WHEN ingested, THEN it is linked to the original invoice via `cac:BillingReference` and the consumer app (shillinq) is notified via `openconnector.factuur.credit-note`.

### REQ-007: Status round-trips via MLR/IRR
The adapter MUST send and receive Peppol Message Level Responses (MLR) and Invoice Responses (IRR) to communicate processing and business status.

- GIVEN an inbound `Factuur` that passes Schematron validation, WHEN the consumer app marks it `accepted`, THEN the adapter generates a valid MLR with `RE` (Acknowledgement) and ships it back through the same gateway.
- GIVEN an inbound `Factuur` that the consumer app disputes (`status=disputed`), WHEN dispatched, THEN the adapter generates an IRR per BIS Invoice Response 3.0 with `CON` (Conditionally accepted) plus dispute reason text and ships it.
- GIVEN an outbound `Factuur` for which an inbound IRR `RE` arrives, WHEN parsed, THEN a `FactuurStatus(status=accepted)` is persisted and shillinq's dunning workflow is paused.

### REQ-008: Validation hooks
The adapter MUST run every outbound UBL through the configured validator before sending and reject locally on any error.

- GIVEN a `Factuur` with an invalid VAT number (Modulus 11 check fails), WHEN the validation step runs, THEN the send is rejected with the precise failing rule (`BR-CO-09: Seller VAT identifier must have ISO 3166-1 prefix`) and the consumer app receives a structured error.
- GIVEN a `Factuur` whose total mismatch (`btwBedrag + subtotaalExBtw != totaalIncBtw`) by more than 1 cent, WHEN validated, THEN the send is rejected; a 1-cent rounding diff is allowed per BIS Schematron `BR-CO-16`.
- GIVEN the OpenPeppol validation artefacts have been updated (new release every quarter), WHEN the adapter is upgraded, THEN the new Schematron set is bundled and the prior validation hash is recorded in `CallLog` for audit.

### REQ-009: Status webhooks to consumer apps
Every state transition on a `Factuur` MUST emit a typed OpenConnector event so consumer apps can subscribe without polling.

- GIVEN shillinq subscribes to `openconnector.factuur.delivered`, WHEN a status transitions from `sent` to `delivered`, THEN shillinq receives the event with the full `Factuur.id` and the new `FactuurStatus.id`.
- GIVEN pipelinq subscribes to `openconnector.factuur.paid`, WHEN an inbound payment webhook from Mollie (other adapter) is linked to the `betaalReferentie` of a `Factuur`, THEN both shillinq AND pipelinq receive the event.

### REQ-010: Compliance and archival
The adapter MUST satisfy the 7-year invoice retention requirement (Algemene wet inzake rijksbelastingen art. 52) and provide an export-ready archive.

- GIVEN a `Factuur` older than 7 years, WHEN the retention job runs, THEN the record is moved to cold storage (Nextcloud Files archive folder) along with `originalUbl`, `originalPdf`, and the full `FactuurStatus` timeline, and the active table entry is marked `archived=true`.
- GIVEN a Belastingdienst audit request, WHEN an admin runs the `openconnector:peppol:export` occ command for a date range, THEN a ZIP is produced containing one folder per invoice with the original UBL, PDF, status log, and SP submission receipts.
- GIVEN a `Factuur` is archived, WHEN any consumer app queries it via the OpenRegister API, THEN it remains retrievable but is flagged `archived=true` so the consumer can show a read-only view.

## Standards & Sources

- Peppol BIS Billing 3.0 specification (`https://docs.peppol.eu/poacc/billing/3.0/`) — UBL 2.1 binding, Schematron, code lists.
- Peppol Authority for the Netherlands (NPa / Logius) — accreditation requirements, OpenPeppol PKI.
- OpenPeppol AS4 transport profile (Peppol-EDEC-AS4) and SMP/SML specifications.
- EN 16931 — European semantic invoice model (BIS Billing 3.0 is an EN 16931 CIUS).
- EU ViDA (VAT in the Digital Age) directive — incoming B2B mandate.
- ISO 11649 — Structured Creditor Reference.
- UNCL5305 — VAT category codes; UN/ECE Recommendation 20 — units of measure.
- Forum Standaardisatie `pas toe of leg uit` register entries: `UBL OHNL`, `Peppol BIS Billing 3.0`.
- NLCIUS — Dutch core invoice usage specification (a CIUS on top of EN 16931).

## Cross-app integration

- **shillinq** is the primary producer/consumer. Its accounts-receivable module calls `openconnector.send('peppol', $factuur)` for every outbound invoice; its accounts-payable inbox subscribes to `openconnector.factuur.received` to auto-create supplier invoices.
- **pipelinq** uses the same adapter for B2B POS invoices where the customer is a Peppol-addressable legal person.
- **procest** (overheidsfacturatie) consumes the inbound path for invoices to public bodies; the Berichtenbox adapter handles citizen invoices, Peppol handles B2B/B2G.
- **scholiq** uses the adapter for inkomende leveranciersfacturen (boekenleverancier, schoonmaakbedrijf).
- **mydash** surfaces a Peppol-throughput dashboard per Source, with delivery times, rejection rates, and AP/SP cost per message.
- **mollie-stripe-payment-adapter** (sibling) consumes `FactuurStatus(status=sent)` to attach a payment link; on Mollie's `paid` webhook it adds `FactuurStatus(status=paid)`.

## Target users

- **Commercial Conduction app users (MKB and corporate)** invoicing other businesses or the government, who currently glue an SP into their stack manually or use the SP's web portal.
- **Public-sector finance teams** that must accept Peppol invoices by law and pay suppliers via Peppol; today they buy a per-AP licence from a single vendor.
- **Conduction's own commercial team** — shillinq becomes an instant Peppol-native invoicing product, removing the largest objection from MKB-prospects.
- **Integrators and partners** running their own Peppol AP who want a thin canonical UBL layer above their AP.
