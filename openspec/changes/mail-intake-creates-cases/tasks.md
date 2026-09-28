# Tasks: mail-intake-creates-cases

## Implementation tasks

### Task 1: `mailbox` source type and `message` schema
- **spec_ref**: `openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-mailbox-is-a-source-and-a-message-is-an-object-req-mail-001`
- **files**: `lib/Settings/integriq_register.json`, `lib/Service/Mail/MailboxSourceHandler.php`
- [x] Implement
- [x] Test (mock-mode fixture with three messages, idempotent re-poll)
- [x] Schedule the poll (build-all pass 2026-09-28): the tick above did not hold, because nothing called `poll()` but the manual endpoint. `MailboxPollJob` (every five minutes, registered in `appinfo/info.xml`) calls `MailboxSourceHandler::pollAll()`, which polls every enabled mailbox source in system context and isolates a failing one. Test: `tests/Unit/BackgroundJob/MailboxPollJobTest.php`, red before the job existed.

### Task 2: Import endpoint for `.eml` and `.msg`
- **spec_ref**: `openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-eml-and-msg-files-import-into-the-same-message-shape-req-mail-002`
- **files**: `lib/Controller/MailIntakeController.php`, `lib/Service/Mail/MessageParser.php`, `appinfo/routes.php`
- [x] Implement
- [x] Test

### Task 3: Case reference detection and `MessageReceivedEvent`
- **spec_ref**: `openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-a-received-message-is-offered-to-the-owning-app-as-a-typed-event-req-mail-003`
- **files**: `lib/Event/MessageReceivedEvent.php`, `lib/Service/Mail/MailIntakeService.php`
- [x] Implement
- [x] Test

### Task 4: Unassigned messages feed filinq's intake
- **spec_ref**: `openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md#requirement-unassigned-attachments-go-to-the-document-intake-inbox-req-mail-004`
- **files**: `lib/Service/Mail/MailIntakeService.php`
- [x] Implement
- [x] Test

### Task 5: UI, i18n, docs
- Mailbox source form fields (`protocol`, `folder`, `casePattern`), import button on the source page, Dutch and English strings, docs with screenshots.
- [x] Implement
- [x] Test (`tests/e2e/mail-intake.spec.ts`)
