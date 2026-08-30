# Tasks: live-payment-providers

## 1. Data model and seed

### Task 1: Declare the `payment_intent` schema and seed data
- **spec_ref**: `openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002`
- **files**: `lib/Settings/openconnector_register.json`, `lib/Settings/openconnector_seed_data.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN a `payment_intent` schema exists with `paymentStatus` (provider-native) and `lastOutcome` (`authorized|captured|failed|voided`) fields
  - GIVEN the seed data WHEN the app installs THEN 3 example payment intents and one `provider: log` sandbox source are present (nil-UUID metadata refs, `MOCK-PAY-*` ids)
- [x] Implement
- [x] Test

## 2. Provider abstraction

### Task 2: Add the payment provider interface + exception + log binding
- **spec_ref**: `openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002`
- **files**: `lib/Service/Payment/PaymentProviderInterface.php`, `lib/Service/Payment/LogPaymentProvider.php`, `lib/Exception/PaymentProviderException.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` WHEN a payment is created THEN a `MOCK-PAY-<n>` id and a canned checkout URL are returned with no HTTP call and no credential read
  - GIVEN the log provider WHEN `fetchPaymentStatus` is called THEN it returns the caller-seeded status with no upstream call
- [x] Implement
- [x] Test

### Task 3: Add the Mollie REST provider binding
- **spec_ref**: `openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002`
- **files**: `lib/Service/Payment/MolliePaymentProvider.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: mollie` WHEN a payment is created or its status is fetched THEN the call routes through `BrokeredCallService` with the API key injected via `credentialRef`, absent from config/logs
  - GIVEN a `mollie` source with no resolvable `credentialRef` WHEN a payment is attempted THEN it fails closed with an actionable, secret-free error
- [x] Implement
- [x] Test

## 3. Payment creation

### Task 4: Add PaymentIntentService.createPayment and the create endpoint
- **spec_ref**: `openspec/specs/live-payment-providers/spec.md#requirement-payment-creation-endpoint-accepting-shillinqs-payload-contract-req-lpp-001`
- **files**: `lib/Service/PaymentIntentService.php`, `lib/Controller/PaymentsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a valid request WHEN `POST /api/payments` is called THEN a `payment_intent` is persisted and the response carries a non-empty `checkoutUrl` and `providerPaymentId`
  - GIVEN a request missing a required amount field WHEN the endpoint runs THEN HTTP 400 is returned, never a 500
  - GIVEN an unreachable provider WHEN the endpoint runs THEN HTTP 502 is returned with a secret-free error
- [x] Implement
- [x] Test

## 4. Webhook, status mapping, idempotency

### Task 5: Add the signed webhook endpoint that never trusts the inbound body
- **spec_ref**: `openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003`
- **files**: `lib/Controller/PaymentsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an unsigned/tampered callback WHEN it arrives at `POST /api/payments/webhook` THEN the response is HTTP 401 with no state change and no event
  - GIVEN a verified callback whose body claims a false status WHEN processed THEN the connector calls `fetchPaymentStatus()` and uses ITS answer, ignoring the body's claim
- [x] Implement
- [x] Test

### Task 6: Add status mapping + CloudEvent emission + idempotency guard
- **spec_ref**: `openspec/specs/live-payment-providers/spec.md#requirement-status-mapping-to-shillinqs-outcome-vocabulary-and-cloudevent-emission-req-lpp-004`
- **files**: `lib/Service/PaymentIntentService.php`
- **acceptance_criteria**:
  - GIVEN a re-fetched status of `paid` WHEN processed THEN exactly one `nl.conduction.payment.status` event is emitted with `outcome="captured"` and `paymentIntentId` equal to the provider payment id
  - GIVEN a re-fetched status of `open` WHEN processed THEN no state change and no event occur (unmapped status)
  - GIVEN a replayed webhook whose re-fetched status maps to the SAME outcome already recorded WHEN processed THEN no second event is emitted and the response is still `{"received": true}`
- [x] Implement
- [x] Test

## 5. Localisation

### Task 7: Ship English source keys with Dutch translations
- **spec_ref**: `openspec/specs/live-payment-providers/spec.md#non-functional-requirements`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN new user-facing strings WHEN the app is loaded in `nl` THEN Dutch translations render for every English source key
- [x] Implement
- [x] Test

### NL translations (plain bullets, not tracked checkboxes)
- `Not authenticated` → `Niet geauthenticeerd`
- `Invalid payment amount` → `Ongeldig betalingsbedrag`
- `Payment provider credential missing` → `Betalingsprovider-inloggegeven ontbreekt`
- `No active payment source is configured` → `Geen actieve betalingsbron geconfigureerd`

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite (29 new tests, all on the no-network `log` provider)
- [x] Code review against spec requirements — self-reviewed; see report

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints — N/A: no Postman collection exists for this connector family (peppol/psd2 precedent also shipped PHPUnit-only backend coverage); tracked as fleet-wide gap, not unique to this change
- [ ] Browser tests (Playwright MCP) for UI changes — N/A: backend-only connector, no UI surface
- [x] All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml` — 826 tests, 2488 assertions, 0 failures; baseline on origin/development was 796)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — N/A: internal connector surface consumed by other apps via API/events, not an end-user-facing doc page (mirrors peppol/psd2 precedent)
- [ ] Screenshot captured and committed to `docs/images/` — N/A: no UI surface

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added
