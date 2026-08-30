# corporate-card-feed Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- corporate-card-feed

## Purpose

OpenConnector ingests corporate/credit-card statement feeds from a card-program
provider and exposes them to sibling apps as card transactions, per ADR-022. It
owns the card-provider abstraction, program API-key storage (credential broker,
ADR-007), card discovery, and the scheduled transaction sync that emits
`nl.conduction.cardfeed.transactions.synced`. A card feed is structurally a
transaction feed like `psd2-ais-bank-feed-connector` and is built on the same
`source-management`, `job-scheduling`, and `events-cloudevents` capabilities —
not a parallel framework. shillinq references the openconnector `Source` slug
and consumes the events from a separate follow-up
(`bookkeeping-card-reconciliation`); no provider client or credential lives in
shillinq (ADR-022).

## ADDED Requirements

### Requirement: Card-provider abstraction with log and generic-REST bindings (REQ-001)

The connector MUST define a `CardfeedProviderInterface`
(`lib/Service/Cardfeed/`) exposing at minimum `listCards(sourceConfiguration)`
and `listTransactions(sourceConfiguration, cardId, since, until)`. A source's
`configuration.provider` selects the binding:

- `log` — `LogCardfeedProvider`, a sandbox binding that performs no real network
  call, returns canned cards and canned transactions, and reads no secret. It is
  the default for dev/CI and mirrors the `source-management` mock-mode
  convention and the sibling `LogPsd2AggregatorProvider`.
- `rest` — `RestCardfeedProvider`, a generic REST card-provider binding
  (`/cards` → `/cards/{id}/transactions`), driven by `configuration.baseUrl` and
  `authentication.credentialRef`; every outbound call MUST go through the
  credential broker (`BrokeredCallService`) so the program API key is injected at
  call time and never stored in the source config.

Adding a new provider vendor MUST be done by implementing the interface, not by
editing the enroll or sync services. All example secrets MUST use placeholder
values (`YOUR_API_KEY_HERE`).

#### Scenario: the log provider drives the full path without a network call or secret

- GIVEN a cardfeed source with `configuration.provider: log`
- WHEN cards are listed and transactions pulled
- THEN canned data SHALL be returned for each step with no outbound HTTP call
  and no credential read
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the rest provider brokers its api key

- GIVEN a cardfeed source with `configuration.provider: rest`,
  `configuration.baseUrl`, and `authentication.credentialRef`
- WHEN a provider call is dispatched
- THEN the call SHALL route through the credential broker with the API key
  injected at call time
- AND the key SHALL NOT appear in the source configuration, exports, or logs
- @e2e exclude backend credential brokering — covered by PHPUnit

### Requirement: Source enrollment and card discovery (REQ-002)

The connector MUST expose `POST /api/cardfeed/sources/{sourceSlug}/enroll` that
calls the provider's `listCards` and records a `cardfeed_account` OR object
carrying `accountId`, `cardfeedSourceSlug`, `provider`, the discovered `cards`
(each `{cardId, last4, cardholderName, currency}`), and `lifecycleState='active'`.
Enrollment MUST be idempotent: re-enrolling the same source updates the card set
in place (keyed by `cardId`) on the existing account and never duplicates it or
creates a second account. The endpoint MUST be action-RBAC gated (ADR-023,
default admin-only) because it grants access to real card data.

#### Scenario: enrollment discovers and records cards idempotently

- GIVEN a cardfeed source `sourceSlug`
- WHEN `POST /api/cardfeed/sources/{sourceSlug}/enroll` is called twice
- THEN a single `cardfeed_account` SHALL exist with each discovered card recorded
  once (no duplicates), carrying `lifecycleState='active'` and no secret
- @e2e exclude backend enrollment — covered by PHPUnit

### Requirement: Scheduled transaction sync emitting a synced event with a batch URI (REQ-003)

Transaction pulls MUST run on a schedule via the existing `job-scheduling`
machinery: a `CardfeedSyncJob` action, registered as an NC `TimedJob` and swept
by `JobService`, on an operator-configurable cadence (default 4× daily). For each
`active` `cardfeed_account` and each of its cards, the job MUST pull transactions
from the last successful watermark (`since`) to now (`until`) via the provider,
persist the new transactions as a `cardfeed_batch` OR object, and emit a
`nl.conduction.cardfeed.transactions.synced` CloudEvent with payload
`{accountId, cardId, since, until, transactionCount, batchUri}` where `batchUri`
references the persisted `cardfeed_batch`. The job MUST skip accounts whose
`lifecycleState` is not `active` (e.g. `disabled`) rather than call the provider,
and MUST advance the watermark only on a successful pull so a failed pull
re-attempts the same window without gaps.

#### Scenario: a scheduled pull emits a synced event referencing the batch

- GIVEN an `active` account with card `SANDBOX-CARD-1` and new transactions since
  the last watermark
- WHEN `CardfeedSyncJob` runs
- THEN a `cardfeed_batch` SHALL be persisted AND a
  `nl.conduction.cardfeed.transactions.synced` event SHALL be emitted with
  `{accountId, cardId:'SANDBOX-CARD-1', since, until, transactionCount, batchUri}`
- @e2e exclude backend scheduled sync + event — covered by PHPUnit

#### Scenario: a disabled account is skipped, not called

- GIVEN an account whose `lifecycleState` is `disabled`
- WHEN the sync job runs
- THEN no provider call SHALL be made for it and no synced event SHALL be emitted
- @e2e exclude backend lifecycle guard — covered by PHPUnit

### Requirement: Idempotent sync on transaction id (REQ-004)

The sync MUST be idempotent on `transactionId`: each `cardfeed_account` MUST
record the transaction ids it has already emitted (`seenTransactionIds`), and a
sweep MUST drop any pulled transaction whose id is already recorded before
persisting a batch or emitting the event. A replayed sync — the provider
returning the same transactions — MUST therefore persist no `cardfeed_batch` and
emit no synced event. The seen-id set MUST be bounded so it cannot grow without
limit.

#### Scenario: a replayed sync does not re-emit already-seen transactions

- GIVEN an `active` account whose first sweep emitted a batch of 2 transactions
- WHEN a second sweep runs and the provider returns the same 2 transactions
- THEN no new `cardfeed_batch` SHALL be persisted AND no
  `nl.conduction.cardfeed.transactions.synced` event SHALL be emitted on the
  second sweep
- @e2e exclude backend idempotency — covered by PHPUnit

### Requirement: Card-provider credentials brokered, never plaintext (REQ-005)

Program API keys MUST be resolved through the OpenRegister credential broker via
`authentication.credentialRef` and MUST NOT be stored as plaintext in source
configuration, the `cardfeed_account` object, exports, logs, or error messages
(ADR-007). When the required API key cannot be supplied for the `rest` provider,
the connector MUST fail closed with an actionable configuration error and MUST
NOT fall back to a plaintext key. The `log` provider needs no secret and MUST
remain usable with none configured.

#### Scenario: absent api key fails closed with no plaintext fallback

- GIVEN a `rest` cardfeed source whose `credentialRef` cannot supply the key
- WHEN a sync is attempted
- THEN it SHALL fail with an actionable config error
- AND no plaintext-key fallback SHALL be used
- @e2e exclude backend credential brokering — covered by PHPUnit

## Non-Functional Requirements

- **Performance:** the sync job MUST page provider transaction results and bound
  each `cardfeed_batch`; the `seenTransactionIds` set MUST be trimmed to a fixed
  size so an account record cannot grow unbounded.
- **Accessibility:** any cardfeed-source configuration UI reuses existing
  `source-management` components (no new bespoke controls).
- **Internationalization:** Dutch and English MUST be supported for all new
  user-facing strings (English source keys) (hydra ADR-007).

## Acceptance Criteria

- [ ] `CardfeedProviderInterface` with `log` + `rest` bindings; `rest` brokers its API key
- [ ] `POST /api/cardfeed/sources/{sourceSlug}/enroll` discovers and records cards idempotently
- [ ] `CardfeedSyncJob` pulls per-account and emits `nl.conduction.cardfeed.transactions.synced` with `batchUri`
- [ ] A replayed sync persists no batch and emits no event (idempotent on transaction id)
- [ ] API keys resolved via `credentialRef`; none appear in config/objects/logs

## Notes

- Cross-app contract: the consume side (matching card transactions to expenses /
  GL entries) is a separate shillinq follow-up
  (`bookkeeping-card-reconciliation`), not built here. Field names (`accountId`,
  `cardId`, `transactionCount`, `batchUri`) are the producer-side contract owned
  by this change.
- Reuses `job-scheduling` (`CardfeedSyncJob` as a `TimedJob` + cron sweep),
  `events-cloudevents` (event emission), `source-management` (source/config +
  mock mode), and `configuration-export-import` (redaction). Structurally
  mirrors `psd2-ais-bank-feed-connector`.
