# mail-intake Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- mail-intake-creates-cases

## Purpose

Integriq pulls messages from shared mailboxes and imports `.eml` and `.msg`
files into one `message` object, detects a case reference, and offers each
message to the owning app through a typed event (ADR-041). The owning app
links or creates; integriq never names a case schema. Unassigned attachments
go to filinq's document intake inbox. Requested by the dossiq competitor
analysis, finding B04.

## ADDED Requirements

### Requirement: A mailbox is a source and a message is an object (REQ-MAIL-001)

Integriq MUST offer a `mailbox` source type with `protocol` (`imap`,
`graph`), `folder`, `sinceCursor` and `casePattern`, and a `message` schema
with `sourceId`, `messageId`, `from`, `to[]`, `subject`, `receivedAt`,
`bodyText`, sanitized `bodyHtml`, `attachments[]`, `detectedReference` and a
lifecycle `received`, `linked`, `caseCreated`, `unassigned`. A re-poll MUST
NOT create a second object for the same `messageId` on the same source.

#### Scenario: A poll is idempotent
- GIVEN a mailbox source in mock mode with three fixture messages
- WHEN the synchronization runs twice
- THEN three `message` objects exist, each once
- @e2e exclude synchronization runs as a background job; covered by PHPUnit on `MailboxSourceHandler`

### Requirement: .eml and .msg files import into the same message shape (REQ-MAIL-002)

`POST /api/mail-intake/import` MUST accept an `.eml` or `.msg` upload for a
mailbox source the user may write, parse it locally, and create one
`message` object with its attachments. A file that does not parse MUST be
stored as a single attachment on a `received` message with a warning, never
dropped.

#### Scenario: An operator imports a saved Outlook message
- GIVEN a mailbox source and an `.msg` file with two attachments
- WHEN the operator imports it onto that source
- THEN one `message` with two attachments exists and the mail intake page shows it
- e2e: `tests/e2e/mail-intake.spec.ts`

### Requirement: A received message is offered to the owning app as a typed event (REQ-MAIL-003)

For every new `message`, integriq MUST run the source's `casePattern`, set
`detectedReference`, and dispatch `MessageReceivedEvent` with the message
and the reference and a result slot. A listener MUST be able to answer
`linked`, `created` or `declined` with an object reference. Integriq MUST
record the outcome on the message and MUST NOT contain any case schema name
or case creation logic.

#### Scenario: A message with a case number is linked by the owning app
- GIVEN a message whose subject contains `ZAAK-2026-0042` and a listener that links it
- WHEN the event is dispatched
- THEN the message is `linked` with the listener's object reference
- @e2e exclude cross-app typed event; covered by PHPUnit with a stub listener

#### Scenario: A message without a reference becomes a case
- GIVEN a message with no reference and a listener that creates a case for it
- WHEN the event is dispatched
- THEN the message is `caseCreated` with the new object reference
- @e2e exclude cross-app typed event; covered by PHPUnit with a stub listener

### Requirement: Unassigned attachments go to the document intake inbox (REQ-MAIL-004)

When no listener sets an outcome, integriq MUST mark the message
`unassigned` and dispatch one `IntakeDocumentReceivedEvent` per attachment
with channel `mail`, the sender and subject as metadata, and the message as
`sourceRef`. When that event has no listener the attachments MUST stay in
integriq's file store.

#### Scenario: Nobody claims the message
- GIVEN a message with one attachment and no listener on `MessageReceivedEvent`
- WHEN intake runs
- THEN the message is `unassigned` and one `IntakeDocumentReceivedEvent` carried the attachment
- @e2e exclude event hand-off; covered by PHPUnit with a recording dispatcher
