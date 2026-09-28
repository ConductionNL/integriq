# Tasks: messaging-payment-command-settlements-and-point-of-sale

Kind: code. Size M. Halves for shillinq `receivables-payment-links` and `receivables-provider-payouts` (rows shillinq `sal-pay-link`, `rec-payment-request`, `rec-psp-payouts`, `sal-tap-to-pay`).

## Implementation tasks

### Task 1: The payment command event
- **spec_ref**: `openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007`
- **files**: `lib/Event/PaymentRequestedEvent.php`, `lib/EventListener/PaymentRequestedListener.php`, `lib/AppInfo/Application.php`, `lib/Service/PaymentIntentService.php`
- **acceptance_criteria**:
  - GIVEN a Mollie source WHEN shillinq dispatches the event for EUR 125.00 THEN the result slot holds the payment intent id, the provider payment id and a checkout URL, and the `payment_intent` carries integriq's own webhook URL
  - GIVEN no payment source WHEN the event is dispatched THEN it is handled with refusal code `no-source` and nothing is thrown to the caller
- [ ] Implement
- [ ] Test (PHPUnit with the real event class and `LogPaymentProvider`; one dispatch against a Mollie test key on a dev instance)

### Task 2: Settlement fetch on the provider seam
- **spec_ref**: `openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008`
- **files**: `lib/Service/Payment/PaymentProviderInterface.php`, `lib/Service/Payment/MolliePaymentProvider.php`, `lib/Service/Payment/LogPaymentProvider.php`
- **acceptance_criteria**:
  - GIVEN a recorded Mollie settlements response with payments, one refund and fees WHEN `fetchSettlements()` runs THEN it returns one settlement whose transaction lines add up to the net amount
- [ ] Implement
- [ ] Test (PHPUnit against recorded Mollie responses)

### Task 3: Store, emit and schedule settlements
- **spec_ref**: `openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008`
- **files**: `lib/Service/Payment/PaymentSettlementService.php`, `lib/BackgroundJob/PaymentSettlementPullJob.php`, `appinfo/info.xml`, `lib/Settings/integriq_register.json` (`payment_settlement`, with an `authorization` block that keeps it to administrators, following `lib/Settings/register.d/99-mail-schemas-lockdown.json`; `payment_intent` has no such block today)
- **acceptance_criteria**:
  - GIVEN a settlement pulled yesterday WHEN the job runs again THEN no second `nl.conduction.payment.settlement` event is emitted
  - GIVEN a source on the `log` provider WHEN the job runs THEN it is skipped
- [ ] Implement
- [ ] Test (PHPUnit; `occ background-job:list` shows the job after upgrade on a dev instance)

### Task 4: Point-of-sale payments and the terminal list
- **spec_ref**: `openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-payment-can-be-taken-on-a-registered-point-of-sale-device-req-lpp-009`
- **files**: `lib/Service/Payment/*.php`, `lib/Controller/PaymentsController.php`, `appinfo/routes.php`, `lib/Settings/integriq_register.json` (`payment_intent.terminalId`)
- **acceptance_criteria**:
  - GIVEN a Mollie source with one terminal WHEN a command asks for method `pointofsale` on that terminal THEN a payment is created without a checkout URL and the result says so
  - GIVEN the `log` provider WHEN the same command is dispatched THEN the refusal code is `unsupported`
- [ ] Implement
- [ ] Test (PHPUnit; Newman for the terminal list route, including a 403 for a user without `payments.create`)

### Task 5: Seed, docs and strings
- **spec_ref**: `openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008`
- **files**: `lib/Settings/integriq_seed_data.json`, `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the demo data WHEN it is loaded THEN the settlement `st_example0001` is present with 15 lines
- [ ] Implement
- [ ] Test (PHPUnit on the seed; the docs page lists the three event types and their fields)

## Verification
- [ ] `openspec validate messaging-payment-command-settlements-and-point-of-sale --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit and Newman exit codes read
- [ ] A shillinq developer confirms the event and field names against `receivables-payment-links` and `receivables-provider-payouts`
