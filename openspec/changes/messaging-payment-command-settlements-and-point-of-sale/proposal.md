---
kind: code
depends_on: []
---

# Proposal: messaging-payment-command-settlements-and-point-of-sale

## Summary

Shillinq can only get a payment link from integriq through `POST /api/payments`, which needs a Nextcloud session. A portal forward, a background job and an intake raise have none, so every payment request stays without a link. This change gives sibling apps a typed command event to create a payment, reports every provider settlement as a CloudEvent with the payments, refunds, chargebacks and fees in it, and lets a payment be taken on a registered point-of-sale device.

## Why

The owner-moves pass of 2026-09-28 handed three halves to integriq. Each one is named by a shillinq OpenSpec change that is merged on shillinq `development`, so each is `build` by the decision rule.

- **Payment command event.** shillinq `receivables-payment-links`, design D1: "`IntegriqPaymentAdapter` ... `class_exists()`-guards integriq's payment command event, dispatches it with provenance ... and reads `providerPaymentId`, `paymentIntentId` and `checkoutUrl` from the result slot." Its proposal, Cross-Project Dependencies: "Today creation is only `POST /apps/integriq/api/payments` behind a Nextcloud session (integriq `appinfo/routes.php:157`), which a portal forward or a background job does not have."
- **Settlement event.** shillinq `receivables-provider-payouts`, design D1: "integriq emits `nl.conduction.payment.settlement` per settlement with data `{settlementReference, settledAt, currency, amountGross, amountFees, amountRefunds, amountChargebacks, amountNet, transactions: [{providerPaymentId, type, amount, fee}]}`."
- **Point-of-sale payment.** shillinq `receivables-provider-payouts`, design D5: a payment "with the point-of-sale method on the user's device for the open amount", and "When integriq answers that the method is not available, the page shows the checkout as a QR code".

Rows in the shillinq matrix (`openspec/parity/capabilities.json`) that these halves serve:

- `sal-pay-link`, "Put a pay now link for iDEAL or card on the invoice." Four competitors rate yes. Exact Online: "Met Exact Online voeg je eenvoudig en kosteloos een betaallink toe aan je verkoopfacturen" (https://www.exact.com/nl/producten/boekhouden/features-en-prijzen). Moneybird: https://helpcenter.moneybird.nl/nl/articles/207092-directe-betaallink-in-de-e-mail-van-de-factuur. SnelStart: "Maak het je klanten makkelijk met een betaallink van Mollie" (https://www.snelstart.nl/ondernemer/instap). Odoo: `addons/account_payment/models/account_move.py:177`.
- `rec-payment-request`, "Send a payment request for a case or intake and see when it is paid." Moneybird rates yes (https://helpcenter.moneybird.nl/nl/articles/286722-betaalverzoek-versturen).
- `rec-psp-payouts`, "Have payment provider payouts matched to the invoices they settle." Two competitors rate yes. Moneybird: "Met de Mollie-koppeling verwerkt Moneybird je uitbetalingen automatisch ... verwerkt ook de transactiekosten, chargebacks en refunds" (https://helpcenter.moneybird.nl/nl/articles/208040-uitbetalingen-van-mollie-automatisch-verwerken-in-je-administratie). Exact Online: automatic import of Mollie statements (https://support.exactonline.com/community/s/article/All-All-HNO-Concept-sales-invoices-slsinv-mtchmolliepayc).
- `sal-tap-to-pay`, "Take a card payment on the spot with your own phone and have it booked in the administration." Two competitors rate yes. Moneybird: "Met Tap to Pay kunnen klanten bij jou pinnen via de Moneybird-app" (https://helpcenter.moneybird.nl/nl/articles/345450-tap-to-pay). Odoo: `addons/point_of_sale/models/res_config_settings.py:35`.
- portaliq `cmp-cas-pay` rides on the same command through shillinq `receivables-payment-links`.

## What integriq already has

- `PaymentIntentService::createPayment()` (`lib/Service/PaymentIntentService.php:145`) validates, resolves the payment source, calls the provider and stores a `payment_intent`.
- `PaymentProviderInterface` (`lib/Service/Payment/PaymentProviderInterface.php`) with `createPayment()` and `fetchPaymentStatus()`, bound by `LogPaymentProvider` and `MolliePaymentProvider`.
- The signed webhook (`appinfo/routes.php:158`) and `emitStatusEvent()` (`PaymentIntentService.php:418`), which emits `nl.conduction.payment.status` with the envelope shillinq's reconciliation reads.
- Two typed ADR-041 commands with a result slot to copy: `DigitalPostSendRequestedEvent` (`lib/Event/DigitalPostSendRequestedEvent.php`) and `DeliveryRequestedEvent`, both registered in `lib/AppInfo/Application.php:283-284`.

## What this change builds

1. `OCA\Integriq\Event\PaymentRequestedEvent`, a typed command with provenance and a result slot, and its listener, which calls `createPayment()` with integriq's own webhook URL.
2. A settlement fetch on the provider seam, a daily job per live payment source, and one `nl.conduction.payment.settlement` CloudEvent per new settlement, idempotent on the settlement reference.
3. The point-of-sale method: a terminal list per payment source and a payment created on a terminal id, answered `unsupported` by a binding that has no such method.

## Out of scope

- The shillinq side: the adapter, `ProviderPayout`, `PaymentDevice`, matching and bookings (`receivables-payment-links`, `receivables-provider-payouts` in shillinq).
- The payment review page and the provider step on the source form (`messaging-payments-review`).
- Hardware terminal rental or pairing. A device is registered with the provider in the provider's own app.
- Refunds created from integriq.

## Impact

- New: `lib/Event/PaymentRequestedEvent.php`, `lib/EventListener/PaymentRequestedListener.php`, `lib/Service/Payment/PaymentSettlementService.php`, a background job for the settlement pull, a `payment_settlement` schema.
- Changed: `PaymentProviderInterface` (two methods), `LogPaymentProvider`, `MolliePaymentProvider`, `PaymentIntentService`, `lib/AppInfo/Application.php`, `appinfo/info.xml`, `lib/Settings/integriq_register.json`.

## Cross-project dependencies

- shillinq `receivables-payment-links` and `receivables-provider-payouts` consume the command, the settlement event and the point-of-sale answer. Their field names are the contract here.

## Risks

- A settlement arrives before the capture it contains. Shillinq matches lines on provider payment id whenever the capture arrives; integriq sends the settlement once and does not wait.
- A provider has no settlement API. The binding answers an empty list and the source shows that settlements are not reported.
