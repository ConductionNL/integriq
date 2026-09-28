---
kind: config
depends_on: []
---

# Proposal: platform-bookkeeping-catalogue-items

## Summary

A bookkeeper who opens shillinq's integrations page finds no card for a bank feed, a payment provider or Peppol, although integriq has all three. Their sandbox sources sit in integriq's seed data, where the catalogue does not look, and the categories they would fall under do not match the ones shillinq filters on. This change turns them into catalogue source templates under the bookkeeping categories shillinq names, and fixes those category names as the contract for the bookkeeping connectors other changes add.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from shillinq `platform-integration-catalogue`, merged on shillinq `development`. It is `build` by the decision rule.

shillinq `platform-integration-catalogue`, Cross-Project Dependencies: "Bookkeeping connectors (PSD2 bank feeds, Mollie, Peppol, Digipoort SBR) need catalogue items in integriq; that is integriq's work and is listed in this lane's hand-back, not specified here." Its design D1 filters integriq's `catalog_item` objects "on the categories `Bank`, `Payments`, `E-invoicing`, `Tax filing`, `Commerce` and `Payroll`", and its Seed Data section says: "With integriq's catalogue as it stands, the page lists no bookkeeping card yet".

Row in the shillinq matrix: `plt-marketplace`, "Pick from a marketplace of ready-made integrations." Four competitors rate yes. Moneybird: "integrations directory with categories Webwinkels, PSP's, Voorraadbeheer, CRM, Kassa, Bouw and salary tools" (https://www.moneybird.nl/product/koppelingen/). SnelStart: "Kies uit meer dan 300 koppelingen" (https://www.snelstart.nl/ondernemer/inkaart). Exact Online: its App Store (https://apps.exactonline.com). Odoo: its Apps Store.

## What integriq already has

- The catalogue: `CatalogRegistryService::collect()` (`lib/Service/CatalogRegistryService.php:147`) assembles adapters, static descriptors and source templates from `lib/Settings/register.d/*.json` (`collectFromSeedFragments()`, :321); `lib/Repair/MaterializeCatalogItems.php` writes them as `catalog_item` objects on upgrade.
- Category labels by source type (`TYPE_CATEGORY_LABELS`, :68: `peppol` "Peppol / e-invoicing", `psd2` and `payment` "Payments / open banking") and per slug (`SLUG_CATEGORY_OVERRIDES`, :88).
- Four bookkeeping sandbox sources in `lib/Settings/integriq_seed_data.json` (:69 Peppol, :84 PSD2 AIS, :100 corporate card feed, :115 payment), none of them in `register.d`, so none is a catalogue item.
- One payment template in `register.d`: `ideal-ouderbijdrage-source.json`, a school fee template on the `log` provider.

## What this change builds

1. Source templates in `register.d` for the Peppol access point, the PSD2 bank aggregator, the corporate card feed and a Mollie payment account, each dormant with its credential by broker reference.
2. The bookkeeping categories `Bank`, `Payments`, `E-invoicing`, `Tax filing`, `Commerce` and `Payroll` as slug overrides, so these templates and the ones later changes add land where shillinq looks.
3. A catalogue test that fails when a bookkeeping template has no bookkeeping category.

## Out of scope

- shillinq's integrations page (shillinq `platform-integration-catalogue`).
- The Digipoort, exchange rate and web shop templates. They come with `connectors-digipoort-sbr-filing`, `sources-ecb-exchange-rates` and `connectors-webshop-orders`, which use the categories fixed here.
- Renaming the existing type labels. Other apps' catalogue views keep "Payments / open banking" and "Peppol / e-invoicing" for sources of those types that carry no override.

## Impact

- New: four seed fragments in `lib/Settings/register.d/`.
- Changed: `SLUG_CATEGORY_OVERRIDES` in `lib/Service/CatalogRegistryService.php`, its unit test.

## Cross-project dependencies

- shillinq `platform-integration-catalogue` fills `catalogItem` per family in its `connections.json` with the slugs published here.

## Risks

- Two sources of truth for the sandbox sources. The seed data keeps its sandbox records for demos; the templates are what an administrator instantiates. The docs say which is which.
