# Design: corporate-card-feed

## Architecture Overview

```
  operator browser                              shillinq (consume side, ADR-022, follow-up)
        │                                              │ references cardfeedSourceSlug
        │ POST /api/cardfeed/sources/{slug}/enroll ──► CardfeedController.enroll ──► CardfeedSyncService.enrollSource
        │                                              │        └─ CardfeedProviderInterface.listCards
        │ ◄──── {accountId, cards:[…]}                 │              ├─ LogCardfeedProvider (sandbox)
        │                                              │              └─ RestCardfeedProvider ─► BrokeredCallService ─► card program
        │
  cron sweep (job-scheduling) ──► CardfeedSyncJob ──► CardfeedSyncService.syncAll
        └─ per active account/card: listTransactions(since,until) ─► filter seenTransactionIds
             └─ persist cardfeed_batch (OR, NEW txns only) + record seen ids
                  └─ emit nl.conduction.cardfeed.transactions.synced {accountId, cardId, since, until, transactionCount, batchUri}
```

Transport, scheduling, and auth reuse existing machinery: the provider's `rest`
calls go through `BrokeredCallService`; the sync runs as a `TimedJob` swept by
`JobService` (`job-scheduling`); events fan out through `EventService`
(`events-cloudevents`). The only new abstraction is the card-provider seam,
mirroring `Psd2AggregatorProviderInterface`.

## API Design

### `POST /api/cardfeed/sources/{sourceSlug}/enroll`
**Response:**
```json
{ "accountId": "…", "cardfeedSourceSlug": "card-provider-sandbox", "cards": [ { "cardId": "SANDBOX-CARD-1", "last4": "4242", "cardholderName": "A. Example", "currency": "EUR" } ], "lifecycleState": "active" }
```
Idempotent: a second enroll of the same source replaces the card set in place on
the existing `cardfeed_account` (keyed by `cardId`), never duplicating it.

## Database Changes

Two new OR schemas added declaratively to
`lib/Settings/openconnector_register.json` (register `openconnector`); no SQL
migration — persisted as OpenRegister objects.

**`cardfeed_account`**

| Field | Type | Purpose |
|-------|------|---------|
| `accountId` | string | Stable id referenced by the event + shillinq FK |
| `cardfeedSourceSlug` | string | FK to the openconnector `Source` slug |
| `provider` | enum | `stripe-issuing\|adyen\|ramp\|moss\|sandbox` |
| `cards` | array | `{cardId, last4, cardholderName, currency}` |
| `lastSyncAt` | date-time\|null | Watermark of the last successful pull |
| `seenTransactionIds` | array | Transaction ids already emitted (idempotency set) |
| `lifecycleState` | enum | `pending\|active\|disabled` |

**`cardfeed_batch`**

| Field | Type | Purpose |
|-------|------|---------|
| `accountId` | string | Owning account |
| `cardId` | string | Card the batch covers |
| `since` / `until` | date-time | Window pulled |
| `transactionCount` | int | Number of NEW transactions |
| `transactions` | array | Provider-normalised transaction rows (new only) |

No API-key/secret field on either schema.

## Nextcloud Integration

- Controllers: `CardfeedController` (`enroll`).
- Services: `CardfeedSyncService`, `Cardfeed\CardfeedProviderInterface` +
  `LogCardfeedProvider` + `RestCardfeedProvider`.
- Cron: `lib/Cron/CardfeedSyncJob.php` (`TimedJob` swept by `JobService`;
  registered via `<background-jobs>` in `appinfo/info.xml`, matching
  `BankfeedSyncJob`'s registration pattern).
- Mappers/Entities: none new — `cardfeed_account`/`cardfeed_batch` via OR
  `ObjectService`.
- Events/Hooks: emits `nl.conduction.cardfeed.transactions.synced` via
  `EventService`.

## Security Considerations

- Program API keys live only behind the credential broker (`credentialRef`);
  fail-closed on missing material, no plaintext fallback (ADR-007).
  `cardfeed_account` carries no secret.
- Enrollment grants read access to real card data — the enroll endpoint MUST be
  action-RBAC gated (ADR-023, default admin-only); the sync job runs in cron
  context.
- Exported configuration MUST redact any credential material per
  `configuration-export-import`.

## Idempotency (REQ-004)

The bank feed persists a batch every sweep (watermark-only). A card feed instead
dedupes on `transactionId`: each `cardfeed_account` carries `seenTransactionIds`.
On each sweep the sync pulls the `[since, until]` window, drops any transaction
whose id is already in `seenTransactionIds`, and only if new transactions remain
does it persist a `cardfeed_batch` and emit the synced event — then it appends
the new ids and advances the watermark. Consequently a **replayed sync** (the
provider returning the same transactions) yields zero new transactions and so
persists nothing and emits nothing. The seen set is bounded to the most recent
`MAX_SEEN_TRANSACTION_IDS` ids (oldest evicted) so it cannot grow without limit;
eviction is safe because the watermark advances past evicted ids' windows.

## Declarative-vs-imperative decision (ADR-031)

The `cardfeed_account` and `cardfeed_batch` schemas, the three-state
`lifecycleState` enum, and `x-openregister-notifications` (notify on a new
`cardfeed_batch`) are declared **declaratively** in
`lib/Settings/openconnector_register.json`. Enrollment, provider calls, the
transaction-id dedup, and the scheduled pull are **imperative** and justified
under ADR-031's "external integration" and "scheduled bulk work" exemptions: a
brokered provider call and a paged transaction pull on a cron cadence are
external-integration side effects that cannot be expressed as a declarative
lifecycle. This mirrors `psd2-ais-bank-feed-connector` and `job-scheduling`
(imperative jobs, declarative log/retention).

## File Structure

```
lib/
  Controller/
    CardfeedController.php            # enroll()
  Service/
    Cardfeed/
      CardfeedProviderInterface.php
      LogCardfeedProvider.php
      RestCardfeedProvider.php        # generic /cards → /cards/{id}/transactions shape
    CardfeedSyncService.php
  Cron/
    CardfeedSyncJob.php               # TimedJob, swept by JobService
  Exception/
    CardfeedProviderException.php
  Settings/
    openconnector_register.json       # + cardfeed_account, cardfeed_batch
appinfo/
  routes.php                          # + /api/cardfeed/* route
  info.xml                            # + <background-jobs> CardfeedSyncJob
```

## Seed Data

A sandbox card-program source (`provider: log`) plus one example account with a
card and one example batch so a fresh install demonstrates enroll → sync → event
without a real provider.

### Schema: `cardfeed_account`
| Field | Object 1 |
|-------|----------|
| slug | acct-conduction-cards |
| accountId | 00000000-0000-0000-0000-000000000000 |
| cardfeedSourceSlug | card-provider-sandbox |
| provider | sandbox |
| cards[].cardId / last4 | SANDBOX-CARD-1 / 4242 |
| lifecycleState | active |

### Schema: `cardfeed_batch`
| Field | Object 1 |
|-------|----------|
| slug | batch-conduction-cards-2026-07-01 |
| accountId | 00000000-0000-0000-0000-000000000000 |
| cardId | SANDBOX-CARD-1 |
| since / until | 2026-06-30 / 2026-07-01 |
| transactionCount | 2 |

**Sandbox source** (`configuration.provider: log`) returns canned cards and
transactions with no upstream call and no secret.

**Related items per object:**
- Files: the `cardfeed_batch.transactions` payload (canned provider-shaped rows).
- Notes: none.
- Contacts: the card-holding organisation (Conduction B.V. flavour).

## Trade-offs

- **Own the account object vs store cards on the Source.** Chose a
  `cardfeed_account` OR object so the sync job, card set, watermark, and
  `seenTransactionIds` have a first-class home and a stable `accountId` for the
  event/shillinq FK. Stashing everything in `Source.configuration` was rejected:
  it overloads the source record and has no natural per-card/per-batch model.
- **Idempotency by seen-id set vs by re-query.** Chose an on-account
  `seenTransactionIds` set (bounded, appended per sweep) over re-querying every
  persisted batch, which would grow O(batches) per sweep. The set is trimmed to
  a fixed size so it cannot grow unbounded.
- **No SCA/consent flow.** Unlike the PSD2 bank feed, corporate card programs
  authenticate with a program API key (broker-held), so the connect/callback
  redirect machinery is deliberately absent — enrollment is a single
  admin-gated discovery call.

## Open Questions

None blocking — sandbox provider makes the path self-contained; cadence is
per-job configuration.
