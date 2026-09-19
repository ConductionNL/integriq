# Design: one-off-and-suppressed-recipients

Kind: code. One resolution step on a list that already exists, and one refusal.

## D1. The list is resolved once and stored as three parts

Resolution takes the standing recipients the caller passes, applies the
additions, applies the suppressions, and writes all three onto the message
record: `standing`, `added`, `suppressed`. It does not write the flattened
result only. A flattened list answers "who got it" and cannot answer "who was
meant to and did not", which is the half of the row that matters a year later.

The transport is handed the resolved list. Everything else on the record is
there to be read.

## D2. An addition is scoped to the message by construction

The addition lives on the delivery request and on the message record, and on
nothing else. There is no place to write it that would outlive the message, so
"it leaked into the standing list" is not a bug that can happen here. A
recipient who should keep receiving messages is a party on the subject, and the
calling app changes that.

## D3. Required is the caller's word, and it travels with the recipient

A caller marks a standing recipient `required` on the delivery request, and says
who required it: a statute, a case type rule, a policy. Integriq does not decide
which recipients are statutory, because that is domain knowledge it does not
have. It enforces the marking: a suppression of a required recipient is refused
and the refusal repeats the caller's own words back, so the handler reads "the
applicant is required by Awb 3:41" rather than "not allowed".

## D4. A suppression is a record, not an omission

The suppressed recipient stays on the record with its reason, the user and the
timestamp. It carries no delivery state, because nothing was attempted, and it
reads `suppressed` rather than `not reported` so it is never confused with the
silence REQ-OCL-005 describes.

## D5. The preview is the same resolution, run early

The send screen asks integriq to resolve without sending, and renders what comes
back. The preview must be the same code path as the send, or it is a second
opinion that will eventually disagree with the first. A preview writes no record
and attempts no transport.

## D6. No migration

Messages sent before this change carry no additions and no suppressions, which
is the truth about them. Nothing is backfilled.

## Risks

- **Suppression as a habit.** If suppressing is easy and the reason box accepts
  anything, the reason becomes "n.v.t." The defence is that the reason is shown
  on the record beside the recipient, where the next person to open the case
  reads it, and not buried in a log.
- **A caller that marks nothing required.** Then nothing is protected, and the
  screen can drop any recipient. That is the caller's decision to make and it is
  visible in its declaration, which is better than integriq inventing a rule
  about Dutch administrative law.
- **A one-off address that is wrong.** It fails per recipient under REQ-OCL-007
  and the other recipients are unaffected, which is behaviour this change
  inherits rather than adds.
