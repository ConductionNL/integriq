# Tasks: signed-outbound-webhooks

## Implementation tasks

### Task 1: A push subscription is created with a signing secret
- **spec_ref**: `openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001`
- **files**: `lib/EventListener/SubscriptionSigningDefaultListener.php` (there is no SubscriptionService; see design D1), `lib/Service/Subscriptions/SubscriptionSigningPolicy.php`, `lib/Controller/EventsController.php`, `lib/AppInfo/Application.php`
- [x] Implement (generate at create, reveal once on the app's create route, redact on every later read; no migration of existing subscriptions)
- [x] Test: `SubscriptionSigningDefaultListenerTest` (7, real OpenRegister events, stored payload validated against the merged register), `EventsControllerTest::testSubscribeRevealsTheGeneratedSecretOnce`, `::testSubscribeDoesNotEchoASuppliedSecret`, `::testGeneratingASecretEndsAnUnsignedDecision`

### Task 2: Unsigned is an explicit choice with a reason
- **spec_ref**: `openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001`
- **files**: `lib/EventListener/SubscriptionSigningDefaultListener.php`, `lib/Settings/integriq_register.json` (event_subscription 1.4.0)
- [x] Implement (`protocolSettings.unsigned` with `reason`, `setBy` and `setAt`; refused at save without a reason, on create and on update)
- [x] Test: `SubscriptionSigningDefaultListenerTest::testUnsignedWithoutAReasonIsRefusedAtCreate`, `::testUnsignedWithAReasonIsRecordedByName`, `::testUnsignedWithoutAReasonIsRefusedAtUpdate`

### Task 3: The verification recipe on the subscription page
- **spec_ref**: `openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-the-subscription-page-states-what-a-receiver-must-compute-req-sow-002`
- **files**: `src/modals/Subscription/SubscriptionSigningModal.vue`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement (the recipe is always shown in the Webhook signing modal, whether or not a secret is revealed)
- [x] Test: `tests/vitest/subscriptionSigningRecipe.spec.js` (4)

### Task 4: Unsigned is visible in the list and in the delivery log
- **spec_ref**: `openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-an-unsigned-subscription-and-an-unsigned-attempt-are-marked-req-sow-003`
- **files**: `src/manifest.json` (Webhooks column `signingPosture`), `lib/Service/EventService.php` delivery logging, `event_message` 1.2.0 (`attempts[].signed`)
- [x] Implement
- [x] Test: `EventServiceTest::testASignedAttemptRecordsThatItWasSigned`, `::testAnUnsignedSubscriptionSendsNoSignatureAndTheAttemptSaysSo` (payload validated against the merged event_message schema), `tests/e2e/signed-outbound-webhooks.spec.ts` (list)

## Verification

- [x] `openspec validate signed-outbound-webhooks --strict` passes
- [x] PHPUnit run (host, `composer check:strict` test:all), exit code read rather than the summary line
- [x] No secret value in a list or detail response (writeOnly `protocolSettings`; `testSubscribeRevealsTheGeneratedSecretOnce` asserts nothing but `signingSecret` carries it; e2e reads back through the object API) or in the delivery log (`attempts[]` holds `signed`, never a value). A configuration export is not covered here.

## Register follow-up

- [ ] Re-point row Q6.20 at `integriq/openspec/specs/webhook-signing/` rather than `events-cloudevents`: the outbound signing it asks for is specified and implemented there, and only the default was missing
