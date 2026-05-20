# Design: Endpoint and virtual desktop workspace connectors

## Context

OpenConnector already provides a generic integration fabric for sources, endpoints, mappings, and synchronisations. The workspace connectors (Recast/Liquit and VMware/Omnissa Horizon) follow the same `Source → CallService → Controller` pattern used by existing protocol adapters (iBabs, DSO, StUF). No new data model entities are introduced; the connectors extend the existing `Source` entity with two new type values and add dedicated services and controller routes.

## Architecture

### New Services

**`RecastLiquitService`** (`lib/Service/RecastLiquitService.php`)
Handles all Recast/Liquit REST API interactions:
- `getApplications(Source $source, string $userId): array` — retrieves the application catalogue for a given user
- `launchApplication(Source $source, string $appId, string $userId): array` — sends a launch/install request
- `testConnection(Source $source): array` — lightweight health/catalogue check for admin UI

**`HorizonService`** (`lib/Service/HorizonService.php`)
Handles all VMware Omnissa Horizon REST API interactions:
- `getDesktopPools(Source $source, string $userId): array` — retrieves entitled desktop pools
- `initiateSession(Source $source, string $poolId, string $userId): array` — requests a launch token or HTML Access URL
- `testConnection(Source $source): array` — retrieves pool list to verify connectivity

### New Controller

**`WorkspaceConnectorController`** (`lib/Controller/WorkspaceConnectorController.php`)
Exposes `/api/workspace/*` proxy endpoints. All methods are annotated `#[NoAdminRequired]` with per-request auth checks. Each method:
1. Resolves the workspace source (Recast/Liquit or Horizon) from app config
2. Verifies the source is active
3. Delegates to the appropriate service
4. Returns a typed `JSONResponse`

Routes:
- `GET  /api/workspace/recast/apps` → `WorkspaceConnectorController::getRecastApps()`
- `POST /api/workspace/recast/launch/{appId}` → `WorkspaceConnectorController::launchRecastApp()`
- `GET  /api/workspace/horizon/pools` → `WorkspaceConnectorController::getHorizonPools()`
- `POST /api/workspace/horizon/launch/{poolId}` → `WorkspaceConnectorController::launchHorizonPool()`

### Existing Infrastructure Leveraged

| Component | Role |
|---|---|
| `Source` entity + `SourceMapper` | Stores connector credentials and config; `source.type` discriminates between `recast-liquit` and `vmware-horizon` |
| `CallService` | Routes HTTP(S) requests to external APIs; handles certificate, rate limiting, and call logging |
| `SourcesController::testConnection()` | Admin-facing test-connection action extended to dispatch to `RecastLiquitService::testConnection()` and `HorizonService::testConnection()` |
| `CallLog` entity | Automatic logging of all connector API calls via `CallService` |
| `IAppConfig` | Stores the active Source ID(s) for each connector type (admin-configurable, not hard-coded) |

## Data Model

No new OpenRegister schemas are introduced in this change. Both connectors are purely proxy-mode: they forward requests to external systems and return responses live (no caching or synchronisation into OpenRegister in phase 1).

### Source entity extensions

The existing `Source` entity is extended with two new valid `type` values:

**`recast-liquit`**

| `configuration` key | Type | Description |
|---|---|---|
| `apiBaseUrl` | string | Recast/Liquit REST API base URL (e.g. `https://api.recast-liquit.example.nl`) |
| `catalogueId` | string (optional) | Specific catalogue ID; omit to use default |

Authentication: `source.auth = 'apikey'`, `source.apikey` holds the API key. `source.authorizationHeader` = `X-Api-Key`.

**`vmware-horizon`**

| `configuration` key | Type | Description |
|---|---|---|
| `connectionServerUrl` | string | Horizon Connection Server URL (e.g. `https://horizon.example.nl`) |
| `domain` | string | Active Directory domain for authentication |
| `launchMode` | string enum | `html-access` (default) or `native-client` |
| `verifySsl` | boolean | Whether to verify the Horizon server TLS certificate |

Authentication: `source.auth = 'basic'`, `source.username` (service account UPN), `source.password`.

## Integration Flow

### Recast/Liquit app retrieval

```
mydash  →  GET /api/workspace/recast/apps
           WorkspaceConnectorController::getRecastApps()
           RecastLiquitService::getApplications($source, $userId)
           CallService::call($source, '/api/v1/applications', 'GET', ['user' => $userId])
           ← JSON list of {id, name, icon, category, status}
```

### Recast/Liquit app launch

```
mydash  →  POST /api/workspace/recast/launch/{appId}
           WorkspaceConnectorController::launchRecastApp($appId)
           RecastLiquitService::launchApplication($source, $appId, $userId)
           CallService::call($source, '/api/v1/applications/{appId}/launch', 'POST', ...)
           ← JSON {success, launchUrl, message}
```

### VMware Horizon pool retrieval

```
mydash  →  GET /api/workspace/horizon/pools
           WorkspaceConnectorController::getHorizonPools()
           HorizonService::getDesktopPools($source, $userId)
           CallService::call($source, '/rest/inventory/v5/desktop-pools', 'GET', ...)
           ← JSON list of {id, name, description, sessionCount, displayName}
```

### VMware Horizon session launch

```
mydash  →  POST /api/workspace/horizon/launch/{poolId}
           WorkspaceConnectorController::launchHorizonPool($poolId)
           HorizonService::initiateSession($source, $poolId, $userId)
           CallService::call($source, '/rest/access-points/v1/session-launch-data', 'POST', ...)
           ← JSON {launchUrl, protocol, tokenExpiry}
```

## Seed Data

These example Source objects represent realistic connector configurations for Dutch municipalities. They are provided as reference only — connector Sources are not stored in OpenRegister; they are configured via the OpenConnector admin UI.

### Example 1: Recast/Liquit source — Gemeente Utrecht

```json
{
  "name": "Recast/Liquit — Gemeente Utrecht",
  "description": "Beheerde applicatieomgeving voor medewerkers Gemeente Utrecht",
  "type": "recast-liquit",
  "location": "https://api.recast.gemeente-utrecht.nl",
  "auth": "apikey",
  "apikey": "rl-key-utrecht-placeholder",
  "authorizationHeader": "X-Api-Key",
  "isEnabled": true,
  "configuration": {
    "apiBaseUrl": "https://api.recast.gemeente-utrecht.nl",
    "catalogueId": "medewerkers"
  }
}
```

### Example 2: Recast/Liquit source — Gemeente Tilburg

```json
{
  "name": "Recast/Liquit — Gemeente Tilburg",
  "description": "Applicatiebeheer voor Tilburgs digitale werkplek",
  "type": "recast-liquit",
  "location": "https://recast-api.tilburg.nl",
  "auth": "apikey",
  "apikey": "rl-key-tilburg-placeholder",
  "authorizationHeader": "X-Api-Key",
  "isEnabled": true,
  "configuration": {
    "apiBaseUrl": "https://recast-api.tilburg.nl"
  }
}
```

### Example 3: VMware Horizon source — Gemeente Eindhoven

```json
{
  "name": "VMware Horizon — Gemeente Eindhoven",
  "description": "Virtuele desktops voor thuiswerkers Gemeente Eindhoven",
  "type": "vmware-horizon",
  "location": "https://horizon.eindhoven.nl",
  "auth": "basic",
  "username": "svc-openconnector@eindhoven.nl",
  "password": "horizon-svc-placeholder",
  "isEnabled": true,
  "configuration": {
    "connectionServerUrl": "https://horizon.eindhoven.nl",
    "domain": "eindhoven.nl",
    "launchMode": "html-access",
    "verifySsl": true
  }
}
```

### Example 4: VMware Horizon source — Gemeente Groningen

```json
{
  "name": "VMware Horizon — Gemeente Groningen",
  "description": "VDI-omgeving voor flexibel werken Gemeente Groningen",
  "type": "vmware-horizon",
  "location": "https://vdi.groningen.nl",
  "auth": "basic",
  "username": "svc-oc@groningen.nl",
  "password": "horizon-svc-placeholder",
  "isEnabled": true,
  "configuration": {
    "connectionServerUrl": "https://vdi.groningen.nl",
    "domain": "groningen.nl",
    "launchMode": "html-access",
    "verifySsl": true
  }
}
```

### Example 5: Recast/Liquit source — Rijksdienst voor Ondernemend Nederland (RVO)

```json
{
  "name": "Recast/Liquit — RVO",
  "description": "Applicatieportaal voor RVO-medewerkers via Recast/Liquit",
  "type": "recast-liquit",
  "location": "https://apps-api.rvo.nl",
  "auth": "apikey",
  "apikey": "rl-key-rvo-placeholder",
  "authorizationHeader": "X-Api-Key",
  "isEnabled": false,
  "configuration": {
    "apiBaseUrl": "https://apps-api.rvo.nl",
    "catalogueId": "rvo-standaard"
  }
}
```

## Reuse Analysis

All existing OpenConnector infrastructure is leveraged; no duplication with OpenRegister or `@conduction/nextcloud-vue`:

| Existing component | Reuse in this change |
|---|---|
| `Source` + `SourceMapper` | Credential and config storage; no new entity |
| `CallService::call()` | All outbound HTTP calls to Recast/Liquit and Horizon APIs |
| `CallLog` | Automatic logging of every workspace connector call |
| `SourcesController::testConnection()` | Dispatches to service `testConnection()` — extended, not replaced |
| `IAppConfig` | Stores active source IDs — no new config mechanism |
| `AuthenticationService` | `apikey` and `basic` auth types already supported — no new auth types needed |
| `EndpointService` | Not used directly — workspace endpoints bypass EndpointService (direct controller routes) |
| `MappingService` | Not needed — responses are passed through without transformation in phase 1 |

**No overlap found** with `ObjectService`, `RegisterService`, `SchemaService`, or `ConfigurationService` in OpenRegister.

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Recast/Liquit REST API is proprietary with limited public documentation | Stub-driven development: implement against documented endpoints, add configurable base URL for environment-specific variations |
| VMware Horizon REST API version drift (v1 → v5) | Configurable API version prefix in `configuration.apiVersion`; default `v5` |
| User-context forwarding: Horizon requires the end user's AD identity, not the service account | Include `userId` (from `IUserSession`) in session-launch call; Horizon REST API accepts `remote_user` claim |
| SSL certificate issues on self-hosted Horizon | `verifySsl` configuration flag; default `true`; admin can disable for dev environments |
| Recast/Liquit rate limiting | `CallService` already tracks `rateLimitRemaining`; service should check before forwarding |

## Dependencies

- **OpenConnector `CallService`**: existing — outbound HTTP calls
- **Recast/Liquit REST API**: external — requires API key from customer environment
- **VMware Omnissa Horizon REST API v5**: external — requires Horizon Connection Server 2306 or later
- **`IUserSession`**: Nextcloud core — for resolving caller identity on `#[NoAdminRequired]` endpoints
