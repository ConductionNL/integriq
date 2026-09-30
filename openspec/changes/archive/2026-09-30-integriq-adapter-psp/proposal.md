---
kind: config
---

# Proposal: integriq-adapter-psp

## Summary
Seeds a discoverable, mock-mode-by-default iDEAL payment-source template ("iDEAL ouderbijdrage") for integriq's existing, complete `live-payment-providers` capability, and registers it in the existing Catalog so an operator can instantiate a working iDEAL payment flow without hand-authoring the Source configuration JSON. This closes change-plan.md row `integriq-adapter-psp` (`13.13` / `payment-request-ux`, priority NICE) against learniq's `PaymentInitiationClient` stub, per D3's abstract-integration pattern (decisions.md): learniq declares the contract, integriq owns the adapter.

## Motivation
ParnasSys's own pricing page documents iDEAL/Wero betaalverzoeken via Parro for school fees ("Schoolkassa") as a live, everyday PO capability (parnassys/round1/sources.md#13.13; M3-integrations.md row I15). learniq's own `PaymentInitiationClient` is still a stub (m1 baseline). Investigation this round found that integriq already ships everything the adapter half of this row needs — `PaymentProviderInterface` (provider-neutral contract), `LogPaymentProvider` (deterministic mock, defaults `payload.method` to `ideal`), `MolliePaymentProvider` (live iDEAL via Mollie, credential-broker only, REQ-LPP-002), and `PaymentsController::create()`/`webhook()` (signature-gated, re-derives status, never trusts the webhook body, REQ-LPP-003) — from the already-shipped `live-payment-providers` capability. What is missing is not adapter code: it is a seeded, discoverable payment-source *template* for the iDEAL/ouderbijdrage use case. `payment` is a documented `type` in the Source schema's vocabulary (`CatalogRegistryService::TYPE_CATEGORY_LABELS`) but no `register.d/*-source.json` seed fragment exists for it, unlike BRP, KvK, xWiki and the messaging channels, which all ship one.

## Affected Projects
- [x] Project: `integriq` — one new seed fragment (`register.d/ideal-ouderbijdrage-source.json`) plus a contract test proving the existing `CatalogRegistryService::collectFromSeedFragments()` picks it up. No changes to `PaymentProviderInterface`, `LogPaymentProvider`, `MolliePaymentProvider`, or `PaymentsController` — they are already correct and are not duplicated here.

## Scope

### In Scope
- `lib/Settings/register.d/ideal-ouderbijdrage-source.json`: a `source`-schema seed object, `type: payment`, `configuration.provider: log` (mock by default — no live credentials shipped or required), `configuration.method: ideal`, discoverable via the existing Catalog mechanism (kind `source-template`, category resolved from `TYPE_CATEGORY_LABELS['payment']`).
- A unit test asserting `CatalogRegistryService::collect()` (the existing, already-tested registry assembly method) returns an entry for this new template — proving the fragment is genuinely wired into a real call site, not a decorative JSON file (per the "guard with no call site" failure mode).
- A unit test exercising the seeded configuration end-to-end against `LogPaymentProvider` directly (create a payment with the seeded `configuration`, assert an `ideal`-flavoured mock checkout envelope comes back), so the template is proven to actually work with the existing payment stack, not merely parse.
- Documentation (this proposal + design.md) making explicit which parts of "an iDEAL PSP adapter... with a mock provider and webhook handling" already exist and are NOT rebuilt here.

### Out of Scope
- Any change to `PaymentProviderInterface`, `LogPaymentProvider`, `MolliePaymentProvider`, `PaymentsController`, or `PaymentIntentService` — all already implement the adapter, mock and webhook halves of this row (REQ-LPP-002, REQ-LPP-003, `openspec/specs/live-payment-providers/spec.md`) and are out of scope for a second implementation.
- A live Mollie (or other PSP) credential — the seeded template ships `provider: log` (mock); flipping it to `provider: mollie` plus a `credentialRef` is an operator action outside this change, exactly as the existing KvK/BRP templates work.
- Learniq's own `PaymentInitiationClient` stub implementation — that is learniq's side of the contract, a different app/lane.

## Approach
Extend, don't duplicate (ADR-011). The existing `CatalogRegistryService::collectFromSeedFragments()` already globs every `lib/Settings/register.d/*.json` fragment whose `@self.schema` is `source` and turns it into a `source-template` Catalog entry, exactly the mechanism the BRP HaalCentraal and KvK templates use (see `kvk-source.json`). Adding one more fragment for `type: payment` uses that existing, already-tested path with zero new PHP registry code.

## New Dependencies
None.

## Impact
- One new JSON file (`lib/Settings/register.d/ideal-ouderbijdrage-source.json`).
- Two new test files exercising the existing `CatalogRegistryService` and `LogPaymentProvider` against the new fixture — no production code changes.

## Cross-Project Dependencies
Depends on learniq's `PaymentInitiationClient` contract being implemented to call integriq's existing `POST /api/payments`/`POST /api/payments/webhook` endpoints (already app-agnostic — see `PaymentIntentService::createPayment()`'s `sourceSlug` selection) — that implementation is learniq's side, a different lane, not this change.

## Risks

### Risk 1: A seed fragment with no consuming test is decorative
**Severity:** Medium — **Mitigation:** this change's two tests (Scope, In Scope) assert the fragment is actually read by `CatalogRegistryService::collect()` and that its configuration actually produces a working mock payment via `LogPaymentProvider` — both are real call sites in already-shipped code, not new scaffolding built to pass its own test.

## Rollback Strategy
Delete the one seed fragment. No OpenRegister schema change, no data migration — the `catalog_item` materialization (`lib/Repair/MaterializeCatalogItems.php`) is idempotent per its own spec.

## Open Questions
None.
