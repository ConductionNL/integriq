# Design: connectors-webshop-orders

Kind: config. Size S to M. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **No shop code.** `grep -rli "woocommerce\|shopify\|lightspeed\|magento\|webshop"` over `lib/`, `openspec/` and `docs/` finds only the integriq matrix.
- **Target writes.** `SynchronizationService` writes a mapped object into an OpenRegister register and schema (`openspec/specs/synchronization-engine/spec.md` REQ-004) and keeps one contract per origin id (REQ-025). An update of the origin updates the same target object.
- **Incremental fetch.** REQ-016 selects a cursor-filtered request, REQ-017 advances the watermark only after a complete fetch, REQ-018 keeps deletion off for incremental syncs.
- **Seeds and catalogue.** `lib/Settings/register.d/kvk-source.json` is the shape of a seeded source template; `CatalogRegistryService::SLUG_CATEGORY_OVERRIDES` (`lib/Service/CatalogRegistryService.php:88`) sets a template's category.
- **The target.** shillinq `sales-webshop-orders` D2 defines `WebshopOrder`: `channelId`, `shopOrderId`, `orderedAt`, `buyer` (`type`, `name`, `email`, `companyName`, `vatId`, `kvkNumber`, `address`, `countryCode`), `currency`, `pricesIncludeVat`, `lines` (`sku`, `description`, `quantity`, `unitPrice`, `vatRate`), `shipping`, `discountTotal`, `totals` (`net`, `vat`, `gross`), `payment` (`status`, `method`, `providerPaymentId`), `administrationId`. Its D3 `WebshopChannel` holds the administration and accounts; an order whose channel is unknown is refused there.

## D1. One template per platform

| Template | Orders endpoint | Credential | Change cursor |
|---|---|---|---|
| `woocommerce-shop` | `GET /wp-json/wc/v3/orders` | consumer key and secret (basic) | `modified_after` |
| `shopify-shop` | `GET /admin/api/<version>/orders.json` | Admin API access token header | `updated_at_min` |
| `lightspeed-shop` | `GET /<language>/orders.json` | API key and secret (basic) | `updated_at_min` |

Each template is dormant (`isEnabled: false`, no location) and carries `configuration.channelId` and `configuration.administrationId`, which the administrator copies from the shop's `WebshopChannel` in shillinq.

## D2. The mapping writes the contract as shillinq defined it

Each mapping fills every `WebshopOrder` field of D2 above from the platform's order. `payment.status` maps the platform status to `paid`, `pending`, `refunded` or `cancelled`; a partial refund lists the refunded lines where the platform names them. The mapping never writes shillinq's own fields (`intakeState`, `arInvoiceId`, `creditNoteId`, `refusalReason`).

## D3. The synchronization is incremental and keyed on the shop's order

Target register `shillinq`, schema `WebshopOrder`, origin id `<channelId>:<shop order id>`. A later fetch of the same order updates `payment` on the same object, which is what shillinq's refund handling listens for. Deletion is off. A run that fails part-way keeps the cursor where it was.

## D4. Catalogue

`SLUG_CATEGORY_OVERRIDES` gains the three slugs under `Commerce`, the category name shillinq's integrations page filters on (`platform-integration-catalogue` D1).

## Declarative versus imperative

All configuration: seed sources, mappings and synchronizations. No new PHP.

## Seed data

The three dormant templates; a disabled WooCommerce synchronization for the channel "theehandelvandijk.nl" with a recorded order 100231 (2 x "Earl Grey los 250 g", paid by iDEAL) as its test fixture, matching shillinq's seed.

## Risks

- [Platform API versions move] the version is part of the template's location, and the mapping test runs against a recorded response per platform.
