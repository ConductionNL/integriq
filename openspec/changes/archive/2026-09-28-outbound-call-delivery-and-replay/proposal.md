---
kind: code
depends_on: [outbound-communication-log]
---

# Proposal: outbound-call-delivery-and-replay

## Summary

A koppeling dies quietly. A webhook stops delivering, a ZGW Notificaties
message is lost while the receiver is down, and nobody finds out from either
side. This change makes every outbound call readable with its request and its
response, replayable from a screen, and governed by a retry policy an
administrator sets rather than a constant a developer chose.

## Motivation

Round 4 discovery, cluster 27, "Delivery, retry and replay of an outbound
call" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Eight candidates, four of them
`must`, eight passers, seven driven and one documented, proving system
gitlab. Owner integriq, size M, wave 3, no decision. Three matrix holes. The
row the candidate notes name is 6.11.

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-integrations-7 | must, matrix hole | partial | a failed delivery is sent again from the screen after the receiver comes back |
| C-integrations-31 | must, matrix hole | yes | read the log of calls to external systems, with request and response, filtered |
| C-integrations-45 | must, matrix hole | yes | the partner's message shape is mapped onto the product's own, both ways, without code |
| C-integrations-12 | must | partial | administer how often a failed outbound call is retried and how long between attempts |
| C-integrations-6 | could | no | a copy of the record set kept in step with another instance on a schedule, either direction |
| C-integrations-16 | could | no | an external checker reports a pass or a fail against the record, and the verdict shows on it |
| C-integrations-20 | could | no | an outside system is asked for permission before the case proceeds, and the product waits |
| C-integrations-32 | could | partial | records and identities reach across independently operated instances |

Replaying a failed delivery is **number 8 of the twenty-five loudest** in
`_round4/discovery/found-and-lacking.md`: "8. Replaying a failed delivery, or
firing one by hand (5)". It is also number 8 of the ten strongest
capabilities the competition has, with the same five driven passers.

The clauses, verbatim from `_round4/discovery/candidates.json`:

- C-integrations-7, `integrations.tsv:29`: "a ZGW Notificaties message lost
  while the receiver was down is invisible today, in both directions".
  Passer: "gitlab: Settings, Webhooks, 16 event checkboxes, web_hook_logs
  with resend (code-census.md, webhooks doc)". Five driven passers: Forgejo,
  Gitea, GitLab, Vikunja and xxllnc Zaken. dossiq: "no, zero hits for a
  delivery log" and "partial, `lib/BackgroundJob/StufRetryJob.php` retries
  without a screen".
- C-integrations-12, `integrations.tsv:31`: "the retry schedule on a
  Digikoppeling call is a policy, not a constant". dossiq: "partial
  `lib/BackgroundJob/StufRetryJob.php` has a hardcoded retry".
- C-integrations-31, `integrations.tsv:28`: "when a ZGW or StUF call fails
  the answer today is in a container log nobody can reach".
- C-integrations-45, `integrations.tsv:23`: "StUF and ZGW shapes differ per
  leverancier and each difference is a release today".

Beside them sits the sibling candidate from cluster 23, C-communication-10,
"a failed delivery is retried and each attempt's request and response is
kept", whose passer is Plane's `WebhookLog` at `webhook.py:65` "with request
body, response body and retry_count at :82". The two clusters meet at the
same machinery and keep separate records: cluster 23 records a message to a
person, this one records a call to a system.

## What integriq already has, so that this change is honest about its size

Integriq is further along here than dossiq's ratings suggest, and the two
`yes` ratings above are integriq's own work seen from dossiq's side.
`dead-letter-replay` ships listing, inspection, audited replay, audited
discard, bulk with per-item outcomes and a UI, for events and for sync items.
`execution-trace` ships a trace id at every entry point, an ordered step
timeline, redaction before buffering, dry-run replay and forced replay
through the original dispatch path. `mapping-editor-ui` and
`mapping-and-search` ship the mapping surface C-integrations-45 asks for.

What is missing is narrower and it is what this change builds: a call is not
a first-class record with its own request and response, a retry schedule is
not administrable, and a replay cannot say which mapping version it ran
under.

## What integriq builds and what dossiq consumes

Integriq builds the call record, the retry policy, the replay and the
verdicts. dossiq shows the log against the case and retires its own retry.

- dossiq's `lib/BackgroundJob/StufRetryJob.php` retries on a hardcoded
  schedule with no screen. It becomes a caller of the policy instead of a
  policy of its own.
- dossiq shows the call log filtered to a case, and links a failed ZGW or
  StUF call to the act that triggered it.
- An external verdict and a blocking pre-check reach dossiq as facts on the
  record. Integriq asks and records; what a refusal means to a case is
  dossiq's decision.

## What this change does not build, and who does

- **C-integrations-6**, a scheduled mirror of a record set in either
  direction, is what `synchronization-engine` already orchestrates: REQ-001
  routes both directions, REQ-002 paginates the source, REQ-009 tracks fetch
  completeness and REQ-010 guards deletion. What is left is a configuration
  recipe, not a capability, and it is recorded rather than built.
- **C-integrations-32**, federation between independently run instances, is a
  Nextcloud platform capability. dossiq reads `partial` on
  `CaseSharingService::createFederatedShare`, and the ten Nextcloud platform
  integration points are their own programme under decision D9. Recorded, not
  built here.

## The existing specs this extends

- `dead-letter-replay`, REQ-DLR-001 through REQ-DLR-006: the replay act, its
  audit, its bulk semantics and its UI. A call replay is that act on a new
  record type.
- `execution-trace`, REQ-002, REQ-003, REQ-005 and REQ-006: the step
  timeline, redaction before buffering, the dry run that writes nothing and
  the forced replay through the original dispatch path.
- `logs-and-statistics`, REQ-003 the per-source call log and REQ-004 the
  retention settings a call record must honour.
- `signed-outbound-webhooks` and `webhook-signing`, REQ-WHS-001 and
  REQ-WHS-002: a replayed delivery is signed like a first one, with the
  subscription's current secret.
- `mapping-editor-ui` and `mapping-and-search`: the mapping a call ran under,
  which this change versions so a replay can name it.
- `http-call-engine` and `synchronization-engine` REQ-011: the call path and
  the rule that a test run writes nothing.

## Size and dependencies

Size M. The build plan makes it depend on cluster 23, the outbound
communication log, which is this branch's `outbound-communication-log`. The
two share the replay act and the retention rules and keep separate records.

## Out of scope

- Messages to people. Cluster 23, `outbound-communication-log`.
- The mail account and the mail transport. D12.
- A scheduled mirror and federation, recorded above.
