# Web shop orders into shillinq

## Overview

A shop owner who sells through WooCommerce, Shopify or Lightspeed eCom no longer types each
order into shillinq. Integriq ships a dormant connector per shop platform: a
source template whose secret stays in the credential broker, a mapping from
the platform's order to shillinq's `WebshopOrder`, and an incremental
synchronization. Every new order becomes one `WebshopOrder` in shillinq's
register; a later refund or cancellation updates the payment status of that
same `WebshopOrder`, which is what shillinq's credit handling listens for.

The templates are listed in the connector catalogue under **Commerce**.

| Platform | Template | Orders call | Changed since | Secret |
|----------|----------|-------------|---------------|--------|
| WooCommerce | `woocommerce-shop` | `GET /wp-json/wc/v3/orders` | `modified_after` | consumer key and secret (HTTP Basic) |
| Shopify | `shopify-shop` | `GET /admin/api/<version>/orders.json` | `updated_at_min` | Admin API access token (`X-Shopify-Access-Token`) |
| Lightspeed eCom | `lightspeed-shop` | `GET /<language>/orders.json` | `updated_at_min` | API key and secret (HTTP Basic) |

Lightspeed's order list names an order's lines only as a link to a separate
resource, `orders/<id>/products.json`. The Lightspeed synchronization fetches
that resource for every order and merges its lines in before the mapping runs.

## Connect a shop

1. **In shillinq**, open the settings gear, then **Web shop channels**, and
   open (or create) the channel for this shop. Note its **id** and the id of
   the **administration** it books into.
2. **In your shop**, create the API access integriq uses:
   - WooCommerce: *WooCommerce → Settings → Advanced → REST API*, a key with
     read access. You get a consumer key and a consumer secret.
   - Shopify: a custom app with the `read_orders` scope. You get an Admin API
     access token.
   - Lightspeed eCom: in the back office, the API keys section of the settings,
     a key with read access to orders.
     You get an API key and an API secret.
3. **In the credential broker**, store the secret under the name the template
   asks for: `woocommerce-shop-consumer-secret`, `shopify-shop-admin-token`
   or `lightspeed-shop-api-secret`.
   The secret never sits on the source itself.
4. **In integriq**, open the source of the template:
   - WooCommerce: set the location to `https://<your-shop>/wp-json/wc/v3` and
     the username to the consumer key.
   - Shopify: set the location to
     `https://<your-store>.myshopify.com/admin/api/<version>`, for example
     `2025-07`.
   - Lightspeed eCom: set the location to your cluster and shop language, for
     example `https://api.webshopapp.com/nl`, and the username to the API key.
   Then enable the source.
5. Open the mapping (`woocommerce-shop-to-webshop-order`,
   `shopify-shop-to-webshop-order` or `lightspeed-shop-to-webshop-order`) and fill **channelId** and
   **administrationId** with the ids from step 1.
6. Enable the synchronization (`woocommerce-shop-orders`,
   `shopify-shop-orders` or `lightspeed-shop-orders`) and give it a job, for example every 15 minutes.

Until the channel id and administration id are filled, the synchronization
refuses to run: it fetches nothing, writes nothing, and its log names the
mapping and the value to fill. One connected shop is one source, one mapping
and one synchronization; for a second shop, copy the three and fill them for
that shop's channel.

## What arrives in shillinq

Each order becomes a `WebshopOrder` with the fields shillinq defines:

| Field | From |
|-------|------|
| `channelId`, `administrationId` | The mapping, as filled in step 5 |
| `shopOrderId` | The shop's order number |
| `orderedAt` | When the order was placed, in UTC |
| `buyer` | Name, e-mail, company, address and country of the billing address; `type` is `business` when a company is named, `consumer` otherwise |
| `currency`, `pricesIncludeVat` | The order's currency and the shop's own flag for prices with or without VAT; Lightspeed gives every price both ways, and integriq writes them including VAT (currency `EUR` when the order names none) |
| `lines` | Per line: SKU, description, quantity, unit price (with or without VAT, as `pricesIncludeVat` says) and VAT rate |
| `shipping`, `discountTotal` | Shipping and discount, on the same VAT basis as the lines |
| `totals` | `net`, `vat` and `gross` of the order |
| `payment` | `status` (`paid`, `pending`, `refunded` or `cancelled`), the payment method, and the payment provider's id where the shop gives it (WooCommerce does; Shopify's and Lightspeed's orders do not) |

Integriq never writes shillinq's own fields: the intake state, the invoice and
credit note links and the refusal reason are shillinq's.

WooCommerce and Shopify do not hold a VAT id or a KvK number in a standard
order field, so `buyer.vatId` and `buyer.kvkNumber` arrive empty from them;
shillinq matches a business buyer on its e-mail address when they are.
Lightspeed's order carries both, and they are passed on.

## Refunds, cancellations and the cursor

The synchronization is incremental. Each run asks the shop only for orders
changed since the last complete run, and moves that point forward only when
the whole fetch succeeded; a run that fails halfway asks again from the same
point next time.

Each order is kept on its shop order id. When the shop refunds or cancels an
order, it shows up as changed on the next run, and the same `WebshopOrder`
gets the new payment status. No second order is made, and nothing is ever
deleted.

A Shopify run reads at most 250 changed orders; when more changed, the next
run continues where this one stopped. A Lightspeed run reads the changed
orders page by page, 250 at a time, and makes one extra call per order for
its lines.
