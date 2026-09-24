# Design: outbound-communication-log

Kind: code. Size M. One record per message, one row per recipient, one entry
per step. Every act on it already exists somewhere in integriq and is reused
rather than rebuilt.

## D1. The record is per recipient, because the failure is

GLPI's notification queue is the proving shape, and the reason it works is
that a queued notification is a recipient, not a message. Three recipients on
one ontvangstbevestiging can have three outcomes, and a record that averages
them cannot answer Awb 3:41 for any of them.

So the message carries the subject, the channel and the body; the recipient
carries the status, the states and its own failure. A bulk retry then has an
obvious unit, and so does a permission.

## D2. Two permissions, because the body is not the fact

Request Tracker separates them with a named right, `ShowOutgoingEmail`, and
the separation is the useful part. "A letter went out on 3 September to these
two people" is what a handler needs to answer a klacht. The text of the
letter is a different question with a different audience, and in a bezwaar
dossier it can be the whole dispute.

Redaction runs before the write, not on read. `execution-trace` REQ-003
already puts snapshot redaction before any step is buffered, and the same
rule is what makes storing a rendered body acceptable at all.

## D3. Retry is the replay we already have

`dead-letter-replay` ships listing, inspection, audited replay, audited
discard, bulk with per-item outcomes and a UI. A failed send is a failed
delivery. Growing a second retry would give the fleet two audit trails, two
bulk semantics and two places for a message to vanish.

So the log's retry button calls that act. The new part is the record it
appends to and the permission that gates it.

## D4. Forwarding is a new record, not an edit

Awb 2:3 asks for provability: the passing on must be provable. An edit to the
original record cannot prove anything, because it replaces the evidence with
its consequence. A linked second record keeps both halves, and the link reads
from either end so the case surface can show "forwarded to" and the forward
can show "forwarded from".

## D5. Three states, and the third one is the honest one

A delivery state with two values forces a lie. Most channels do not report,
and "not failed" is not "delivered". So every state is one of `reported`,
`not reported` or `unsupported by this channel`.

This is the same discipline the sweep itself insists on: a documented passer
is an upper bound and never a driven one. A channel that cannot report is not
a channel that reported nothing, and rendering the difference is what keeps
the field from being quietly wrong on a beschikking.

C-communication-58's own lane disagreed about it, and the candidate records
that: "lanes disagree, rated must, not". Three states is what lets the
capability ship without settling that argument.

## D6. Last contact is a query, not a field

C-communication-56 asks for a searchable field. The field belongs on the
case, because that is where it is searched, and the lane marks the row
`dossiq-only 6.20`. What integriq owns is the answer.

Storing the field here would make integriq write onto a case, which ADR-022
and the ownership rule both refuse. Answering a query lets dossiq project it
onto a field it indexes, and lets the answer stay correct when a message is
retried a week later.

## D7. An external address is a recipient, not a user

C-communication-39 wants a standing external participant. Two halves: who may
be a participant, and whether a send can address one. The first is the party
model, cluster 14. The second is this change, and it is small: a recipient
row with an address and no account.

Creating a Nextcloud account for a gemachtigde would be the wrong answer
twice, once for the licence count and once for the AVG.

## D8. What this change refuses to become

It records sends. It does not acquire an account, a credential, an OAuth
flow, an alias domain or a transport. D12 gave the account to Nextcloud Mail
and integriq's umbrella already records that integriq opens no mail-account
change. A log that quietly grows a transport is how that decision gets
reversed without anyone deciding to reverse it.
