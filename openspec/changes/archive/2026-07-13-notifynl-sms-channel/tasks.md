# Tasks — notifynl-sms-channel

## 1. Data model

### Task 1: Declare the `sms_message` schema
- **spec_ref**: `openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-generic-sms-provider-contract-req-001`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN an `sms_message` schema exists with the `status` enum `queued|sent|delivered|failed` and an `x-openregister-notifications` block firing on `failed`
  - GIVEN the register's schemas list WHEN compared to `components.schemas` THEN every declared schema slug (including `sms_message`) is listed
- [x] Implement
- [x] Test

## 2. Provider abstraction

### Task 2: Add the SMS provider interface with log and NotifyNL REST bindings
- **spec_ref**: `openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002`
- **files**: `lib/Service/Sms/SmsProviderInterface.php`, `lib/Service/Sms/DeliveryResult.php`, `lib/Service/Sms/LogSmsProvider.php`, `lib/Service/Sms/RestNotifyNlProvider.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` WHEN a message is sent THEN a `MOCK-SMS-<n>` id is returned with no HTTP call and no credential read
  - GIVEN `configuration.provider: notifynl` WHEN a message is sent THEN a fresh HS256 JWT is signed per request and posted as `Authorization: Bearer <jwt>`
- [x] Implement
- [x] Test

### Task 3: Add the pure E.164 phone number validator
- **spec_ref**: `openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-e164-phone-validation-with-nl-default-region-req-005`
- **files**: `lib/Service/Sms/PhoneNumberValidator.php`
- **acceptance_criteria**:
  - GIVEN a national NL number (`0612345678`) WHEN normalised THEN the result is `+31612345678`
  - GIVEN a candidate that cannot be normalised WHEN validated THEN `toE164()` returns null
- [x] Implement
- [x] Test

## 3. Dispatch orchestration

### Task 4: Add SmsDispatchService (send, poll, callback lifecycle)
- **spec_ref**: `openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-delivery-status-polling-and-callback-req-007`
- **files**: `lib/Service/SmsDispatchService.php`, `lib/Exception/SmsProviderException.php`
- **acceptance_criteria**:
  - GIVEN a valid recipient and an active SMS source WHEN `sendMessage()` runs THEN an `sms_message` moves `queued`→(provider status) and one delivery-status CloudEvent is emitted
  - GIVEN a provider exception WHEN `sendMessage()` runs THEN the message is persisted `status=failed` (never an unhandled throw out of the method)
  - GIVEN a redelivered inbound callback for an unknown `providerMessageId` WHEN processed THEN it is logged and returns null, never a crash
- [x] Implement
- [x] Test

## 4. REST surface

### Task 5: Add NotifyNlController (send, status, inbound) + routes
- **spec_ref**: `openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-send-endpoint-consumable-by-sibling-apps-req-006`
- **files**: `lib/Controller/NotifyNlController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated session WHEN `POST /api/notifynl/messages` is called with `{to, templateId}` THEN the created `sms_message` is returned
  - GIVEN an unsigned inbound callback WHEN `POST /api/notifynl/inbound` receives it THEN HTTP 401 is returned with no state change
- [x] Implement
- [x] Test

## 5. Pre-existing gap fix (encountered while editing the register file)

### Task 6: Add `bankfeed_connection`/`bankfeed_batch` to the register's schemas list
- **spec_ref**: N/A — pre-existing gap unrelated to this capability's requirements, fixed per house convention (`peppol_transmission` had the same gap, fixed alongside `lti-13-platform`)
- **files**: `lib/Settings/openconnector_register.json`, `tests/Unit/Settings/RegisterDescriptorTest.php`
- **acceptance_criteria**:
  - GIVEN the register's schemas list WHEN compared to `components.schemas` THEN `bankfeed_connection` and `bankfeed_batch` are both listed
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [ ] `openspec validate` passes (CLI availability checked at implementation time)
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite (52 new tests: PhoneNumberValidatorTest, LogSmsProviderTest, RestNotifyNlProviderTest, SmsDispatchServiceTest, NotifyNlControllerTest; full suite 761/761 green after fixing RegisterDescriptorTest's schema-slug list)
- [x] Code review against spec requirements — self-reviewed; see Deviations below

## Deviations

- **Credential storage does not use `credentialRef`/`BrokeredCallService`
  (REQ-004).** Verified against OpenRegister's `CredentialBrokerService::
  injectAuth()` at HEAD, the broker's auth injection is a static
  single-placeholder substitution that discards any caller-supplied
  `Authorization` header — it cannot compute NotifyNL's required per-request
  HS256 JWT (payload includes a fresh `iat` timestamp). The NotifyNL API key
  is instead stored `configuration.authentication.encryptedApiKey`, encrypted
  at rest via `OCP\Security\ICrypto`, decrypted in-process only to sign each
  request's JWT. Full analysis in design.md "Credential storage: why not
  `credentialRef`".
- **No event-driven outbound path (unlike Peppol's `PeppolOutboundConsumer`).**
  NotifyNL's send is a fast, synchronous request/response; sibling apps need
  the `sms_message` id back immediately, so `sendMessage()` is called directly
  from `NotifyNlController::send()`, not triggered by a consumed CloudEvent.
  `nl.conduction.sms.delivery.status` is still emitted on every status change.
- **No dedicated `SmsStatusPollJob` cron (unlike PSD2's `BankfeedSyncJob`).**
  Deferred — NotifyNL's inbound webhook (`POST /api/notifynl/inbound`) is the
  primary status path; on-demand polling (`GET /api/notifynl/messages/{id}`)
  covers the "check now" case. A scheduled sweep can be added later without
  changing `SmsProviderInterface` or `SmsDispatchService`.
- **`source.type = 'sms'`** was added as a new recognised (free-form, per the
  schema's own documented extensibility) value, alongside `peppol`/`psd2`.
- **`bankfeed_connection`/`bankfeed_batch` register-list gap fixed in
  passing** (Task 6) — encountered while editing the same JSON file; not
  itself part of this capability but a genuine pre-existing bug (same class
  already documented as fixed for `peppol_transmission`).
