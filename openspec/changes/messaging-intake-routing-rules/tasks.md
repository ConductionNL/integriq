# Tasks: messaging-intake-routing-rules

Kind: code. Size M. Rows `dossiq:1.9`, `integriq:msg-routing`,
`integriq:msg-teams`, `integriq:msg-form-intake` and
`integriq:msg-public-space`.

### Task 1: The rule editor dialog on the intake routing page
- **spec_ref**: openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
- **files**: `src/manifest.d/intake-channels.json`, `src/modals/v2/IntakeRuleEditorModal.vue`, `src/registry.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the "Intake routing" page WHEN an administrator adds a rule for `teams` with a case type THEN it is saved through `POST /api/intake/routing-rules` and listed
  - GIVEN a mapping onto a field the case type lacks WHEN the administrator saves THEN the dialog stays open and shows the refusal on that field
  - GIVEN the channel select WHEN it opens THEN it lists only what `GET /api/intake/channels` returns
- [x] Implement
  - `IntakeRoutingRules` is `type: index` with `slots.form-dialog: IntakeRuleEditorModal`; the dialog saves through `saveRule()` (`src/modals/v2/intakeRuleApi.js`) and maps a refusal onto its field (`src/modals/v2/intakeRuleDraft.js`).
- [ ] Test (Vitest done in `tests/vitest/intakeRuleDraft.spec.js` and `tests/vitest/intakeRoutingEditor.spec.js`; e2e left. Vitest on the dialog payload and error mapping; `tests/e2e/intake-routing-rules.spec.ts`)

### Task 2: Reorder, enable and delete from the list
- **spec_ref**: openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-administrator-writes-a-routing-rule-on-the-intake-routing-page-req-ire-001
- **files**: `src/manifest.d/intake-channels.json`, `src/modals/v2/IntakeRuleEditorModal.vue`
- **acceptance_criteria**:
  - GIVEN two rules on one channel WHEN an administrator moves the second above the first THEN both `order` values are written through `saveRule()` and the first match changes
  - GIVEN an enabled rule WHEN the administrator disables it THEN `firstMatchingRule()` no longer returns it
- [x] Implement
  - Move up, Move down, Enable and Disable row actions (`src/handlers/intakeRuleHandlers.js`) write through `saveRule()`; delete is the built-in action, copy is off.
- [ ] Test (Vitest on the handlers done in `tests/vitest/intakeRoutingEditor.spec.js`; e2e left. `tests/e2e/intake-routing-rules.spec.ts`)

### Task 3: Test a draft rule
- **spec_ref**: openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-draft-rule-is-tested-against-a-sample-message-without-side-effects-req-ire-002
- **files**: `lib/Controller/IntakeChannelsController.php` (`testRule()`), `appinfo/routes.php`, `src/modals/v2/IntakeRuleEditorModal.vue`
- **acceptance_criteria**:
  - GIVEN a draft rule and a sample message WHEN the test runs THEN the response says whether it matches, shows the target payload and names the saved rule that wins today
  - GIVEN any test WHEN it completes THEN no object is written and no `IntakeMessageRoutedEvent` is dispatched
- [x] Implement
  - Server half: `POST /api/intake/routing-rules/test` on `IntakeChannelsController::testRule()`. Editor half: the "Test this rule" section of `IntakeRuleEditorModal`, with typed text or a held message as the sample.
- [ ] Test (PHPUnit done in `tests/Unit/Controller/IntakeRuleTestAndRouteAgainTest.php`; e2e left. PHPUnit on `testRule()` asserting no `saveObject` and no `dispatchTyped` call; e2e in `tests/e2e/intake-routing-rules.spec.ts`)

### Task 4: Keep a held message's files
- **spec_ref**: openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
- **files**: `lib/Intake/IntakeRoutingService.php` (`hold()`), `lib/Intake/InboundMessage.php`
- **acceptance_criteria**:
  - GIVEN a message with a photo that matches no rule WHEN it is held THEN the photo is stored as a file on the held `intake_message`
  - GIVEN a message that routes on arrival WHEN it is routed THEN no file is stored on the `intake_message`
- [x] Implement
- [x] Test (PHPUnit on `hold()` against the real `InboundMessage`)

### Task 5: Route a held message again
- **spec_ref**: openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-a-held-message-is-routed-again-on-request-with-its-files-req-ire-003
- **files**: `lib/Intake/IntakeRoutingService.php` (`reroute()`), `lib/Controller/IntakeChannelsController.php` (`routeAgain()`), `appinfo/routes.php`, `src/manifest.d/intake-channels.json`, `src/handlers/actionHandlers.js`
- **acceptance_criteria**:
  - GIVEN a held report and a matching rule added since WHEN an administrator chooses "Route again" THEN the same `intake_message` becomes `routed` with the rule name and the case reference
  - GIVEN a selection of three held messages, one of which still matches nothing WHEN they are routed in bulk THEN the response answers each one and two are routed
  - GIVEN a saved rule WHEN nobody chooses "Route again" THEN held messages stay held
- [x] Implement
  - Server half: `IntakeRoutingService::reroute()`, `POST /api/intake/messages/{id}/route` and `POST /api/intake/messages/route`. Inbox half: `IntakeMessageRowActions` (row, held and failed only) and `IntakeMessageActions` with `IntakeRouteAgainModal` (a selection, answered per message). The inbox stays a log page, so the action files sit in `src/components/intake/` and `src/modals/Intake/` rather than `src/handlers/actionHandlers.js`.
- [ ] Test (PHPUnit done in `tests/Unit/Intake/IntakeRerouteTest.php`; e2e left. PHPUnit on `reroute()` with files read back; `tests/e2e/intake-routing-rules.spec.ts`)

### Task 6: The Outlook add-in manifest and task pane
- **spec_ref**: openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-an-outlook-user-sends-the-open-message-to-intake-from-a-task-pane-req-ire-004
- **files**: `lib/Controller/OutlookAddinController.php`, `appinfo/routes.php`, `templates/outlook-pane.php`, `src/outlook/pane.js`, `lib/Settings/IntegriqAdmin.php` (the `outlook_intake_source` setting), `webpack.config.js`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they download the manifest THEN its task pane URL is this instance's `/outlook/pane`
  - GIVEN a signed-in pane and a mail open in Outlook WHEN the user chooses "Send to intake" THEN the EML is posted to `/api/mail-intake/import` under the configured source and the pane shows linked, new case or unassigned
  - GIVEN a user who may not import mail WHEN they send THEN the pane explains that an administrator must grant mail import
- [ ] Implement
- [ ] Test (PHPUnit on the controller and the CSP it sets; a manual check in Outlook on the web recorded in the PR body)

### Task 7: Seed rules that work
- **spec_ref**: openspec/changes/messaging-intake-routing-rules/specs/intake-channels/spec.md#requirement-the-seeded-routing-rules-name-channels-integriq-has-req-ire-005
- **files**: `lib/Settings/integriq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN demo data loaded WHEN an administrator opens "Intake routing" THEN three rules name `teams`, `public-space-report` and `form-submission`, and one of them is disabled
- [x] Implement
- [ ] Test (`tests/validate-register.js` and `tests/Unit/Settings/SeededRoutingRulesTest.php` pass; e2e left. `tests/validate-register.js`; `tests/e2e/intake-routing-rules.spec.ts`)

### Task 8: Import a saved .eml or .msg file from the inbox page
- **spec_ref**: openspec/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002
- **files**: `src/manifest.d/mail-intake.json`, `src/manifest.d/intake-channels.json`, `src/handlers/actionHandlers.js`, `src/modals/ImportMailFileModal.vue`
- **acceptance_criteria**:
  - GIVEN a handler with the mail import right on the inbox page WHEN they choose "Import file (.eml or .msg)" and pick a saved Outlook message THEN it is posted to `/api/mail-intake/import` under the chosen mailbox source and the new message appears in the list
  - GIVEN a file that does not parse WHEN it is imported THEN the dialog names the reason and the stored failure is listed
  - GIVEN a user without the right WHEN they open the inbox THEN the import action is not offered
- [ ] Implement
- [ ] Test (`tests/e2e/mail-intake-import.spec.ts`)

## Verification

- `openspec validate messaging-intake-routing-rules --type change --strict`
- A Teams message and a public space report each open a case on a local
  instance with dossiq installed, after a rule is written on the page.
- `composer check:strict` and `npm run lint` once before push.
