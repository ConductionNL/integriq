---
kind: code
depends_on: [intake-channels-beyond-mail]
---

# Proposal: teams-messages-open-cases

## Summary

A Microsoft Teams message opens or joins a case, over the intake channel
contract integriq already ships. One adapter and one signature scheme, no new
idea about what a case is.

## Motivation

Parity ledger row 1.9, "create a case from MS Office, Teams or Outlook".
Zaaksysteem does it, OpenCase does part of it, dossiq does none of it, and
the row carries no change. The row is three capabilities in one line, and two
of the three are already answered:

- **Outlook is covered.** `mail-intake-creates-cases` shipped a `mailbox`
  source with `protocol: graph`, and `lib/Service/Mail/Transport/GraphMailboxTransport.php`
  reads a shared Exchange Online mailbox today. A saved message imports
  through `POST /api/mail-intake/import`, which parses `.msg` as well as
  `.eml`. A case number in the subject links; a message naming nothing is
  offered as a new case.
- **An Office document is covered** once the file is in Nextcloud.
  OpenRegister registers a Files action that attaches any file to any
  register object (`files-leaf-save-to-object`), and the desktop client is
  what carries a Word document there.
- **Teams is covered by nothing.** A gemeente that runs its internal
  coordination in Teams has no way from a message to a case, and the message
  is where the work is being discussed.

The ledger row should be corrected on the first two points either way. This
change is about the third.

## What changes

- A `teams` intake channel adapter implementing the shipped
  `IntakeChannelAdapterInterface`: `receive()` normalises a Teams activity
  into the inbound shape (the author as correspondent, the message text,
  its attachments, the raw payload), `describe()` says it can reply, and
  `reply()` answers in the same conversation rather than by e-mail.
- A `teams` signature scheme on `WebhookSignatureService`. Teams signs an
  outgoing webhook with `Authorization: HMAC <base64>`, an HMAC-SHA256 over
  the raw body under the base64-decoded shared secret, and carries no
  timestamp. None of the three schemes we have reads that, so today a Teams
  webhook either fails to verify or would have to be accepted unverified,
  and unverified is not an option on a route that opens cases.
- The routing rules, the review inbox for an unmatched message, the reply
  path and the case-number detection are reused as they are. A Teams message
  that names a case number links to it, and one that names nothing goes
  through the same rules as every other channel.

## Out of scope, and why

- **An Outlook add-in or an Office task pane.** That is a Microsoft-store
  product with its own Entra registration, its own hosted pane and its own
  review cycle, and it buys a shortcut to a path that already works through
  the mailbox and through Files. It needs a product decision before it needs
  a spec.
- **Teams calls and meetings.** A meeting is not an intake channel. Live
  conversation on a case is dossiq's `live-conversation-on-the-case` over
  Nextcloud Talk.
- **Posting case updates back into a Teams channel** beyond the reply to the
  message that opened the case. Outbound notification is the notification
  engine's, not intake's.

## Impact

- **Affected specs**: `intake-channels` (delta), `webhook-signing` (delta).
- **Affected code**: `lib/Intake/Adapter/TeamsChannelAdapter.php`,
  `lib/Service/WebhookSignatureService.php`, the DI tag in
  `lib/AppInfo/Application.php`.
- **Backwards compatible**: a new adapter and a new scheme value. Every
  existing channel and every existing signed webhook is untouched.
- **Consumers**: a Teams message is handed on as the same
  `MessageReceivedEvent` a mail is, so nothing channel-specific reaches the
  owning app.
- 🔴 **Nobody answers that event yet.** Measured on 2026-09-18 against
  dossiq `parity/round2` at `c3bdf65d`: dossiq registers one cross-app
  listener on integriq, `DeliveryConcludedEvent`, and none on
  `MessageReceivedEvent`. dossiq creates cases from mail through its own IMAP
  poller instead. So every channel integriq receives, mail included, ends
  `unassigned` on this path until dossiq's listener lands. That listener is
  dossiq's `an-intake-message-becomes-a-case`, and this change is worth
  little without it.
