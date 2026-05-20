---
status: proposed
---

# SaaS Productivity and Work Management Connectors

## Purpose

Extends OpenConnector with three integration capabilities demanded in government and enterprise tenders: a Monday.com bidirectional task-and-board sync connector (demand: 55), a structured list view for efficient data scanning and bulk actions (demand: 54), and a DXP integration-first connector for unifying enterprise CMS/DXP content (demand: 54). All features are built on top of existing OpenConnector infrastructure (Source, CallService, SynchronizationService, AuthenticationService, JobService) and add no duplicate platform capabilities.

## Requirements

### REQ-MON-001: Monday.com API Connection

OpenConnector MUST support connecting to the Monday.com API using an API token (bearer auth) or OAuth2. The connection is configured as an OpenConnector Source entity of type `json` with the Monday.com GraphQL endpoint (`https://api.monday.com/v2`) and authentication credentials stored in the Source `configuration` JSON field. All API calls are routed through `CallService`, which logs each request in CallLog and automatically tracks rate limit headers.

**Scenarios:**

1. **GIVEN** an administrator creates a new Source with type `json` and auth method `apikey` for Monday.com **AND** enters the workspace API token and board ID in the source configuration **WHEN** they save the source **THEN** the Source entity is persisted with the Monday.com endpoint and credentials, the source status is set to `enabled`, and a test connection call is executed to validate the token.

2. **GIVEN** a Monday.com source is configured **WHEN** the administrator clicks "Test connection" **THEN** `CallService` executes a lightweight Monday.com GraphQL query (`{ me { name } }`) and returns the workspace name on success or an error message on failure (401 = invalid token, 429 = rate limit exceeded).

3. **GIVEN** a Monday.com API call returns rate limit headers (`X-RateLimit-Remaining`, `X-RateLimit-Reset`) **WHEN** `CallService` processes the response **THEN** the Source entity's rate limit fields are updated automatically using the existing `CallService.sourceRateLimit()` behaviour, preventing further calls until the reset time passes.

4. **GIVEN** a Monday.com source is configured with OAuth2 **WHEN** the OAuth2 access token expires **THEN** `AuthenticationService` automatically refreshes the token using the stored refresh token before retrying the failed request, with no interruption to ongoing sync operations.

### REQ-MON-002: Monday.com Board Item to OpenConnector Object Sync (Inbound)

OpenConnector MUST retrieve updates from Monday.com boards and reflect them in the corresponding OpenRegister objects within 5 minutes. Retrieval uses a polling job (`MondayPollJob`) triggered every 5 minutes via `JobService`. Updated board items are mapped to OpenRegister object properties via the `MondayBoardSync` field mapping record and applied through `SynchronizationService`.

**Scenarios:**

1. **GIVEN** a connected Monday.com account and a board item "Vergunning Horecabedrijf" is updated with a new status in Monday.com **WHEN** the 5-minute poll job runs **THEN** `MondayConnectorService` queries Monday.com for items updated since `lastSyncAt`, retrieves the changed item, maps the status column value to the configured OpenRegister field, and updates the corresponding OpenRegister object — the change is visible in OpenConnector within 5 minutes.

2. **GIVEN** a Monday.com board item has its assignee changed to a new team member **WHEN** the poll job processes the update **THEN** the `assignee` field in the corresponding OpenRegister object is updated to match the Monday.com person column value, using the `fieldMappings` defined in the `MondayBoardSync` record.

3. **GIVEN** multiple board items are updated between two poll cycles **WHEN** the poll job runs **THEN** all changed items are retrieved in a single batched GraphQL query using `items_page` cursor pagination and applied to their respective OpenRegister objects in the same job run.

4. **GIVEN** the Monday.com API is temporarily unavailable during polling **WHEN** the poll job encounters an HTTP 503 **THEN** a `CallLog` entry records the failure, the `MondayBoardSync` record retains its previous `lastSyncAt`, and the next poll job retries automatically at the next scheduled interval.

### REQ-MON-003: OpenConnector Object to Monday.com Board Item Sync (Outbound)

OpenConnector MUST create or update a corresponding Monday.com board item when a task or object is saved in OpenConnector. The sync is triggered by an object mutation event dispatched via `EventService`. New objects create a Monday.com item (`create_item` mutation); existing mapped objects update column values (`change_multiple_column_values` mutation). The `MondayBoardSync` record stores the resulting `mondayItemId` for future updates.

**Scenarios:**

1. **GIVEN** an object "Subsidieaanvraag Groen Schoolplein" is created in OpenConnector with a Monday.com sync mapping active **WHEN** the object is saved **THEN** `MondayConnectorService` executes a `create_item` GraphQL mutation with the object's title, status, and assignee mapped to the configured Monday.com board columns, and the returned `mondayItemId` is stored in the `MondayBoardSync` record.

2. **GIVEN** an existing mapped object is updated with a new status in OpenConnector **WHEN** the object mutation event fires **THEN** `MondayConnectorService` executes a `change_multiple_column_values` mutation on the stored `mondayItemId`, updating only the columns defined in the `fieldMappings` — unchanged fields are not sent to avoid overwriting concurrent Monday.com edits.

3. **GIVEN** the outbound sync encounters a conflict (the Monday.com item was also updated between the last inbound sync and the outbound write) **WHEN** the conflict is detected **THEN** the `MondayBoardSync` record status is set to `conflict`, a Nextcloud notification is sent to the administrator identifying the conflicting fields, and no automatic overwrite occurs — resolution requires manual action.

### REQ-MON-004: Monday.com Token Expiry and Re-authentication

OpenConnector MUST detect when a Monday.com connection token expires or is revoked and notify the responsible administrator with a re-authentication prompt.

**Scenarios:**

1. **GIVEN** the Monday.com integration is enabled **AND** the API token expires or is revoked **WHEN** a sync attempt returns HTTP 401 **THEN** `CallService` records the failure in `CallLog`, the Source entity status is updated to `error`, and a Nextcloud notification is sent to the administrator with a direct link to the Source configuration for re-authentication.

2. **GIVEN** the administrator receives the re-authentication notification **WHEN** they open the Source configuration and enter a new API token **THEN** the Source status is reset to `enabled`, a test connection call confirms the new token is valid, and the next scheduled poll job resumes normally.

3. **GIVEN** token expiry prevents the sync **WHEN** the source status is `error` **THEN** no further sync attempts are made until the source status is manually reset to `enabled` after re-authentication — preventing repeated failed calls that consume rate limit quota.

### REQ-LST-001: List View Data Display

OpenConnector MUST display synchronisation records and source objects in a structured list format with visible column headers, allowing users to scan and compare records efficiently. The list view uses `CnIndexPage` with `useListView` composable and `CnDataTable` component. Columns are generated from the schema via `columnsFromSchema()`.

**Scenarios:**

1. **GIVEN** a synchronisation record dataset is loaded in OpenConnector **WHEN** the user switches to list view **THEN** all records are displayed as rows with visible column headers derived from the schema (e.g., source name, status, last sync, direction) using `columnsFromSchema()` and rendered via `CnDataTable`.

2. **GIVEN** the list view is active and shows synchronisation records **WHEN** the page first loads **THEN** records are fetched via `ObjectService.findAll()` with the default sort order and pagination settings from `useListView`, and the `CnPagination` control shows the total record count and current page.

3. **GIVEN** the list view is active **WHEN** the user performs a text search **THEN** `useListView` debounces the input and issues a new `findAll()` call with the search term, reloading the table rows without a full page reload.

### REQ-LST-002: Column Sorting

OpenConnector list views MUST support sorting by any visible column. Sorting is managed by the `useListView` composable which tracks the active sort column and direction and passes `_order` parameters to `ObjectService.findAll()`.

**Scenarios:**

1. **GIVEN** the list view is active **WHEN** the user clicks a column header (e.g., "Last sync") **THEN** `useListView` sets the sort column and direction (ascending), issues a new `findAll()` call with `_order[lastSyncAt]=asc`, and the `CnDataTable` re-renders with the sorted rows and a sort indicator on the active column header.

2. **GIVEN** a column is already sorted ascending **WHEN** the user clicks the same column header again **THEN** the sort direction toggles to descending (`_order[lastSyncAt]=desc`) and the table rows reorder to reflect the new direction.

3. **GIVEN** the user sorts by a column **WHEN** they navigate to the next page **THEN** the sort order is preserved by `useListView` state and the paginated `findAll()` request includes the same `_order` parameter, returning records in the correct sort order.

### REQ-LST-003: Row Selection and Bulk Actions

OpenConnector list views MUST allow users to select one or more rows for bulk actions. Selection state is managed by the `selectionPlugin` on the object store. The `CnMassActionBar` floats at the bottom of the screen when one or more rows are selected.

**Scenarios:**

1. **GIVEN** the list view is active **WHEN** the user clicks the checkbox on a row **THEN** the row is visually highlighted using Nextcloud CSS variables (`var(--color-primary-element-light)`), the `selectionPlugin` adds the object ID to the selection set, and the `CnMassActionBar` appears with available bulk actions (e.g., delete, re-sync, export).

2. **GIVEN** one or more rows are selected **WHEN** the user clicks "Select all" in the table header **THEN** all currently loaded rows are added to the selection set and the `CnMassActionBar` updates to show the total selection count.

3. **GIVEN** multiple rows are selected **WHEN** the user clicks "Re-sync selected" in the `CnMassActionBar` **THEN** the selected synchronisation records are queued for immediate re-sync via `JobService`, a progress indicator is shown, and the list view refreshes when all re-sync jobs complete.

### REQ-DXP-001: DXP Endpoint Configuration

OpenConnector MUST allow administrators to configure connections to external DXP systems using standard integration protocols. A DXP connection is stored as a Source entity of type `json` with the DXP REST endpoint URL and authentication credentials. Configuration includes the sync interval (in seconds), content type filter, and optional field path templates for vendor-specific API response structures.

**Scenarios:**

1. **GIVEN** an administrator opens the OpenConnector source configuration **WHEN** they create a new Source with type `json` for a DXP endpoint (e.g., Sitecore, Umbraco, Kentico, Contentful) **AND** enters the API URL, authentication credentials (API key or OAuth2), and sync interval **THEN** the Source entity is persisted with the DXP-specific configuration in the `configuration` JSON field and a `DXPContentSync` record is created with status `active` and the configured `syncInterval`.

2. **GIVEN** a DXP source is configured **WHEN** the administrator saves the integration settings **THEN** `DXPConnectorService` immediately performs an initial content retrieval via `CallService` to validate the endpoint and returns the number of retrieved content items, confirming the DXP connection is working without manual intervention.

3. **GIVEN** a DXP source is configured with OAuth2 authentication **WHEN** the `DXPSyncJob` runs **THEN** `AuthenticationService` transparently handles token refresh before the content retrieval call, with no interruption to the sync cycle.

### REQ-DXP-002: Automatic DXP Content Retrieval and Display

OpenConnector MUST automatically retrieve content from a configured DXP endpoint and make it available in OpenConnector-managed objects without manual intervention. The sync runs at the configured `syncInterval` via `JobService`. Retrieved content is mapped to OpenRegister objects through `SynchronizationService` using the Source's field mapping configuration.

**Scenarios:**

1. **GIVEN** a DXP source is configured with a `syncInterval` of 300 seconds **WHEN** the `DXPSyncJob` triggers **THEN** `DXPConnectorService.fetchContent()` calls the DXP REST endpoint via `CallService`, retrieves all content items matching the configured `contentType` filter, and the content is available as OpenRegister objects in OpenConnector — the end user sees updated content without any manual action.

2. **GIVEN** an active DXP integration for a municipality's Sitecore instance **WHEN** the content editors publish a new "Nieuws-artikel" in Sitecore **THEN** within the configured sync interval the new article is retrieved by `DXPConnectorService`, stored as an OpenRegister object, and visible in OpenConnector's list view for that content type.

3. **GIVEN** the DXP endpoint returns a paginated response with 200 content items **WHEN** `DXPConnectorService` retrieves content **THEN** all pages are fetched via cursor or offset pagination, all 200 items are mapped to OpenRegister objects, and the `DXPContentSync` record's `itemCount` is updated to 200.

### REQ-DXP-003: DXP Incremental Sync

OpenConnector MUST support incremental sync for DXP content, retrieving only items modified since the last successful sync to minimise API load and processing time. The `lastSyncAt` timestamp from the `DXPContentSync` record is passed as a filter parameter to the DXP API request.

**Scenarios:**

1. **GIVEN** an active DXP integration with `lastSyncAt` set to 30 minutes ago **WHEN** the `DXPSyncJob` triggers **THEN** `DXPConnectorService` appends a `modifiedAfter` (or vendor-equivalent) parameter to the DXP API request, retrieving only content items modified in the last 30 minutes rather than the full dataset.

2. **GIVEN** the DXP source publishes 5 new content items and updates 3 existing items **WHEN** the incremental sync runs **THEN** `SynchronizationService` creates 5 new OpenRegister objects and updates 3 existing objects, and the `DXPContentSync` `itemCount` reflects the cumulative total.

3. **GIVEN** the DXP endpoint does not support incremental filtering (no `modifiedAfter` parameter) **WHEN** `DXPConnectorService` detects this from the source configuration flag `supportsIncrementalSync: false` **THEN** it falls back to full-dataset retrieval and uses `SynchronizationService` change detection to skip unchanged objects.

### REQ-DXP-004: DXP Credential Error Handling

OpenConnector MUST surface a clear error notification to the administrator when DXP credentials are invalid or expired, without disrupting other dashboard content or active sync jobs.

**Scenarios:**

1. **GIVEN** a DXP source has an expired or revoked API key **WHEN** a `DXPSyncJob` attempts content retrieval and receives HTTP 401 **THEN** `CallService` logs the failure in `CallLog`, the Source status is updated to `error`, the `DXPContentSync` record's `status` is set to `error` with an `errorMessage`, and a Nextcloud notification is dispatched to the administrator — no other active sources or sync jobs are affected.

2. **GIVEN** the DXP source status is `error` **WHEN** the administrator views the OpenConnector dashboard **THEN** the `DXPContentSync` record shows an error badge with the error message and the timestamp of the last failure, while other dashboard widgets continue displaying their cached content normally.

3. **GIVEN** the administrator updates the DXP source with valid credentials **WHEN** they save the configuration **THEN** `DXPConnectorService` performs an immediate test retrieval; if successful, the Source status is reset to `enabled`, the `DXPContentSync` status is set to `active`, and the next scheduled `DXPSyncJob` resumes the normal sync cycle.

## Data Model

### MondayBoardSync (stored in OpenRegister)

| Field | Type | Required | Description |
|---|---|---|---|
| mondayBoardId | string | Yes | Monday.com board ID (numeric string) |
| mondayItemId | string | No | Monday.com item ID; null until first outbound sync |
| openconnectorObjectId | string (UUID) | Yes | Related OpenRegister object UUID |
| sourceSlug | string | Yes | Slug of the OpenConnector Source entity |
| direction | string (enum) | Yes | `outbound`, `inbound`, or `bidirectional` |
| status | string (enum) | Yes | `pending`, `synced`, `failed`, `conflict` |
| lastSyncAt | datetime | No | Timestamp of last successful sync |
| fieldMappings | object | No | Column-to-property mapping overrides (`{ "monday_column": "orProperty" }`) |
| errorMessage | string | No | Error details when status is `failed` |
| itemCount | integer | No | Number of items synced in last cycle |

### DXPContentSync (stored in OpenRegister)

| Field | Type | Required | Description |
|---|---|---|---|
| dxpEndpointId | string | Yes | Content ID or path in the DXP system |
| openconnectorObjectId | string (UUID) | No | Related OpenRegister object UUID (null for collection-level records) |
| sourceSlug | string | Yes | Slug of the OpenConnector Source entity |
| contentType | string | Yes | DXP content type identifier (e.g., `nieuws-artikel`, `dienst-pagina`) |
| syncInterval | integer | Yes | Sync interval in seconds (minimum: 60) |
| lastSyncAt | datetime | No | Timestamp of last successful sync |
| status | string (enum) | Yes | `active`, `error`, `paused` |
| itemCount | integer | No | Total OpenRegister objects managed by this sync record |
| errorMessage | string | No | Error details when status is `error` |
