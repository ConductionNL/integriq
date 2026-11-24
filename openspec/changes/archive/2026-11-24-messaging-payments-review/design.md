# Design: messaging-payments-review

Kind: code. Size S. Read at `development` 92f282bc.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Record | `lib/Settings/integriq_register.json:3311` (`payment_intent`, v1.1.0) | no `authorization` block |
| Source | `lib/Settings/integriq_register.json:148` (`source.type` enum has `payment`) | configuration edited as raw JSON |
| Service | `lib/Service/PaymentIntentService.php:145` `createPayment()`, :206 `handleWebhook()`, :327 `resolveSource()`, :368 `resolveProvider()` | log is the default and the fallback |
| Controller | `lib/Controller/PaymentsController.php:92` `create()`, :141 `webhook()` | no read or refresh route |
| Bindings | `lib/Service/Payment/LogPaymentProvider.php`, `MolliePaymentProvider.php` | Mollie via `BrokeredCallService` |
| Form | `src/modals/v2/SourceFormFields.vue`, digital post picker :425 to :640 | the pattern to copy |

## D1. The payments page is declared over `payment_intent`

A new manifest fragment, `src/manifest.d/payments.json`, declares a
`Payments` page at `/payments`, `type: index`, over register `integriq` and
schema `payment_intent`, with no create and no edit, and a menu entry under
the connections group. Columns: `createdAt`, `description`, `amountValue`
with `amountCurrency`, `provider`, `paymentStatus` as a badge, `lastOutcome`
and `sourceSlug`. The detail sidebar shows every property, including
`checkoutUrl` and `metadata`.

Rejected: a custom Vue page. Everything the page shows is a schema property,
and the manifest renders it (ADR-024).

## D2. Refresh status reuses the webhook path

A payment whose provider cannot reach this instance never gets its webhook,
so its status stays `open`. "Refresh status" is a row action posting to a new
`POST /api/payments/{id}/refresh` on `PaymentsController::refresh()`, behind
a new `payments.review` action. It loads the `payment_intent`, reads its
`providerPaymentId` and calls `handleWebhook()`. That method already
re-fetches the authoritative status, applies the `lastOutcome` guard and
emits the CloudEvent only on a real change, so a refresh and a webhook can
never disagree or double-reconcile.

Rejected: a separate "sync status" method. Two paths that set
`lastOutcome` are two idempotency guards to keep in step.

## D3. The provider step on the source form

`SourceFormFields.vue` gains a payment section, shown when `type` is
`payment`, built exactly like the digital post one (:425 to :640):

- A provider picker fed by a new `GET /api/payments/providers`, on
  `PaymentsController::providers()`, which lists the bindings the service
  knows (`log`, `mollie`) with a label and whether each needs a key.
- The existing broker key picker writes
  `configuration.authentication.credentialRef`, as it does for every other
  source.
- "Check provider" posts the draft configuration to
  `POST /api/payments/providers/check`. The service resolves the binding and
  calls a probe. The result says `ok` or the refusal, and `mode`: `log`,
  `test` or `live`.

The probe is a new, optional interface,
`lib/Service/Payment/PaymentProviderProbeInterface.php`, with one method,
`probe(array $sourceConfiguration): array`. `MolliePaymentProvider`
implements it with a read-only `GET {baseUrl}/profiles/me` through the
broker. Mollie answers that call for an API key with the website profile the
key belongs to, including its `mode`, so the check names the profile and says
`test` or `live` without integriq ever seeing the key. `LogPaymentProvider`
answers `ok`, mode `log`.
A separate interface leaves `PaymentProviderInterface` untouched, so a third
party binding written against it keeps compiling.

## D4. An unknown provider is refused

`resolveProvider()` (:368) returns `logProvider` for any value except
`mollie`. On a source meant to be live, a value such as `Mollie` runs the
stub and records payments as created. The change makes `resolveProvider()`
throw `PaymentProviderException` for a value it has no binding for, and the
controller maps that to `502` as it already does for provider errors
(`PaymentsController.php:111`). An absent value still means `log`, so the
seeded dormant source keeps working.

## D5. `payment_intent` gets an authorization block

`payment_intent` has no `authorization` block. The lockdown fragment
`lib/Settings/register.d/99-mail-schemas-lockdown.json` records what that
means in OpenRegister: an absent block is default open for reads. A new
fragment, `lib/Settings/register.d/99-payment-intent-lockdown.json`, declares
the five actions with empty lists, the same shape, so only administrators and
the owner read a payment. The page is administrator facing, so this closes
nothing anyone needs.

## Declarative versus imperative

The page is declarative. The refresh is an imperative act on an external
system, a status fetch, and cannot be a schema extension. The CloudEvent it
may emit is the existing one from `emitStatusEvent()` (:418), unchanged.

## Seed data

`payment_intent` is unchanged in shape; only its `authorization` block is
added. Three demo intents are added to `lib/Settings/integriq_mock_register.json`
against the `ideal-ouderbijdrage` source with provider `log`: one `paid`
with outcome `captured`, one `open`, one `expired`. The review page then
shows three states on a demo install.

## Risks

- A refresh on a live Mollie source is an outbound call per click. The
  action is behind `payments.review` and is not bulk.
- Throwing on an unknown provider can break a source that today silently runs
  the stub. That source was not charging anyone, so the break is the fix; the
  release note names it.
