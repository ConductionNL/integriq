# Tasks: data-infra-connectors

> Sub-bullets describe the work per task. Each top-level checkbox is the unit Hydra tracks; flip when the whole task (implementation + tests) is done. ADR-032 cap respected (≤20).

## Task 1: Deduplication Check
- **spec_ref**: ADR-012
- **files**: (read-only audit — no file changes)
- **acceptance_criteria**:
  - Search `openspec/specs/` and `openregister/lib/Service/` for overlap with ObjectService, RegisterService, SchemaService, ConfigurationService, and shared Vue components. Document findings.
- Findings: CallService, SynchronizationService, JobService, EventService, AuthenticationService, NotificationService all reused directly from existing OpenConnector infrastructure. No custom CRUD, search, or pagination logic required. No overlap with existing connector changes (ibabs-notubiz-connector, dso-omgevingsloket, stuf-adapter). Three new source types (`kick`, `influxdb`, `dovecot`) extend the existing Source entity without modifying existing types.
- [ ] Task complete

## Task 2: OpenRegister Schemas and Seed Data
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-kick-002`, `#req-influx-003`, `#req-dovecot-002`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN fresh install WHEN register imported THEN KickSyncLog, InfluxQueryConfig, DovecotMailboxSync schemas present with correct fields and required flags
  - GIVEN seed data loaded WHEN list view opened THEN 3 KickSyncLog, 3 InfluxQueryConfig, 4 DovecotMailboxSync objects visible with Dutch-language field values
  - GIVEN re-import WHEN force: false THEN existing objects matched by slug, no duplicates created
- Define three new schemas in `openconnector_register.json` using schema.org vocabulary where applicable. Include 3-5 seed objects per schema with `@self` envelope (`register`, `schema`, `slug`). Import via existing `importFromApp()` pipeline called from repair step or `SettingsLoadService`. Seed data: Dutch values, realistic slugs. See `design.md` Seed Data section for concrete object values.
- [ ] Task complete

## Task 3: KICK Source Configuration (REQ-KICK-001)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-kick-001`
- **files**: `lib/Service/KICKConnectorService.php`, `lib/Db/Source.php`
- **acceptance_criteria**:
  - GIVEN KICK source configured WHEN "Test connection" clicked THEN CallService GET probe to KICK API, HTTP 200 confirms, HTTP 401 returns descriptive error
  - GIVEN OAuth2 credential WHEN access token expires THEN AuthenticationService auto-refreshes before retrying
  - GIVEN rate limit headers returned WHEN response processed THEN Source rate limit fields updated automatically
- Add `kick` to the Source entity's supported type enum. Implement `KICKConnectorService` with `testConnection(Source $source): array` method routing through CallService. Support API key auth via `apikey` method and OAuth2 via existing `AuthenticationService::getAccessToken()`. PHPDoc `@spec` tag on class and all public methods. EUPL-2.1 docblock header. Tests: `testConnection_success`, `testConnection_unauthorized`, `testConnection_rateLimit` (≥3 methods in `tests/Unit/Service/KICKConnectorServiceTest.php`).
- [ ] Task complete

## Task 4: KICK Client Data Synchronisation (REQ-KICK-002)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-kick-002`
- **files**: `lib/Service/KICKConnectorService.php`, `lib/Cron/KICKSyncJob.php`
- **acceptance_criteria**:
  - GIVEN KICK source active WHEN KICKSyncJob runs THEN client records fetched via CallService and mapped via SynchronizationService
  - GIVEN 2 records have missing BSN WHEN processed THEN KickSyncLog status: partial, recordsErrored: 2 with descriptive message
  - GIVEN client record updated in KICK WHEN next sync runs THEN SynchronizationService change detection updates OpenRegister object, recordsUpdated incremented
- Implement `KICKSyncJob` extending Nextcloud `TimedJobList`. Implement `KICKConnectorService::syncClients(Source $source): array` — fetch paginated client records from KICK API via CallService, map to OpenRegister objects using SynchronizationService, create `KickSyncLog` record via `ObjectService::saveObject()` with status/counts/errors. PHPDoc `@spec` tags. Tests: `syncClients_success`, `syncClients_partial`, `syncClients_changeDetection` (≥3 methods).
- [ ] Task complete

## Task 5: KICK Connection Health and Error Handling (REQ-KICK-003)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-kick-003`
- **files**: `lib/Service/KICKConnectorService.php`
- **acceptance_criteria**:
  - GIVEN KICK API unreachable WHEN sync runs THEN Source status: error, KickSyncLog status: failed, admin notification sent, no data loss
  - GIVEN HTTP 401 from KICK WHEN sync runs THEN Source status: error, admin notified with credential expiry instruction
  - GIVEN sync fails WHEN next interval arrives THEN KICKSyncJob retries automatically
- Implement error handling in `KICKConnectorService::syncClients()`: catch `\Throwable`, update Source status via `ObjectService`, create failed `KickSyncLog` record, dispatch Nextcloud notification via `NotificationService`. Log real error server-side; return static error string to caller (no `$e->getMessage()` in API responses). Tests: `syncClients_unreachable`, `syncClients_credentialExpiry`, `syncClients_retryOnNextInterval` (≥3 methods).
- [ ] Task complete

## Task 6: InfluxDB Source Configuration (REQ-INFLUX-001)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-influx-001`
- **files**: `lib/Service/InfluxDBConnectorService.php`, `lib/Db/Source.php`
- **acceptance_criteria**:
  - GIVEN InfluxDB v2 source with token WHEN saved THEN Source persisted with type: influxdb, configuration fields correct
  - GIVEN InfluxDB v1 source with username/password WHEN configured THEN Basic auth header used for all requests
  - GIVEN invalid token WHEN test connection THEN descriptive error naming specific failure (token/org/endpoint) returned
- Add `influxdb` to Source entity type enum. Implement `InfluxDBConnectorService` with `testConnection(Source $source): array` — execute `GET /api/v2/buckets` probe via CallService (v2) or `GET /query?q=SHOW+DATABASES` (v1). Detect API version from `configuration.apiVersion`. Support token auth (`Authorization: Token {token}`) and basic auth via AuthenticationService. PHPDoc `@spec` tags. Tests: `testConnection_v2_success`, `testConnection_v1_basicAuth`, `testConnection_invalidToken` (≥3 methods).
- [ ] Task complete

## Task 7: InfluxDB Bucket and Measurement Discovery (REQ-INFLUX-002)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-influx-002`
- **files**: `lib/Service/InfluxDBConnectorService.php`
- **acceptance_criteria**:
  - GIVEN InfluxDB source saved WHEN "Discover buckets" triggered THEN GET /api/v2/buckets executed via CallService, bucket names returned as array
  - GIVEN bucket selected WHEN "Discover measurements" triggered THEN Flux schema.measurements() query executed, measurement names returned
- Implement `listBuckets(Source $source): array` routing `GET /api/v2/buckets` through CallService and returning bucket name array. Implement `listMeasurements(Source $source, string $bucket): array` posting the Flux schema query `import "influxdata/influxdb/schema" schema.measurements(bucket: "{bucket}")` and parsing the CSV response. PHPDoc `@spec` tags. Tests: `listBuckets_success`, `listMeasurements_success`, `listBuckets_authError` (≥3 methods).
- [ ] Task complete

## Task 8: InfluxDB Time-Series Query Execution (REQ-INFLUX-003)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-influx-003`
- **files**: `lib/Service/InfluxDBConnectorService.php`
- **acceptance_criteria**:
  - GIVEN Flux query config WHEN panel requests data THEN POST /api/v2/query executed, CSV annotated response parsed to time-series array
  - GIVEN InfluxQL query config WHEN panel requests data THEN GET /query?q=... executed, JSON response parsed to time-series array
  - GIVEN query returns zero data points WHEN processed THEN empty array returned, no error thrown
- Implement `executeFluxQuery(Source $source, string $queryText): array` — POST to `/api/v2/query` with `Content-Type: application/vnd.flux`, `Accept: application/csv`; parse CSV annotated response to structured array. Implement `executeInfluxQLQuery(Source $source, string $queryText, string $bucket): array` — GET `/query?q=...&db=...`; parse JSON response to structured array. Handle empty result sets without error. PHPDoc `@spec` tags. Tests: `executeFluxQuery_success`, `executeInfluxQLQuery_success`, `executeFluxQuery_emptyResult` (≥3 methods).
- [ ] Task complete

## Task 9: InfluxDB Connection Testing and Error Reporting (REQ-INFLUX-004)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-influx-004`
- **files**: `lib/Service/InfluxDBConnectorService.php`
- **acceptance_criteria**:
  - GIVEN expired token WHEN test connection THEN descriptive auth error returned (not generic)
  - GIVEN Flux syntax error WHEN query executed THEN HTTP 400 parse error surfaced to caller
  - GIVEN InfluxDB unreachable WHEN panel refresh triggers THEN failure logged in CallLog, caller receives connection failure context
- Implement structured error parsing in `testConnection()` and `executeFluxQuery()` / `executeInfluxQLQuery()`: map HTTP 400 to query parse errors, 401/403 to auth errors, 5xx/timeout to connection errors. Return structured error array (not raw `$e->getMessage()`). Log real exception server-side. PHPDoc `@spec` tags. Tests: `testConnection_expiredToken`, `executeFluxQuery_syntaxError`, `executeFluxQuery_serverUnavailable` (≥3 methods).
- [ ] Task complete

## Task 10: Dovecot Pro Source Configuration (REQ-DOVECOT-001)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-dovecot-001`
- **files**: `lib/Service/DovecotConnectorService.php`, `lib/Db/Source.php`
- **acceptance_criteria**:
  - GIVEN Dovecot credentials configured WHEN saved THEN Source persisted with type: dovecot, mydash reports successful connection
  - GIVEN "Test connection" clicked WHEN Dovecot API responds THEN success status returned
  - GIVEN incorrect credentials WHEN test connection THEN descriptive error without credential exposure
- Add `dovecot` to Source entity type enum. Implement `DovecotConnectorService` with `testConnection(Source $source): array` — execute `GET /api/v1/server/version` via CallService with Basic auth from AuthenticationService. Return structured success/error response. Static error messages only — no `$e->getMessage()` surfaced. PHPDoc `@spec` tags. Tests: `testConnection_success`, `testConnection_wrongCredentials`, `testConnection_unreachable` (≥3 methods).
- [ ] Task complete

## Task 11: Dovecot Mailbox Provisioning and Deprovisioning Sync (REQ-DOVECOT-002)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-dovecot-002`
- **files**: `lib/Service/DovecotConnectorService.php`, `lib/Cron/DovecotSyncJob.php`
- **acceptance_criteria**:
  - GIVEN new Nextcloud user WHEN DovecotSyncJob runs THEN mailbox provisioned in Dovecot, DovecotMailboxSync record: status: active
  - GIVEN user disabled WHEN sync runs THEN mailbox suspended, DovecotMailboxSync: status: suspended
  - GIVEN user already has DovecotMailboxSync record WHEN sync runs THEN creation skipped (idempotent), quota updated if changed
- Implement `DovecotSyncJob` extending Nextcloud `TimedJobList`. Implement `DovecotConnectorService::reconcileMailboxes(Source $source): void` — iterate Nextcloud users via `IUserManager`, check existing `DovecotMailboxSync` record per user via `ObjectService::findObjects()`, call `createMailbox()` / `suspendMailbox()` / `deleteMailbox()` as appropriate. Save/update `DovecotMailboxSync` records via `ObjectService::saveObject()`. PHPDoc `@spec` tags. Tests: `reconcile_newUser_provisions`, `reconcile_disabledUser_suspends`, `reconcile_existingRecord_idempotent` (≥3 methods).
- [ ] Task complete

## Task 12: Dovecot Connection Health and Error Handling (REQ-DOVECOT-003)
- **spec_ref**: `specs/data-infra-connectors/spec.md#req-dovecot-003`
- **files**: `lib/Service/DovecotConnectorService.php`
- **acceptance_criteria**:
  - GIVEN Dovecot unreachable WHEN sync runs THEN Source status: error, no partial changes applied, admin notified
  - GIVEN provisioning error (e.g., disk quota exceeded) WHEN processed THEN DovecotMailboxSync errorMessage set, admin notified
  - GIVEN HTTP 401 WHEN sync runs THEN Source status: error, admin notified with credential update instruction
- Implement error handling in `DovecotConnectorService::reconcileMailboxes()` and the provisioning methods: catch `\Throwable` at the job level (no partial state), update Source status to `error` via ObjectService, store `errorMessage` on the affected `DovecotMailboxSync` record, dispatch Nextcloud notification via `NotificationService`. Log real error server-side. Tests: `reconcile_unreachable_haltsSafely`, `reconcile_provisioningError_storesMessage`, `reconcile_credentialExpiry_notifiesAdmin` (≥3 methods).
- [ ] Task complete

## Task 13: Unit Tests
- **spec_ref**: ADR-008
- **files**: `tests/Unit/Service/KICKConnectorServiceTest.php`, `tests/Unit/Service/InfluxDBConnectorServiceTest.php`, `tests/Unit/Service/DovecotConnectorServiceTest.php`
- **acceptance_criteria**:
  - All tests pass in `composer check:strict`
  - Happy path and error paths (401, 400, 503) covered for all three connector services
  - No hardcoded credentials in test collections — use env variable placeholders
- Each test file covers: connection management (test connection success/failure), core business logic (sync/query/provision), and error handling (auth failure, network timeout, API error). Minimum 3 test methods per file. Mock CallService and ObjectService — do not hit live InfluxDB, KICK, or Dovecot endpoints in unit tests.
- [ ] Task complete

## Task 14: API Documentation
- **spec_ref**: ADR-009
- **files**: `docs/features/vilans-kick-connector.md`, `docs/features/influxdb-connector.md`, `docs/features/dovecot-connector.md`
- **acceptance_criteria**:
  - Configuration guide covers Source setup, credential types, and sync interval settings
  - Troubleshooting section covers the three main error scenarios per connector
- Write English-primary documentation for each connector: Source configuration steps, supported auth methods, sync interval options, example query configs (InfluxDB), troubleshooting for credential expiry and connection failures.
- [ ] Task complete
