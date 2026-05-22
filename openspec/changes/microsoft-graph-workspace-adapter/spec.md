# Spec: Microsoft Graph + Google Workspace Adapter

**Status:** proposed
**Scope:** openconnector
**Tier:** saas-productivity-adapter
**Depends on:** saas-productivity-connectors (openconnector, for registration + auth + capability contract per REQ-SPC-001/002/003), prometheus-metrics (openconnector), hydra ADR-019 (integration-registry), hydra ADR-022 (apps consume OR abstractions), hydra ADR-024 (app manifest), hydra ADR-031 (scheduled workflows for subscription renewal), hydra ADR-005 (security — OAuth default)

## ADDED Requirements

### REQ-MGWA-001: The adapter SHALL register as a single IntegrationProvider for both Microsoft Graph and Google Workspace tenants

The microsoft-graph-workspace adapter is a SINGLE `IntegrationProvider` (not two separate adapters) that dispatches to the correct provider implementation (Graph or Workspace) based on the configured `ProductivityProviderConfig.provider` field. The adapter class MUST live at `lib/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspace.php` and implement `OCA\OpenRegister\Service\Integration\IntegrationProvider` per ADR-019.

The manifest entry in `src/manifest.json` MUST list a single `connectors[]` entry with:
- `id: "microsoft-graph-google-workspace"`
- `category: "saas-productivity"`
- `subCategory: "email-calendar"` (covering the email + calendar operations; v2 may split this)
- `authModes: ["oauth2", "serviceAccountJwt"]` (OAuth first per REQ-SPC-003; serviceAccountJwt for Graph client_credentials and Workspace domain-wide delegation)
- `capabilities: ["record-crud", "event-subscribe", "search-lookup", "bulk-export", "attachment-fetch"]` (per REQ-SPC-002 vocabulary)
- `rateLimits: { requestsPerMinute: 100, requestsPerDay: 10000 }` (conservative defaults; per-tenant configuration adjusts these)
- `pollingMode: "webhookSubscription"` (primary); `fallbackPollingMode: "scheduled"` (for mail delta syncs via ScheduledWorkflow)
- `schemaDiscovery: "providedViaManifest"` (canonical models are fixed in this spec, not discovered at runtime)

#### Scenario: Adapter registration and discovery

- **GIVEN** the openconnector instance with the microsoft-graph-workspace adapter deployed
- **WHEN** openregister's integration-registry is queried via `IntegrationRegistry::listIds()`
- **THEN** the ID `"microsoft-graph-google-workspace"` is present in the returned list.
- **AND WHEN** the adapter's manifest entry is fetched via `IntegrationRegistry::get("microsoft-graph-google-workspace")`
- **THEN** the returned entry carries all required fields (category, subCategory, authModes, capabilities, rateLimits, pollingMode, schemaDiscovery, documentationUrl).

### REQ-MGWA-002: The adapter SHALL define six canonical operations that abstract provider-specific quirks

The adapter MUST expose the following six canonical operations in a consistent interface that both Graph and Workspace providers implement:

| Operation | Purpose | Input | Output |
|---|---|---|---|
| `list_users(config_id, filter?, page_size=100, cursor?)` | Enumerate users in a tenant with paging support | filter: optional OData-like filter (normalized syntax) | `{ items: ProductivityUser[], next_cursor: opaque_string\|null }` |
| `upsert_event(config_id, user_email, event, external_id)` | Create or update a calendar event with idempotency | event: ProductivityEvent shape | `ProductivityEvent` (with provider_event_id, etag set) |
| `send_mail(config_id, from_user?, to, cc, bcc, subject, body_html, attachments)` | Send mail with attachments (up to 50 MB total) | from_user: email or null (uses default_user_principal); attachments: array of {name, content_type, data_base64} | `{ message_id, sent_at }` |
| `list_mail(config_id, user_email, folder='inbox', delta_cursor?, page_size=100, cursor?)` | Sync inbox changes (delta/sync-token support) | delta_cursor: opaque token from prior call (for incremental sync) | `{ items: ProductivityMail[], next_cursor, delta_cursor }` |
| `list_files(config_id, user_email, folder_id?, page_size=100, cursor?)` | Enumerate files in a drive (OneDrive or Google Drive) | folder_id: optional, defaults to root | `{ items: ProductivityFile[], next_cursor }` |
| `download_file(config_id, user_email, file_id)` | Stream file bytes (with Range request support) | file_id: provider-native file ID | `{ stream_handle, total_bytes, content_type }` (stream is PHP resource) |

All operations return responses in the canonical model shape (ProductivityUser, ProductivityEvent, etc.) defined in this spec. Provider-native fields are available ONLY via the `raw_request` operation (REQ-MGWA-010).

#### Scenario: Both Graph and Workspace implement the same operation signature

- **GIVEN** a ProductivityProviderConfig with `provider = "microsoft_graph"` and another with `provider = "google_workspace"`
- **WHEN** `list_users(graph_config, filter="department:Engineering")` is called on Graph and `list_users(workspace_config, filter="department:Engineering")` is called on Workspace
- **THEN** both return the same canonical response shape: `{ items: ProductivityUser[], next_cursor }`, where next_cursor is an opaque token usable in the next invocation to fetch the next page.

### REQ-MGWA-003: Canonical data models SHALL represent the intersection of what both Graph and Workspace expose

The adapter defines the following canonical models, each representing fields available from BOTH Graph and Workspace (no vendor-specific extensions):

**ProductivityUser**:
```json
{
  "id": "uuid",
  "provider_user_id": "graph-user-id or workspace-user-id",
  "principal_name": "user@tenant.onmicrosoft.com or user@company.com",
  "email": "user@company.com",
  "display_name": "John Doe",
  "given_name": "John",
  "family_name": "Doe",
  "job_title": "Engineer",
  "department": "Engineering",
  "manager_id": "manager-user-id",
  "enabled": true,
  "photo_url": "https://.../photo (anonymized unless user_sensitivity=high)"
}
```

**ProductivityEvent**:
```json
{
  "id": "uuid",
  "provider_event_id": "graph-event-id or workspace-event-id",
  "calendar_id": "calendar-id",
  "subject": "Q3 Planning Meeting",
  "body_html": "<p>Discuss Q3 OKRs</p>",
  "start_at": "2024-06-15T14:00:00+02:00",
  "end_at": "2024-06-15T15:00:00+02:00",
  "location": "Amsterdam Office, Room 4",
  "attendees": [
    { "email": "alice@company.com", "response": "accepted|tentative|declined|noResponse" }
  ],
  "organiser_email": "organiser@company.com",
  "online_meeting_url": "https://teams.microsoft.com/... or https://meet.google.com/...",
  "recurrence_rrule": "FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20240901",
  "etag": "opaque-etag-for-optimistic-locking"
}
```

**ProductivityMail**:
```json
{
  "id": "uuid",
  "provider_message_id": "graph-message-id or workspace-message-id",
  "conversation_id": "thread-id",
  "from_email": "sender@company.com",
  "to": ["recipient1@company.com"],
  "cc": ["cc@company.com"],
  "bcc": ["bcc@company.com"],
  "subject": "Monthly Report",
  "body_html": "<p>Attached is the monthly report</p>",
  "body_text": "Attached is the monthly report",
  "attachments": [
    { "id": "attach-id", "name": "report.pdf", "content_type": "application/pdf", "size": 204800 }
  ],
  "received_at": "2024-05-22T12:34:56Z",
  "has_attachments": true,
  "importance": "normal|high|low",
  "is_read": false
}
```

**ProductivityFile**:
```json
{
  "id": "uuid",
  "provider_file_id": "graph-item-id or workspace-file-id",
  "name": "report.pdf",
  "mime_type": "application/pdf",
  "size_bytes": 204800,
  "parent_id": "folder-id",
  "path": "/Shared Documents/report.pdf (or best-effort for Workspace)",
  "web_url": "https://onedrive.live.com/... or https://drive.google.com/...",
  "download_url_ref": "opaque-token-resolvable-via-download_file()",
  "created_at": "2024-05-15T10:00:00Z",
  "modified_at": "2024-05-22T11:30:00Z",
  "modified_by_email": "editor@company.com",
  "version": "provider-native-version-id"
}
```

All timestamps are ISO 8601 with timezone offset (e.g., `2024-05-22T12:34:56+02:00`). All IDs are UUID v4 or provider-native strings, stored as-is (no normalization).

#### Scenario: ProductivityUser fields are available from both Graph and Workspace

- **GIVEN** a Graph user and a Workspace user with comparable data
- **WHEN** `list_users()` is called for each
- **THEN** both return ProductivityUser objects with the same field set (principal_name, email, job_title, department, etc.); no Graph object has an extra `extensionProperties` field, no Workspace object has an extra `customSchemas` field.

### REQ-MGWA-004: Provider selection drives implementation routing; authentication and token caching are transparent

When any canonical operation is invoked with a ProductivityProviderConfig, the adapter MUST:

1. Inspect `config.provider` to determine which Provider class to use (GraphProvider or WorkspaceProvider).
2. Fetch credentials from `config.auth_profile_ref` via the auth-protocol-suite (OAuth2 token for delegated permission, service-account JWT for application permission).
3. Execute the operation using the provider's SDK (Graph SDK for Graph; google/apiclient for Workspace).
4. Translate the provider-native response into the canonical model.
5. Log the invocation to CallLog with operation, status, latency, errors, quota remaining.

Consumers never see provider-native response shapes unless they explicitly invoke `raw_request` (REQ-MGWA-010).

#### Scenario: Same operation routes to different provider implementations transparently

- **GIVEN** two ProductivityProviderConfigs, one for Graph (tenant A) and one for Workspace (customer B)
- **WHEN** `send_mail(graph_config, to="alice@company.com", subject="Hello")` is called
- **THEN** the adapter uses GraphProvider to POST `users/{user}/sendMail` to Graph.
- **AND WHEN** `send_mail(workspace_config, to="alice@company.com", subject="Hello")` is called
- **THEN** the adapter uses WorkspaceProvider to POST `gmail.users.messages.send` to Workspace.
- **AND WHEN** both responses are compared
- **THEN** both return the same canonical shape: `{ message_id, sent_at }`.

### REQ-MGWA-005: List users with pagination normalisation

`list_users(config_id, filter?, page_size=100, cursor?)` MUST:

- For **Graph**: Call `GET /v1.0/users` with `$top=page_size` and optional `$filter=...` (OData syntax). Follow `@odata.nextLink` to fetch next pages.
- For **Workspace**: Call `directory.users.list` with `maxResults=page_size` and optional `query=...` (Workspace syntax, different from OData). Follow `nextPageToken`.
- **Normalise the response**: Both return `{ items: ProductivityUser[], next_cursor: "opaque_token_or_null" }`. The `next_cursor` is the `@odata.nextLink` or `nextPageToken` from the provider; it can be passed back to `list_users()` in the `cursor` parameter for the next page.

Per REQ-SPC-002, pagination is a `record-crud` capability surface; both providers expose it.

#### Scenario: Pagination works symmetrically for Graph and Workspace

- **GIVEN** a tenant with 250 users and `page_size=100`
- **WHEN** `list_users(config, page_size=100)` is called for Graph
- **THEN** the response is `{ items: [100 ProductivityUser], next_cursor: "<@odata.nextLink>" }`.
- **AND WHEN** `list_users(config, page_size=100, cursor=next_cursor)` is called
- **THEN** the response is `{ items: [100 ProductivityUser], next_cursor: "<@odata.nextLink>" }`.
- **AND WHEN** `list_users(config, page_size=100, cursor=next_cursor)` is called a third time
- **THEN** the response is `{ items: [50 ProductivityUser], next_cursor: null }` (no more pages).
- **AND WHEN** the same sequence is repeated for Workspace
- **THEN** the same response shape is returned (with nextPageToken instead of @odata.nextLink under the hood, but the canonical interface is identical).

### REQ-MGWA-006: Create or update calendar event with idempotency via external_id

`upsert_event(config_id, user_email, event: ProductivityEvent, external_id: string)` MUST:

- Check if an event with matching `external_id` already exists.
  - **For Graph**: Store external_id in `singleValueExtendedProperties` and query on it: `$filter=singleValueExtendedProperties/any(ep: ep/id eq 'external_id' and ep/value eq '{value}')`.
  - **For Workspace**: Store external_id in `extendedProperties.private.external_id` and query similarly via Drive metadata.
- **If found**: Fetch the existing event, merge the new fields (RFC 6902 JSON Merge Patch semantics), and PATCH/UPDATE the event.
- **If not found**: Create a new event with the `external_id` annotation.
- Return the canonical ProductivityEvent with `provider_event_id` and `etag` set (for optimistic locking on future updates).

This enables idempotent scheduling workflows: if a scheduler is invoked twice for the same appointment, the second invocation updates the existing event instead of creating a duplicate.

#### Scenario: Upsert creates on first call, updates on retry

- **GIVEN** a call `upsert_event(config, user="alice@company.com", event={subject: "Team Sync"}, external_id="sched-123")`
- **WHEN** invoked the first time
- **THEN** an event is created in Alice's calendar with subject "Team Sync" and external_id="sched-123".
- **AND WHEN** the same call is retried (e.g., due to network timeout)
- **THEN** the adapter finds the existing event by external_id, updates the subject field (if it differs), and returns the same event.

### REQ-MGWA-007: Send mail with attachment support and resumable upload for large files

`send_mail(config_id, from_user: string?, to: string[], cc: string[], bcc: string[], subject: string, body_html: string, attachments: {name, content_type, data_base64}[])` MUST:

- **For Graph**: 
  - Compose an RFC 5322 MIME message with all recipients (To, Cc, Bcc) and HTML body.
  - If any attachment > 3 MB, use Graph's `createUploadSession` for resumable upload (split into 327 KB chunks).
  - POST `users/{from_user}/sendMail` with the message.
- **For Workspace**: 
  - Compose the same RFC 5322 MIME message.
  - If total message > 5 MB, use Gmail's resumable upload session.
  - POST `gmail.users.messages.send` with the RFC 5322 body (not the JSON shape).
- Return `{ message_id: "provider-native-id", sent_at: "ISO8601-timestamp" }`.

Attachments MUST NOT be persisted; bytes pass through the adapter. Per ADR-022, if bytes must land on the Conduction side, the consuming app uses docudesk for persistence, not the adapter.

#### Scenario: Send mail with attachment and handle large-file resumable upload

- **GIVEN** a request to send mail with 2 attachments: one 2 MB PDF, one 5 MB Excel file (total 7 MB)
- **WHEN** `send_mail(config, to=["bob@company.com"], attachments=[{name: "report.pdf", ...}, {name: "data.xlsx", ...}])` is called
- **THEN** for Graph, the adapter creates an upload session (because 5 MB > 3 MB limit) and resumes the upload in 327 KB chunks.
- **AND WHEN** the response is returned
- **THEN** both providers return `{ message_id: "...", sent_at: "2024-05-22T12:34:56Z" }`.

### REQ-MGWA-008: List mail with delta/sync support for incremental syncs

`list_mail(config_id, user_email: string, folder: string='inbox', delta_cursor?: string, page_size: number=100, cursor?: string)` MUST:

- **For Graph**: 
  - If `delta_cursor` is provided, call `GET /users/{user}/mailFolders/inbox/messages/delta?$deltatoken={delta_cursor}`.
  - If not provided, call `GET /users/{user}/mailFolders/inbox/messages/delta` to fetch all messages with a fresh delta cursor.
  - Follow `@odata.nextLink` to fetch additional pages.
  - Return the final `@odata.deltaLink` as `delta_cursor` in the response.
- **For Workspace**: 
  - If `delta_cursor` (which is a `historyId`) is provided, call `gmail.users.history.list(userId='me', startHistoryId={delta_cursor})`.
  - If not provided, fetch the current `historyId` from `gmail.users.getProfile()`.
  - Follow `nextPageToken` for pagination.
  - Return the final `historyId` as `delta_cursor`.
- Return `{ items: ProductivityMail[], next_cursor: "pagination-cursor", delta_cursor: "provider-sync-token" }`.

The `delta_cursor` is stored in Redis (per Design decision D5) and passed on the next invocation to receive only changes since the last call. This is essential for periodic mail-sync workflows that need to stay in sync without re-fetching every message.

#### Scenario: First sync fetches all messages; subsequent syncs fetch only changes

- **GIVEN** a mailbox with 1000 messages
- **WHEN** `list_mail(config, user="alice@company.com", delta_cursor=null)` is called
- **THEN** the adapter fetches all 1000 messages (paginated), returns `{ items: [100 ProductivityMail], next_cursor: "page2", delta_cursor: "final-cursor" }`.
- **AND WHEN** 1 hour later, `list_mail(config, user="alice@company.com", delta_cursor="final-cursor")` is called
- **THEN** the adapter fetches only the changes (e.g., 5 new messages, 2 marked as read), returns `{ items: [7 ProductivityMail], next_cursor: null, delta_cursor: "updated-cursor" }`.
- **Storage**: The latest `delta_cursor` is persisted in Redis (DeltaCursor record) for the next call.

### REQ-MGWA-009: List files with drive enumeration and path reconstruction

`list_files(config_id, user_email: string, folder_id?: string, page_size: number=100, cursor?: string)` MUST:

- **For Graph**: 
  - Call `GET /users/{user}/drive/root/children` (or `/items/{folder_id}/children` if folder_id is provided).
  - Follow `@odata.nextLink` for pagination.
- **For Workspace**: 
  - Call `drive.files.list(q="'{folder_id}' in parents")` (or `q="'root' in parents"` if folder_id is not provided).
  - Follow `nextPageToken`.
- **Path reconstruction**: 
  - For Graph, `path` is reconstructed from the `parentReference.path` field (e.g., `/Shared Documents`).
  - For Workspace, `path` is best-effort built from the parent chain (call `drive.files.get(id=item.parents[0])` recursively to build the path). If the parent chain is too deep or unavailable, `path` is populated with a deterministic placeholder (e.g., `/ROOT`).
- Return `{ items: ProductivityFile[], next_cursor: "cursor_or_null" }`.

#### Scenario: List files in OneDrive and Google Drive with path reconstruction

- **GIVEN** a user's OneDrive or Google Drive
- **WHEN** `list_files(config, user="alice@company.com", folder_id=null)` is called
- **THEN** for Graph, the adapter calls `GET /users/alice/drive/root/children` and returns `{ items: [ProductivityFile{name: "report.pdf", path: "/Shared Documents"}], next_cursor: null }`.
- **AND WHEN** the same call is made for Workspace
- **THEN** the adapter calls `drive.files.list(q="'root' in parents")` and reconstructs the path for each file, returning the same canonical shape.

### REQ-MGWA-010: Download file with streaming and Range request support

`download_file(config_id, user_email: string, file_id: string)` MUST:

- **For Graph**: Call `GET /users/{user}/drive/items/{file_id}/content` and stream the response body.
- **For Workspace**: Call `drive.files.get(fileId={file_id}, alt='media')` and stream the response body.
- **Honor Range requests**: If the HTTP request includes a `Range: bytes=start-end` header, pass it to the provider's API and return only the requested byte range.
- Return `{ stream_handle: PHP_resource, total_bytes: int, content_type: "application/pdf" }` (or the provider-native content type).

The caller (e.g., a controller) reads the stream and pipes it to the HTTP response body. The adapter does NOT buffer the entire file in memory.

#### Scenario: Stream a 200 MB file without in-memory buffering

- **GIVEN** a 200 MB file in Drive
- **WHEN** `download_file(config, user="alice@company.com", file_id="file-123")` is called
- **THEN** the adapter returns `{ stream_handle: resource, total_bytes: 209715200, content_type: "application/octet-stream" }`.
- **AND WHEN** the caller reads from the stream in 1 MB chunks and pipes to the response
- **THEN** memory usage remains O(chunk_size) ≈ 1 MB, not O(file_size) ≈ 200 MB.

### REQ-MGWA-011: Per-tenant configuration isolation and multi-tenant safe token caching

ProductivityProviderConfig (stored in the Source registry) MUST ensure:

1. **Separate token caches** — tokens are cached per `(config_id, auth_profile_id, scopes)`. Two configurations with different tenants cache tokens separately.
2. **Separate rate-limit counters** — each configuration gets its own quota bucket; exhausting quota on one tenant doesn't throttle another.
3. **Separate CallLog entries** — every operation is logged with `source_id = config_id`, allowing per-tenant usage tracking and debugging.

Multi-tenant deployments (e.g., a SaaS product serving 100 customers, each with their own M365 or Workspace tenant) must have 100 separate ProductivityProviderConfig records, one per customer. This is the deployment pattern; the adapter itself enforces nothing but the contract.

#### Scenario: Two tenants are isolated; exhausting one's quota doesn't throttle the other

- **GIVEN** two ProductivityProviderConfigs: one for Tenant A (Microsoft 365), one for Tenant B (Google Workspace)
- **WHEN** Tenant A's rate limit is exhausted (429 Too Many Requests)
- **THEN** the adapter returns `{ error: "Tenant A quota exceeded", retryAfter: 60 }`.
- **AND WHEN** Tenant B's API is called immediately after
- **THEN** the adapter proceeds normally (Tenant B's quota is unaffected).
- **AND WHEN** both operations are logged to CallLog
- **THEN** both entries carry `source_id: "config-a"` and `source_id: "config-b"` respectively, allowing per-tenant quota audits in mydash.

### REQ-MGWA-012: Webhook subscription for inbound change notifications

The adapter MUST support subscription to inbound change notifications (e.g., new mail, event changes) via webhooks:

`subscribe(config_id: string, user_email: string, resource: "mail"|"event", callback_endpoint: string): subscription_id` MUST:

- **For Graph**: POST `/subscriptions` with `notificationUrl=callback_endpoint`, `resource="/users/{user}/mailFolders/inbox/messages"` or `"/users/{user}/calendar/events"`, and `expirationDateTime=now + 3 days`.
- **For Workspace**: Create a Google push channel via `gmail.users.watch(userId='me', topicName=callback_endpoint, labelIds=['INBOX'])` or `calendar.channels.watch(...)`.
- **Renewal**: Store the subscription in openregister with the expiry time. Schedule a ScheduledWorkflow (per ADR-031) to auto-renew 12h before expiry.
- **Inbound webhook normalization**: When the provider's webhook is received, translate it to the CloudEvent schema (per ADR-022) and dispatch via the openregister CloudEvent dispatcher.

Return `subscription_id` (provider-native subscription ID).

#### Scenario: Subscribe to mail changes and auto-renew

- **GIVEN** a call `subscribe(config, user="alice@company.com", resource="mail", callback_endpoint="https://myapp/webhooks/productivity/mail")`
- **WHEN** invoked
- **THEN** for Graph, a subscription is created with 3-day expiry.
- **AND WHEN** a ScheduledWorkflow is created to auto-renew 2.5 days from now
- **THEN** at renewal time, the subscription is re-created without user intervention.
- **AND WHEN** a new mail arrives in Alice's inbox
- **THEN** the provider sends a webhook notification to `callback_endpoint`.
- **AND WHEN** the adapter processes the webhook
- **THEN** it normalises the notification to CloudEvent (e.g., `{ type: "com.example.mail.received", data: { ... } }`) and dispatches via OR dispatcher.

### REQ-MGWA-013: Availability and free/busy lookup for scheduling

The adapter MUST support scheduling workflows with per-attendee availability:

`find_availability(config_id: string, organiser_email: string, attendees: string[], duration_minutes: int, window_start: ISO8601, window_end: ISO8601)` MUST:

- **For Graph**: POST `/users/{organiser}/calendar/getSchedule` with all attendee emails and the time window. Returns available slots and attendee status for each.
- **For Workspace**: Call `calendar.freebusy.query(items=[{id: attendee}], timeMin={window_start}, timeMax={window_end})`. Returns busy/free status for each attendee.
- **Normalise the response**: Return `{ slots: [{start, end, attendee_statuses: {attendee: "available|busy"}}], ranked_by: "attendee_fit" }`.

#### Scenario: Find a 30-minute meeting slot for 5 attendees

- **GIVEN** a call `find_availability(config, organiser="bob@company.com", attendees=["alice@company.com", "charlie@company.com", ...], duration_minutes=30, window_start="2024-06-15T08:00:00Z", window_end="2024-06-15T17:00:00Z")`
- **WHEN** invoked
- **THEN** the adapter returns `{ slots: [{start: "2024-06-15T14:00:00Z", end: "2024-06-15T14:30:00Z", attendee_statuses: {alice: "available", charlie: "busy", ...}}, ...], ranked_by: "attendee_fit" }`.

### REQ-MGWA-014: Group + membership operations

The adapter MUST support enumeration and management of tenant groups:

`list_groups(config_id: string, filter?: string)` — enumerate groups.
`create_group(config_id: string, name: string, description: string?, mail_enabled: bool?)` — create a group.
`add_member(config_id: string, group_id: string, user_email: string)` — add a member.
`remove_member(config_id: string, group_id: string, user_email: string)` — remove a member.

**Canonical model: ProductivityGroup**:
```json
{
  "id": "uuid",
  "provider_group_id": "graph-group-id or workspace-group-id",
  "name": "Engineering Team",
  "description": "All engineers in the company",
  "email": "engineering@company.com",
  "members": ["user-id-1", "user-id-2"],
  "group_kind": "security_group|distribution_list|office365_group|google_group"
}
```

- **For Graph**: Use `/groups`, `/groups/{id}/members/$ref` API; distinguish unified groups, security groups, distribution lists.
- **For Workspace**: Use Cloud Identity Groups API + `members.insert` + `members.delete`; distinguish security groups, Google Groups for Business.

#### Scenario: Create a group and add members

- **GIVEN** a call `create_group(config, name="Engineering Team", description="Engineers", mail_enabled=true)`
- **WHEN** invoked for Graph
- **THEN** a security-enabled group is created in Azure AD.
- **AND WHEN** `add_member(config, group_id="...", user_email="alice@company.com")` is called
- **THEN** Alice is added to the group.

### REQ-MGWA-015: Raw-passthrough escape hatch for provider-specific features

`raw_request(config_id: string, method: "GET"|"POST"|"PATCH"|"DELETE", path: string, body?: object, headers?: object)` MUST:

- Authenticate using the configured auth_profile (OAuth2 token or JWT).
- Apply rate-limiting and reliability policies (ipaas-reliability).
- Execute the raw HTTP request against the provider's API.
- Return the raw response body untouched (no canonical normalization).
- Increment a deprecation counter (to guide future canonical-surface extensions).

This allows consumers to use provider-specific features (Graph extension properties, Google appProperties, etc.) when the canonical surface doesn't cover them, without being blocked.

#### Scenario: Access Graph extension properties for custom metadata

- **GIVEN** a need to store custom metadata on a user (not covered by ProductivityUser canonical fields)
- **WHEN** `raw_request(config, method="PATCH", path="/users/{user_id}", body={extensionProperties: {...}})` is called
- **THEN** the request is authenticated, routed to Graph, and the raw response is returned.
- **AND WHEN** the adapter's deprecation counter is incremented
- **THEN** over time, mydash can identify "productivityAdapter:extensionProperties was used 47 times in May" and suggest adding it to the canonical surface.

### REQ-MGWA-016: Throttle and quota awareness with canonical error responses

When the adapter receives a throttle response (HTTP 429 or 403 with quota-related errors):

- **For Graph**: HTTP 429 with `Retry-After: N` header (seconds).
- **For Workspace**: HTTP 403 with error code `userRateLimitExceeded` or `quotaExceeded`; no standard Retry-After, so use exponential backoff (1s, 2s, 4s, 8s, ..., capped at 60s).

The adapter MUST:

1. Respect ipaas-reliability's retry policy (honor Retry-After or apply backoff).
2. Surface remaining quota via `X-OC-Quota-Remaining: N` response headers (if the provider exposes it).
3. On sustained throttling (5+ consecutive 429s), trigger the per-tenant circuit breaker (ipaas-reliability), alerting the operator to review quota.

#### Scenario: Respect Retry-After and circuit break on sustained throttling

- **GIVEN** Graph returns HTTP 429 with `Retry-After: 30`
- **WHEN** the adapter receives the response
- **THEN** it waits 30 seconds and retries per ipaas-reliability policy.
- **AND WHEN** 5 consecutive retries fail with 429
- **THEN** the circuit breaker opens; all subsequent calls fail fast with a "tenant quota exceeded" error.
- **AND WHEN** the response is returned to the consumer
- **THEN** it includes `X-OC-Quota-Remaining: 0` (if Graph reports it) or `X-OC-Circuit-Breaker-Open: true`.

## Integration Points

The adapter consumes:

- **openconnector's auth-protocol-suite** — OAuth2 token exchange, PKCE, token refresh, caching.
- **openconnector's ipaas-reliability** — retry policy, circuit breaker, idempotency tracking.
- **openconnector's CallLog** — operation logging and audit trail.
- **openconnector's Source registry** — ProductivityProviderConfig storage.
- **openregister's integration-registry** — IntegrationProvider contract (ADR-019).
- **openregister's per-user credential surface** — per-user OAuth token storage (if available).
- **openregister's ScheduledWorkflow** — webhook subscription auto-renewal (ADR-031).
- **openregister's CloudEvent dispatcher** — webhook event normalization (ADR-022).
- **prometheus-metrics** — `openconnector_adapter_invocations_total` counter extension.

## Dependencies (External Libraries)

- **microsoft/graph-sdk** (v1.x) — REST API client for Microsoft Graph v1.0.
- **microsoft/graph-beta-sdk** (v1.x) — for Beta API features (e.g., mail delta queries).
- **google/apiclient** (v2.x) — includes Admin SDK, Gmail, Calendar, Drive, Directory APIs.
- **google/auth-library-php** — JWT signing for service-account tokens and domain-wide delegation.
- **ramsey/uuid** (already in openconnector) — UUID generation for canonical IDs.
- **guzzlehttp/guzzle** (already in openconnector) — HTTP client for all SDK transports.

## Standards

- **Microsoft Graph REST API v1.0** (+ Beta when necessary).
- **Google Workspace Admin SDK v1**, **Gmail API v1**, **Calendar API v3**, **Drive API v3**.
- **RFC 5322** — Internet Message Format (MIME) for mail composition.
- **RFC 7233** — HTTP Range Requests for partial downloads.
- **RFC 3986** — URI syntax for raw_request paths.
- **OAuth 2.0 Authorization Code Grant with PKCE** (RFC 7636, RFC 6234).
- **Microsoft identity platform v2.0** — token exchange, admin consent.
- **Google Cloud Identity & Domain-Wide Delegation** — service-account impersonation.
- **ISO 8601** — timestamps and durations.
- **CloudEvents v1.0** — webhook event schema.
- **GDPR / AVG** — personal data minimization (photo_url anonymization).

## Compliance

- **ADR-019** (integration-registry) — adapter registers via DI-tagged IntegrationProvider.
- **ADR-022** (consume OR abstractions) — uses Source, ScheduledWorkflow, CloudEvent, audit-trail.
- **ADR-024** (app manifest) — manifest entry per spec, consuming apps discover adapter via registry.
- **ADR-031** (declarative business logic) — webhook renewal via ScheduledWorkflow, no per-adapter TimedJob.
- **ADR-005** (security) — OAuth2 default (REQ-SPC-003); no secrets in config; application permission via JWT.

## Observability

- **CallLog**: Every operation logged with request, response, latency, errors, quota remaining, provider-native error codes.
- **Metrics**: `openconnector_adapter_invocations_total` (per adapter, operation, provider, status).
- **Alerting**: Circuit breaker opens, token refresh failure, sustained throttling.
