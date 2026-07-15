---
kind: code
depends_on: []
---

# Proposal: corporate-card-feed

## Summary

Add corporate/credit-card statement ingestion to OpenConnector so that sibling
apps (shillinq) can obtain card transactions from a card-program provider
without embedding a provider HTTP client or credential management of their own.
Structurally a card feed is a transaction feed like the already-shipped PSD2 AIS
bank feed (`psd2-ais-bank-feed-connector`, #131/132): it rides the same rails —
a provider abstraction (`log`/sandbox + a generic REST binding), the credential
broker, `EventService::emitCloudEvent()`, and the `job-scheduling` (`TimedJob`)
sync idiom. The connector enrolls a card-program source, discovers its cards,
and runs a scheduled sync that emits `nl.conduction.cardfeed.transactions.synced`
CloudEvents carrying a batch URI. Card-program API keys are resolved through the
credential broker (`authentication.credentialRef`), never stored on the
consuming app. Unlike the bank feed, the sync is **idempotent on transaction
id**: a replayed sync (same transactions) MUST NOT persist a second batch or
re-emit the synced event.

## Motivation

Corporate cards produce a transaction stream (purchases, refunds, fees) that a
bookkeeping app must reconcile against expenses and the general ledger. Per
ADR-022, external-integration connectors live in openconnector, not in the
accounting leaf: shillinq MUST NOT embed a card-provider HTTP client or manage
provider API versioning. The PSD2 AIS connector already owns the bank-feed
producer side of that boundary; a card feed is the same shape (source → cards →
scheduled transaction pull → synced CloudEvent) and belongs beside it. None of
the card-side machinery exists today. This change supplies it, reusing the bank
feed's provider-abstraction idiom rather than forking a new one.

## Capabilities

- `corporate-card-feed` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `CardfeedController` (enroll + card
  discovery), a `CardfeedProviderInterface` abstraction with `Log` + generic
  `Rest` bindings, a `CardfeedSyncJob` (`TimedJob` action) for scheduled
  transaction sync, `cardfeed_account` + `cardfeed_batch` OR schemas, brokered
  API-key resolution, and CloudEvent emission
  (`nl.conduction.cardfeed.transactions.synced`).
- [ ] Project: `shillinq` — no code change here; the consume side (matching card
  transactions to expenses / GL entries) is a **separate shillinq follow-up**
  (`bookkeeping-card-reconciliation`), which will reference the source slug and
  consume the synced event this change introduces (documented cross-app
  contract only — not built here).

## Scope

### In Scope

- Card-provider abstraction `CardfeedProviderInterface`: `log`/sandbox +
  generic `rest` (a `/cards` → `/cards/{id}/transactions` REST shape), driven by
  `configuration.provider`, `configuration.baseUrl`, and
  `authentication.credentialRef`; placeholders only (`YOUR_API_KEY_HERE`).
- Source enrollment + card discovery: `POST /api/cardfeed/sources/{sourceSlug}/enroll`
  enumerates the program's cards and records a `cardfeed_account` idempotently
  (re-enrollment replaces the card set in place, never duplicates).
- Scheduled transaction sync (`job-scheduling` idiom): a `CardfeedSyncJob`
  action that pulls each card's transactions per account on a configurable
  cadence, persists a `cardfeed_batch`, and emits
  `nl.conduction.cardfeed.transactions.synced`
  `{accountId, cardId, since, until, transactionCount, batchUri}`.
- Idempotency on transaction id: each account tracks the transaction ids already
  emitted; a sync only persists/emits the transactions it has not seen before,
  so a replayed sync produces no batch and no event.
- API-key resolution via the existing credential broker; never plaintext.

## Out of Scope

- Reconciliation / matching card transactions to expenses or GL entries — that
  happens in shillinq's follow-up (`bookkeeping-card-reconciliation`); this
  connector emits the transactions batch and its URI, shillinq consumes it.
- A per-provider onboarding UI beyond the standard source editor; operators
  configure the card-program source in openconnector.
- A consent/SCA redirect flow — corporate card programs authenticate with a
  program API key (broker-held), not a per-cardholder SCA handshake.

## Approach

Model each card program as an openconnector Source (`type: cardfeed`) whose
`configuration.provider` selects a binding (`log` | `rest`) and carries
`configuration.baseUrl` + `authentication.credentialRef`. Enrollment discovers
the program's cards and persists a `cardfeed_account`. A `CardfeedSyncJob` runs
via the existing `TimedJob`/`JobService` machinery, pulls each card's
transactions since the last watermark, filters out any transaction id already
recorded on the account, persists a `cardfeed_batch` of the new transactions,
emits the synced event, and records the newly seen ids. Details in design.md.

## New Dependencies

None. Reuses `BrokeredCallService`/`CredentialBrokerService`, the
`job-scheduling` machinery, and `EventService`.

## Impact

- New: `lib/Controller/CardfeedController.php`,
  `lib/Service/Cardfeed/CardfeedProviderInterface.php` + `Log`/`Rest` bindings,
  `lib/Service/CardfeedSyncService.php`, `lib/Cron/CardfeedSyncJob.php`,
  `lib/Exception/CardfeedProviderException.php`, `cardfeed_account` +
  `cardfeed_batch` schemas in `lib/Settings/openconnector_register.json`,
  `appinfo/routes.php` entries, a `<background-jobs>` registration in
  `appinfo/info.xml`, seed data, and en/nl l10n.

## Cross-Project Dependencies

- shillinq will consume this from its follow-up
  `bookkeeping-card-reconciliation`: it references the source slug and creates
  expense/GL match records on `nl.conduction.cardfeed.transactions.synced`.
  Field names (`accountId`, `cardId`, `transactionCount`, `batchUri`) are the
  producer-side contract owned by this change.

## Risks

### Risk 1: A replayed sync double-emits transactions

**Severity:** High — **Mitigation:** the sync is idempotent on `transactionId`;
each `cardfeed_account` records `seenTransactionIds`, and only transactions not
already seen enter a batch or the synced event. A replayed sweep therefore
persists nothing and emits nothing.

### Risk 2: A live card-program account + API key is required

**Severity:** Medium — **Mitigation:** ship the `log`/sandbox provider so the
full path (enroll → discovery → sync → synced event) is demonstrable in dev/CI
with no real provider; production swaps in the `rest` provider + a real
`credentialRef`.

### Risk 3: API keys leaking

**Severity:** Medium — **Mitigation:** program API keys live only behind the
credential broker (`credentialRef`); `cardfeed_account` carries no secret;
`rest` calls fail closed when the key cannot be brokered (ADR-007).

## Rollback Strategy

Additive. Revert by removing the new controller/services/job/exception/routes,
the `<background-jobs>` entry, and the two schemas; no existing source, job, or
event behaviour changes, so removal cannot regress current integrations.

## Open Questions

None blocking — the sandbox provider makes the change self-contained; provider
vendor and cadence are per-source/per-job configuration.
