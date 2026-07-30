---
kind: code
depends_on: []
---

# Proposal: psd2-ais-bank-feed-connector

## Summary

Add PSD2 Account Information Service (AIS) bank feeds to OpenConnector so that
sibling apps (shillinq) can obtain bank transactions from licensed EU
aggregators without embedding an aggregator HTTP client, an OAuth/SCA flow, or
consent management of their own. The connector provides a redirect-based
consent (SCA) flow, account discovery, and a scheduled transaction sync that
emits `nl.conduction.bankfeed.transactions.synced` CloudEvents carrying a batch
URI. Aggregator credentials and consent tokens are stored via the existing
credential broker (`authentication.credentialRef`), never on the consuming app.
A provider abstraction runs a `log`/sandbox aggregator in dev/CI and a generic
REST aggregator (GoCardless Bank-Account-Data-style shape) in production.

## Motivation

shillinq's `bookkeeping-bank-connectors` spec (REQ-BC-001) mandates, per
ADR-022, that "PSD2 AIS aggregator integrations SHALL be consumed from
openconnector … shillinq MUST NOT embed an aggregator HTTP client, MUST NOT
implement OAuth/SCA flows, and MUST NOT manage aggregator API versioning." Its
`BankConnection` register references an openconnector `Source` by
`aggregatorSourceSlug` and stores only a non-credential `consentReference`
(REQ-BC-002/003); its consent-renewal action redirects to "openconnector's SCA
endpoint" (REQ-BC-006); its scheduled polling calls "openconnector by source
slug" and creates `BankStatement` records on
`nl.conduction.bankfeed.transactions.synced`. None of that openconnector-side
machinery exists today. This change supplies it, owning the producer side of
the contract shillinq already consumes.

## Capabilities

- `psd2-ais-bank-feed-connector` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `Psd2Controller` (SCA connect + callback,
  account discovery), a `Psd2AggregatorProviderInterface` abstraction with
  `Log` + generic `Rest` bindings, a `BankfeedSyncJob` (TimedJob action) for
  scheduled transaction sync, `bankfeed_connection` + `bankfeed_batch` OR
  schemas, brokered token storage, and CloudEvent emission
  (`nl.conduction.bankfeed.transactions.synced` + consent lifecycle events).
- [ ] Project: `shillinq` — no code change here; shillinq's
  `bookkeeping-bank-connectors` spec (REQ-BC-001..008) is the consume side and
  references the source slug, SCA endpoint, and event this change introduces
  (documented cross-app contract only).

## Scope

### In Scope

- Redirect-based SCA/consent flow: `POST /api/psd2/connect` (returns the
  aggregator SCA redirect URL) and `GET /api/psd2/callback` (completes consent,
  stores the token via `credentialRef`, records a `bankfeed_connection` with a
  `consentReference` + `consentExpiresAt`).
- Account discovery: enumerate the accounts authorised by a consent.
- Scheduled transaction sync (job-scheduling idiom): a `BankfeedSyncJob` action
  that pulls transactions per connection on a configurable cadence, writes a
  `bankfeed_batch`, and emits `nl.conduction.bankfeed.transactions.synced`
  `{connectionId, accountIban, since, until, transactionCount, batchUri}`.
- Consent-lifecycle CloudEvents so a consumer's connection state (shillinq
  `BankConnection`) can transition (granted → active, revoked, expiring/expired).
- Provider abstraction `Psd2AggregatorProviderInterface`: `log`/sandbox +
  generic `rest` (GoCardless Bank-Account-Data-style: requisitions, accounts,
  transactions), placeholders only (`YOUR_API_KEY_HERE`).
- Token/credential storage via the existing broker; never plaintext.

## Out of Scope

- CAMT.053 materialisation and `BankStatement` creation — those happen in
  shillinq's `ScheduledWorkflow` (REQ-BC-004); this connector emits the
  transactions batch and its URI, shillinq normalises/attaches.
- Payment Initiation Service (PIS) — this change is AIS (read) only.
- A per-aggregator onboarding UI beyond the standard source editor; operators
  configure the aggregator source in openconnector.

## Approach

Model each aggregator as an openconnector Source whose `configuration.provider`
selects a binding (`log` | `rest`) and carries `configuration.baseUrl` +
`authentication.credentialRef`. The SCA flow is two endpoints: `connect`
delegates to the provider to create a requisition/consent and returns the bank
redirect URL; `callback` finalises it, brokers the returned token, and persists
a `bankfeed_connection` (consentReference, accounts, `consentExpiresAt`,
lifecycle). A `BankfeedSyncJob` runs via the existing job-scheduling machinery
(NC `TimedJob` sweep, per-job cadence), pulls transactions since the last
watermark, writes a `bankfeed_batch` object, and emits the synced event.
Consent state changes emit CloudEvents. Details in design.md.

## New Dependencies

None. Reuses `BrokeredCallService`/`CredentialBrokerService`, the
`job-scheduling` machinery (`JobService`/`JobTask`), and `EventService`.

## Impact

- New: `lib/Controller/Psd2Controller.php`,
  `lib/Service/Psd2/Psd2AggregatorProviderInterface.php` + `Log`/`Rest`
  bindings, `lib/Service/BankfeedSyncService.php`,
  `lib/Cron/BankfeedSyncJob.php`, `bankfeed_connection` + `bankfeed_batch`
  schemas in `lib/Settings/openconnector_register.json`, `appinfo/routes.php`
  entries, a `<background-jobs>` registration in `appinfo/info.xml`.

## Cross-Project Dependencies

- shillinq consumes this: it references the source slug
  (`aggregatorSourceSlug`), redirects to `POST /api/psd2/connect` for SCA
  renewal, and creates `BankStatement` records on
  `nl.conduction.bankfeed.transactions.synced`. Field names MUST align with
  shillinq `bookkeeping-bank-connectors` REQ-BC-002 (`consentReference`,
  `consentExpiresAt`, `bankAccountIban`, `aggregator`). Contract owned by both;
  this change owns the producer side.

## Risks

### Risk 1: Consent expiry (PSD2 90-day SCA renewal)

**Severity:** High — **Mitigation:** persist `consentExpiresAt` on
`bankfeed_connection` and emit a consent-expiring CloudEvent ahead of the
deadline so shillinq's `BankConnection` lifecycle (REQ-BC-005) can move to
`expiring` and prompt renewal; the sync job MUST skip expired connections
rather than call the aggregator with a dead consent.

### Risk 2: A licensed aggregator + real bank credentials are required

**Severity:** Medium — **Mitigation:** ship the `log`/sandbox provider so the
full path (connect → callback → discovery → sync → synced event) is
demonstrable in dev/CI with no real aggregator; production swaps in the `rest`
provider + a real `credentialRef`.

### Risk 3: Token/consent secrets leaking

**Severity:** Medium — **Mitigation:** OAuth tokens and API keys live only
behind the credential broker (`credentialRef`); `bankfeed_connection` carries
the non-credential `consentReference` only, mirroring shillinq REQ-BC-002/003;
redaction on export per `configuration-export-import`.

## Rollback Strategy

Additive. Revert by removing the new controller/services/job/routes, the
`<background-jobs>` entry, and the two schemas; no existing source, job, or
event behaviour changes, so removal cannot regress current integrations. Any
stored consent tokens are broker-held and revocable independently.

## Open Questions

None blocking — the sandbox provider makes the change self-contained; aggregator
vendor and cadence are per-source/per-job configuration.
