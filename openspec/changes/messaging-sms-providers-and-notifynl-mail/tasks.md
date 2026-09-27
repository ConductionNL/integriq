# Tasks: messaging-sms-providers-and-notifynl-mail

Kind: code. Size M. Rows `integriq:msg-sms` and `integriq:nl-notifynl`.

### Task 1: The provider registry and the refusal of unknown values
- **spec_ref**: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
- **files**: `lib/Service/Sms/SmsProviderRegistry.php`, `lib/Service/SmsDispatchService.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN provider `twillio` WHEN a message is sent THEN it is stored `failed` naming the value
  - GIVEN no provider value WHEN a message is sent THEN the log binding runs
- [ ] Implement
- [ ] Test (PHPUnit on `resolveProvider()` against the real registry)

### Task 2: The CM.com binding
- **spec_ref**: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
- **files**: `lib/Service/Sms/CmComSmsProvider.php`
- **acceptance_criteria**:
  - GIVEN a CM.com source with a brokered product token WHEN a message is sent THEN one `POST /message` leaves through the broker and the reference is stored
  - GIVEN no `credentialRef` WHEN a message is sent THEN nothing leaves and the reason names the credential
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed `BrokeredCallService`)

### Task 3: The MessageBird binding
- **spec_ref**: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
- **files**: `lib/Service/Sms/MessageBirdSmsProvider.php`
- **acceptance_criteria**:
  - GIVEN a MessageBird source WHEN a message is sent and then polled THEN the send and the status read both go through the broker and the status is mapped
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed `BrokeredCallService`)

### Task 4: The Twilio binding
- **spec_ref**: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
- **files**: `lib/Service/Sms/TwilioSmsProvider.php`
- **acceptance_criteria**:
  - GIVEN a Twilio source with an account SID and a brokered Basic value WHEN a message is sent THEN it posts to the account's `Messages.json` and stores the SID
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed `BrokeredCallService`)

### Task 5: Source choice and the provider picker
- **spec_ref**: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
- **files**: `lib/Service/SmsDispatchService.php`, `lib/Controller/NotifyNlController.php`, `lib/Controller/SmsProvidersController.php`, `appinfo/routes.php`, `src/modals/v2/SourceFormFields.vue`, `lib/Settings/integriq_register.json` (`sms_message.sourceSlug`, version 1.1.0)
- **acceptance_criteria**:
  - GIVEN a `sourceSlug` of an enabled SMS source WHEN a message is sent THEN that source sends and its slug is stored
  - GIVEN a slug of a non-SMS source WHEN a message is sent THEN it is refused
  - GIVEN a new SMS source WHEN the form opens THEN the provider picker lists the five providers
- [ ] Implement
- [ ] Test (PHPUnit on the dispatcher; `tests/e2e/sms-providers.spec.ts`)

### Task 6: Vendor delivery callbacks
- **spec_ref**: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
- **files**: `lib/Controller/SmsStatusController.php`, `appinfo/routes.php`, the three bindings (signature checks), `lib/Service/SmsDispatchService.php`
- **acceptance_criteria**:
  - GIVEN a Twilio callback with the right token and a valid signature WHEN it arrives THEN the message becomes `delivered`
  - GIVEN a wrong token WHEN a callback arrives THEN it is refused before the body is read
- [ ] Implement
- [ ] Test (PHPUnit on the controller; `tests/e2e/sms-providers.spec.ts` posts a signed fixture)

### Task 7: NotifyNL e-mail
- **spec_ref**: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
- **files**: `lib/Service/Sms/RestNotifyNlProvider.php` (`sendEmail()`), `lib/Controller/NotifyNlController.php` (`sendEmail()`, `inbound()`), `appinfo/routes.php`, `lib/Outbound/MessageRecorder.php` (caller only)
- **acceptance_criteria**:
  - GIVEN a mapped mail template WHEN a caller posts an e-mail THEN NotifyNL gets `/v2/notifications/email` and the outbound log shows it handed over
  - GIVEN a NotifyNL callback for that id WHEN it arrives THEN the outbound record shows the recipient delivered
- [ ] Implement
- [ ] Test (PHPUnit on the provider and the controller; `tests/e2e/sms-providers.spec.ts`)

### Task 8: Demo rows
- **spec_ref**: openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
- **files**: `lib/Settings/integriq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN demo data WHEN the SMS page opens THEN one message per new provider is listed in the states sent, delivered and failed
- [ ] Implement
- [ ] Test (`tests/validate-register.js`)

## Verification

- `openspec validate messaging-sms-providers-and-notifynl-mail --type change --strict`
- One live send per vendor from a test account, recorded in the PR body.
- `composer check:strict` and `npm run lint` once before push.
