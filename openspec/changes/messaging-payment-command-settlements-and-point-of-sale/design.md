# Design: messaging-payment-command-settlements-and-point-of-sale

Kind: code. Size M. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **Creation.** `PaymentsController::create()` (routed at `appinfo/routes.php:157`) calls `PaymentIntentService::createPayment()` (`lib/Service/PaymentIntentService.php:145`). The service checks `amount.value`, `amount.currency` and `description` (`validateCreateRequest()`, :303), resolves the source by `sourceSlug` or the single `type=payment` source (`resolveSource()`, :327), and saves a `payment_intent` in register `integriq`. The caller supplies `webhookUrl`; `MolliePaymentProvider::createPayment()` (`lib/Service/Payment/MolliePaymentProvider.php:89`) passes it to Mollie unchanged, so a caller that leaves it out gets no status callback.
- **Status.** `handleWebhook()` (:206) re-reads the status from the provider and `emitStatusEvent()` (:418) emits `nl.conduction.payment.status` with `paymentIntentId` set to the provider payment id, `outcome`, `errorCode`, `errorMessage`, `settlementReference` and `gatewayFeeAmount`.
- **Point of sale today.** `MolliePaymentProvider::createPayment()` throws when the response has no checkout link (:124-131). A Mollie point-of-sale payment is sent to a terminal and has no checkout link, so it would fail there.
- **Command pattern.** `DigitalPostSendRequestedEvent` holds readonly provenance, `setHandled()`, a result id and `setRefusal(reason, code)`. Its listener (`lib/EventListener/DigitalPostSendRequestedListener.php`) catches every throwable and writes a refusal, so the caller never sees an exception. It is registered with `addServiceListener()` in `Application.php:284`.
- **Events out.** `EventService::emitCloudEvent()` (`lib/Service/EventService.php:2584`) saves an object in register `integriq`, schema `event`.
- **Jobs.** `appinfo/info.xml:103-118` registers integriq's background jobs. The bank feed pull, `BankfeedSyncJob` (`lib/BackgroundJob/BankfeedSyncJob.php`, a `TimedJob`, `info.xml:118`), is the nearest scheduled pull of a provider API.

## D1. A typed command with a result slot

`PaymentRequestedEvent(sourceApp, subjectRef, amountValue, amountCurrency, description, redirectUrl, method = null, terminalId = null, sourceSlug = null, metadata = [], correlationId = '')`. Result slot: `setHandled()`, `setResult(paymentIntentId, providerPaymentId, checkoutUrl, paymentStatus, dormant)`, `setRefusal(reason, code)` with codes `invalid`, `no-source`, `provider-error`, `unsupported`. `PaymentRequestedListener` builds the payload, adds integriq's own absolute webhook URL (`IURLGenerator::linkToRouteAbsolute('integriq.payments.webhook')`), and calls `createPayment()`. `metadata` gets `sourceApp` and `subjectRef` so the `payment_intent` keeps its provenance.

Alternative considered: let the caller keep supplying `webhookUrl`. Rejected: a caller does not know integriq's public URL, and a wrong one silently loses every status.

## D2. Settlements are pulled once a day and sent once

`PaymentProviderInterface` gains `fetchSettlements(configuration, since): array`, each item `{settlementReference, settledAt, currency, amountGross, amountFees, amountRefunds, amountChargebacks, amountNet, transactions: [{providerPaymentId, type (payment, refund, chargeback, fee), amount, fee}]}`. `MolliePaymentProvider` reads Mollie's settlements and, per settlement, its payments, refunds and chargebacks; `LogPaymentProvider` returns an empty list. `PaymentSettlementService::pull(source)` stores each new settlement as a `payment_settlement` object (unique on source and settlement reference) and emits `nl.conduction.payment.settlement` with that data. A settlement already stored is skipped, so a rerun sends nothing twice. A daily background job runs the pull for every payment source whose provider is not `log`.

Alternative considered: a Mollie webhook for settlements. Rejected: Mollie reports settlements through its API, not through the payment webhook.

## D3. Point of sale is a method with a terminal

`PaymentProviderInterface` gains `listTerminals(configuration): array` (`{terminalId, label, status}`). `createPayment()` accepts `method = pointofsale` with a `terminalId`; `MolliePaymentProvider` sends both and accepts a response without a checkout link for this method only. The `payment_intent` stores `terminalId`. A binding without a point-of-sale method, and `LogPaymentProvider`, answer the command with refusal code `unsupported`, which is what makes shillinq show the QR fallback. `GET /api/payments/sources/{slug}/terminals` lists terminals for an administrator registering a device (NC session, `payments.create` action).

## Declarative versus imperative

| Behaviour | Path | Rationale |
|---|---|---|
| Creating a payment for another app | Imperative, typed event and listener | ADR-041 command with a result. |
| Settlement pull | Imperative, a background job (ADR-031 exception for an external integration) | A scheduled read of a third-party API. |
| Settlement hand-off | Declarative CloudEvent in the `event` schema | Same channel as the payment status. |

## Seed data

- A `payment_settlement` for the demo Mollie source: reference `st_example0001`, settled 2026-10-02, 14 payments totalling EUR 612.40, one refund of EUR 22.85, fees EUR 4.06, net EUR 585.49, matching the shillinq seed of `receivables-provider-payouts`.
- The dormant `ideal-ouderbijdrage` source stays on `log`, so no settlement is pulled for it.

## Risks

- [Mollie settlement lists are paged] the pull follows the next link until the date bound.
- [Fees carry VAT differently per country] integriq reports the fee amounts as the provider gives them; the VAT treatment is shillinq's.
