# webshop-order-connectors Specification

## ADDED Requirements

### Requirement: A shop platform is a source template (REQ-WSO-001)

Integriq MUST ship dormant source templates for WooCommerce, Shopify and Lightspeed eCom. Each template MUST take its credential through a broker reference, MUST carry the shillinq channel id and administration id it writes for, and MUST be listed in the catalogue under the category `Commerce`.

#### Scenario: a shop owner connects a WooCommerce shop
- GIVEN the `woocommerce-shop` template in the catalogue
- WHEN an administrator instantiates it with the shop URL, a credential reference and the channel id of "theehandelvandijk.nl"
- THEN a source exists for that shop, and no key or secret is stored on it in plain text
- e2e: `tests/e2e/catalog-webshop-template.spec.ts`

### Requirement: An order becomes a WebshopOrder as shillinq defines it (REQ-WSO-002)

For each platform integriq MUST ship a mapping that fills the `WebshopOrder` fields shillinq `sales-webshop-orders` defines (channel, shop order number, order date, buyer, currency, whether prices include VAT, lines with VAT rate, shipping, discount, totals, payment status, method and provider payment id, administration). The mapping MUST NOT write shillinq's own intake fields.

#### Scenario: a paid consumer order arrives
- GIVEN WooCommerce order 100231 from J. Bakker, Utrecht, for 2 x "Earl Grey los 250 g" at EUR 8.95 and EUR 4.95 shipping, paid by iDEAL
- WHEN the synchronization runs
- THEN shillinq's register holds one `WebshopOrder` with those lines, `pricesIncludeVat` true, `payment.status` paid and the provider payment id, and its intake fields are empty
- @e2e exclude scheduled synchronization; covered by PHPUnit against a recorded order

### Requirement: A later refund updates the same order (REQ-WSO-003)

The synchronization MUST fetch incrementally, MUST key each order on its channel and shop order number, and MUST update the existing `WebshopOrder` when the shop reports a new payment status. It MUST NOT delete orders, and a failed run MUST NOT move its cursor.

#### Scenario: the shop refunds an order
- GIVEN order 100231 already written as paid
- WHEN the shop refunds it and the synchronization runs again
- THEN the same `WebshopOrder` shows `payment.status` refunded and no second object exists
- @e2e exclude scheduled synchronization; covered by PHPUnit with two recorded fetches
