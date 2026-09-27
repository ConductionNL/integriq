# Tasks: messaging-payments-review

Kind: code. Size S. Row `integriq:msg-payment`.

### Task 1: The payments page
- **spec_ref**: openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-an-administrator-reviews-payments-on-the-payments-page-req-prv-001
- **files**: `src/manifest.d/payments.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN three demo payments WHEN an administrator opens "Payments" THEN all three are listed with status, outcome and source
  - GIVEN the status filter WHEN `open` is picked THEN only open payments remain
- [ ] Implement
- [ ] Test (`tests/validate-manifest.js`; `tests/e2e/payments-review.spec.ts`)

### Task 2: Refresh status
- **spec_ref**: openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payments-status-is-refreshed-from-the-provider-on-request-req-prv-002
- **files**: `lib/Controller/PaymentsController.php` (`refresh()`), `appinfo/routes.php`, `src/handlers/actionHandlers.js`, `src/registry.js`, `src/manifest.d/payments.json`
- **acceptance_criteria**:
  - GIVEN a payment the provider reports paid WHEN "Refresh status" runs THEN `handleWebhook()` is called with its `providerPaymentId` and the row shows `paid`
  - GIVEN a user without `payments.review` WHEN they refresh THEN the request is refused before any provider call
- [ ] Implement
- [ ] Test (PHPUnit on `refresh()` with a real `PaymentIntentService` and a stubbed binding; `tests/e2e/payments-review.spec.ts`)

### Task 3: The provider list and the probe
- **spec_ref**: openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
- **files**: `lib/Service/Payment/PaymentProviderProbeInterface.php`, `lib/Service/Payment/MolliePaymentProvider.php`, `lib/Service/Payment/LogPaymentProvider.php`, `lib/Service/PaymentIntentService.php`, `lib/Controller/PaymentsController.php` (`providers()`, `check()`), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN the instance WHEN `GET /api/payments/providers` is called THEN it lists `log` and `mollie` with whether each needs a key
  - GIVEN a Mollie configuration with an accepted test key WHEN the check runs THEN it answers `ok` and mode `test`, and the key is in neither the response nor the log
- [ ] Implement
- [ ] Test (PHPUnit on `MolliePaymentProvider::probe()` against a stubbed `profiles/me` response)

### Task 4: The provider step on the source form
- **spec_ref**: openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
- **files**: `src/modals/v2/SourceFormFields.vue`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a source of type `payment` WHEN the form opens THEN it shows the provider picker, the key picker and "Check provider"
  - GIVEN a check result WHEN it returns THEN the form shows accepted or refused and the mode
- [ ] Implement
- [ ] Test (Vitest on the section; `tests/e2e/payments-review.spec.ts`)

### Task 5: Refuse an unknown provider
- **spec_ref**: openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-an-unknown-provider-value-is-refused-not-run-as-the-stub-req-prv-004
- **files**: `lib/Service/PaymentIntentService.php` (`resolveProvider()`)
- **acceptance_criteria**:
  - GIVEN provider `Mollie` WHEN a payment is created THEN a `PaymentProviderException` names the value and nothing is stored
  - GIVEN no provider value WHEN a payment is created THEN the log binding runs
- [ ] Implement
- [ ] Test (PHPUnit on `createPayment()` and `handleWebhook()`)

### Task 6: Close `payment_intent` and seed three payments
- **spec_ref**: openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-is-readable-only-by-administrators-and-its-owner-req-prv-005
- **files**: `lib/Settings/register.d/99-payment-intent-lockdown.json`, `lib/Settings/integriq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the imported register WHEN a non-admin lists `payment_intent` THEN nothing is returned
  - GIVEN demo data WHEN the page opens THEN a paid, an open and an expired payment are listed
- [ ] Implement
- [ ] Test (`tests/validate-register.js`; integration test on the imported schema)

## Verification

- `openspec validate messaging-payments-review --type change --strict`
- On a local instance: switch the seeded source to Mollie with a test key,
  create a payment, pay it in Mollie's test checkout, refresh it, and read
  `captured` on the page.
- `composer check:strict` and `npm run lint` once before push.
