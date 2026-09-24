---
kind: code
depends_on: [outbound-communication-log]
---

# Proposal: one-off-and-suppressed-recipients

## Summary

The recipients of a message are today whoever the subject implies, and nobody
can change that for one message without changing the subject. A gemachtigde who
needs this one letter cannot be added, and a party who must not receive this one
letter cannot be left out. This change makes the recipient list of a single
message a decision: standing recipients, plus what somebody added for this
message only, minus what somebody suppressed for this message only, with the
suppression carrying a reason and staying visible on the record.

## The row this change closes

Row **6.23**, area "Communication", capability "One-off recipient added to a
single message, or a standing one suppressed", rated **no** for dossiq.

Source field, verbatim:

```
dossiq#2314, published as 6.19
```

Corpus batch file `procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md`
in ConductionNL/market-intelligence, table row verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **6.23** | 6.19 | One-off recipient added to a single message, or a standing one suppressed | no | unread |  |
```

Ledger note, dossiq's own evidence, verbatim:

> CaseEmailService sends to the recipients the case implies. There is no way to add a person to one message only, or to leave one out and record that you did.

## What the competitor evidence is

None, and the corpus says so rather than guessing. Row 6.23 is one of the 98
rows promoted under decision D1:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is a
> reading of a product somebody opened, and filling these cells with it would
> fabricate thirty readings per row.

This proposal therefore makes no claim about any competitor. The evidence is the
ledger note and `CaseEmailService`, which is ours.

## Why this is integriq's and not dossiq's

`outbound-communication-log` already puts the record of what was sent, to whom
and with what outcome in integriq, per recipient. Where the recipient list is
recorded is where the list is decided: putting the decision in a case app and
the record in integriq would mean the record could not explain itself, because
the suppressed recipient would simply be missing from it.

The standing list stays the subject's. A case knows who its parties are and
integriq does not. Integriq holds the per-message departure from that list and
the reason for it.

## What integriq builds

1. **The recipient list of a message is a resolved decision, recorded.** The
   record carries the standing recipients, the ones added for this message only,
   and the ones suppressed for this message only. A suppressed recipient appears
   on the record as suppressed, never as absent.
2. **An addition is for one message.** It does not change the subject's standing
   list and it does not carry to the next message. A recipient who should keep
   receiving things is a change to the subject, which is the calling app's.
3. **A suppression carries a reason and is refused without one**, per ADR-102. A
   year later the question is why this party did not get the letter, and the
   answer has to be readable without reading code.
4. **A recipient the caller marks as required cannot be suppressed.** The
   refusal names the recipient and who required it. A statutory addressee is not
   something a send screen should be able to drop quietly.
5. **The resolved list is shown before the send.** The person pressing send sees
   who will receive it, including what was added and what was suppressed, so the
   decision is made in front of them rather than discovered in the log.
6. **The caller hands additions and suppressions with the delivery request.**
   They ride the ADR-041 delivery event that `absorb-dossiq-deliveries` already
   defines, so a sibling app grows no recipient resolution code of its own.

## What dossiq consumes

To be specified in dossiq. There is no dossiq slug for the recipient decision
yet. Its half rides the delivery request its `dossiq-delivers-nothing` change
already dispatches:

- Offer the send screen on the case where a handler adds a one-off recipient or
  suppresses a standing one and types the reason.
- Pass the standing recipients the case implies, with the statutory ones marked
  required, and pass the additions and suppressions on the delivery request.
- Render the resolved list, suppressions included, on the case timeline entry
  for that message.

## ADRs this cites

- **ADR-041** (hydra, cross-app commands via typed events): additions,
  suppressions and required markers travel on the delivery request event
  integriq already defines, not on a second channel.
- **ADR-102** (hydra, configuration fail mode): a suppression without a reason,
  and a suppression of a required recipient, are refused rather than defaulted.
- **ADR-091** (hydra, the external API surface belongs to integriq): the
  transport and the address list that reaches it are integriq's, so the
  per-message list resolves here.

## Size

M. One resolution step, one refusal with a reason, one preview surface and two
fields on the delivery event. The record it writes into is
`outbound-communication-log`'s, which is why this change depends on it.

## The existing specs it extends

- `outbound-message-log`, REQ-OCL-001 the per-recipient record and REQ-OCL-007
  an external address as a first-class recipient. A one-off recipient is exactly
  that recipient, admitted for one message.
- `delivery-intake`, the ADR-041 request event that carries the payload today
  and carries the recipient decision after this change.

## Out of scope

- Who the standing recipients are. That is the party model on the case, and it
  stays with the calling app.
- A recipient who should stop receiving everything. That is a change to the
  subject, not a suppression on one message.
- The delivery outcome per recipient, which `outbound-communication-log`
  already specifies under REQ-OCL-001 and REQ-OCL-005.
