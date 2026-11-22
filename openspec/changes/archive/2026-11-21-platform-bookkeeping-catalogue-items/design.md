# Design: platform-bookkeeping-catalogue-items

Kind: config. Size S. Read at integriq development `966d6458` on 2026-09-28.

## Context

- `CatalogRegistryService::collectFromSeedFragments()` (:321) globs `lib/Settings/register.d/*.json`, reads every object whose `@self.schema` is `source`, and makes it a `source-template` entry; its category is `SLUG_CATEGORY_OVERRIDES[slug]`, else `TYPE_CATEGORY_LABELS[type]`, else "Integrations" (:366).
- The four bookkeeping sandbox sources live only in `lib/Settings/integriq_seed_data.json` (types `peppol`, `psd2`, `cardfeed`, `payment`, all `provider: log`, no slug), which the catalogue does not read.
- shillinq's integrations page (`platform-integration-catalogue` D1) is an index over `catalog_item` with a default filter on `Bank`, `Payments`, `E-invoicing`, `Tax filing`, `Commerce` and `Payroll`; its D2 lists families without an item as "not available yet".

## D1. Four templates

| Slug | Type | Category | Credential |
|---|---|---|---|
| `peppol-access-point` | `peppol` | `E-invoicing` | access point API key by `credentialRef` |
| `psd2-bank-aggregator` | `psd2` | `Bank` | aggregator client credentials by `credentialRef` |
| `corporate-card-feed` | `cardfeed` | `Bank` | provider API key by `credentialRef` |
| `mollie-payments` | `payment` | `Payments` | Mollie API key by `credentialRef` |

Each is `isEnabled: false` with `configuration.provider` set to the live binding (`rest` or `mollie`) and no credential, so instantiating it is the step where the administrator adds one. The sandbox records in the seed data stay as they are.

## D2. The bookkeeping categories are a contract

`SLUG_CATEGORY_OVERRIDES` maps the four slugs above, and the later bookkeeping slugs (`digipoort-sbr` to `Tax filing`, `ecb-eurofxref` to `Bank`, the three shop templates to `Commerce`), to these names. A unit test lists the six names and fails when a slug in the bookkeeping list resolves to anything else, so a rename is a visible change.

## Declarative versus imperative

Configuration and one constant. No behaviour changes.

## Seed data

The four templates above.

## Risks

- [A template is instantiated without a credential] the live bindings already fail closed without key material (`peppol-access-point-connector` REQ-006, `live-payment-providers` REQ-LPP-006).
