---
status: proposed
---

# Endpoint and virtual desktop workspace connectors

## Purpose

Adds two workspace connector integrations to OpenConnector, enabling mydash and other consumers to retrieve and launch managed applications (Recast/Liquit) and virtual desktops (VMware/Omnissa Horizon) via a unified `/api/workspace/*` proxy. Both connectors are registered as new Source types (`recast-liquit`, `vmware-horizon`) and are implemented as thin proxy services on top of the existing `CallService` + `Source` infrastructure.

---

## Requirements

### REQ-EWC-001: Recast/Liquit source registration

OpenConnector MUST accept Source objects with `type = recast-liquit`. The source MUST store the API base URL and API key. On save, the source status MUST be set to `active` if the connection test passes, or `error` if it fails.

**Scenarios:**

1. **GIVEN** an IT admin creates a Source with `type = recast-liquit`, an API base URL, and a valid API key **WHEN** the Source is saved **THEN** OpenConnector stores the Source, sets `status = active`, and returns HTTP 201 with the saved Source object.

2. **GIVEN** an IT admin creates a Recast/Liquit Source with an API key that is rejected by the Recast/Liquit API **WHEN** the Source is saved and test-connection runs **THEN** the Source `status` is set to `error` and a generic failure message is returned (not the raw Recast/Liquit error).

3. **GIVEN** a Recast/Liquit Source is saved with a missing `apikey` **WHEN** the Source is validated **THEN** OpenConnector returns HTTP 400 with a validation error before any API call is made.

---

### REQ-EWC-002: Recast/Liquit connection test

OpenConnector MUST support a test-connection action for `recast-liquit` Sources that calls `RecastLiquitService::testConnection()`. The test result MUST include the number of available applications and the connection latency.

**Scenarios:**

1. **GIVEN** a Recast/Liquit Source is active **WHEN** the admin triggers test-connection **THEN** OpenConnector calls the Recast/Liquit catalogue endpoint, returns `{"success": true, "applicationCount": N, "latencyMs": M}`, and sets Source `status = active`.

2. **GIVEN** the Recast/Liquit API is unreachable **WHEN** test-connection is triggered **THEN** OpenConnector returns `{"success": false, "message": "Connection failed"}` and sets Source `status = error`. No raw API error or internal path is exposed.

3. **GIVEN** the Recast/Liquit API responds but returns HTTP 401 **WHEN** test-connection is triggered **THEN** OpenConnector returns `{"success": false, "message": "Authentication failed"}`.

---

### REQ-EWC-003: Retrieve Recast/Liquit application catalogue

OpenConnector MUST expose `GET /api/workspace/recast/apps` returning the list of applications available to the authenticated user. The endpoint MUST be annotated `#[NoAdminRequired]`.

**Scenarios:**

1. **GIVEN** an active Recast/Liquit Source is configured **AND** an authenticated user calls `GET /api/workspace/recast/apps` **WHEN** the request is processed **THEN** OpenConnector returns HTTP 200 with a JSON array of application objects, each containing `id`, `name`, `icon`, `category`, and `status`.

2. **GIVEN** the Recast/Liquit Source is in `error` state **WHEN** `GET /api/workspace/recast/apps` is called **THEN** OpenConnector returns HTTP 503 with `{"message": "Workspace connector unavailable"}`.

3. **GIVEN** no Recast/Liquit Source is configured **WHEN** `GET /api/workspace/recast/apps` is called **THEN** OpenConnector returns HTTP 404 with `{"message": "No Recast/Liquit source configured"}`.

4. **GIVEN** the Recast/Liquit API returns an empty catalogue **WHEN** the endpoint is called **THEN** OpenConnector returns HTTP 200 with an empty array `[]`, not an error.

---

### REQ-EWC-004: Launch a Recast/Liquit application

OpenConnector MUST expose `POST /api/workspace/recast/launch/{appId}` that forwards the launch request to the Recast/Liquit backend on behalf of the authenticated user. The endpoint MUST be annotated `#[NoAdminRequired]`.

**Scenarios:**

1. **GIVEN** a valid `appId` is supplied **AND** the Recast/Liquit backend confirms the launch **WHEN** `POST /api/workspace/recast/launch/{appId}` is called **THEN** OpenConnector returns HTTP 200 with `{"success": true, "launchUrl": "...", "message": "Application launch initiated"}`.

2. **GIVEN** an `appId` that does not exist in the user's catalogue **WHEN** the launch endpoint is called **THEN** the Recast/Liquit API returns a 404 which OpenConnector forwards as HTTP 404 with `{"message": "Application not found"}`.

3. **GIVEN** the Recast/Liquit backend is unavailable during a launch request **WHEN** the endpoint is called **THEN** OpenConnector returns HTTP 503 with `{"message": "Launch service unavailable"}` and logs the failure to CallLog.

4. **GIVEN** a launch request succeeds **WHEN** the response is returned **THEN** the call is recorded in CallLog with the source ID, endpoint path, response code, and latency.

---

### REQ-EWC-005: VMware Horizon source registration

OpenConnector MUST accept Source objects with `type = vmware-horizon`. The source MUST store the Horizon Connection Server URL, domain, service account credentials, and launch mode configuration. On save, the source status MUST be set to `active` if the connection test passes.

**Scenarios:**

1. **GIVEN** an IT admin creates a Source with `type = vmware-horizon`, a valid Connection Server URL, domain, service account username, and password **WHEN** the Source is saved **THEN** OpenConnector stores the Source, calls the Horizon REST API to verify connectivity, sets `status = active`, and returns HTTP 201.

2. **GIVEN** a Horizon Source is saved with a self-signed Horizon server certificate **AND** `configuration.verifySsl = false` **WHEN** the Source is saved **THEN** OpenConnector skips TLS verification and sets `status = active` if the API responds.

3. **GIVEN** a Horizon Source has an invalid `connectionServerUrl` (malformed URL) **WHEN** the Source is validated on save **THEN** OpenConnector returns HTTP 400 with `{"message": "Invalid connection server URL"}` before any API call is made.

---

### REQ-EWC-006: VMware Horizon connection test

OpenConnector MUST support a test-connection action for `vmware-horizon` Sources that calls `HorizonService::testConnection()`. The test MUST retrieve the desktop pool list and return pool names in the result.

**Scenarios:**

1. **GIVEN** a Horizon Source is active **WHEN** the admin triggers test-connection **THEN** OpenConnector returns `{"success": true, "poolCount": N, "pools": ["Pool A", "Pool B"], "latencyMs": M}` and sets Source `status = active`.

2. **GIVEN** the Horizon Connection Server is unreachable **WHEN** test-connection is triggered **THEN** OpenConnector returns `{"success": false, "message": "Connection failed"}` and sets Source `status = error`.

3. **GIVEN** Horizon credentials are rejected (HTTP 401 from Horizon API) **WHEN** test-connection is triggered **THEN** OpenConnector returns `{"success": false, "message": "Authentication failed"}` without including the Horizon error body.

---

### REQ-EWC-007: Retrieve VMware Horizon desktop pools

OpenConnector MUST expose `GET /api/workspace/horizon/pools` returning the list of desktop pools entitled to the authenticated user. The endpoint MUST be annotated `#[NoAdminRequired]`.

**Scenarios:**

1. **GIVEN** an active Horizon Source is configured **AND** an authenticated user calls `GET /api/workspace/horizon/pools` **WHEN** the request is processed **THEN** OpenConnector returns HTTP 200 with a JSON array of pool objects, each containing `id`, `displayName`, `description`, `sessionCount`, and `protocol`.

2. **GIVEN** the authenticated user has no entitled desktop pools **WHEN** `GET /api/workspace/horizon/pools` is called **THEN** OpenConnector returns HTTP 200 with an empty array `[]`, not an error response.

3. **GIVEN** the Horizon Source is in `error` state **WHEN** the pools endpoint is called **THEN** OpenConnector returns HTTP 503 with `{"message": "Workspace connector unavailable"}`.

4. **GIVEN** no Horizon Source is configured **WHEN** the pools endpoint is called **THEN** OpenConnector returns HTTP 404 with `{"message": "No Horizon source configured"}`.

---

### REQ-EWC-008: Initiate a VMware Horizon session

OpenConnector MUST expose `POST /api/workspace/horizon/launch/{poolId}` that requests a session launch from the Horizon REST API on behalf of the authenticated user and returns either an HTML Access URL or a native client launch URI. The endpoint MUST be annotated `#[NoAdminRequired]`.

**Scenarios:**

1. **GIVEN** a valid `poolId` is supplied **AND** the Horizon API issues a session launch token **WHEN** `POST /api/workspace/horizon/launch/{poolId}` is called **THEN** OpenConnector returns HTTP 200 with `{"launchUrl": "https://...", "protocol": "BLAST", "tokenExpiry": "2026-05-20T15:30:00Z"}`.

2. **GIVEN** `launchMode = html-access` is configured **WHEN** a launch request is processed **THEN** the returned `launchUrl` is an HTTPS URL pointing to the Horizon HTML Access interface.

3. **GIVEN** `launchMode = native-client` is configured **WHEN** a launch request is processed **THEN** the returned `launchUrl` is a `vmware-view://` URI for the native Horizon client.

4. **GIVEN** a `poolId` that is not entitled for the authenticated user **WHEN** the launch endpoint is called **THEN** OpenConnector returns HTTP 403 with `{"message": "Not authorised for this desktop pool"}`.

5. **GIVEN** the Horizon API is unavailable during a launch request **WHEN** the endpoint is called **THEN** OpenConnector returns HTTP 503 with `{"message": "Launch service unavailable"}` and logs the failure to CallLog.

---

### REQ-EWC-009: Call logging for workspace connector requests

All outbound calls made by `RecastLiquitService` and `HorizonService` via `CallService` MUST be recorded in `CallLog` with source ID, endpoint path, HTTP method, response status code, and response time.

**Scenarios:**

1. **GIVEN** a Recast/Liquit app-catalogue request is made **WHEN** `CallService::call()` completes **THEN** a `CallLog` record is created with `sourceId`, `endpoint = /api/v1/applications`, `method = GET`, `statusCode`, and `responseTime`.

2. **GIVEN** a Horizon session launch call fails with a network timeout **WHEN** the error is caught **THEN** a `CallLog` record is created with `statusCode = 0` and an error message, and the caller receives HTTP 503.

3. **GIVEN** an admin views the CallLog for a workspace connector Source **WHEN** they filter by sourceId **THEN** all app-catalogue, launch, and test-connection calls are listed in reverse-chronological order.

---

### REQ-EWC-010: Source type validation

The Sources API MUST reject Source objects with `type = recast-liquit` or `type = vmware-horizon` that are missing required configuration keys, without making any outbound API calls.

**Scenarios:**

1. **GIVEN** a `recast-liquit` Source is submitted without an `apikey` **WHEN** the Source is validated **THEN** OpenConnector returns HTTP 400 with `{"message": "API key is required for Recast/Liquit sources"}` before calling the Recast/Liquit API.

2. **GIVEN** a `vmware-horizon` Source is submitted without `configuration.connectionServerUrl` **WHEN** the Source is validated **THEN** OpenConnector returns HTTP 400 with `{"message": "Connection server URL is required for VMware Horizon sources"}`.

3. **GIVEN** a `vmware-horizon` Source is submitted with a `connectionServerUrl` that does not start with `https://` **WHEN** the Source is validated **THEN** OpenConnector returns HTTP 400 with `{"message": "Horizon Connection Server URL must use HTTPS"}`.

---

## Data Model

No new OpenRegister schemas are introduced. The connectors extend the existing **Source** entity:

### Source entity — new type values

| `type` value | Auth method | Required `configuration` keys | Optional `configuration` keys |
|---|---|---|---|
| `recast-liquit` | `apikey` (`source.apikey`, header `X-Api-Key`) | `apiBaseUrl` | `catalogueId` |
| `vmware-horizon` | `basic` (`source.username`, `source.password`) | `connectionServerUrl`, `domain` | `launchMode` (`html-access`\|`native-client`), `verifySsl` (bool), `apiVersion` (default `v5`) |

### API response shapes

**GET /api/workspace/recast/apps — application object:**

```json
{
  "id": "string",
  "name": "string",
  "icon": "string (URL or base64)",
  "category": "string",
  "status": "available | installing | unavailable"
}
```

**POST /api/workspace/recast/launch/{appId} — response:**

```json
{
  "success": true,
  "launchUrl": "string (optional)",
  "message": "string"
}
```

**GET /api/workspace/horizon/pools — pool object:**

```json
{
  "id": "string",
  "displayName": "string",
  "description": "string",
  "sessionCount": 0,
  "protocol": "BLAST | PCoIP | RDP"
}
```

**POST /api/workspace/horizon/launch/{poolId} — response:**

```json
{
  "launchUrl": "string",
  "protocol": "BLAST | PCoIP | RDP",
  "tokenExpiry": "ISO 8601 datetime string"
}
```

## Dependencies

- **OpenConnector `CallService`**: outbound HTTP routing (existing)
- **OpenConnector `Source` + `SourceMapper`**: credential storage (existing)
- **OpenConnector `CallLog`**: automatic call logging via `CallService` (existing)
- **Nextcloud `IUserSession`**: caller identity on `#[NoAdminRequired]` endpoints (Nextcloud core)
- **Recast/Liquit REST API**: external — requires API key obtained from customer environment
- **VMware Omnissa Horizon REST API v5**: external — requires Horizon Connection Server 2306+

## Standards and References

- **Recast/Liquit**: Managed endpoint and application delivery platform. REST API requires API key authentication. Application catalogue endpoints follow ITSM application catalogue conventions.
- **VMware Omnissa Horizon REST API**: Horizon Connection Server exposes REST endpoints under `/rest/`. Version 5 is current for Horizon 2306+. Desktop pools are retrieved via `/rest/inventory/v5/desktop-pools`. Session launch via `/rest/access-points/v1/session-launch-data`.
- **BLAST protocol**: VMware's display protocol for Horizon HTML Access (browser-based).
- **ADR-002**: API URL pattern `/index.php/apps/openconnector/api/workspace/*` — lowercase plural, hyphens.
- **ADR-003**: Controller → Service → (no Mapper needed for proxy-only calls). All business logic in services.
- **ADR-005**: `#[NoAdminRequired]` on all user-facing endpoints; no per-object IDOR check needed (endpoints retrieve data scoped to the calling user's session from the external system).
