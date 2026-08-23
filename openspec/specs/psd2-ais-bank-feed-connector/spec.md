---
status: done
---

# psd2-ais-bank-feed-connector Specification

## Purpose

Integriq consumes PSD2 Account Information Service (AIS) feeds from
licensed EU aggregators (GoCardless Bank Account Data, Tink, Klarna Kosma,
Yapily, etc.) and exposes them to sibling apps as bank transactions, per ADR-022
and shillinq's `bookkeeping-bank-connectors` REQ-BC-001. It owns the
redirect-based SCA/consent flow, consent-token storage (credential broker,
ADR-007), account discovery, and the scheduled transaction sync that emits
`nl.conduction.bankfeed.transactions.synced`. shillinq references the
integriq `Source` slug and consumes the events; no aggregator client,
OAuth flow, or consent record lives in shillinq. Built on the existing
`source-management`, `job-scheduling`, and `events-cloudevents` capabilities.

@e2e exclude backend PSD2 AIS feed (SCA flow, account discovery, scheduled sync) — covered by PHPUnit/Newman, no browser UI

**OpenSpec changes**
- `psd2-ais-bank-feed-connector` (done) — introduces the
  `Psd2AggregatorProviderInterface` abstraction (`log`/sandbox + generic `rest`
  bindings), the redirect-based SCA connect/callback endpoints with brokered
  token storage, account discovery, the `BankfeedSyncJob` scheduled transaction
  sync emitting `nl.conduction.bankfeed.transactions.synced`, and the
  consent-lifecycle CloudEvents.
  Archived at `openspec/changes/archive/2026-07-12-psd2-ais-bank-feed-connector/`.

## Requirements

### Requirement: Aggregator provider abstraction with log and generic-REST bindings (REQ-001)

The connector MUST define a `Psd2AggregatorProviderInterface`
(`lib/Service/Psd2/`) exposing at minimum `createRequisition(redirectUrl,
institutionId)`, `finaliseConsent(reference)`, `listAccounts(consent)`, and
`listTransactions(accountId, since, until)`. A source's
`configuration.provider` selects the binding:

- `log` — `LogPsd2AggregatorProvider`, a sandbox binding that performs no real
  network call, returns a canned SCA redirect URL, canned accounts, and canned
  transactions, and reads no secret. It is the default for dev/CI and mirrors
  the `source-management` mock-mode convention.
- `rest` — `RestPsd2AggregatorProvider`, a generic REST aggregator binding
  shaped after the GoCardless Bank-Account-Data API (requisitions → accounts →
  transactions), driven by `configuration.baseUrl` and
  `authentication.credentialRef`; every outbound call MUST go through the
  credential broker (`BrokeredCallService`) so the aggregator API key / OAuth
  token is injected at call time and never stored in the source config.

Adding a new aggregator vendor MUST be done by implementing the interface, not
by editing the SCA or sync services. All example secrets MUST use placeholder
values (`YOUR_API_KEY_HERE`).

#### Scenario: the log provider drives the full path without a network call or secret

- GIVEN a PSD2 source with `configuration.provider: log`
- WHEN a requisition is created, accounts listed, and transactions pulled
- THEN canned data SHALL be returned for each step with no outbound HTTP call
  and no credential read
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the rest provider brokers its token

- GIVEN a PSD2 source with `configuration.provider: rest`,
  `configuration.baseUrl`, and `authentication.credentialRef`
- WHEN an aggregator call is dispatched
- THEN the call SHALL route through the credential broker with the token
  injected at call time
- AND the token SHALL NOT appear in the source configuration, exports, or logs
- @e2e exclude backend credential brokering — covered by PHPUnit

### Requirement: Redirect-based SCA consent flow (REQ-002)

The connector MUST expose `POST /api/psd2/connect` and `GET /api/psd2/callback`
implementing a redirect-based Strong Customer Authentication (SCA) flow.
`connect` MUST accept `{sourceSlug, institutionId, redirectUrl}`, call the
provider's `createRequisition`, and return the aggregator's bank SCA redirect
URL for the operator's browser. After the operator authenticates at the bank
and is redirected back, `callback` MUST call `finaliseConsent`, store the
returned consent/refresh token through the credential broker (referenced by
`authentication.credentialRef`), and persist a `bankfeed_connection` OR object
carrying `connectionId`, `aggregatorSourceSlug`, `consentReference`,
`consentGrantedAt`, `consentExpiresAt`, and `lifecycleState='active'`. The
consent token MUST NOT be written onto the `bankfeed_connection` object — only
the non-credential `consentReference` is stored there (aligning shillinq
REQ-BC-002/003). This endpoint is the SCA target shillinq redirects to for
consent renewal (REQ-BC-006).

#### Scenario: connect returns a bank SCA redirect URL

- GIVEN a PSD2 source `sourceSlug` and an `institutionId`
- WHEN `POST /api/psd2/connect` is called with a `redirectUrl`
- THEN the response SHALL carry the aggregator's bank SCA redirect URL
- @e2e exclude backend SCA initiation — covered by PHPUnit/Newman

#### Scenario: callback finalises consent and stores only the reference

- GIVEN a completed bank authentication redirected back to `GET /api/psd2/callback`
- WHEN the callback runs
- THEN the consent/refresh token SHALL be stored via the credential broker
- AND a `bankfeed_connection` SHALL be persisted with `consentReference`,
  `consentExpiresAt`, and `lifecycleState='active'`, carrying no token field
- @e2e exclude backend consent finalisation — covered by PHPUnit

### Requirement: Account discovery after consent (REQ-003)

After a consent is active the connector MUST discover the authorised accounts
via the provider's `listAccounts` and record each account's IBAN, BIC, currency,
and aggregator account id on (or linked to) the `bankfeed_connection`. Account
discovery MUST NOT expose the consent token and MUST be re-runnable idempotently
(re-discovery updates, never duplicates, the account set).

#### Scenario: accounts are discovered and recorded for an active connection

- GIVEN a `bankfeed_connection` in `active` state
- WHEN account discovery runs
- THEN each authorised account's IBAN/BIC/currency/account-id SHALL be recorded
  on the connection, with no token exposed
- @e2e exclude backend account discovery — covered by PHPUnit

#### Scenario: re-discovery is idempotent

- GIVEN a connection whose accounts were already discovered
- WHEN discovery runs again
- THEN the account set SHALL be updated in place with no duplicates
- @e2e exclude backend discovery idempotency — covered by PHPUnit

### Requirement: Scheduled transaction sync emitting a synced event with a batch URI (REQ-004)

Transaction pulls MUST run on a schedule via the existing `job-scheduling`
machinery: a `BankfeedSyncJob` action, registered as an NC `TimedJob` and swept
by `JobService`, on an operator-configurable cadence (default 4× daily). For
each `active` `bankfeed_connection` and each of its accounts, the job MUST pull
transactions from the last successful watermark (`since`) to now (`until`) via
the provider, persist them as a `bankfeed_batch` OR object, and emit a
`nl.conduction.bankfeed.transactions.synced` CloudEvent with payload
`{connectionId, accountIban, since, until, transactionCount, batchUri}` where
`batchUri` references the persisted `bankfeed_batch`. The payload field names
MUST match shillinq `bookkeeping-bank-connectors` (the consume side). The job
MUST skip connections whose `lifecycleState` is not `active` (e.g. expired or
revoked) rather than call the aggregator with a dead consent, and MUST advance
the watermark only on a successful pull so a failed pull re-attempts the same
window without gaps or double-counting.

#### Scenario: a scheduled pull emits a synced event referencing the batch

- GIVEN an `active` connection with account IBAN `NL00BANK0123456789` and new
  transactions since the last watermark
- WHEN `BankfeedSyncJob` runs
- THEN a `bankfeed_batch` SHALL be persisted AND a
  `nl.conduction.bankfeed.transactions.synced` event SHALL be emitted with
  `{connectionId, accountIban:'NL00BANK0123456789', since, until,
  transactionCount, batchUri}`
- @e2e exclude backend scheduled sync + event — covered by PHPUnit

#### Scenario: an expired connection is skipped, not called

- GIVEN a connection whose `lifecycleState` is `expired`
- WHEN the sync job runs
- THEN no aggregator call SHALL be made for it and no synced event SHALL be emitted
- @e2e exclude backend lifecycle guard — covered by PHPUnit

#### Scenario: a failed pull does not advance the watermark

- GIVEN a connection whose provider pull throws
- WHEN the sync job runs
- THEN the watermark SHALL NOT advance and the same window SHALL be re-attempted
  on the next run
- @e2e exclude backend watermark integrity — covered by PHPUnit

### Requirement: Consent-lifecycle CloudEvents for consumer state transitions (REQ-005)

The connector MUST emit CloudEvents on consent lifecycle changes so a consuming
app (shillinq `BankConnection`, REQ-BC-005/006) can transition its own state:
`nl.conduction.bankfeed.consent.granted` on successful callback,
`nl.conduction.bankfeed.consent.expiring` when `consentExpiresAt` is within the
configured warning window, and `nl.conduction.bankfeed.consent.revoked` when the
aggregator or operator revokes. Each payload MUST carry `{connectionId,
aggregatorSourceSlug, consentReference, consentExpiresAt}`. The connector MUST
NOT itself mutate any consuming app's records — it only emits events.

#### Scenario: a granted consent emits a granted event

- GIVEN a successful `GET /api/psd2/callback`
- WHEN the connection becomes `active`
- THEN a `nl.conduction.bankfeed.consent.granted` event SHALL be emitted with
  the connection identifiers
- @e2e exclude backend consent event — covered by PHPUnit

#### Scenario: an approaching expiry emits an expiring event once

- GIVEN a connection whose `consentExpiresAt` enters the warning window
- WHEN the lifecycle check runs
- THEN a single `nl.conduction.bankfeed.consent.expiring` event SHALL be emitted
  (not repeated every sweep)
- @e2e exclude backend expiry event — covered by PHPUnit

### Requirement: Consent tokens and aggregator credentials brokered, never plaintext (REQ-006)

Aggregator OAuth tokens, refresh tokens, and API keys MUST be resolved through
the OpenRegister credential broker via `authentication.credentialRef` and MUST
NOT be stored as plaintext in source configuration, the `bankfeed_connection`
object, exports, logs, or error messages (ADR-007, aligning shillinq
REQ-BC-002/003). When required token material cannot be supplied for the `rest`
provider, the connector MUST fail closed with an actionable configuration error
and MUST NOT fall back to a plaintext token. The `log` provider needs no secret
and MUST remain usable with none configured.

#### Scenario: the token is brokered and never appears in config, objects, or logs

- GIVEN a `rest` PSD2 source configured with `authentication.credentialRef`
- WHEN an aggregator call is dispatched
- THEN the token SHALL be resolved through the credential broker
- AND the token SHALL NOT appear in source config, the `bankfeed_connection`,
  exports, logs, or errors
- @e2e exclude backend credential brokering — covered by PHPUnit

#### Scenario: absent token material fails closed with no plaintext fallback

- GIVEN a `rest` PSD2 source whose `credentialRef` cannot supply the token
- WHEN a sync is attempted
- THEN it SHALL fail with an actionable config error
- AND no plaintext-token fallback SHALL be used
- @e2e exclude backend credential brokering — covered by PHPUnit

