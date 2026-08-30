# Design: psd2-ais-bank-feed-connector

## Architecture Overview

```
  operator browser                         shillinq BankConnection (consume side, ADR-022)
        │                                          │ references aggregatorSourceSlug / consentReference
        │ POST /api/psd2/connect ──► Psd2Controller.connect ──► Psd2AggregatorProviderInterface.createRequisition
        │                                          │               ├─ LogPsd2AggregatorProvider (sandbox)
        │ ◄──── bank SCA redirect URL              │               └─ RestPsd2AggregatorProvider ─► BrokeredCallService ─► aggregator
        │ (operator authenticates at bank)         │
        └─ GET /api/psd2/callback ──► finaliseConsent ──► credential broker (token)  +  bankfeed_connection (OR, consentReference)
                                                          └─ emit nl.conduction.bankfeed.consent.granted

  cron sweep (job-scheduling) ──► BankfeedSyncJob ──► BankfeedSyncService
        └─ per active connection/account: listTransactions(since,until) ──► persist bankfeed_batch (OR)
             └─ emit nl.conduction.bankfeed.transactions.synced {connectionId, accountIban, since, until, transactionCount, batchUri}
```

Transport, scheduling, and auth reuse existing machinery: the aggregator calls
go through `BrokeredCallService`; the sync runs as a `JobTask` action swept by
`JobService` (`job-scheduling`); events fan out through `EventService`
(`events-cloudevents`). The only new abstraction is the aggregator provider seam.

## API Design

### `POST /api/psd2/connect`
**Request:**
```json
{ "sourceSlug": "bank-aggregator-sandbox", "institutionId": "SANDBOX_BANK", "redirectUrl": "https://nc.example/apps/shillinq/psd2/return" }
```
**Response:**
```json
{ "redirectUrl": "https://aggregator.example/psd2/sca/REQ-123", "reference": "REQ-123" }
```

### `GET /api/psd2/callback?ref=REQ-123`
Finalises consent, brokers the token, persists `bankfeed_connection`, emits
`nl.conduction.bankfeed.consent.granted`. Redirects the browser back to the
caller's `redirectUrl`.
**Effect (internal):**
```json
{ "connectionId": "00000000-0000-0000-0000-000000000000", "consentReference": "REQ-123", "consentExpiresAt": "2026-10-10T00:00:00Z", "lifecycleState": "active" }
```

## Database Changes

Two new OR schemas added declaratively to
`lib/Settings/openconnector_register.json` (register `openconnector`); no SQL
migration — persisted as OpenRegister objects.

**`bankfeed_connection`**

| Field | Type | Purpose |
|-------|------|---------|
| `connectionId` | string | Stable id referenced by the event + shillinq FK |
| `aggregatorSourceSlug` | string | FK to the openconnector `Source` slug |
| `aggregator` | enum | `gocardless\|tink\|klarna-kosma\|yapily\|sandbox` |
| `consentReference` | string | Aggregator consent id (non-credential) |
| `consentGrantedAt` | date-time | When consent was granted |
| `consentExpiresAt` | date-time | 90-day SCA renewal deadline |
| `accounts` | array | `{iban, bic, currency, aggregatorAccountId}` |
| `lastSyncAt` | date-time\|null | Watermark of the last successful pull |
| `lifecycleState` | enum | `pending\|active\|expiring\|expired\|revoked` |

**`bankfeed_batch`**

| Field | Type | Purpose |
|-------|------|---------|
| `connectionId` | string | Owning connection |
| `accountIban` | string | Account the batch covers |
| `since` / `until` | date-time | Window pulled |
| `transactionCount` | int | Number of transactions |
| `transactions` | array | Aggregator-normalised transaction rows |

No token/secret field on either schema (aligns shillinq REQ-BC-002/003).

## Nextcloud Integration

- Controllers: `Psd2Controller` (`connect`, `callback`, `discoverAccounts`).
- Services: `BankfeedSyncService`, `Psd2\Psd2AggregatorProviderInterface` +
  `LogPsd2AggregatorProvider` + `RestPsd2AggregatorProvider`.
- Cron: `lib/Cron/BankfeedSyncJob.php` (`JobTask` action swept by `JobService`;
  registered via `<background-jobs>` in `appinfo/info.xml`, matching
  `EventRetryJob`'s registration pattern).
- Mappers/Entities: none new — `bankfeed_connection`/`bankfeed_batch` via OR
  `ObjectService`.
- Events/Hooks: emits `nl.conduction.bankfeed.transactions.synced` and the
  consent-lifecycle events via `EventService`.

## Security Considerations

- OAuth/consent tokens and API keys live only behind the credential broker
  (`credentialRef`); fail-closed on missing material, no plaintext fallback
  (ADR-007). `bankfeed_connection` carries `consentReference` only.
- `GET /api/psd2/callback` MUST validate the aggregator `ref`/state against the
  pending requisition it created (CSRF/mix-up defence) before finalising, and
  MUST only redirect back to the `redirectUrl` registered at `connect` time
  (open-redirect defence).
- Exported configuration MUST redact any credential material per
  `configuration-export-import`.
- Consent tokens grant read access to real bank data — access to the
  connect/callback/discovery endpoints MUST be admin-gated; the sync job runs in
  cron context. (Note the `job-scheduling` IDOR flags in REQ-002 of that spec
  apply to job triggering generally and are not widened here.)

## Declarative-vs-imperative decision (ADR-031)

The `bankfeed_connection` and `bankfeed_batch` schemas, the five-state
`lifecycleState` enum, and `x-openregister-notifications` (e.g. notify on a new
`bankfeed_batch`) are declared **declaratively** in
`lib/Settings/openconnector_register.json`. The SCA flow, aggregator calls, and
scheduled transaction sync are **imperative** and justified under ADR-031's
"external integration" and "scheduled bulk work" exemptions: a redirect-based
OAuth/SCA handshake, brokered token exchange, and a paged transaction pull on a
cron cadence are external-integration side effects that cannot be expressed as a
declarative lifecycle. The connection *state* is recorded declaratively; the
network work is service code. This mirrors `job-scheduling` (imperative jobs,
declarative log/retention) and shillinq's own split (declarative
`x-openregister-lifecycle`, no embedded client).

## File Structure

```
lib/
  Controller/
    Psd2Controller.php              # connect(), callback(), discoverAccounts()
  Service/
    Psd2/
      Psd2AggregatorProviderInterface.php
      LogPsd2AggregatorProvider.php
      RestPsd2AggregatorProvider.php   # GoCardless Bank-Account-Data shape
    BankfeedSyncService.php
  Cron/
    BankfeedSyncJob.php             # TimedJob action, swept by JobService
  Settings/
    openconnector_register.json     # + bankfeed_connection, bankfeed_batch
appinfo/
  routes.php                        # + /api/psd2/* routes
  info.xml                          # + <background-jobs> BankfeedSyncJob
```

## Seed Data

A sandbox aggregator source (`provider: log`) plus example connections and
batches so a fresh install demonstrates connect → discovery → sync → event
without a real aggregator.

### Schema: `bankfeed_connection`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | conn-gemeente-nijmegen | conn-consultancy-bv | conn-travel-agency |
| connectionId | 00000000-0000-0000-0000-000000000000 | 00000000-0000-0000-0000-000000000000 | 00000000-0000-0000-0000-000000000000 |
| aggregatorSourceSlug | bank-aggregator-sandbox | bank-aggregator-sandbox | bank-aggregator-sandbox |
| aggregator | sandbox | sandbox | sandbox |
| consentReference | REQ-SANDBOX-1 | REQ-SANDBOX-2 | REQ-SANDBOX-3 |
| consentExpiresAt | 2026-10-10T00:00:00Z | 2026-07-20T00:00:00Z | 2026-10-31T00:00:00Z |
| bankAccountIban (accounts[].iban) | NL00BANK0000000001 | NL00BANK0000000002 | NL00BANK0000000003 |
| lifecycleState | active | expiring | active |

### Schema: `bankfeed_batch`
| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | batch-nijmegen-2026-07-01 | batch-consultancy-2026-07-01 |
| connectionId | 00000000-0000-0000-0000-000000000000 | 00000000-0000-0000-0000-000000000000 |
| accountIban | NL00BANK0000000001 | NL00BANK0000000002 |
| since / until | 2026-06-30 / 2026-07-01 | 2026-06-30 / 2026-07-01 |
| transactionCount | 12 | 3 |

**Sandbox source** (`configuration.provider: log`) returns canned SCA URL,
accounts, and transactions with no upstream call and no secret.

**Related items per object:**
- Files: the `bankfeed_batch.transactions` payload (canned aggregator-shaped rows).
- Notes: none.
- Tasks: none.
- Contacts: the account-holder organisation (municipality / consultancy / travel agency flavour).

## Trade-offs

- **Own the connection object vs store consent only on the Source.** Chose a
  `bankfeed_connection` OR object so the sync job, lifecycle events, and account
  set have a first-class home and a stable `connectionId` for the event/shillinq
  FK. Alternative (stashing everything in `Source.configuration`) rejected: it
  overloads the source record and has no natural per-account/per-batch model.
- **TimedJob sync vs OR ScheduledWorkflow.** shillinq's REQ-BC-004 runs a
  `ScheduledWorkflow` on *its* side to materialise CAMT.053; the openconnector
  side only needs to pull + emit, which fits the existing `job-scheduling`
  `JobTask` machinery. Keeping the pull in openconnector's own job surface avoids
  a second scheduling framework and reuses its retention/log plumbing.
- **GoCardless-shape generic REST provider.** Chose the requisition→account→
  transaction shape as the generic template because it is the simplest AIS
  vendor model and maps cleanly onto the interface; other vendors (Tink, Yapily)
  differ only in endpoint/auth detail, absorbed by a new provider class.

## Open Questions

None blocking — sandbox provider makes the path self-contained; cadence and
warning window are per-job/per-source configuration.
