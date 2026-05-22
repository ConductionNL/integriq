# Design — Peppol E-Invoicing Adapter

## Context

EU and Dutch legislation is making Peppol e-invoicing mandatory for B2G (2017 for NL central government; expanding to all public bodies 2025–2026) and increasingly for B2B (ViDA directive 2025–2028 across Belgium, France, Germany, Poland, Italy). Conduction's commercial apps (shillinq, pipelinq, procest, scholiq) cannot comply with customer mandate unless they expose a first-class Peppol invoicing surface. Today, every app integrates ad-hoc against a commercial Peppol Service Provider or operates its own AP — unsustainable duplication.

This adapter codifies the Peppol integration contract once in openconnector, so:
- shillinq becomes Peppol-native for outbound invoicing (primary business value)
- pipelinq, procest, scholiq get Peppol inbound at no marginal engineering cost
- mydash can surface Peppol SLA dashboards per Source
- Future Peppol Service Providers (Pagero, Basware, Tickstar, Anachron) plug in without core rewrites

The change is phased:
- **Phase 1 (this change)**: Service Provider mode (Storecove reference implementation) + inbound ingestion + status round-trips
- **Phase 2 (follow-up)**: Own Access Point mode (AS4/ebMS3, SMP/SML lookup), additional SPs

## Goals

- **Mandatory-compliance-ready** — Peppol BIS Billing 3.0 conformance per OpenPeppol Schematron without manual tweaking; EU ViDA + NL tax-law-ready (7-year retention).
- **Service-Provider-agnostic** — pluggable `IPeppolGateway` interface so Storecove today, Pagero/Basware/Tickstar tomorrow, own AP in phase 2.
- **Inbound-invoice-ready** — canonical `Factuur` object in openconnector so every consumer app (shillinq, pipelinq, procest, scholiq) sources inbound invoices from one place.
- **Status-round-trip-complete** — MLR/IRR handling (acknowledgement, dispute response) so outbound invoices have closure and dunning workflows can trust delivery confirmation.
- **Event-driven** — typed status webhooks (sent, delivered, accepted, rejected, paid, disputed) so consumer apps subscribe without polling.
- **Audit-ready** — 7-year archival, Belastingdienst-exportable, `CallLog` for every invocation.
- **Developer-friendly** — a Conduction developer onboarding to Peppol should read `design.md` + `spec.md` + the Storecove gateway implementation and know how to add a new SP or consume the adapter in a sibling app.

## Non-Goals

- **Own AP implementation in phase 1** — defer to phase 2 after Service Provider mode is proven.
- **Universal e-invoicing** — this adapter scopes Peppol only; other standards (Peppol-adjacent like Belgium's MyInvois, other e-invoicing formats) are out of scope.
- **Real-time transcoding** — the adapter generates UBL from `Factuur`; it does not transcode arbitrary existing invoice PDFs into Peppol UBL.
- **Revenue-grade Peppol AP** — openconnector does not become an AP itself; large deployments run their own AP or use a commercial partner.
- **Per-app Peppol configuration** — every consumer app uses openconnector's unified Source/credential model; no per-app override.

## Data Model

Schemas live in openconnector register, reusable across consumer apps:

| Entity | Fields | Purpose |
|---|---|---|
| **Factuur** | `id` (UUID), `richting` (enum: `uitgaand`, `inkomend`), `factuurnummer`, `factuurdatum`, `vervaldatum`, `valuta` (ISO 4217), `subtotaalExBtw`, `btwBedrag`, `totaalIncBtw`, `betaalReferentie` (ISO 11649), `afzender` (object: `{naam, kvk, btwNummer, peppolId, adres}`), `ontvanger` (same shape), `regels` (array of `Factuurregel`), `btwRegels` (VAT breakdown), `bijlagen` (Base64 PDF), `referentieOrderId`, `referentieContractId`, `originalUbl`, `originalPdf`, `peppolEnvelope` (object: `{senderId, receiverId, processId, documentType, transmissionId, transmittedAt}`) | Canonical invoice envelope; primary store for both outbound + inbound; shillinq's invoice-of-record. |
| **Factuurregel** | `regelnummer`, `omschrijving`, `aantal`, `eenheid` (UN/ECE Rec 20), `prijsPerEenheid`, `btwTarief`, `btwCategorie` (UNCL5305), `productCode` (GTIN/eCl@ss), `regelTotaal` | Invoice line item; array on `Factuur`. |
| **FactuurStatus** | `status` (enum: `sent`, `delivered`, `accepted`, `rejected`, `paid`, `disputed`), `tijdstip`, `kanaal` (enum: `peppol-mlr`, `peppol-irr`, `manual`, `payment-webhook`), `details` (raw MLR/IRR XML or JSON), `correlatieId` | Status event log per `Factuur`; immutable append-only per ADR-003 (CallLog pattern). |
| **PeppolDeelnemer** | `peppolId` (e.g. `0106:12345678`), `naam`, `documenten` (array of supported doc-type identifiers from SMP), `laatstGecheckt` | Addressee cache; TTL 24h per SMP best-practice. |

## Decisions

### D1 — Canonical `Factuur` object: both production and round-trip store

shillinq authors outbound invoices as `Factuur` objects; the Peppol adapter consumes them 1:1, serializes to UBL, sends, and records the transmission id + status in `peppolEnvelope` and `FactuurStatus` on the same `Factuur` record. Inbound invoices are also parsed into `Factuur` with `richting=inkomend`, so the consumer app (shillinq AR, pipelinq) has a single invoice table to query. This prevents Peppol-specific columns from leaking into shillinq's schema.

**Alternative considered**: A separate `PeppolInvoice` table that wraps `Factuur`. Rejected — creates a synchronisation surface and requires shillinq to query both tables.

### D2 — Service Provider mode first; own-AP mode in phase 2

The vast majority of users (MKB, small public bodies) will pick a hosted SP (Storecove, Pagero) rather than operate their own AP. Phase 1 ships SP mode only. Phase 2 evaluates AS4/ebMS3 complexity and delivers own-AP for large deployments that already have AP infrastructure.

**Alternative considered**: Deliver both modes in phase 1. Rejected — AS4 is complex; SP mode is the MVP and delivers 80% of user value immediately.

### D3 — Pluggable `IPeppolGateway` interface for Service Provider abstraction

Every SP (Storecove, Pagero, Basware, Tickstar) has a different REST API shape. The adapter exposes one `IPeppolGateway` interface (`send()`, `handleWebhook()`, `discoverCapabilities()`) and routes to the appropriate SP implementation based on `Source.configuration.gateway_type`. Per-adapter implementations (Pagero, Basware) land in separate follow-up changes, each with its own `PageroGateway implements IPeppolGateway` class.

**Alternative considered**: A switch statement in `UblSender.php` that hard-codes each SP's REST API. Rejected per ADR-031 (imperative → declarative); each SP becomes a separate DI-tagged service.

### D4 — Webhook ingestion, not polling for inbound invoices

Every Service Provider (Storecove, Pagero) offers a webhook `INVOICE_RECEIVED` callback. The adapter registers a webhook endpoint and parses inbound invoices on callback, not via periodic polling. This reduces latency and SP API load.

**Alternative considered**: Periodic polling of the SP's inbox. Rejected — vendor-specific; webhook is standard across SPs.

### D5 — SMP/SML lookup with 24h TTL and stale-while-revalidate

Before sending to an unknown receiver, the adapter looks up `peppolId` in the openconnector `PeppolDeelnemer` cache. If missing or >24h old, it performs SML→SMP lookup, caches the result, and returns immediately. If the result is stale but valid, it triggers an async refresh in the background (stale-while-revalidate).

**Alternative considered**: Synchronous SML/SMP lookup every send (no cache). Rejected — adds 200-500ms latency per invoice; SMP results are stable per OpenPeppol best-practice (document-type list changes rarely).

### D6 — Pre-send Schematron validation; fail locally before network call

Every outbound UBL is validated against the bundled Peppol BIS Billing 3.0 Schematron BEFORE the network call to the SP. If validation fails, the adapter returns a structured error to the caller (e.g. shillinq) without attempting the send. This prevents rejected invoices from clogging the SP queue and gives immediate feedback to the user.

**Alternative considered**: Validate on the SP side and retry on validation failure. Rejected — longer feedback loop, sender doesn't know why the invoice was rejected until they query CallLog.

### D7 — MLR (Message Level Response) and IRR (Invoice Response) for status round-trips

Peppol defines two status mechanisms:
- **MLR** — Machine-level acknowledgement ("I received your invoice and it parses").
- **IRR** — Business-level acknowledgement/dispute ("I accepted it", "I dispute it for reason X").

The adapter generates MLR on inbound validation success and IRR when the consumer app marks an inbound invoice as accepted/disputed. This gives senders closure and dunning workflows (e.g. shillinq's payment follow-up) can trust delivery.

**Alternative considered**: Status-only events without round-trip. Rejected — Peppol spec mandates MLR/IRR for production compliance; sender has no other way to know if the invoice was received.

### D8 — 7-year archival per Dutch tax law (Algemene wet inzake rijksbelastingen art. 52)

Every `Factuur` older than 7 years is moved to cold storage (Nextcloud Files archive folder) with full UBL + PDF + status timeline + receipts. The active table entry is marked `archived=true`. Consumer apps can still query it via OpenRegister API but get a read-only view.

**Alternative considered**: Delete old invoices after 7 years. Rejected — Dutch tax law requires retention; Belastingdienst audits can go back 7 years and demand invoice proof.

### D9 — Event-driven status updates via OpenConnector event-bus, not polling

Every status transition on a `Factuur` emits a typed CloudEvent (e.g. `openconnector.factuur.delivered`, `openconnector.factuur.paid`). Consumer apps subscribe to the events they care about, not polling `FactuurStatus` tables.

**Alternative considered**: Consumer apps poll `FactuurStatus` for state changes. Rejected per ADR-013 — the event-bus is the subscription primitive in openconnector.

### D10 — `CallLog` as the primary observability surface per ADR-003

Every outbound HTTP call to a Peppol Service Provider, SMP/SML, AS4 endpoint, or webhook receipt is logged to the existing `CallLog` table with request, response, duration, status. This audit trail is immutable and queryable by consumer apps for debugging + compliance.

## Reuse Analysis

| Need | What already exists | Peppol adapter reuse strategy |
|---|---|---|
| Adapter registration | openconnector doesn't yet have a pluggable adapter registry | Peppol service classes are registered via DI tags; future `add-peppol-{sp}` changes add new SP tags |
| Source/credential storage | openconnector `Source` registry (mature) | `Source.configuration` JSON stores SP API keys, cert paths, webhook URLs per SR |
| Webhook receiver endpoints | openconnector has existing webhook layer | Consumed for SP inbound + AS4 receipt handling |
| HTTP call audit | openconnector existing `CallLog` table | Consumed per ADR-003; every SP/SMP/SML/AS4 call produces a `CallLog` row |
| Event subscription | openconnector event-bus per ADR-013 | Consumed for status transitions (`sent`, `delivered`, `accepted`, `rejected`, `paid`, `disputed`) |
| Scheduled archival job | openconnector existing `JobService` + `TimedJob` | Consumed for 7-year retention sweep |
| Validation framework | openconnector doesn't yet have a pre-send validation hook | New: `ValidatorInterface` registered per schema, consulted before send |
| UBL serialization | no existing; Peppol standard is UBL 2.1 | New: `UblGenerator` class using league/xml or similar |
| Schematron validation | no existing; Peppol BIS Billing 3.0 defines it | New: `SchematronValidator` wrapping bundled Schematron XSD |

**Net new code**:
- `UblGenerator.php` — UBL 2.1 serialization from `Factuur`
- `UblValidator.php` — Schematron + custom validation (VAT, amounts, etc.)
- `GatewayRouter.php` — route to SP or own-AP based on `Source.configuration`
- `Gateways/PeppolGateway.php` (interface) + `Gateways/StorecoveGateway.php` (reference impl)
- `SmpSmlService.php` — SML/SMP lookup, caching, discovery
- `InboundIngestionService.php` — UBL→`Factuur` parsing, PDF extraction, triage
- `StatusRoundTripService.php` — MLR/IRR generation + receipt handling
- `ArchivalService.php` — 7-year cold-storage migration, audit export
- Database migrations for `Factuur`, `Factuurregel`, `FactuurStatus`, `PeppolDeelnemer`

All other surfaces (event-bus, CallLog, webhook receiver, Source, archival job) are existing abstractions consumed at the boundary.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| SP credential storage | Declarative (on `Source.configuration` JSON) | Single authoritative location; no duplicates |
| SP routing | Declarative (on `Source.configuration.gateway_type`) | Operator chooses SP at Source creation time; no code change |
| Inbound webhook URL | Declarative (on `Source.configuration.webhook_url`) | Operator registers webhook in SP UI once; openconnector listens at the configured URL |
| Scheduled archival | Imperatively (openconnector `TimedJob` or OR `ScheduledWorkflow`) | Retention is a time-based trigger; scheduled-job abstraction is appropriate |
| Validation rules | Mix: Schematron is declarative (XSD), custom rules are imperative (PHP guards) | Peppol BIS defines Schematron; openconnector adds custom guards (amount reconciliation, Dutch VAT rules) |
| Status transitions | Declarative (event definitions on schema) | Each status change emits a typed event; event is the declaration |
| Archival policy | Declarative (7-year constant) | Tax law is fixed; no per-source override needed |

No new service classes beyond the Peppol-specific ones (UblGenerator, GatewayRouter, etc.). Validation, archival, event emission all use existing openconnector abstractions.

## Seed Data

Phase 1 ships NO seed Source records (no default Storecove or Anachron configs). Operators create Sources via the admin UI for each SP account they have. Per-adapter follow-ups (Storecove-native, Pagero-native) MAY ship example seed sources in `lifecycleState: paused` so operators see what a fully configured source looks like.

Example Storecove seed (shipped with future `add-peppol-storecove-adapter` change):

```json
{
  "_meta": {
    "adapter": "peppol-storecove",
    "imported": "2026-05-22T00:00:00Z"
  },
  "slug": "storecove-prod",
  "name": "Storecove Production",
  "type": "peppol-storecove",
  "configuration": {
    "gateway_type": "storecove",
    "api_key": "sk_test_...",
    "legalEntityId": "12345",
    "webhook_url": "https://myapp.example.com/connector/webhook/peppol/storecove"
  },
  "lifecycleState": "paused",
  "notes": "Configure api_key and webhook_url before activating."
}
```

## Migration Plan

Phase 1 (this change):
1. Add database migrations for `Factuur`, `Factuurregel`, `FactuurStatus`, `PeppolDeelnemer` tables.
2. Add Peppol service classes (UblGenerator, UblValidator, GatewayRouter, SmpSmlService, InboundIngestionService, StatusRoundTripService, ArchivalService).
3. Add `PeppolGateway` interface and `StorecoveGateway` reference implementation.
4. Register webhook endpoint for SP inbound receipts.
5. Add validation hook to pre-send pipeline.
6. Add event emissions for status transitions.
7. Add cron job for 7-year archival sweep.

Phase 2 (separate change, follow-up):
1. Add `OwnApGateway implements PeppolGateway` for AS4/ebMS3 direct sending.
2. Add `SmlSmlService` upgrades for own-AP SMP/SML lookup (phase 1 only caches, doesn't lookup).
3. Add per-SP gateways (`PageroGateway`, `BaswareGateway`, `TickstarGateway`, `AnachronGateway`) in separate follow-up changes.

Down-direction rollback:
1. Revert the openconnector PR.
2. Run rollback DB migration (drop `Factuur`, `Factuurregel`, `FactuurStatus`, `PeppolDeelnemer`).
3. Remove webhook handler, event subscriptions, validation hook, archival job.
4. Consumer apps roll back their Peppol integration changes (if any) independently.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Service Provider API surface inconsistency | Pluggable gateway per-adapter implementation; core logic unchanged |
| AS4/ebMS3 complexity for own-AP | Phase own-AP to phase 2; SP mode is MVP |
| Schematron breaking changes quarterly | Bundle at release; record validation hash in CallLog; version openconnector when needed |
| Unparseable inbound invoices | Park in inbox with `status=unrouted`; operator manual triage |
| High-volume archival contention | Asynchronous batch job; deploy on Nextcloud Files S3/Azure backend for scale |
| Multi-consumer-app release scheduling | Adapter is independent; consumer apps deploy Peppol integrations in parallel |
| Webhook ingestion failure (SP down) | Retry per-SP pattern (exponential backoff); async requeue |
| SMP/SML lookup latency (DNS + HTTP) | 24h cache + background refresh minimises lookups |
| Belastingdienst audit export complexity | Archive export is deterministic ZIP structure; test format against sample audits |

## Open Questions

1. **AS4/ebMS3 implementation approach for phase 2** — build in-house, use a library (ebMS3.org), or recommend a partner integration? Defer to phase 2 discovery.
2. **SML/SML DNS caching layer** — should openconnector cache DNS results, or rely on OS cache? Defer to performance triage if latency surfaces.
3. **Mollie payment adapter synchronisation** — when does mollie-stripe-payment-adapter emit its `FactuurStatus(status=paid)` event? Coordinate roadmap with Mollie adapter owner.
4. **High-volume archival performance tuning** — if a deployment archives 50k+ invoices/year, benchmark archival job on Nextcloud Files S3 backend. Defer to customer-specific performance engineering if needed.
5. **Multi-currency invoicing** — should a single `Factuur` support line items in different currencies? Defer if a consumer app surfaces the need.
