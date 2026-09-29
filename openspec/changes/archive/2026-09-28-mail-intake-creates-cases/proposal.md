---
kind: code
---

# Proposal: mail-intake-creates-cases

## Summary
Poll one or more shared mailboxes, and import `.eml` and `.msg` files, into
one message shape. A message that names a case number is offered to the
owning app as a link. A message that names nothing is offered as the start
of a new case, and its attachments go to filinq's intake inbox. Integriq
owns the connector and the message; dossiq decides what a case is.

## Motivation
OpenCase treats IMAP mailboxes as import locations
(`oc/pages/IncomingDocuments.md`) and Zaaksysteem imports a message and
assigns it to a case (`zs/pages/Communicatie.md`), both in the dossiq
competitor analysis under `concurrentie-analyse/procest/_round2/`. dossiq's
`InboundEmailJob` polls one mailbox and links tagged mail to an existing
case; it creates nothing and imports nothing (finding B04, M1 1.5, 6.5,
6.10). The analysis assigns intake to integriq and import to the
OpenRegister email leaf, and asks integriq to write its half now (D11).

Integriq already owns sources, synchronizations and the CloudEvents fan-out
(`events-cloudevents`). A mailbox is a source; a message is a synchronized
object; "create a case" is a command for the owning app, sent as a typed
event per ADR-041.

## Scope
- A `mailbox` source type (IMAP, Microsoft Graph) with a synchronization that
  pulls messages into an integriq `message` schema.
- `POST /api/mail-intake/import` accepting `.eml` and `.msg` and producing
  the same `message` object.
- Case-number detection per configured pattern; a `MessageReceivedEvent`
  typed event with the message and the detected reference, result slot for
  the owning app's answer.
- Attachments to filinq through `IntakeDocumentReceivedEvent`
  (`document-intake-inbox`) when the message is unassigned.

## Out of scope
- Rendering the message on a case. That is the OpenRegister email leaf.
- Sending mail. `outbound-webhooks-activation` and the notification engine
  cover that.
