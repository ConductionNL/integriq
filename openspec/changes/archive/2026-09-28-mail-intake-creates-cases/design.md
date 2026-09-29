# Design: mail-intake-creates-cases

Kind: code. A source type, a schema, an import endpoint, one typed event.

## Architecture overview

```
mailbox (IMAP / Graph) ──Synchronization──▶ integriq `message` object
.eml / .msg upload ─────POST /import───────▶ integriq `message` object
                                                │
                              detect case reference (pattern per mailbox)
                                                │
                       MessageReceivedEvent {message, reference?} ──▶ owning app listener
                                                │                       (link, or create a case,
                                                │                        answer in the result slot)
                       no listener claimed it ──▶ IntakeDocumentReceivedEvent per attachment (filinq)
```

## D1. `mailbox` source

A `Source` with type `mailbox`, `protocol` `imap` or `graph`, credentials
through the existing credential resolution, `folder`, `sinceCursor`, and a
`casePattern` (regex with one capture group, default the fleet case-number
shape). Mock-mode fixtures per `source-management`.

## D2. `message` schema

In integriq's register: `sourceId`, `messageId`, `from`, `to[]`, `subject`,
`receivedAt`, `bodyText`, `bodyHtml` (sanitized), `attachments[]`
(`{fileRef, name, mime, size}`), `detectedReference`, `status` lifecycle
`received`, `linked`, `caseCreated`, `unassigned`. `messageId` per source is
unique, so a re-poll is idempotent.

## D3. Import endpoint

`POST /api/mail-intake/import`, `#[NoAdminRequired]`, write on the mailbox
source required. Parses `.eml` (RFC 5322) and `.msg` (CFB) into the same
`message`. The parser is a dependency (`php-mime-mail-parser`, `hfig/mapi`);
no external call.

## D4. The typed event (ADR-041)

`OCA\Integriq\Event\MessageReceivedEvent` with `getMessage()`,
`getDetectedReference()`, and a result slot `setOutcome(outcome, objectRef)`
where outcome is `linked`, `created` or `declined`. The owning app's listener
runs in its own DI context and decides: link to the referenced case, create a
case, or decline. Integriq writes the outcome on the message. No integriq
code names a case schema.

## D5. Unassigned messages

When no listener sets an outcome, integriq marks the message `unassigned`
and dispatches one `IntakeDocumentReceivedEvent` per attachment with channel
`mail` and the message as sender metadata, so filinq's intake inbox shows
them. If that event has no listener either, the message stays `unassigned`
and the attachments stay in integriq's file store.

## Risks
- A mailbox pattern that matches too much links mail to the wrong case. The
  owning app's listener may still decline; the outcome is on the message.
- `.msg` parsing is a known weak spot. A parse failure stores the raw file as
  one attachment and marks the message `received` with a warning.
