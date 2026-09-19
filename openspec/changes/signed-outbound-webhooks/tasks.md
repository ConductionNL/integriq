# Tasks: signed-outbound-webhooks

## Implementation tasks

### Task 1: A push subscription is created with a signing secret
- **spec_ref**: `openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001`
- **files**: `lib/Service/SubscriptionService.php`, `lib/Controller/EventsController.php`, `lib/Service/WebhookSignatureService.php`
- [ ] Implement (generate at create, reveal once, redact on every later read; no migration of existing subscriptions)
- [ ] Test

### Task 2: Unsigned is an explicit choice with a reason
- **spec_ref**: `openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-a-push-subscription-is-signed-unless-somebody-says-otherwise-req-sow-001`
- **files**: `lib/Service/SubscriptionService.php`, the subscription schema in `lib/Settings/`
- [ ] Implement (`protocolSettings.unsigned` with `reason`, `by` and `at`; refused at save without a reason)
- [ ] Test

### Task 3: The verification recipe on the subscription page
- **spec_ref**: `openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-the-subscription-page-states-what-a-receiver-must-compute-req-sow-002`
- **files**: the subscription edit modal and detail view, Dutch and English strings
- [ ] Implement
- [ ] Test

### Task 4: Unsigned is visible in the list and in the delivery log
- **spec_ref**: `openspec/changes/signed-outbound-webhooks/specs/webhook-signing/spec.md#requirement-an-unsigned-subscription-and-an-unsigned-attempt-are-marked-req-sow-003`
- **files**: the subscription list view, `lib/Service/EventService.php` delivery logging
- [ ] Implement
- [ ] Test

## Verification

- [ ] `openspec validate signed-outbound-webhooks --strict` passes
- [ ] PHPUnit run in the container, exit code read rather than the summary line
- [ ] A log assertion that no secret value appears in any list or detail response, in the delivery log, or in a configuration export

## Register follow-up

- [ ] Re-point row Q6.20 at `integriq/openspec/specs/webhook-signing/` rather than `events-cloudevents`: the outbound signing it asks for is specified and implemented there, and only the default was missing
