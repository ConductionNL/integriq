# Tasks — psd2-ais-bank-feed-connector

## 1. Data model and seed

### Task 1: Declare `bankfeed_connection` + `bankfeed_batch` schemas and seed data
- **spec_ref**: `openspec/specs/psd2-ais-bank-feed-connector/spec.md#req-004-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri`
- **files**: `lib/Settings/openconnector_register.json`, `lib/Settings/openconnector_seed_data.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN `bankfeed_connection` (with the five-state `lifecycleState` enum and `consentReference`, no token field) and `bankfeed_batch` schemas exist
  - GIVEN the seed data WHEN the app installs THEN a `provider: log` sandbox source, 3 example connections, and 2 example batches are present (nil-UUID ids, `REQ-SANDBOX-*` references)
- [x] Implement
- [x] Test

## 2. Provider abstraction

### Task 2: Add the aggregator provider interface with log and generic-REST bindings
- **spec_ref**: `openspec/specs/psd2-ais-bank-feed-connector/spec.md#req-001-aggregator-provider-abstraction-with-log-and-generic-rest-bindings`
- **files**: `lib/Service/Psd2/Psd2AggregatorProviderInterface.php`, `lib/Service/Psd2/LogPsd2AggregatorProvider.php`, `lib/Service/Psd2/RestPsd2AggregatorProvider.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` WHEN requisition/accounts/transactions are requested THEN canned data is returned with no HTTP call and no credential read
  - GIVEN `configuration.provider: rest` WHEN a call is dispatched THEN it routes through `BrokeredCallService` with the token injected via `credentialRef` and absent from config/logs (GoCardless requisition→account→transaction shape)
- [x] Implement
- [x] Test

## 3. Consent flow

### Task 3: Add the redirect-based SCA connect/callback endpoints
- **spec_ref**: `openspec/specs/psd2-ais-bank-feed-connector/spec.md#req-002-redirect-based-sca-consent-flow`
- **files**: `lib/Controller/Psd2Controller.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a source + institution WHEN `POST /api/psd2/connect` is called THEN the bank SCA redirect URL is returned
  - GIVEN a completed bank auth WHEN `GET /api/psd2/callback` runs THEN the token is broker-stored, a `bankfeed_connection` (`consentReference`, `consentExpiresAt`, `active`) is persisted carrying no token, and the `ref`/`redirectUrl` are validated against the pending requisition
- [x] Implement
- [x] Test

### Task 4: Add account discovery
- **spec_ref**: `openspec/specs/psd2-ais-bank-feed-connector/spec.md#req-003-account-discovery-after-consent`
- **files**: `lib/Controller/Psd2Controller.php`, `lib/Service/BankfeedSyncService.php`
- **acceptance_criteria**:
  - GIVEN an `active` connection WHEN discovery runs THEN each account's IBAN/BIC/currency/account-id is recorded with no token exposed
  - GIVEN an already-discovered connection WHEN discovery re-runs THEN the account set updates in place with no duplicates
- [x] Implement
- [x] Test

## 4. Scheduled sync and events

### Task 5: Add the scheduled transaction sync job emitting the synced event
- **spec_ref**: `openspec/specs/psd2-ais-bank-feed-connector/spec.md#req-004-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri`
- **files**: `lib/Cron/BankfeedSyncJob.php`, `lib/Service/BankfeedSyncService.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN an `active` connection with new transactions WHEN `BankfeedSyncJob` runs THEN a `bankfeed_batch` is persisted and `nl.conduction.bankfeed.transactions.synced` `{connectionId, accountIban, since, until, transactionCount, batchUri}` is emitted
  - GIVEN an `expired` connection THEN it is skipped with no aggregator call; GIVEN a failed pull THEN the watermark does not advance
- [x] Implement
- [x] Test

### Task 6: Emit consent-lifecycle CloudEvents
- **spec_ref**: `openspec/specs/psd2-ais-bank-feed-connector/spec.md#req-005-consent-lifecycle-cloudevents-for-consumer-state-transitions`
- **files**: `lib/Service/BankfeedSyncService.php`, `lib/Controller/Psd2Controller.php`
- **acceptance_criteria**:
  - GIVEN a granted consent THEN `nl.conduction.bankfeed.consent.granted` is emitted; GIVEN an approaching expiry THEN a single `nl.conduction.bankfeed.consent.expiring` is emitted; GIVEN a revocation THEN `nl.conduction.bankfeed.consent.revoked` is emitted
  - AND each payload carries `{connectionId, aggregatorSourceSlug, consentReference, consentExpiresAt}` and the connector mutates no consuming-app record
- [x] Implement
- [x] Test

## 5. Localisation

### Task 7: Ship English source keys with Dutch translations
- **spec_ref**: `openspec/specs/psd2-ais-bank-feed-connector/spec.md#non-functional-requirements`
- **files**: `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN new user-facing strings WHEN the app is loaded in `nl` THEN Dutch translations render for every English source key
- [x] Implement
- [x] Test

### NL translations (plain bullets, not tracked checkboxes)
- `Connect a bank account` → `Bankrekening koppelen`
- `Consent expired` → `Toestemming verlopen`
- `Consent expiring soon` → `Toestemming verloopt binnenkort`
- `Transactions synced` → `Transacties gesynchroniseerd`
- `Aggregator credential missing` → `Aggregator-inloggegeven ontbreekt`
- `Account discovery failed` → `Ontdekken van rekeningen mislukt`

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite (40 new tests: LogPsd2AggregatorProviderTest, RestPsd2AggregatorProviderTest, BankfeedSyncServiceTest, Psd2ControllerTest, BankfeedSyncJobTest cover connect → callback → discovery → sync → events end-to-end on the sandbox provider)
- [x] Code review against spec requirements — self-reviewed; see Deviations below

## Deviations

- **`lifecycleState` stays `active` inside the expiry warning window.** REQ-004 says the sync job
  "MUST skip connections whose lifecycleState is not active"; flipping to `expiring` on warning
  would therefore stop syncing a still-valid consent. Instead the connector emits the single
  `nl.conduction.bankfeed.consent.expiring` event (REQ-005) and stamps `expiryWarnedAt` (the
  emit-once guard); the `expiring` enum value remains on the schema for consumer-side (shillinq
  BankConnection) parity.
- **Consent-token brokering is fail-closed but usually a no-op.** In the GoCardless
  Bank-Account-Data model (the generic `rest` shape) the only secret is the aggregator API key,
  already brokered per call via `authentication.credentialRef`; `finaliseConsent` returns
  `consentToken: null`. When a future provider DOES return token material, `BankfeedSyncService`
  brokers it through the lazily-resolved OR `CredentialStoreResolver` and aborts the whole
  finalisation (nothing persisted) when the store is unavailable — no plaintext fallback (REQ-006).
- **Revocation detection.** A consent-scoped 401/403 from the `rest` provider maps to
  `Psd2ConsentRevokedException`; the sync sweep then moves the connection to `revoked` and emits
  `nl.conduction.bankfeed.consent.revoked`. No dedicated operator "revoke" route was added — Task 3
  only specifies connect/callback routes; operator revocation can call
  `BankfeedSyncService::markRevoked()` (public) when a UI/occ surface lands.
- **Two schema fields beyond the design table.** `redirectUrl` (the return URL registered at
  connect time — the callback only ever redirects there, per design.md's open-redirect defence) and
  `expiryWarnedAt` (the emit-once guard REQ-005's "not repeated every sweep" scenario requires).
- **Watermark atomicity is per connection.** All account pulls for a connection happen before any
  `bankfeed_batch` is persisted, so a mid-pull failure persists nothing and re-attempts the same
  window — no gaps, no double-counting (REQ-004's "failed pull does not advance the watermark").
- **`GET /api/psd2/callback` carries `#[NoCSRFRequired]`** because the browser arrives from an
  external bank redirect and cannot hold an NC request token; auth body = NC session + ADR-023
  action RBAC (`bankfeed.connect`, default admin-only) + pending-requisition `ref` validation
  (CSRF/mix-up defence per design.md).
- **Pre-existing fix (house rule):** the local test stub
  `tests/stubs/OCA/OpenRegister/Service/Credential/CredentialBrokerService.php` was missing the
  `actingUserId` parameter that openregister `origin/development` ships (credential-doriath-leaf),
  which broke 9 pre-existing Adapter unit tests ("Parameter count … too low"). The stub now mirrors
  the real 7-parameter `request()` signature; BrokeredCallService's legacy-vs-capable broker feature
  detection keeps its own Fake*Broker fixtures, so both generations stay covered.
