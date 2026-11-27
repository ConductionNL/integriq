---
kind: code
depends_on: []
---

# Proposal: outbound-communication-log

## Summary

Every message the product sends to a person is recorded per recipient and per
step, with the point at which it failed, the exact text that left, and who is
allowed to read it back. "Did the ontvangstbevestiging leave the building" is
asked weekly, and today the answer is in a container log nobody can reach.

## Motivation

Round 4 discovery, cluster 23, "The outbound communication log, per recipient
and per step" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Eight candidates, six of them
`must`, nine passers, eight driven and one documented, proving system glpi.
Owner integriq, size M, wave 3, no decision. Three matrix holes, the most of
any integriq cluster. The rows the candidate notes name are 6.11, 6.20 and
6.23.

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-communication-33 | must, matrix hole | partial | an administrator reads the log of outbound messages, sees where a send failed, and acts on it |
| C-communication-57 | must, matrix hole | no | the exact outgoing message and its real recipients are readable, behind their own permission |
| C-communication-5 | must, matrix hole | no | a case message is forwarded to someone outside the system and recorded on the case |
| C-communication-58 | must | no | the handler sees whether the outgoing message was delivered and opened |
| C-communication-56 | must | no | the date the requester was last told anything is held as a field and searchable |
| C-communication-39 | must | no | an outside e-mail address is a standing participant, receiving updates and replying onto the case |
| C-communication-10 | should | no | a failed delivery is retried and each attempt's request and response is kept |
| C-communication-40 | should | no | an unfinished reply is kept on the case between sessions, per user or shared |

The clauses are the argument, verbatim from
`_round4/discovery/candidates.json`:

- C-communication-33, `communication.tsv:36`: "'did the ontvangstbevestiging
  leave the building' is asked weekly". Passer: "glpi: Notification queue
  (front/queuednotification.php, src/QueuedNotification.php)", with OTOBO and
  Znuny beside it. dossiq: "no, `lib/BackgroundJob/EmailPdfRetryJob.php`
  retries silently with no queue screen" and "partial, failures land in the
  Nextcloud log".
- C-communication-57, `communication.tsv:68`: "Awb 3:41 turns on whether the
  letter went out". Passer: "request-tracker: Ticket,
  share/html/Ticket/ShowEmailRecord.html, right ShowOutgoingEmail". dossiq:
  "no, zero hits for a send log or a bounce".
- C-communication-5, `communication.tsv:28`: "Awb 2:3 doorzendplicht: a
  request for the wrong bestuursorgaan must be passed on and the passing on
  must be provable". Passer: "otobo: Ticket menu, Forward and Resend
  (AgentTicketForward.pm, AgentTicketEmailResend.pm)". dossiq: "no, zero hits
  for forward or doorzend".
- C-communication-58, `communication.tsv:80`: "'did the applicant actually
  see it' decides whether a term may run, and nothing else in the corpus
  reports it on the case". The lane notes that its readers disagreed, "rated
  must, not".
- C-communication-56, `communication.tsv:83`: "'nobody has written to this
  citizen in six weeks' is the complaint behind most klachten, and it is one
  query here". dossiq: "no, zero hits for lastTold or an equivalent".

## The line this change does not cross

Decision D12, "The mail transport, and whose it is", was answered against the
build plan's own recommendation: **Nextcloud Mail owns the mail account**,
because the OAuth 2.0 flow is already Nextcloud Mail's. Integriq's umbrella
records it and says integriq opens no mail-account change.

This change opens none. It holds the record of what was sent, to whom, in
which step it failed and whether it can be sent again. It holds no account,
no credential, no OAuth flow, no alias domain and no transport of its own.
Cluster 28 stays closed and cluster 60, outbound sender identity, stays in a
later wave, because it needs a re-read now that the account is Nextcloud
Mail's.

## What integriq builds and what dossiq consumes

Integriq builds the record and the acts on it. dossiq renders it on the case
and keeps the case fields.

- dossiq shows the send history on the case and links each entry to its step.
- C-communication-56 splits: integriq answers "when was this recipient last
  told anything about this subject", and dossiq projects that answer onto the
  searchable case field the row asks for. The lane marks the row
  `dossiq-only 6.20`.
- C-communication-39 splits: the participant list is the party model, cluster
  14, openregister and dossiq. Integriq's half is that a recipient may be a
  plain address that is not a Nextcloud account, recorded per recipient like
  any other.

## What this change does not build, and who does

**C-communication-40, draft replies kept on the case.** A draft is not a
send. It lives where the reply is composed, which is the case surface, and
the passers agree: Zammad's are "shared drafts
(app/models/ticket/shared_draft_start.rb:10 per group, shared_draft_zoom.rb:70-79
written into the case history)", which is a case-history artefact. Integriq
builds nothing for it and the candidate is handed to dossiq.

## The existing specs this extends

- `logs-and-statistics`, REQ-001 and REQ-003: the synchronisation log and the
  per-source call log, and the retention settings both already honour.
- `dead-letter-replay`, REQ-DLR-002 inspection and REQ-DLR-003 audited
  replay: a failed send is a failed delivery, and it reuses the replay act
  rather than growing a second one.
- `execution-trace`, REQ-002 the ordered per-execution step timeline and
  REQ-003 snapshot redaction before any step is buffered: the per-step half
  of this log is that timeline, and the redaction rule is what makes it safe
  to keep a message body.
- `notifynl-sms-channel`, `berichtenbox-digital-post-adapter` and the other
  outbound channels: each becomes a recorded transport rather than a separate
  story.

## Size and dependencies

Size M. The build plan lists it as depending on cluster 60, outbound sender
identity. That cluster is deferred: D12 moved the account to Nextcloud Mail
and the umbrella records that cluster 60 "needs a re-read before it is
written". Nothing in this change waits on it, because a record of what was
sent does not need to know which identity sent it. When cluster 60 lands, the
sender identity becomes one more field on the record.

## Out of scope

- The mail account, OAuth2, alias domains and the transport. D12.
- Sender identity and deliverability, cluster 60, deferred.
- The party model and who may be a participant, cluster 14.
- Draft replies, C-communication-40, handed to dossiq above.
