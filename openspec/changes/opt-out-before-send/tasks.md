# Tasks: opt-out-before-send (integriq)

Spec only until Ruben approves ConductionNL/hydra#739. Build in this order. The tasks of `outbound-sender-identity-and-deliverability` stay as they are: 4.2 and 5.1 stay ticked (Ruben, 2026-10-05).

## 1. The decision

- [ ] 1.1 Deduplication check: confirm no second opt-out reader exists (`git grep -n "OptOutMapper\|recipient_opt_out" lib/`) and that `PhoneNumberValidator::toE164()` is the only phone normaliser used.
  - files: none
  - acceptance: the PR body lists the hits.
- [ ] 1.2 `OptOutMapper::findForAddresses()` and `OptOutRegistry::decideMany()`, with the rules in design section 1. `decide()` wraps it.
  - spec_ref: `specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002`
  - files: `lib/Db/OptOutMapper.php`, `lib/Outbound/Identity/OptOutRegistry.php`, `tests/Unit/Outbound/Identity/OptOutRegistryTest.php`
  - acceptance: a batch of five with two opt-outs gives two `opted-out` and three `allowed`. Red before.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutRegistryTest`
- [ ] 1.3 The fixed exempt floor and the aliases. Unknown categories read as `service`.
  - spec_ref: `#requirement-the-exempt-categories-are-a-fixed-floor-req-ooa-004`
  - files: `lib/Outbound/Identity/OptOutRegistry.php`, its test
  - acceptance: both config scenarios pass. Red before.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutRegistryTest`
- [ ] 1.4 Consent rules for `requiresConsent`.
  - spec_ref: `#requirement-marketing-needs-recorded-consent-req-ooa-005`
  - files: `lib/Outbound/Identity/OptOutRegistry.php`, its test
  - acceptance: list and channel are separate gates. `imported` refuses. `soft-opt-in` needs the objection.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutRegistryTest`

## 2. The table and the log

- [ ] 2.1 Migration for the new columns and the `contact_ref` index. The dedupe key keeps old rows' keys.
  - spec_ref: `#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003`
  - files: `lib/Migration/Version2Date2026100xxxxxxx.php`, `lib/Db/OptOut.php`
  - acceptance: an existing row's dedupe key is unchanged after the migration.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutTest`
- [ ] 2.2 Migration and mapper for `integriq_opt_out_log`. Admin-only read.
  - spec_ref: `#requirement-suppressions-and-overrides-are-logged-req-ooa-007`
  - files: `lib/Migration/`, `lib/Db/OptOutLogMapper.php`, `lib/Db/OptOutLogEntry.php`
  - acceptance: a batch of 500 with one suppression writes two rows.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutLog`

## 3. The events

- [ ] 3.1 `OutboundSendDecisionRequestedEvent` and its listener. Registered in `Application.php`.
  - spec_ref: `#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002`
  - files: `lib/Event/OutboundSendDecisionRequestedEvent.php`, `lib/EventListener/OutboundSendDecisionRequestedListener.php`, `lib/AppInfo/Application.php`
  - acceptance: the test constructs the real event, dispatches it through a real dispatcher, and reads the slot. A listener failure leaves it unhandled.
  - test: `vendor/bin/phpunit --no-coverage --filter OutboundSendDecision`
- [ ] 3.2 `OptOutChangeRequestedEvent`, its listener and `OptOutRegistry::record()`. `legacyRef` makes it idempotent.
  - spec_ref: `#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003`
  - files: `lib/Event/OptOutChangeRequestedEvent.php`, `lib/EventListener/OptOutChangeRequestedListener.php`
  - acceptance: STOP then START gives one row reading `opted-in` and two log entries.
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutChange`
- [ ] 3.3 `DigitalPostSendRequestedEvent` gains an optional `category`. Default `service`.
  - files: `lib/Event/DigitalPostSendRequestedEvent.php`
  - acceptance: an event built without a category still constructs (dossiq's current call).
  - test: `vendor/bin/phpunit --no-coverage --filter DigitalPost`

## 4. integriq's own senders

- [ ] 4.1 NotifyNL SMS asks before the provider send. POST `/api/notifynl/messages` takes an optional `category`.
  - spec_ref: `#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001`
  - files: `lib/Service/SmsDispatchService.php`, `lib/Controller/NotifyNlController.php`
  - acceptance: an opted-out number gives 409 `opted-out` and no provider call.
  - test: `vendor/bin/phpunit --no-coverage --filter SmsDispatch`
- [ ] 4.2 Intake reply asks before the adapter.
  - files: `lib/Intake/IntakeReplyService.php`
  - test: `vendor/bin/phpunit --no-coverage --filter IntakeReply`
- [ ] 4.3 Digital post asks after the source checks, before the provider.
  - files: `lib/Service/DigitalPost/DigitalPostService.php`
  - acceptance: a `case-update` is refused, a `besluit` is sent with an override entry.
  - test: `vendor/bin/phpunit --no-coverage --filter DigitalPostService`
- [ ] 4.4 Delivery ingest asks when the payload names a personal recipient.
  - files: `lib/Service/EventService.php`
  - test: `vendor/bin/phpunit --no-coverage --filter EventService`
- [ ] 4.5 Every send above composes through `MessageComposer` and starts a `MessageRecorder` row.
  - files: the four senders
  - acceptance: `git grep -n "messageComposer->compose\|recorder->start" lib/` shows a caller in each sender.

## 5. The link

- [ ] 5.1 Version 3 tokens. `linkFor()` returns the material structure.
  - spec_ref: `#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006`
  - files: `lib/Outbound/Identity/UnsubscribeTokenService.php`, `lib/Outbound/Identity/MessageComposer.php`
  - test: `vendor/bin/phpunit --no-coverage --filter UnsubscribeLinkTest`
- [ ] 5.2 GET confirms, POST writes, 200 without redirect.
  - files: `lib/Controller/SenderIdentityController.php`, `appinfo/routes.php`, `templates/`
  - acceptance: a GET leaves the table unchanged. Version 2 links still stop a case.
  - test: `vendor/bin/phpunit --no-coverage --filter SenderIdentityController`
- [ ] 5.3 Short SMS link table and `/u/{id}`.
  - files: `lib/Migration/`, `lib/Db/`, `lib/Controller/SenderIdentityController.php`, `appinfo/routes.php`
  - acceptance: `smsText` is at most 50 characters.
- [ ] 5.4 Hash BSN recipients for digital post (approved by Ruben 2026-10-05).
  - spec_ref: `#requirement-a-digital-post-recipient-is-never-stored-as-a-plain-bsn-req-ooa-008`
  - files: `lib/Outbound/Identity/OptOutRegistry.php`
- [ ] 5.5 English and Dutch strings for the confirmation page in `l10n/en.json` and `l10n/nl.json`, then `npm run l10n:build`.
  - test: `npm run test:l10n`

## 6. Decisions from 2026-10-05

- [ ] 6.0a `reply` with `inReplyTo` passes an opt-out. The intake reply sets it.
  - spec_ref: `specs/outbound-opt-out-authority/spec.md#requirement-a-direct-reply-to-a-citizen-s-message-passes-an-opt-out-req-ooa-009`
  - files: `lib/Outbound/Identity/OptOutRegistry.php`, `lib/Intake/IntakeReplyService.php`
  - test: `vendor/bin/phpunit --no-coverage --filter "OptOutRegistryTest|IntakeReply"`
- [ ] 6.0b Retention job: delete `integriq_opt_out_log` entries older than 7 years.
  - spec_ref: `#requirement-suppressions-and-overrides-are-logged-req-ooa-007`
  - files: `lib/BackgroundJob/OptOutLogRetentionJob.php`, `appinfo/info.xml`
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutLogRetentionJob`
- [ ] 6.0c `erase-contact` on `OptOutChangeRequestedEvent`: clear `contact_ref` and `evidence`, keep the opt-out.
  - spec_ref: `#requirement-contact-erasure-keeps-the-opt-out-req-ooa-010`
  - files: `lib/Outbound/Identity/OptOutRegistry.php`, `lib/EventListener/OptOutChangeRequestedListener.php`
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutChange`

## 7. Verify

- [ ] 7.1 Measure `decideMany()` with 500 recipients on the development instance. Record the number here.
- [ ] 7.2 Live check on a throwaway instance: unsubscribe through the link, then send an SMS and a digital post `case-update` to the same person. Both are refused. A `besluit` goes out.
- [ ] 7.3 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` once, then `npm run lint`.
- [ ] 7.4 The build PR says "Closes #2167".
