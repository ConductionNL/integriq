---
status: proposed
---

# Infrastructure and domain data source connectors

## Purpose

Provides three new infrastructure and domain-specific data source connectors for OpenConnector: **Vilans KICK** (demand 55, care professional client data synchronisation), **InfluxDB** (demand 54, time-series metrics dashboarding), and **Dovecot Pro** (demand 54, enterprise email mailbox provisioning). These connectors extend OpenConnector's catalogue for healthcare, observability, and email infrastructure domains. Required by active Dutch tender processes; combined demand score 163 across 3 procurement processes.

## Requirements

### REQ-KICK-001: Vilans KICK Source Configuration

The connector MUST allow an administrator to configure a Vilans KICK integration as an OpenConnector Source entity of type `kick`. The source stores the KICK API endpoint URL, authentication credentials (API key or OAuth2 client credentials), organisation identifier, and synchronisation interval. All API calls are routed through CallService for request logging, rate limit tracking, and authentication.

**Scenarios:**

1. **GIVEN** an administrator opens the OpenConnector sources list and selects "Add source" with type "kick" **AND** enters the KICK API URL, API key, organisation ID, and sync interval (default: 60 minutes) **WHEN** they save the source **THEN** a Source entity is persisted with type `kick`, connector-specific configuration stored in the `configuration` JSON column, and the source is marked as enabled.

2. **GIVEN** a KICK source is configured **WHEN** the administrator clicks "Test connection" **THEN** CallService makes a lightweight GET request to the KICK API (e.g., `GET /api/clients?limit=1`) and the response status is shown — HTTP 200 confirms connectivity, HTTP 401 indicates an invalid API key with a descriptive error message.

3. **GIVEN** a KICK source configured with OAuth2 client credentials **WHEN** the access token expires **THEN** AuthenticationService automatically refreshes the token using the stored client_id and client_secret before retrying the API call, without administrator intervention.

4. **GIVEN** the KICK API returns rate limit headers (`X-RateLimit-Remaining`, `X-RateLimit-Reset`) **WHEN** CallService processes the response **THEN** the Source entity's rate limit fields are updated automatically, preventing API calls that would exceed the limit during the configured sync interval.

### REQ-KICK-002: Client Data Synchronisation

The connector MUST periodically synchronise client records from Vilans KICK to OpenRegister objects. The synchronisation is driven by a KICKSyncJob background cron job running at the configured interval. Each sync run creates a KickSyncLog record capturing the run status, record counts, and any error details.

**Scenarios:**

1. **GIVEN** a valid KICK source is configured with a 60-minute sync interval **AND** the KICK API has 42 client records **WHEN** the KICKSyncJob runs **THEN** all 42 records are fetched via CallService, mapped to OpenRegister objects using SynchronizationService, and a KickSyncLog record is created with `status: success`, `recordsProcessed: 42`.

2. **GIVEN** a care professional is logged into mydash **WHEN** they view a client profile **THEN** data sourced from the last successful KICK sync (within the configured interval) is visibly present and accurate, including KICK-sourced fields.

3. **GIVEN** 38 client records are fetched from KICK **AND** 2 records are missing a required BSN field **WHEN** SynchronizationService processes the records **THEN** 36 records are successfully mapped and saved, the 2 invalid records are skipped with a warning, and the KickSyncLog records `status: partial`, `recordsProcessed: 38`, `recordsErrored: 2` with a descriptive message identifying the skipped records.

4. **GIVEN** a client record is updated in KICK (e.g., an address change) **WHEN** the next KICKSyncJob runs **THEN** SynchronizationService detects the change via its existing change-detection mechanism, updates the matching OpenRegister object, and the KickSyncLog increments `recordsUpdated` by 1.

### REQ-KICK-003: KICK Connection Health and Error Handling

The connector MUST handle KICK API connection failures, credential expiry, and API errors gracefully. On error, the Source status is updated to `error`, a KickSyncLog failure record is created, and a Nextcloud notification is sent to the administrator. Data already synchronised is never rolled back.

**Scenarios:**

1. **GIVEN** the KICK integration is active **AND** the KICK API becomes unreachable (HTTP timeout or 5xx error) **WHEN** the sync job attempts to connect **THEN** the sync is halted without any partial data changes, the Source status is set to `error`, a KickSyncLog record is created with `status: failed` and the error message, and a Nextcloud notification is sent to the administrator.

2. **GIVEN** the KICK API key expires or is revoked **WHEN** the sync job runs and receives HTTP 401 **THEN** the CallLog records the failure, the Source status is set to `error`, and the administrator receives a Nextcloud notification indicating credential expiry with instructions to update the API key in the Source configuration.

3. **GIVEN** the KICK sync fails on one run **WHEN** the next configured sync interval arrives **THEN** the KICKSyncJob retries the synchronisation automatically without requiring manual intervention from the administrator.

### REQ-INFLUX-001: InfluxDB Source Configuration

The connector MUST allow an administrator to configure an InfluxDB data source as an OpenConnector Source entity of type `influxdb`. The source stores the InfluxDB server URL, authentication credentials (token for v2/v3, username/password for v1), organisation name, default bucket, and API version. All queries are executed via CallService against the InfluxDB HTTP API.

**Scenarios:**

1. **GIVEN** an administrator creates a new Source with type "influxdb" **AND** enters the InfluxDB URL (e.g., `http://influxdb.intern:8086`), authentication token, organisation name, and default bucket **WHEN** they save the source **THEN** a Source entity is persisted with the InfluxDB-specific configuration in the `configuration` JSON column and marked as enabled.

2. **GIVEN** an InfluxDB v2 source is configured with a valid token **WHEN** the operator saves the data source configuration **THEN** mydash confirms the connection by executing a `GET /api/v2/buckets` probe via CallService and reports success.

3. **GIVEN** an InfluxDB v1 source is configured with username and password **WHEN** the source is saved **THEN** AuthenticationService uses the `username-password` auth method and the connector constructs `Authorization: Basic` headers for all subsequent requests to the v1-compatible endpoints.

4. **GIVEN** an InfluxDB source is configured with an invalid or expired authentication token **WHEN** the administrator tests the connection **THEN** mydash displays a descriptive error identifying the specific failure — invalid token, wrong organisation name, or unreachable endpoint — without exposing the token value or internal server details.

### REQ-INFLUX-002: Bucket and Measurement Discovery

The connector MUST list available buckets and measurements from the configured InfluxDB source so that dashboard operators can discover data without writing raw queries.

**Scenarios:**

1. **GIVEN** a valid InfluxDB source is saved **WHEN** the operator clicks "Discover buckets" in the query configuration form **THEN** the connector executes `GET /api/v2/buckets` via CallService and populates a dropdown with the available bucket names from the InfluxDB instance.

2. **GIVEN** a bucket is selected in the query configuration form **WHEN** the operator clicks "Discover measurements" **THEN** the connector executes a Flux schema query (`import "influxdata/influxdb/schema" schema.measurements(bucket: "...")`) and populates a measurements dropdown with the available measurement names.

3. **GIVEN** valid InfluxDB connection credentials **WHEN** the operator saves the data source configuration **THEN** mydash confirms the connection and lists available buckets/measurements, as required by the acceptance criteria for the InfluxDB Integration feature.

### REQ-INFLUX-003: Time-Series Query Execution

The connector MUST execute Flux and InfluxQL queries against the configured InfluxDB source and return time-series data for dashboard panel rendering. Query configurations are stored as InfluxQueryConfig objects in OpenRegister and referenced by dashboard panels.

**Scenarios:**

1. **GIVEN** an InfluxQueryConfig with `queryLanguage: flux` and a valid Flux query body **WHEN** a dashboard panel requests data **THEN** the connector posts the query to the InfluxDB v2 API (`POST /api/v2/query` with `Accept: application/csv`), receives the CSV-annotated response, parses it into a structured time-series array, and returns it to the panel without error.

2. **GIVEN** a configured InfluxDB source and an InfluxQueryConfig with `queryLanguage: influxql` and an InfluxQL query (e.g., `SELECT mean("cpu_usage") FROM "metrics" WHERE time > now() - 1h GROUP BY time(5m)`) **WHEN** a dashboard panel is created with that configuration **THEN** the connector routes the query to the InfluxDB v1-compatible endpoint (`GET /query?q=...`), parses the JSON response, and renders the queried time-series data in the panel without error.

3. **GIVEN** an InfluxDB Flux query that returns zero data points (empty bucket or time range with no data) **WHEN** the panel requests data **THEN** the connector returns an empty dataset and the panel renders a "No data" state without throwing an error or logging a failure.

4. **GIVEN** an InfluxQueryConfig with `refreshInterval: 30` **WHEN** the dashboard panel renders **THEN** the panel automatically re-fetches data every 30 seconds using the stored query configuration, keeping the visualisation current.

### REQ-INFLUX-004: InfluxDB Connection Testing and Error Reporting

The connector MUST provide clear, actionable error messages when InfluxDB connections fail, queries have syntax errors, or authentication is invalid.

**Scenarios:**

1. **GIVEN** an InfluxDB source with an authentication failure (expired token, wrong organisation, or wrong bucket) **WHEN** the operator tests the connection **THEN** mydash displays a descriptive error indicating the credential or endpoint issue, specifying whether the problem is the token, the organisation name, or the URL — matching the acceptance criteria for the InfluxDB Integration feature.

2. **GIVEN** a Flux query with a syntax error (e.g., missing closing parenthesis or invalid function name) **WHEN** the query is executed **THEN** the InfluxDB API returns HTTP 400 with a parse error body, the connector surfaces the error message to the dashboard panel, and the panel displays "Query error: [error detail]" rather than blank data.

3. **GIVEN** the InfluxDB instance is temporarily unreachable **WHEN** a scheduled panel refresh triggers the query **THEN** the connector logs the connection failure in CallLog and the panel displays a "Source unavailable" state with the timestamp of the last successful data fetch.

### REQ-DOVECOT-001: Dovecot Pro Source Configuration

The connector MUST allow an IT administrator to configure a Dovecot Pro integration as an OpenConnector Source entity of type `dovecot`. The source stores the Dovecot admin API URL, admin credentials, and default mailbox quota. All API calls are routed through CallService.

**Scenarios:**

1. **GIVEN** Dovecot Pro credentials (admin API URL, username, password) are configured **WHEN** the admin saves the integration settings **THEN** a Source entity is persisted with type `dovecot`, the Dovecot-specific configuration stored in the `configuration` JSON column, and mydash connects to Dovecot Pro and reports a successful connection status — as required by the acceptance criteria for the Dovecot Pro integration feature.

2. **GIVEN** a Dovecot source is configured **WHEN** the administrator clicks "Test connection" **THEN** CallService makes a lightweight request to the Dovecot admin API (e.g., `GET /api/v1/server/version`) and reports success or failure with the specific error (authentication failure, connection refused, or API endpoint not found).

3. **GIVEN** a Dovecot source is configured with incorrect credentials **WHEN** the admin tests the connection **THEN** mydash displays a clear error message indicating the authentication failure without exposing the credentials or internal server details in the error response.

### REQ-DOVECOT-002: Mailbox Provisioning and Deprovisioning Sync

The connector MUST synchronise Nextcloud user accounts with Dovecot Pro mailboxes. When a Nextcloud user is created, the connector provisions a Dovecot mailbox. When a user is disabled or deleted, the connector suspends or removes the corresponding mailbox. Each operation is tracked in a DovecotMailboxSync record in OpenRegister.

**Scenarios:**

1. **GIVEN** the Dovecot Pro integration is active **AND** a new Nextcloud user "t.de.vries" is created **WHEN** the DovecotSyncJob runs **THEN** the connector calls the Dovecot admin API to provision a mailbox at `/mail/t.de.vries` with the configured default quota, creates a DovecotMailboxSync record with `status: active`, and the change is reflected in Dovecot Pro without manual intervention — as required by the acceptance criteria for the Dovecot Pro integration feature.

2. **GIVEN** the Dovecot Pro integration is active **AND** the Nextcloud user "p.smits" is disabled by the administrator **WHEN** the DovecotSyncJob runs **THEN** the connector calls the Dovecot admin API to suspend the mailbox, updates the DovecotMailboxSync record to `status: suspended`, and the change is reflected in Dovecot Pro without manual intervention.

3. **GIVEN** a DovecotMailboxSync record exists for user "h.dejong" with `status: active` **WHEN** the Nextcloud user account is permanently deleted **THEN** the connector marks the mailbox for removal in Dovecot Pro, updates the DovecotMailboxSync record to `status: deleted`, and logs the operation via CallLog.

4. **GIVEN** the DovecotSyncJob runs **AND** a Nextcloud user already has an existing DovecotMailboxSync record with `status: active` **WHEN** the reconciliation processes that user **THEN** the connector detects the existing record, skips mailbox creation (idempotent), updates the quota if it differs from the configured default, and logs an idempotency skip.

### REQ-DOVECOT-003: Dovecot Connection Health and Error Handling

The connector MUST handle Dovecot Pro connection failures, credential expiry, and provisioning errors gracefully. On failure, the Source status is updated, the IT administrator receives a Nextcloud notification, and pending mailbox operations are retried at the next sync interval.

**Scenarios:**

1. **GIVEN** Dovecot Pro is unreachable **WHEN** the integration attempts a sync or provisioning operation **THEN** the connector halts the sync without applying any partial changes, the Source status is set to `error`, and mydash displays a clear error message to the IT admin indicating the connection failure — as required by the acceptance criteria for the Dovecot Pro integration feature.

2. **GIVEN** a mailbox provisioning call to Dovecot returns an error (e.g., disk quota exceeded on the mail server) **WHEN** the connector processes the error **THEN** a descriptive error is stored in the DovecotMailboxSync record's `errorMessage` field, the IT admin receives a Nextcloud notification with the specific failure reason, and the DovecotSyncJob retries at the next configured interval.

3. **GIVEN** the Dovecot admin API credentials expire or are revoked **WHEN** the sync job runs and receives HTTP 401 **THEN** the Source status is set to `error`, the IT admin receives a Nextcloud notification indicating credential expiry with instructions to update the credentials in the Source configuration, and no mailbox changes are applied.

## Data Model

See `design.md` for full schema definitions with field types, required flags, and seed data.

Entities used by this change:

- **Source** (existing OpenConnector entity): Connection configuration for KICK, InfluxDB, and Dovecot Pro sources; each uses a new type value (`kick`, `influxdb`, `dovecot`).
- **KickSyncLog** (new schema): Audit log for Vilans KICK synchronisation runs — status, record counts, errors, next scheduled run.
- **InfluxQueryConfig** (new schema): Flux/InfluxQL query configurations for dashboard panels — query language, query text, bucket, measurement, refresh interval.
- **DovecotMailboxSync** (new schema): Mailbox provisioning state per Nextcloud user — mailbox path, quota, status, last sync timestamp, error message.

## Standards and References

- **Vilans KICK REST API**: Care professional client management system by Stichting Vilans, Netherlands. Proprietary REST API with API key or OAuth2 client_credentials authentication.
- **InfluxDB v2 HTTP API**: Open-source time-series database API by InfluxData. Token-based authentication; query endpoint `POST /api/v2/query` (Flux, CSV annotated output). Spec: docs.influxdata.com/influxdb/v2/api/.
- **Flux query language**: Functional data scripting language for InfluxDB v2/v3. Reference: flux-lang.org.
- **InfluxQL**: SQL-like query language for InfluxDB v1. Supported in InfluxDB v2/v3 via the compatibility endpoint `GET /query`.
- **Dovecot Pro admin API**: REST API for Dovecot IMAP server administration (Open-Xchange). Provides mailbox, quota, and user management endpoints. Documentation provided by Dovecot upon integration agreement.
- **Nextcloud IUserManager**: Nextcloud core API for Nextcloud user lifecycle events. Used to detect user creation, suspension, and deletion events that trigger DovecotSyncJob mailbox operations.
