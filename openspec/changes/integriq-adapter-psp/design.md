# Design: integriq-adapter-psp

## Architecture Overview
No new architecture. This change adds one seed fragment to a mechanism that already exists and is already tested end-to-end:

```
lib/Settings/register.d/ideal-ouderbijdrage-source.json  (new)
  -> CatalogRegistryService::collectFromSeedFragments()   (existing, unchanged)
       -> catalog_item "source-template:ideal-ouderbijdrage" (existing upsert path,
          lib/Repair/MaterializeCatalogItems.php, unchanged)

An operator instantiates a real `source` object from the template (existing
Catalog "Instantiate" action, connector-catalog spec REQ-002, unchanged)
  -> PaymentIntentService::createPayment(payload: ['sourceSlug' => '<instantiated-slug>', ...])
       -> resolveProvider(): configuration.provider === 'log' (seeded default)
            -> LogPaymentProvider::createPayment()   (existing, unchanged)
                 -> deterministic MOCK-PAY-<n> + checkoutUrl, method defaults to 'ideal'
```

**What already exists and is NOT touched by this change**: `PaymentProviderInterface`, `LogPaymentProvider`, `MolliePaymentProvider`, `PaymentIntentService`, `PaymentsController` (`create()` + signature-gated `webhook()`). Together they are the complete "iDEAL PSP adapter behind a payment-initiation contract, with a mock provider and webhook handling" this row asks for — see `openspec/specs/live-payment-providers/spec.md` REQ-LPP-002/003. This change's only job is making that capability *discoverable and instantiable* for the specific iDEAL/ouderbijdrage shape, the one thing genuinely missing (no seed fragment existed for `type: payment`).

## API Design
No new endpoint. The existing `POST /api/payments` (create) and `POST /api/payments/webhook` (signature-gated receive) are unchanged and already app-agnostic (selected by `payload.sourceSlug`).

## Database Changes
None — no OpenRegister schema change. The seed fragment creates one `source` object on install/repair, exactly like the existing `kvk-source.json`/`brp-haalcentraal-source.json` fragments.

## Nextcloud Integration
- Controllers: none new.
- Services: none new (consumes `CatalogRegistryService`, `LogPaymentProvider`, `PaymentIntentService` — all existing, all unchanged).
- Mappers/Entities: none new.
- Events/Hooks: none.

## Security Considerations
The seeded template ships `configuration.provider: log` (mock) — no live PSP credential is shipped or required. Flipping to `provider: mollie` requires an operator to set `configuration.authentication.credentialRef` to a real credential the OpenRegister credential broker holds (per `MolliePaymentProvider`'s existing fail-closed behaviour) — an explicit operator action, not something this change does or could do silently. No new authentication surface.

## File Structure
```
lib/
  Settings/
    register.d/
      ideal-ouderbijdrage-source.json   (new)
tests/
  Unit/
    Service/
      CatalogRegistryServiceTest.php     (existing file — one assertion added)
    Settings/
      IdealOuderbijdrageSourceTemplateTest.php   (new — proves the seeded
        configuration actually produces a working LogPaymentProvider mock
        payment, not just a catalog listing)
```

## Trade-offs
A seed fragment plus two tests, versus writing a new dedicated PSP adapter class: rejected the latter because `PaymentProviderInterface`/`LogPaymentProvider`/`MolliePaymentProvider` already are that adapter, generically, and already ship iDEAL support (`method: ideal` is `LogPaymentProvider`'s own default). Building a second, iDEAL-specific class would duplicate REQ-LPP-002/003 for zero behavioural difference — exactly the anti-pattern ADR-011 exists to prevent.
