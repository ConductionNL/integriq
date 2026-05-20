# Design: SaaS Productivity and Work Management Connectors

## Architecture

The SaaS productivity connectors extend OpenConnector's existing source/synchronisation infrastructure with three domain-specific capabilities: Monday.com bidirectional sync, list view display, and DXP content integration.

### New Services

- **MondayConnectorService** (`lib/Service/MondayConnectorService.php`): Handles Monday.com GraphQL API interactions — board item queries, mutations, webhook processing, and bidirectional field mapping between Monday.com columns and OpenRegister object properties.
- **DXPConnectorService** (`lib/Service/DXPConnectorService.php`): Handles DXP endpoint interactions — content retrieval via configurable REST/JSON sources, sync scheduling, and error notification dispatch.

### Integration with Existing Infrastructure

Both new services delegate to existing OpenConnector components:

- **CallService** (`lib/Service/CallService.php`): Routes all outbound HTTP requests (Monday.com GraphQL + DXP REST) through the existing request/response logging, rate limiting, retry support, and authentication handling.
- **SynchronizationService** (`lib/Service/SynchronizationService.php`): Orchestrates bidirectional sync contracts. Monday.com sync uses outbound (OpenConnector → Monday.com) and inbound (Monday.com → OpenConnector) contract types. DXP sync uses an inbound-only contract.
- **AuthenticationService** (`lib/Service/AuthenticationService.php`): Handles Monday.com API token (bearer) and OAuth2 flows. DXP sources support `apikey`, `oauth`, and `basic` auth methods already present in the Source entity.
- **JobService** (`lib/Service/JobService.php`): Schedules polling jobs for Monday.com board updates and DXP content changes. Default poll interval: 5 minutes (Monday.com), configurable (DXP).
- **EventService** (`lib/Service/EventService.php`): Dispatches events when Monday.com item status changes or DXP content is updated, enabling downstream workflow triggers.
- **Source entity** (`lib/Db/Source.php`): Stores Monday.com workspace URL + API token and DXP endpoint URL + credentials. Source type `json` with auth method `apikey` or `oauth`.

### List View Integration

The list view feature is a frontend-only enhancement. It wires the existing `CnIndexPage` + `useListView` composable + `CnDataTable` components in OpenConnector's dashboard pages. No new backend services or schemas are required.

- **Component**: `CnIndexPage` with `useListView(entityType, { sidebarState, objectStore })` composable.
- **Sorting**: Delegated to `useListView`'s built-in sort state; server-side sort via `ObjectService.findAll()` `_order` parameter.
- **Row selection**: `selectionPlugin` on the entity object store; `CnMassActionBar` floats on multi-select.
- **Column headers**: Generated from schema via `columnsFromSchema()` utility.

### Data Flow

**Monday.com outbound (OpenConnector → Monday.com):**
1. User creates/updates an object in OpenConnector.
2. `EventService` fires an object mutation event.
3. `MondayConnectorService.pushItemToBoard()` maps object fields to Monday.com column values via the stored `MondayBoardSync` mapping record.
4. `CallService` executes the Monday.com GraphQL mutation (`create_item` / `change_multiple_column_values`).
5. The `MondayBoardSync` sync record is updated with the returned `mondayItemId` and `lastSyncAt`.

**Monday.com inbound (Monday.com → OpenConnector):**
1. `JobService` triggers `MondayPollJob` every 5 minutes.
2. `MondayConnectorService.pollBoardChanges()` queries Monday.com for items updated since `lastSyncAt`.
3. Changed items are mapped back to OpenRegister object properties via `SynchronizationService`.
4. `MondayBoardSync` records are updated; conflicts set status to `conflict` and notify the administrator.

**DXP inbound (DXP → OpenConnector):**
1. `JobService` triggers `DXPSyncJob` at the configured `syncInterval`.
2. `DXPConnectorService.fetchContent()` calls the DXP REST endpoint via `CallService`.
3. Retrieved content items are mapped to OpenRegister objects via `SynchronizationService`.
4. The `DXPContentSync` record is updated with `lastSyncAt` and content item count.
5. On credential failure: `DXPConnectorService` sets the Source status to `error` and dispatches a Nextcloud notification via `NotificationService`.

## Reuse Analysis

> Required by ADR-012-deduplication.

| Capability | Existing component reused | Notes |
|---|---|---|
| HTTP API calls to Monday.com | `CallService` | Full request/response logging + rate limit handling already supported |
| HTTP calls to DXP endpoints | `CallService` | Same as above — `json` source type with configurable auth |
| OAuth2 / API key authentication | `AuthenticationService` | Supports `oauth`, `apikey`, `basic` — no new auth types needed |
| Sync orchestration | `SynchronizationService` | Bidirectional contracts already implemented |
| Background polling | `JobService` | Scheduled background jobs already implemented |
| Event dispatch | `EventService` | Object mutation events already supported |
| List view display | `CnIndexPage` + `CnDataTable` + `useListView` | Composable handles search/filter/sort/pagination |
| Row selection + bulk actions | `selectionPlugin` + `CnMassActionBar` | Already in `@conduction/nextcloud-vue` |
| Column generation from schema | `columnsFromSchema()` | Utility in `@conduction/nextcloud-vue` |
| Token expiry notification | `NotificationService` | Already available for Nextcloud notifications |

**Conclusion**: No custom HTTP clients, no custom stores, no custom pagination. All integration infrastructure is reused. New code is limited to Monday.com GraphQL request builders and DXP-specific mapping configuration — genuinely domain-specific logic that cannot live in shared services.

## Seed Data

> Required by ADR-001 seed data rules. Applies because this change introduces `MondayBoardSync` and `DXPContentSync` schemas.

### MondayBoardSync — 4 seed objects

```json
[
  {
    "@self": {
      "register": "openconnector",
      "schema": "monday-board-sync",
      "slug": "monday-sync-amsterdam-projecten"
    },
    "mondayBoardId": "4827361905",
    "mondayItemId": "9283746512",
    "openconnectorObjectId": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "sourceSlug": "monday-gemeente-amsterdam",
    "direction": "bidirectional",
    "status": "synced",
    "lastSyncAt": "2026-05-20T08:30:00+02:00",
    "fieldMappings": {
      "name": "title",
      "status": "procesStatus",
      "assignee": "behandelaar"
    }
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "monday-board-sync",
      "slug": "monday-sync-rijnmond-adviesprojecten"
    },
    "mondayBoardId": "3916204871",
    "mondayItemId": "8174920365",
    "openconnectorObjectId": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
    "sourceSlug": "monday-rijnmond-advies",
    "direction": "outbound",
    "status": "synced",
    "lastSyncAt": "2026-05-19T14:15:00+02:00",
    "fieldMappings": {
      "name": "projectNaam",
      "status": "fase",
      "date": "deadlineDatum"
    }
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "monday-board-sync",
      "slug": "monday-sync-utrecht-vrijwilligers"
    },
    "mondayBoardId": "7293847561",
    "mondayItemId": null,
    "openconnectorObjectId": "c3d4e5f6-a7b8-9012-cdef-012345678902",
    "sourceSlug": "monday-sociaal-werk-utrecht",
    "direction": "bidirectional",
    "status": "pending",
    "lastSyncAt": null,
    "fieldMappings": {
      "name": "activiteitNaam",
      "status": "status",
      "assignee": "coordinatorNaam"
    }
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "monday-board-sync",
      "slug": "monday-sync-rotterdam-bouwprojecten"
    },
    "mondayBoardId": "5619384720",
    "mondayItemId": "7362819045",
    "openconnectorObjectId": "d4e5f6a7-b8c9-0123-def0-123456789003",
    "sourceSlug": "monday-gemeente-rotterdam",
    "direction": "inbound",
    "status": "conflict",
    "lastSyncAt": "2026-05-18T09:00:00+02:00",
    "fieldMappings": {
      "name": "projectOmschrijving",
      "status": "bouwFase",
      "assignee": "projectleider"
    }
  }
]
```

### DXPContentSync — 4 seed objects

```json
[
  {
    "@self": {
      "register": "openconnector",
      "schema": "dxp-content-sync",
      "slug": "dxp-sync-rotterdam-sitecore"
    },
    "dxpEndpointId": "content-item-nl-home-actueel-001",
    "openconnectorObjectId": "e5f6a7b8-c9d0-1234-ef01-234567890004",
    "sourceSlug": "dxp-rotterdam-sitecore",
    "contentType": "nieuws-artikel",
    "syncInterval": 300,
    "lastSyncAt": "2026-05-20T08:00:00+02:00",
    "status": "active",
    "itemCount": 47
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "dxp-content-sync",
      "slug": "dxp-sync-eindhoven-umbraco"
    },
    "dxpEndpointId": "product-catalog-gemeentediensten",
    "openconnectorObjectId": "f6a7b8c9-d0e1-2345-f012-345678900005",
    "sourceSlug": "dxp-eindhoven-umbraco",
    "contentType": "dienst-pagina",
    "syncInterval": 600,
    "lastSyncAt": "2026-05-20T07:30:00+02:00",
    "status": "active",
    "itemCount": 124
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "dxp-content-sync",
      "slug": "dxp-sync-vliegende-start-kentico"
    },
    "dxpEndpointId": "reizen-aanbieding-collectie",
    "openconnectorObjectId": "a7b8c9d0-e1f2-3456-0123-456789000006",
    "sourceSlug": "dxp-vliegende-start-kentico",
    "contentType": "reisaanbieding",
    "syncInterval": 1800,
    "lastSyncAt": "2026-05-19T22:00:00+02:00",
    "status": "active",
    "itemCount": 83
  },
  {
    "@self": {
      "register": "openconnector",
      "schema": "dxp-content-sync",
      "slug": "dxp-sync-drechtsteden-contentful"
    },
    "dxpEndpointId": "raadsinformatie-documenten",
    "openconnectorObjectId": "b8c9d0e1-f2a3-4567-1234-567890000007",
    "sourceSlug": "dxp-drechtsteden-contentful",
    "contentType": "raadsdocument",
    "syncInterval": 900,
    "lastSyncAt": null,
    "status": "error",
    "errorMessage": "Authenticatie mislukt: API-sleutel verlopen op 2026-05-19"
  }
]
```

## Dependencies

- **OpenConnector**: Source, CallService, SynchronizationService, AuthenticationService, JobService, EventService
- **OpenRegister**: Object storage for MondayBoardSync and DXPContentSync records
- **@conduction/nextcloud-vue**: CnIndexPage, CnDataTable, useListView, selectionPlugin, CnMassActionBar, columnsFromSchema
- **Monday.com GraphQL API**: External service (api.monday.com/v2) — requires workspace API token or OAuth2 app credentials
- **DXP REST endpoint**: External service — configurable per tenant; supports Sitecore, Umbraco, Kentico, Contentful

## Risks

| Risk | Mitigation |
|---|---|
| Monday.com rate limits (10 req/s, 10k complexity/min) | Rate limit headers parsed by CallService; `MondayConnectorService` batches GraphQL queries using `items_page` cursor to stay within complexity budget |
| Monday.com webhooks require public HTTPS endpoint | Polling fallback (5 min) when webhook registration fails; admin is notified to expose the endpoint |
| DXP APIs vary wildly across vendors (Sitecore, Umbraco, Kentico, Contentful) | Source entity `configuration` JSON stores vendor-specific path templates; MappingService handles field translation |
| Monday.com column type changes break field mappings | `MondayConnectorService` validates column types on each sync; logs warning and skips incompatible column rather than failing entire sync |
| DXP credentials expiry disrupts dashboard content | DXPConnectorService marks Source `status: error`, dispatches Nextcloud notification to admin, dashboard shows last cached content |
