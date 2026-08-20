# Tasks — corporate-card-feed

## 1. Data model and seed

### Task 1: Declare `cardfeed_account` + `cardfeed_batch` schemas and seed data
- **spec_ref**: `openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003`
- **files**: `lib/Settings/openconnector_register.json`, `lib/Settings/openconnector_seed_data.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN `cardfeed_account` (with the three-state `lifecycleState` enum, `cards`, `seenTransactionIds`, no secret field) and `cardfeed_batch` schemas exist
  - GIVEN the seed data WHEN the app installs THEN a `provider: log` sandbox source, 1 example account, and 1 example batch are present (nil-UUID ids)
- [x] Implement
- [x] Test

## 2. Provider abstraction

### Task 2: Add the card-provider interface with log and generic-REST bindings
- **spec_ref**: `openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-card-provider-abstraction-with-log-and-generic-rest-bindings-req-001`
- **files**: `lib/Service/Cardfeed/CardfeedProviderInterface.php`, `lib/Service/Cardfeed/LogCardfeedProvider.php`, `lib/Service/Cardfeed/RestCardfeedProvider.php`, `lib/Exception/CardfeedProviderException.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` WHEN cards/transactions are requested THEN canned data is returned with no HTTP call and no credential read
  - GIVEN `configuration.provider: rest` WHEN a call is dispatched THEN it routes through `BrokeredCallService` with the API key injected via `credentialRef` and absent from config/logs (`/cards` → `/cards/{id}/transactions` shape, `YOUR_API_KEY_HERE` placeholder)
- [x] Implement
- [x] Test

## 3. Enrollment

### Task 3: Add the enroll + card-discovery endpoint
- **spec_ref**: `openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-source-enrollment-and-card-discovery-req-002`
- **files**: `lib/Controller/CardfeedController.php`, `lib/Service/CardfeedSyncService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a source WHEN `POST /api/cardfeed/sources/{sourceSlug}/enroll` is called THEN each card's cardId/last4/cardholder/currency is recorded on an `active` `cardfeed_account` with no secret exposed
  - GIVEN an already-enrolled source WHEN enrollment re-runs THEN the card set updates in place with no duplicates and no second account
- [x] Implement
- [x] Test

## 4. Scheduled sync and idempotency

### Task 4: Add the scheduled transaction sync job emitting the synced event
- **spec_ref**: `openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003`
- **files**: `lib/Cron/CardfeedSyncJob.php`, `lib/Service/CardfeedSyncService.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN an `active` account with new transactions WHEN `CardfeedSyncJob` runs THEN a `cardfeed_batch` is persisted and `nl.conduction.cardfeed.transactions.synced` `{accountId, cardId, since, until, transactionCount, batchUri}` is emitted
  - GIVEN a `disabled` account THEN it is skipped with no provider call; GIVEN a failed pull THEN the watermark does not advance
- [x] Implement
- [x] Test

### Task 5: Make the sync idempotent on transaction id
- **spec_ref**: `openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-idempotent-sync-on-transaction-id-req-004`
- **files**: `lib/Service/CardfeedSyncService.php`
- **acceptance_criteria**:
  - GIVEN a first sweep that emitted a batch of transactions WHEN a second sweep returns the same transactions THEN no batch is persisted and no synced event is emitted
  - AND the `seenTransactionIds` set is bounded to a fixed maximum
- [x] Implement
- [x] Test

## 5. Credential brokering and localisation

### Task 6: Broker the API key (fail closed) and ship EN + NL strings
- **spec_ref**: `openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-card-provider-credentials-brokered-never-plaintext-req-005`
- **files**: `lib/Service/Cardfeed/RestCardfeedProvider.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a `rest` source whose `credentialRef` cannot supply the key WHEN a call is dispatched THEN it fails closed with an actionable error and no plaintext fallback
  - GIVEN new user-facing strings WHEN the app is loaded in `nl` THEN Dutch translations render for every English source key
- [x] Implement
- [x] Test

### NL translations (plain bullets, not tracked checkboxes)
- `Enroll a card program` → `Kaartprogramma aanmelden`
- `Transactions synced` → `Transacties gesynchroniseerd`
- `Card provider credential missing` → `Inloggegeven kaartaanbieder ontbreekt`
- `Card enrollment failed` → `Aanmelden van kaartprogramma mislukt`

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite (LogCardfeedProviderTest, RestCardfeedProviderTest, CardfeedSyncServiceTest, CardfeedControllerTest, CardfeedSyncJobTest cover enroll → sync → idempotency → events end-to-end on the sandbox provider)
- [x] Code review against spec requirements — self-reviewed; see Deviations below

## Deviations

- **No SCA/consent flow.** Unlike `psd2-ais-bank-feed-connector`, corporate card
  programs authenticate with a program API key (broker-held), so no
  connect/callback redirect machinery is present; enrollment is a single
  admin-gated discovery call.
- **Idempotency by seen-id set.** Each `cardfeed_account` carries a
  `seenTransactionIds` set, trimmed to `MAX_SEEN_TRANSACTION_IDS` (oldest
  evicted), against which each sweep dedupes; eviction is safe because the
  watermark advances past evicted ids' windows.
- **Watermark atomicity is per account+card.** All of a card's transactions are
  filtered and persisted in one batch before the watermark advances, so a
  mid-pull failure persists nothing and re-attempts the same window.
- **Two schema fields beyond the design table core:** `seenTransactionIds`
  (idempotency set, REQ-004) and the `pending` enum value (reserved for a source
  enrolled but not yet card-discovered).
