# Design: Infrastructure and domain data source connectors

## Architecture

The data-infra-connectors change adds three new connector services to OpenConnector, each following the established Source + CallService + SynchronizationService pattern used by the ibabs-notubiz and dso-omgevingsloket connectors.

### New Services

- **KICKConnectorService** (`lib/Service/KICKConnectorService.php`): Manages authenticated connections to the Vilans KICK REST API. Handles client record polling, field mapping via SynchronizationService, sync interval scheduling, and KickSyncLog audit record creation.
- **InfluxDBConnectorService** (`lib/Service/InfluxDBConnectorService.php`): Manages connections to InfluxDB v1/v2/v3. Handles bucket and measurement discovery, Flux query execution (POST `/api/v2/query`), InfluxQL query execution (GET `/query` compatibility endpoint), and CSV/JSON response parsing.
- **DovecotConnectorService** (`lib/Service/DovecotConnectorService.php`): Manages connections to the Dovecot Pro admin API. Handles mailbox provisioning, suspension, deletion, quota management, idempotent reconciliation, and DovecotMailboxSync record maintenance.

### New Background Jobs

- **KICKSyncJob** (`lib/Cron/KICKSyncJob.php`): Periodic cron job that polls KICK for updated client records at the interval configured on the Source entity (default: 60 minutes).
- **DovecotSyncJob** (`lib/Cron/DovecotSyncJob.php`): Periodic cron job that reconciles Nextcloud user accounts with Dovecot mailboxes. Provisions new mailboxes, suspends disabled users, and marks deleted users for removal.

### Integration Pattern

All three connectors follow the Source-first pattern used across the OpenConnector adapter catalogue:

- **Source entity**: Each connector is registered as an OpenConnector Source object of a new type (`kick`, `influxdb`, `dovecot`). Connector-specific configuration (API URL, credentials, sync interval, default quota) is stored in the `configuration` JSON column.
- **CallService**: All outbound HTTP calls are routed through CallService for unified request logging, rate limit tracking, and authentication header injection.
- **AuthenticationService**: KICK supports API key and OAuth2 (client_credentials). InfluxDB v2/v3 uses token auth, v1 uses username/password (Basic). Dovecot Pro uses Basic auth — all handled via existing AuthenticationService methods.
- **SynchronizationService**: KICK client sync and Dovecot mailbox reconciliation use SynchronizationService for change detection and bidirectional mapping. No custom sync logic.
- **JobService**: Background jobs for KICK polling and Dovecot reconciliation use JobService scheduling.
- **NotificationService**: Connection failures, credential expiry, and provisioning errors dispatch Nextcloud notifications to the administrator.

### Data Flow: Vilans KICK

```
Nextcloud cron
  → KICKSyncJob.run()
  → KICKConnectorService.syncClients(source)
  → CallService.call(source, GET /api/clients)
  → SynchronizationService.syncObjects(records, mapping)
  → ObjectService.saveObject(register, schema, personData)
  → ObjectService.saveObject(register, kick-sync-log, logRecord)
```

### Data Flow: InfluxDB

```
Dashboard panel refresh
  → InfluxDBConnectorService.executeQuery(queryConfig)
  → [Flux]    CallService.call(source, POST /api/v2/query, flux)  → parseCSVResponse()
  → [InfluxQL] CallService.call(source, GET /query?q=...) → parseJSONResponse()
  → return time-series array to panel
```

### Data Flow: Dovecot Pro

```
Nextcloud cron
  → DovecotSyncJob.run()
  → DovecotConnectorService.reconcileMailboxes(source)
  → IUserManager.getUsers() [Nextcloud user list]
  → per user: DovecotMailboxSync lookup via ObjectService
  → [new user]      CallService.call(source, POST /api/v1/mailboxes)  → status: active
  → [disabled user] CallService.call(source, PUT  /api/v1/mailboxes/{id}/suspend) → status: suspended
  → [deleted user]  CallService.call(source, DELETE /api/v1/mailboxes/{id}) → status: deleted
  → ObjectService.saveObject(register, dovecot-mailbox-sync, syncRecord)
```

## Reuse Analysis

The following existing OpenConnector services are leveraged directly — no duplication:

| Service | Usage in this change |
|---------|----------------------|
| `CallService` | All outbound HTTP calls for KICK REST, InfluxDB HTTP API, and Dovecot admin API |
| `SynchronizationService` | KICK client data sync with change detection; Dovecot mailbox reconciliation |
| `AuthenticationService` | OAuth2 (KICK), token auth (InfluxDB v2), Basic auth (Dovecot, InfluxDB v1) |
| `JobService` | `KICKSyncJob` and `DovecotSyncJob` scheduling |
| `EventService` | Post-sync event dispatching for n8n workflow triggers |
| `NotificationService` | Admin notifications on connection errors, credential expiry, provisioning failures |
| `Source entity` | All three connectors registered as Source objects with new `type` values |
| `SynchronizationLog` | Existing audit log for KICK and Dovecot sync runs via SynchronizationService |
| `ObjectService` | Save KickSyncLog, InfluxQueryConfig, DovecotMailboxSync records |

No custom CRUD, search, pagination, file management, or dashboard components are required. No overlap with existing connectors (ibabs-notubiz, dso-omgevingsloket, stuf-adapter).

## Data Model

### KickSyncLog (stored in OpenRegister)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| sourceId | string (UUID) | Yes | Source entity ID of the KICK source |
| direction | string (enum) | Yes | Always `inbound` (KICK → OpenRegister) |
| syncedAt | datetime | Yes | Timestamp when the sync run started |
| status | string (enum) | Yes | `success`, `partial`, `failed` |
| recordsProcessed | integer | Yes | Total client records fetched from KICK |
| recordsUpdated | integer | No | Records with changes applied to OpenRegister |
| recordsErrored | integer | No | Records that failed to process |
| errorMessage | string | No | Descriptive error if status is `partial` or `failed` |
| nextSyncAt | datetime | No | Scheduled time of the next sync run |

### InfluxQueryConfig (stored in OpenRegister)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| sourceId | string (UUID) | Yes | Source entity ID of the InfluxDB source |
| title | string | Yes | Human-readable panel title |
| queryLanguage | string (enum) | Yes | `flux` or `influxql` |
| queryText | string | Yes | The Flux or InfluxQL query body |
| bucketName | string | Yes | Target InfluxDB bucket |
| measurementName | string | No | Target measurement (optional for open queries) |
| refreshInterval | integer | No | Auto-refresh interval in seconds (default: 30) |
| isActive | boolean | Yes | Whether this query configuration is enabled |

### DovecotMailboxSync (stored in OpenRegister)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| sourceId | string (UUID) | Yes | Source entity ID of the Dovecot source |
| userId | string | Yes | Nextcloud user UID |
| mailboxPath | string | Yes | Dovecot mailbox path (e.g., `/mail/j.bakker`) |
| quotaMB | integer | No | Mailbox quota in megabytes |
| status | string (enum) | Yes | `active`, `suspended`, `deleted` |
| lastSyncAt | datetime | No | Timestamp of the last successful sync operation |
| provisionedAt | datetime | No | When the mailbox was first provisioned |
| errorMessage | string | No | Error from the last failed sync operation |

## Seed Data

Seed objects are loaded on install via the existing `importFromApp()` pipeline in `openconnector_register.json`. All slugs are unique and idempotent across re-imports.

### KickSyncLog (3 seed objects)

```json
[
  {
    "@self": {
      "register": "openconnector",
      "schema": "kick-sync-log",
      "slug": "kick-sync-log-2026-05-20-success"
    },
    "sourceId": "00000000-0000-0000-0000-000000000001",
    "direction": "inbound",
    "syncedAt": "2026-05-20T09:00:00Z",
    "status": "success",
    "recordsProcessed": 42,
    "recordsUpdated": 5,
    "recordsErrored": 0,
    "nextSyncAt": "2026-05-20T10:00:00Z"
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "kick-sync-log",
      "slug": "kick-sync-log-2026-05-19-partial"
    },
    "sourceId": "00000000-0000-0000-0000-000000000001",
    "direction": "inbound",
    "syncedAt": "2026-05-19T14:30:00Z",
    "status": "partial",
    "recordsProcessed": 38,
    "recordsUpdated": 3,
    "recordsErrored": 2,
    "errorMessage": "Twee cliëntrecords konden niet worden bijgewerkt: BSN-veld ontbreekt",
    "nextSyncAt": "2026-05-19T15:30:00Z"
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "kick-sync-log",
      "slug": "kick-sync-log-2026-05-18-failed"
    },
    "sourceId": "00000000-0000-0000-0000-000000000001",
    "direction": "inbound",
    "syncedAt": "2026-05-18T09:00:00Z",
    "status": "failed",
    "recordsProcessed": 0,
    "recordsUpdated": 0,
    "recordsErrored": 0,
    "errorMessage": "Verbinding met KICK API mislukt: HTTP 401 – Ongeldige API-sleutel",
    "nextSyncAt": "2026-05-18T09:15:00Z"
  }
]
```

### InfluxQueryConfig (3 seed objects)

```json
[
  {
    "@self": {
      "register": "openconnector",
      "schema": "influx-query-config",
      "slug": "influx-query-cpu-gebruik-server-nl01"
    },
    "sourceId": "00000000-0000-0000-0000-000000000002",
    "title": "CPU gebruik server-NL01",
    "queryLanguage": "flux",
    "queryText": "from(bucket: \"systeem\") |> range(start: -1h) |> filter(fn: (r) => r[\"_measurement\"] == \"cpu\") |> mean()",
    "bucketName": "systeem",
    "measurementName": "cpu",
    "refreshInterval": 30,
    "isActive": true
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "influx-query-config",
      "slug": "influx-query-geheugengebruik-24-uur"
    },
    "sourceId": "00000000-0000-0000-0000-000000000002",
    "title": "Geheugengebruik afgelopen 24 uur",
    "queryLanguage": "influxql",
    "queryText": "SELECT mean(\"used_percent\") FROM \"mem\" WHERE time > now() - 24h GROUP BY time(1h)",
    "bucketName": "systeem",
    "measurementName": "mem",
    "refreshInterval": 60,
    "isActive": true
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "influx-query-config",
      "slug": "influx-query-netwerk-doorvoer-30min"
    },
    "sourceId": "00000000-0000-0000-0000-000000000002",
    "title": "Netwerk doorvoer laatste 30 minuten",
    "queryLanguage": "flux",
    "queryText": "from(bucket: \"netwerk\") |> range(start: -30m) |> filter(fn: (r) => r[\"_measurement\"] == \"net\") |> sum()",
    "bucketName": "netwerk",
    "measurementName": "net",
    "refreshInterval": 15,
    "isActive": false
  }
]
```

### DovecotMailboxSync (4 seed objects)

```json
[
  {
    "@self": {
      "register": "openconnector",
      "schema": "dovecot-mailbox-sync",
      "slug": "dovecot-mailbox-j-bakker"
    },
    "sourceId": "00000000-0000-0000-0000-000000000003",
    "userId": "j.bakker",
    "mailboxPath": "/mail/j.bakker",
    "quotaMB": 5120,
    "status": "active",
    "lastSyncAt": "2026-05-20T08:00:00Z",
    "provisionedAt": "2025-01-15T12:00:00Z"
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "dovecot-mailbox-sync",
      "slug": "dovecot-mailbox-m-vanderberg"
    },
    "sourceId": "00000000-0000-0000-0000-000000000003",
    "userId": "m.vanderberg",
    "mailboxPath": "/mail/m.vanderberg",
    "quotaMB": 2048,
    "status": "active",
    "lastSyncAt": "2026-05-20T08:00:00Z",
    "provisionedAt": "2025-03-01T09:30:00Z"
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "dovecot-mailbox-sync",
      "slug": "dovecot-mailbox-p-smits"
    },
    "sourceId": "00000000-0000-0000-0000-000000000003",
    "userId": "p.smits",
    "mailboxPath": "/mail/p.smits",
    "quotaMB": 5120,
    "status": "suspended",
    "lastSyncAt": "2026-05-19T16:30:00Z",
    "provisionedAt": "2024-11-20T10:00:00Z"
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "dovecot-mailbox-sync",
      "slug": "dovecot-mailbox-h-dejong"
    },
    "sourceId": "00000000-0000-0000-0000-000000000003",
    "userId": "h.dejong",
    "mailboxPath": "/mail/h.dejong",
    "quotaMB": 10240,
    "status": "active",
    "lastSyncAt": "2026-05-20T08:00:00Z",
    "provisionedAt": "2024-06-10T14:00:00Z"
  }
]
```

## Dependencies

- **OpenConnector**: Source entity, CallService, SynchronizationService, JobService, EventService, AuthenticationService, NotificationService
- **OpenRegister**: Schema and object storage for KickSyncLog, InfluxQueryConfig, DovecotMailboxSync
- **Vilans KICK REST API**: Proprietary care management system API (Vilans). API key or OAuth2 authentication. Endpoint and documentation provided by Vilans upon integration agreement.
- **InfluxDB v2/v3 HTTP API**: Open-source time-series database (InfluxData). Token-based authentication. API spec at `https://docs.influxdata.com/influxdb/v2/api/`. InfluxDB v1 supported via compatibility endpoints.
- **Dovecot Pro Admin API**: Enterprise IMAP server administration REST API (Open-Xchange / Dovecot). Basic authentication or API token.

## Current Implementation Status

### Implemented

None of the three connectors are implemented. No KICK service, InfluxDB service, or Dovecot service exists in the codebase.

### Partially relevant existing infrastructure

- **Source entity** (`lib/Db/Source.php`, `src/entities/source/source.types.ts`): Supports types `json`, `xml`, `soap`, `ftp`, `sftp` with multiple auth methods (`apikey`, `jwt`, `oauth`, `username-password`). All three new source types extend this foundation.
- **CallService** (`lib/Service/CallService.php`): Generic HTTP client with request/response logging, rate limit tracking, authentication header injection, and retry support. Routes all outbound calls.
- **SynchronizationService** (`lib/Service/SynchronizationService.php`): Bidirectional sync framework with contracts, logs, and mapping. Directly applicable to KICK client sync and Dovecot reconciliation.
- **AuthenticationService** (`lib/Service/AuthenticationService.php`): Handles OAuth2 client_credentials, API key, JWT, and username/password flows — covers all three connectors.
- **JobService** (`lib/Service/JobService.php`): Background job scheduling for cron jobs.
- **EventService** (`lib/Service/EventService.php`): Event dispatching for workflow triggers.

### Not implemented

- `kick` source type and KICKConnectorService
- `influxdb` source type and InfluxDBConnectorService
- `dovecot` source type and DovecotConnectorService
- KickSyncLog schema and seed objects
- InfluxQueryConfig schema and seed objects
- DovecotMailboxSync schema and seed objects
- KICKSyncJob background cron job
- DovecotSyncJob background cron job
- Error notifications for all three connectors

## Risks

- **Vilans KICK API**: Proprietary and not publicly documented. Integration requires API access agreement with Vilans; field mappings may need adjustment once API docs are available.
- **InfluxDB version detection**: InfluxDB v1 (InfluxQL, JSON responses) and v2/v3 (Flux, CSV annotated responses) use different authentication and query endpoints. The connector must detect the configured API version or allow the operator to specify it explicitly.
- **Dovecot Pro admin API**: Requires elevated network access and admin credentials on the mail server. Organisations may have Dovecot behind a firewall; network configuration must be coordinated with the mail server administrator.
