<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# Payments for other apps

Integriq takes payments for the other apps of the fleet. An app such as
shillinq asks for a payment, the customer pays online or on a terminal at the
desk, and integriq reports the outcome and, later, the payout that settled it.

This page is for the developer of an app that takes payments through
integriq, and for the administrator who sets up the payment source.

## The payment source

A source of type `payment` holds the provider. `configuration.provider` is
`mollie` for a live Mollie account, or `log` for a sandbox that answers
without calling anyone. The demo data ships `payment-sandbox` on `log`, and
one demo payout, `st_example0001`, so the payout screens have something to
show.

## Three messages

| Message | Direction | When |
|---|---|---|
| `PaymentRequestedEvent` | your app to integriq, in process | You want a payment |
| `nl.conduction.payment.status` | integriq to your app, CloudEvent | The provider reports a status |
| `nl.conduction.payment.settlement` | integriq to your app, CloudEvent | A payout reached the account |

### `PaymentRequestedEvent`

Dispatch it with Nextcloud's event dispatcher and read the answer from the
same object. It never throws.

| Field | Meaning |
|---|---|
| `sourceApp` | Your app id |
| `subjectRef` | What is paid for, in your app (an invoice id) |
| `amountValue`, `amountCurrency` | The amount as a decimal string, and its ISO 4217 currency |
| `description` | What the customer sees |
| `redirectUrl` | Where the customer returns after paying online |
| `method` | Optional: `ideal`, `pointofsale`, ... |
| `terminalId` | Required for `pointofsale`: the registered terminal |
| `sourceSlug` | Optional: the payment source, when there is more than one |
| `metadata` | Optional: your own keys, returned with the status |
| `correlationId` | Optional: your trace id |

The answer: `paymentIntentId`, `providerPaymentId`, `checkoutUrl` (empty for a
terminal payment), `paymentStatus` and `dormant` (true on a `log` source). Or
a refusal with a reason and one of the codes `invalid`, `no-source`,
`provider-error` and `unsupported`.

### `nl.conduction.payment.status`

| Field | Meaning |
|---|---|
| `paymentIntentId` | The provider's payment id |
| `outcome` | What happened to the payment |
| `errorCode`, `errorMessage` | Why it failed, when it did |
| `settlementReference` | The payout that settles it, once known |
| `gatewayFeeAmount` | The provider's fee, when reported |

### `nl.conduction.payment.settlement`

Once a day integriq reads the payouts of every payment source that is not on
`log`. It reports each payout once. A rerun sends nothing it already sent.

| Field | Meaning |
|---|---|
| `settlementReference` | The payout's reference, as on the bank statement |
| `settledAt` | When it was paid out |
| `currency` | ISO 4217 currency of every amount |
| `amountGross` | The payments it settles |
| `amountFees` | What the provider kept |
| `amountRefunds`, `amountChargebacks` | What it deducts |
| `amountNet` | What reached the account |
| `transactions` | One line per payment (positive), refund or chargeback (negative): `providerPaymentId`, `type`, `amount`, `fee` |

Example, from the demo data: `st_example0001`, paid out on 2 October 2026,
has 14 payments totalling EUR 612.40, one refund of EUR 22.85 and EUR 4.06
in fees, so EUR 585.49 net.

Integriq reports fees as the provider gives them. How VAT on fees is booked
is up to the receiving app.
