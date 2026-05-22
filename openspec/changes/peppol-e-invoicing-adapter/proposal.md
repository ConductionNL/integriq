# Proposal: peppol-e-invoicing-adapter

A production-grade OpenConnector adapter for sending and receiving electronic invoices over the Peppol network conforming to Peppol BIS Billing 3.0 and the EU ViDA directive, so Conduction apps (shillinq, pipelinq, procest, scholiq) and their customers can comply with mandatory B2G and B2B e-invoicing mandates that take full effect in 2025–2026.

## Summary

**Peppol** (Pan-European Public Procurement Online) is the de-facto European standard for B2G and increasingly B2B e-invoicing. The Netherlands mandated Peppol for central government invoicing in 2017; the EU ViDA package and Belgium, France, Germany, Poland, Italy extend the mandate to all public bodies and B2B invoicing in the 2025–2028 window. Without a first-class Peppol adapter, every Conduction commercial app that bills customers has to integrate ad-hoc against commercial Peppol Service Providers (Storecove, Pagero, Basware, Tradeshift, Tickstar) in code, per app. That is not sustainable.

This adapter solves it once via two selectable operating modes:

1. **Service Provider mode** — externally hosted Peppol Access Point (Storecove, Pagero, Basware, Tickstar, Anachron) with credential abstraction behind one `IPeppolGateway` interface.
2. **Own Access Point mode** — self-hosted or partner-hosted AP with OpenPeppol accreditation; implements AS4 transport (ebMS3/AS4 + XAdES), SMP/SML lookup, and direct endpoint addressing for large deployments.

In both modes the adapter:
- Generates and validates UBL 2.1 invoices conforming to Peppol BIS Billing 3.0
- Handles status round-trips (`sent`, `delivered`, `accepted`, `rejected`, `paid`, `disputed`) via MLR/IRR
- Surfaces inbound invoices as canonical `Factuur` objects in OpenRegister
- Satisfies 7-year retention requirements (Dutch tax law)
- Emits typed OpenConnector events for consumer-app subscription

## Affected Projects

- [x] **openconnector** — primary implementation: UBL generation, Peppol gateway abstraction, SMP/SML caching, inbound ingestion, status webhooks, validation hooks, archival.
- [ ] **openregister** — no source changes. Consumes existing event-bus and audit-trail abstractions.
- [ ] **shillinq** — consumes via `openconnector.send('peppol', $factuur)` for outbound invoices; subscribes to `openconnector.factuur.received` for inbound supplier invoices.
- [ ] **pipelinq** — consumes for B2B POS invoices where customer is Peppol-addressable.
- [ ] **procest** — consumes for invoices to public bodies (B2B/B2G path); citizen invoices via Berichtenbox adapter.
- [ ] **scholiq** — consumes for inbound supplier invoices.
- [ ] **mydash** — consumes for Peppol throughput dashboard per Source (delivery times, rejection rates, AP/SP cost per message).
- [ ] **mollie-stripe-payment-adapter** — sibling adapter; consumes `FactuurStatus(status=sent)` to attach payment link; on Mollie `paid` webhook adds `FactuurStatus(status=paid)`.

## Scope

### In Scope

- **UBL 2.1 generation & validation** conforming to Peppol BIS Billing 3.0 Schematron + XSD artifacts without errors/warnings.
- **Service Provider mode integration** with Storecove as reference implementation; pluggable `IPeppolGateway` interface for future SPs (Pagero, Basware, Tickstar, Anachron).
- **Own Access Point mode** — AS4/ebMS3 transport, XAdES signing, SMP/SML lookup, direct endpoint addressing.
- **Inbound invoice ingestion** — UBL→`Factuur` mapping, PDF extraction, unrouted-invoice triage.
- **Status round-trips** — MLR/IRR generation and receipt handling for processing and business status.
- **SMP/SML caching** — 24h TTL per peppolId, document-type discovery, receiver-capabilities validation.
- **Validation hooks** — Schematron + custom rules (VAT numbers, amount reconciliation) before network send.
- **Status webhooks** — typed OpenConnector events (`openconnector.factuur.*`) for consumer-app subscription.
- **Compliance & archival** — 7-year retention per Dutch tax law; zip export for audit requests; read-only archive flag.
- **Data model** — `Factuur`, `Factuurregel`, `FactuurStatus`, `PeppolDeelnemer` schemas in openconnector register.

### Out of Scope

- **Individual Peppol Service Provider implementations** (Pagero, Basware, Tickstar, Anachron) beyond the pluggable gateway pattern — each lands in a follow-up `add-peppol-{sp}-gateway` change.
- **Consumer app integrations** (shillinq, pipelinq, procest, scholiq) — those apps' calls to `openconnector.send('peppol', ...)` land in their own changes.
- **Payment integration** (Mollie/Stripe webhook linking) — that lands in the `mollie-stripe-payment-adapter` change.
- **User-facing Peppol admin UI** — operator configuration (Source creation, credential entry, webhook routing) reuses openconnector's existing admin surfaces per ADR-019.
- **Performance optimisation** (caching, batch queuing, async scheduling) — addressed in follow-up if a consuming app surfaces the need.

## Approach

Five requirements packages, each adding REQ-* entries:

1. **REQ-001: UBL 2.1 generation** — Schematron compliance, VAT categories, credit-note routing.
2. **REQ-002: Service Provider mode (Storecove)** — REST API abstraction, webhook receipt, inbound invoice parsing.
3. **REQ-003: Pluggable gateway** — `IPeppolGateway` interface, multi-SP routing, transient-failure backoff.
4. **REQ-004: Own Access Point mode** — AS4 transport, SML/SMP lookup, XAdES signing.
5. **REQ-005: SMP/SML caching & discovery** — 24h TTL, stale-while-revalidate, process-support validation.
6. **REQ-006: Inbound invoice ingestion** — UBL→`Factuur` mapping, PDF extraction, unrouted triage.
7. **REQ-007: Status round-trips (MLR/IRR)** — acknowledgement, dispute response, status event logging.
8. **REQ-008: Validation hooks** — Schematron, custom rules, pre-send rejection.
9. **REQ-009: Status webhooks** — typed OpenConnector events for consumer subscription.
10. **REQ-010: Compliance & archival** — 7-year retention, Belastingdienst export, archive flag.

All specs follow the conduction-schema format (RFC 2119, `### REQ-NNN:`, `#### Scenario:` with GIVEN/WHEN/THEN).

## New Dependencies

- **Peppol BIS Billing 3.0 validator artefacts** (Schematron XSD, code lists) — bundled, updated quarterly by OpenPeppol; no external call.
- **AS4/ebMS3 library** (e.g. `messaging-gateway` or direct implementation) — for own-AP mode transport.
- **phpseclib or OpenSSL** — for XAdES signing in own-AP mode.
- **SML/SMP DNS + HTTP client** — OpenPeppol infrastructure lookup; uses existing Nextcloud HTTP client.
- **PEPPOL AP certificate** — test or production CA, loaded from Nextcloud Vault (ADR-007 pending encryption layer).

## Impact

- New folder: `openspec/changes/peppol-e-invoicing-adapter/` containing proposal.md, design.md, tasks.md, and specs/peppol-e-invoicing/spec.md.
- New runtime code in openconnector:
  - `lib/Service/Peppol/UblGenerator.php` — UBL 2.1 serialization
  - `lib/Service/Peppol/UblValidator.php` — Schematron + custom validation
  - `lib/Service/Peppol/GatewayRouter.php` — Service Provider vs own-AP routing
  - `lib/Service/Peppol/Gateways/PeppolGateway.php` (interface) + implementations
  - `lib/Service/Peppol/SmpSmlService.php` — lookup & caching
  - `lib/Service/Peppol/InboundIngestionService.php` — UBL parsing, `Factuur` creation
  - `lib/Service/Peppol/StatusRoundTripService.php` — MLR/IRR handling
  - `lib/Service/Peppol/ArchivalService.php` — 7-year retention, zip export
  - Database migrations for new schemas: `Factuur`, `Factuurregel`, `FactuurStatus`, `PeppolDeelnemer`
  - Webhook handler for Service Provider inbound + AS4 receipt parsing
  - Validator registration per ADR-008 (pre-send hook)
  - Event emission per ADR-013 (status transitions)

## Cross-Project Dependencies

- **openregister** — depends on event-bus (`EventMessage`, `EventSubscription`) and audit-trail abstractions (ADR-013, ADR-022) being available for status events and compliance logging.
- **shillinq** — primary consumer; depends on openconnector event-bus stability for `openconnector.factuur.received`, `openconnector.factuur.delivered`, `openconnector.factuur.paid`.
- **mollie-stripe-payment-adapter** — depends on `FactuurStatus` schema being queryable to attach payment links and respond to payment webhooks.
- **Peppol Authority (Logius)** — openconnector does not contact Logius directly; assumes the invoicing organisations have their own AP accreditation or SP contracts.

## Risks

### Risk 1: Service Provider API surface inconsistency across vendors

**Severity**: Medium  
**Mitigation**: The `IPeppolGateway` interface is shaped around Storecove's (the most-documented SP REST API). Per-adapter follow-ups (Pagero, Basware, Tickstar) surface the adapter's own quirks in implementation code, not in openconnector's core logic.

### Risk 2: AS4/ebMS3 implementation complexity for own-AP mode

**Severity**: High  
**Mitigation**: Phase 1 ships Service Provider mode first (no own-AP). Phase 2 evaluates whether to build own-AP in-house or recommend a mature library (ebMS3.org, AS4-gateway partners). Large deployments (central government) likely have existing AP infrastructure, so own-AP mode is nice-to-have, not blocking.

### Risk 3: Schematron validation artefacts have quarterly releases and may introduce breaking changes

**Severity**: Low  
**Mitigation**: Bundle the Schematron set at release time; record the validation hash in `CallLog` for audit. If OpenPeppol ships a breaking release, a new openconnector version is required; that's acceptable for a standards-compliance-critical component.

### Risk 4: Inbound invoices may not parse if the remote sender uses a non-standard UBL variant

**Severity**: Low  
**Mitigation**: REQ-006 parks unparseable invoices in the inbox with `status=unrouted`; operator can manually triage or the consumer app can retrieve them for manual review via OpenRegister API.

### Risk 5: 7-year archival cold-storage strategy (moving to Nextcloud Files archive folder) may not scale for high-volume deployments

**Severity**: Medium  
**Mitigation**: Archival is asynchronous (batch job); high-volume deployments configure their own Nextcloud Files storage backend (S3, Azure Blob) to handle scale. The schema and retention logic are independent of storage transport.

### Risk 6: Multiple cross-app dependencies (shillinq, pipelinq, procest, scholiq) may create scheduling bottlenecks for coordinated release

**Severity**: Medium  
**Mitigation**: This adapter ships independent of consumer apps. Each consumer app adds a feature branch with its own Peppol integration changes, which land in parallel. The adapter release is a prerequisite, not a bottleneck.

## Rollback Strategy

Non-destructive. To roll back:

1. Revert the openconnector PR.
2. Run the rollback DB migration (drops `Factuur`, `Factuurregel`, `FactuurStatus`, `PeppolDeelnemer` tables).
3. Remove the webhook handler and event subscriptions.

Consumer apps that have already deployed Peppol integrations continue to work if they fall back to their prior invoicing path (e.g. shillinq manually attaching to a SP or reverting to non-Peppol invoicing). Each consumer app's rollback is independent.

## Open Questions

1. **AS4/ebMS3 implementation for own-AP mode** — evaluate in phase 2 discovery whether to build in-house or recommend a partner library. Defer to a separate change once Service Provider mode is stable.
2. **SMP/SML lookup DNS caching layer** — should openconnector cache DNS results for SML lookups, or rely on OS-level DNS cache? Defer to performance triage if latency becomes an issue.
3. **Mollie payment integration timing** — when does mollie-stripe-payment-adapter add its `FactuurStatus(status=paid)` event consumer? Coordinate with mollie-stripe-payment-adapter roadmap.
4. **High-volume archival performance** — if a deployment archives 50k+ invoices per year, does the batch job create contention on the Nextcloud Files backend? Defer performance tuning to a follow-up if a customer surfaces the issue.
5. **Multi-currency invoicing** — today the adapter assumes invoices are generated in a single currency per `Factuur` (field: `valuta`). Intra-EU B2B may require multi-currency line items (e.g. invoice in EUR but one line in GBP pre-Brexit). Defer to a separate change if a consuming app surfaces the need.
