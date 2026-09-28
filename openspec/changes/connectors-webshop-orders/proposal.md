---
kind: config
depends_on: []
---

# Proposal: connectors-webshop-orders

## Summary

A shop owner who sells through WooCommerce, Shopify or Lightspeed types every order into shillinq by hand, because integriq has no shop connector. This change ships one per platform: a source template with brokered credentials, a mapping from the platform's order to shillinq's `WebshopOrder`, and an incremental synchronization that writes new orders and carries a later refund or cancellation onto the same record.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from shillinq `sales-webshop-orders`, merged on shillinq `development`. It is `build` by the decision rule.

shillinq `sales-webshop-orders`, Cross-Project Dependencies: "integriq: a web shop connector (source type, credentials through its broker, and a synchronization per shop platform) whose mapping targets shillinq's `WebshopOrder` schema. integriq ships no shop connector on development today (its tree has no WooCommerce, Shopify, Lightspeed or Magento source). This change publishes the target contract; the connector is integriq's change." Its design D1: "integriq writes one `WebshopOrder` per shop order into register `shillinq` through its synchronization, the same way it writes any target object."

Row in the shillinq matrix: `sal-webshop-sync`, "Turn web shop orders into sales invoices automatically." Three competitors rate yes:

- Moneybird: "Koppel je webshop aan Moneybird en laat bestellingen, facturen en betalingen automatisch in je administratie belanden ... van WooCommerce en Shopify tot CCV Shop en Magento" (https://www.moneybird.nl/product/koppelingen/webshops/).
- SnelStart: "Met SnelStart inHandel worden je orders automatisch opgehaald en verwerkt"; its marketplace lists WooCommerce and Shopify connectors (https://www.snelstart.nl/koppelingen).
- Odoo: `addons/sale/models/payment_transaction.py:184`, a paid web shop order is invoiced automatically.

## What integriq already has

- The synchronization engine writes a mapped object into another app's register and schema (`openspec/specs/synchronization-engine/spec.md` REQ-004), upserts a contract on the origin's identity (REQ-025), and fetches incrementally with a cursor that advances only after a complete fetch (REQ-016, REQ-017).
- Source templates seeded from `lib/Settings/register.d/*-source.json` and listed in the catalogue by `CatalogRegistryService::collectFromSeedFragments()` (`lib/Service/CatalogRegistryService.php:321`).
- Brokered credentials on a source through `credentialRef` (`lib/Service/BrokeredCallService.php`).

## What this change builds

1. Source templates `woocommerce-shop`, `shopify-shop` and `lightspeed-shop`, dormant until an administrator adds the shop URL and a credential reference.
2. One mapping per platform onto `WebshopOrder` as shillinq `sales-webshop-orders` defines it.
3. One incremental synchronization per platform that writes new orders and updates the payment status of an order already written, keyed on channel and shop order number.
4. Catalogue entries in the category `Commerce`.

## Out of scope

- Turning an order into an invoice, debtor matching and VAT routing (shillinq `sales-webshop-orders`).
- Stock and product sync back to the shop.
- Magento and CCV Shop in the first release; each is one more template and mapping.

## Impact

- New: three seed fragments in `lib/Settings/register.d/`, three mappings and three synchronizations in the seed data, catalogue category overrides.
- No PHP service is added; the engine does the work.

## Cross-project dependencies

- shillinq `sales-webshop-orders` owns `WebshopOrder` and `WebshopChannel`. A field rename there is a mapping edit here.

## Risks

- A platform returns prices with and without VAT in different fields. The mapping sets `pricesIncludeVat` from the platform's own flag, and the fixture tests cover both.
