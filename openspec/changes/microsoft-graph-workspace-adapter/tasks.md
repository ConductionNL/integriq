# Tasks — Microsoft Graph + Google Workspace Adapter

## 0. Deduplication Check

### Task 0.1: Confirm adapter does not already exist

- **spec_ref**: proposal.md, spec.md
- **files**: `lib/Service/Adapter/Saas/`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `lib/Service/Adapter/Saas/` WHEN scanned THEN no existing `MicrosoftGraphGoogleWorkspace.php` or similar.
  - GIVEN `src/manifest.json` WHEN inspected THEN no existing `connectors[]` entry with id `"microsoft-graph-google-workspace"`.
  - GIVEN the existing `prometheus-metrics` spec WHEN scanned THEN no metric named `openconnector_adapter_invocations_total` is already declared (REQ-MGWA-010 scope).
- [ ] Implement
- [ ] Test

## 1. Core Adapter Implementation

### Task 1.1: Implement MicrosoftGraphGoogleWorkspace adapter class (main router)

- **spec_ref**: REQ-MGWA-001, REQ-MGWA-004
- **files**: `lib/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspace.php`
- **acceptance_criteria**:
  - GIVEN the adapter class WHEN inspected THEN it implements `OCA\OpenRegister\Service\Integration\IntegrationProvider`.
  - GIVEN a ProductivityProviderConfig with `provider = "microsoft_graph"` WHEN an operation is invoked THEN the adapter routes to GraphProvider.
  - GIVEN a ProductivityProviderConfig with `provider = "google_workspace"` WHEN an operation is invoked THEN the adapter routes to WorkspaceProvider.
  - GIVEN any operation WHEN executed THEN a CallLog entry is created with operation, status, latency, errors, quota remaining.
  - GIVEN the adapter class WHEN scanned THEN every public operation method has return type matching the canonical response shape (ProductivityUser[], ProductivityEvent, ProductivityMail[], etc.).
- [ ] Implement
- [ ] Test (unit: router dispatch logic, mock both providers)

### Task 1.2: Implement GraphProvider class (Microsoft Graph operations)

- **spec_ref**: REQ-MGWA-002 through REQ-MGWA-016 (Graph-specific implementation)
- **files**: `lib/Service/Adapter/Saas/Provider/GraphProvider.php`
- **acceptance_criteria**:
  - GIVEN the GraphProvider class WHEN inspected THEN it implements the canonical interface (listUsers, upsertEvent, sendMail, listMail, listFiles, downloadFile, findAvailability, listGroups, createGroup, addMember, removeMember, rawRequest).
  - GIVEN `listUsers()` WHEN called THEN it calls `GET /v1.0/users` with `$top` and `$filter`, follows `@odata.nextLink`, and returns canonical ProductivityUser[].
  - GIVEN `upsert_event()` with an external_id WHEN called THEN it checks `singleValueExtendedProperties` for the external_id, merges fields on update, and returns ProductivityEvent with etag.
  - GIVEN `sendMail()` with attachments WHEN called THEN it composes RFC 5322 MIME, uses `createUploadSession` for > 3 MB attachments, and returns { message_id, sent_at }.
  - GIVEN `listMail()` with delta_cursor WHEN called THEN it calls `/messages/delta` with `$deltatoken`, follows `@odata.nextLink`, returns ProductivityMail[] + final delta_cursor.
  - GIVEN `listFiles()` WHEN called THEN it calls `/drive/root/children` or `/items/{folder_id}/children`, reconstructs path from parentReference, returns ProductivityFile[].
  - GIVEN `downloadFile()` WHEN called THEN it calls `GET /items/{id}/content` and returns a stream handle (PHP resource) without buffering the entire file in memory.
  - GIVEN `findAvailability()` WHEN called THEN it POSTs `/calendar/getSchedule` with attendees and window, returns slots ranked by attendee fit.
  - GIVEN `listGroups()` WHEN called THEN it enumerates `/groups`, distinguishes group types (unified, security, distribution), returns ProductivityGroup[].
  - GIVEN `rawRequest()` WHEN called THEN it executes the raw HTTP request, increments deprecation counter, returns raw response.
- [ ] Implement
- [ ] Test (integration: mock Graph SDK; test each operation against mocked responses; verify MIME composition for mail; verify stream handle for downloads)

### Task 1.3: Implement WorkspaceProvider class (Google Workspace operations)

- **spec_ref**: REQ-MGWA-002 through REQ-MGWA-016 (Workspace-specific implementation)
- **files**: `lib/Service/Adapter/Saas/Provider/WorkspaceProvider.php`
- **acceptance_criteria**:
  - GIVEN the WorkspaceProvider class WHEN inspected THEN it implements the canonical interface (listUsers, upsertEvent, sendMail, listMail, listFiles, downloadFile, findAvailability, listGroups, createGroup, addMember, removeMember, rawRequest).
  - GIVEN `listUsers()` WHEN called THEN it calls `directory.users.list` with `maxResults` and optional `query`, follows `nextPageToken`, returns canonical ProductivityUser[].
  - GIVEN `upsert_event()` with an external_id WHEN called THEN it checks `extendedProperties.private.external_id`, merges fields on update, returns ProductivityEvent with etag.
  - GIVEN `sendMail()` with attachments WHEN called THEN it composes RFC 5322 MIME, uses resumable upload for > 5 MB, POSTs `gmail.users.messages.send`, returns { message_id, sent_at }.
  - GIVEN `listMail()` with delta_cursor (historyId) WHEN called THEN it calls `gmail.users.history.list` with `startHistoryId`, follows pagination, returns ProductivityMail[] + final historyId.
  - GIVEN `listFiles()` WHEN called THEN it calls `drive.files.list` with `q="'{parent}' in parents"`, reconstructs path by walking parent chain, returns ProductivityFile[].
  - GIVEN `downloadFile()` WHEN called THEN it calls `drive.files.get(alt='media')` and returns a stream handle without buffering.
  - GIVEN `findAvailability()` WHEN called THEN it calls `calendar.freebusy.query` with window and attendees, returns slots + attendee statuses.
  - GIVEN `listGroups()` WHEN called THEN it uses Cloud Identity Groups API, distinguishes group types (security, Google Groups), returns ProductivityGroup[].
  - GIVEN `rawRequest()` WHEN called THEN it executes the raw request, increments deprecation counter, returns raw response.
- [ ] Implement
- [ ] Test (integration: mock google/apiclient SDK; test each operation; verify RFC 5322 composition; verify path reconstruction for files; verify stream for downloads)

## 2. Manifest + Registration

### Task 2.1: Add manifest entry to src/manifest.json

- **spec_ref**: REQ-MGWA-001, REQ-SPC-002, REQ-SPC-003
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest entry WHEN inspected THEN it includes:
    - `id: "microsoft-graph-google-workspace"`
    - `category: "saas-productivity"`
    - `subCategory: "email-calendar"`
    - `authModes: ["oauth2", "serviceAccountJwt"]` (OAuth first per REQ-SPC-003)
    - `capabilities: ["record-crud", "event-subscribe", "search-lookup", "bulk-export", "attachment-fetch"]`
    - `rateLimits: { requestsPerMinute: 100, requestsPerDay: 10000 }`
    - `pollingMode: "webhookSubscription"` with `fallbackPollingMode: "scheduled"`
    - `schemaDiscovery: "providedViaManifest"`
    - `documentationUrl: "https://docs.openconnector.nl/.../microsoft-graph-google-workspace"`
  - GIVEN `npm run check:manifest` WHEN run THEN it exits 0.
- [ ] Implement
- [ ] Test (manifest validation; browser smoke test confirming adapter appears in integration-registry admin UI)

### Task 2.2: Add DI tag to lib/AppInfo/Application.php

- **spec_ref**: REQ-MGWA-001
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `lib/AppInfo/Application.php` WHEN inspected THEN the MicrosoftGraphGoogleWorkspace adapter is registered with DI tag `IntegrationProvider`.
  - GIVEN the dev container is rebuilt WHEN `OCA.OpenRegister` integration registry is queried THEN the adapter id `"microsoft-graph-google-workspace"` is present.
- [ ] Implement
- [ ] Test (integration-registry list-ids assertion; browser smoke)

## 3. Configuration + Seeding

### Task 3.1: Create seed ProductivityProviderConfig for Microsoft Graph (dev/test)

- **spec_ref**: design.md Seed Data
- **files**: `lib/Settings/seeds/sources/microsoft-graph.json`
- **acceptance_criteria**:
  - GIVEN the seed file WHEN parsed as JSON THEN it conforms to the Source registry shape and includes:
    - `provider: "microsoft_graph"`
    - `tenant_id: "00000000-0000-0000-0000-000000000001"`
    - `lifecycleState: "paused"`
    - `_meta` block with seed_provider, seed_version, notes
  - GIVEN a fresh dev install WHEN the repair step runs THEN the seed appears in the Source registry and is paused.
- [ ] Implement
- [ ] Test (PHPUnit repair fixture; verify source is paused and idempotent on re-run)

### Task 3.2: Create seed ProductivityProviderConfig for Google Workspace (dev/test)

- **spec_ref**: design.md Seed Data
- **files**: `lib/Settings/seeds/sources/google-workspace.json`
- **acceptance_criteria**:
  - GIVEN the seed file WHEN parsed as JSON THEN it conforms to the Source registry shape and includes:
    - `provider: "google_workspace"`
    - `customer_id: "C0000000000"`
    - `service_account_subject: "service-account@localhost.iam.gserviceaccount.com"`
    - `lifecycleState: "paused"`
    - `_meta` block
  - GIVEN a fresh dev install WHEN the repair step runs THEN the seed appears and is paused.
- [ ] Implement
- [ ] Test (PHPUnit repair fixture; verify source is paused and idempotent)

## 4. Advanced Features

### Task 4.1: Implement webhook subscription and auto-renewal (REQ-MGWA-012)

- **spec_ref**: REQ-MGWA-012, design.md decision D6
- **files**: `lib/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspace.php` (subscribe method), `lib/Migration/` (subscription renewal ScheduledWorkflow registration)
- **acceptance_criteria**:
  - GIVEN `subscribe(config, user, resource="mail", callback_endpoint)` WHEN called THEN:
    - For Graph: POST `/subscriptions` with 3-day expiry; store in openregister.
    - For Workspace: Create Google push channel via `gmail.users.watch`.
  - GIVEN a ScheduledWorkflow WHEN created THEN it auto-renews 12h before expiry.
  - GIVEN an inbound webhook notification WHEN received THEN it is normalized to CloudEvent and dispatched via OR dispatcher.
  - GIVEN `subscribe()` called twice with the same (config, user, resource, callback) THEN the second call returns the same subscription_id (idempotent).
- [ ] Implement
- [ ] Test (mock Graph/Workspace webhooks; verify subscription renewal ScheduledWorkflow is created; verify CloudEvent normalization)

### Task 4.2: Implement DeltaCursor tracking in Redis for incremental mail sync (REQ-MGWA-008)

- **spec_ref**: REQ-MGWA-008, design.md decision D5
- **files**: `lib/Service/DeltaCursor.php` (new), redis integration in WorkspaceProvider + GraphProvider
- **acceptance_criteria**:
  - GIVEN `listMail()` called the first time WHEN the response includes a delta_cursor THEN the cursor is stored in Redis under key `productivity:delta:{config_id}:{resource}:{user_id}`.
  - GIVEN `listMail()` called a second time with the stored delta_cursor WHEN passed to the provider THEN only changes since the last call are returned.
  - GIVEN Redis is unavailable WHEN `listMail()` is called THEN the adapter falls back to full fetch (no delta) and logs a warning.
  - GIVEN 1 hour between calls WHEN `listMail()` is called for Workspace THEN the adapter fetches only the deltas; for Graph, the `@odata.deltaLink` is used.
- [ ] Implement
- [ ] Test (Redis mock; verify cursor persistence; verify incremental fetch behavior)

### Task 4.3: Implement throttle + quota awareness with circuit breaker (REQ-MGWA-016)

- **spec_ref**: REQ-MGWA-016, design.md decision D8
- **files**: integration with ipaas-reliability in both providers
- **acceptance_criteria**:
  - GIVEN Graph returns HTTP 429 with `Retry-After: 30` WHEN the adapter receives it THEN it respects the Retry-After and retries per ipaas-reliability policy.
  - GIVEN Workspace returns HTTP 403 with `userRateLimitExceeded` WHEN the adapter receives it THEN it applies exponential backoff (capped at 60s).
  - GIVEN 5 consecutive 429s WHEN received THEN the per-tenant circuit breaker opens and subsequent calls fail fast.
  - GIVEN the response body includes quota information WHEN returned THEN `X-OC-Quota-Remaining: N` header is included in the response.
  - GIVEN the circuit breaker is open WHEN a call is made THEN the error response includes `X-OC-Circuit-Breaker-Open: true`.
- [ ] Implement
- [ ] Test (mock 429 responses; verify backoff timing; verify circuit breaker state transitions)

### Task 4.4: Implement streaming file download without in-memory buffering (REQ-MGWA-010)

- **spec_ref**: REQ-MGWA-010, design.md decision D7
- **files**: GraphProvider + WorkspaceProvider download_file methods, controller integration
- **acceptance_criteria**:
  - GIVEN `downloadFile()` called for a 200 MB file WHEN the operation completes THEN the stream handle is returned without buffering the entire file in memory.
  - GIVEN the caller reads from the stream in 1 MB chunks WHEN piped to the HTTP response THEN memory usage remains O(chunk_size), not O(file_size).
  - GIVEN a `Range: bytes=0-999999` header in the request WHEN download_file is called THEN it is passed to the provider and only the requested range is returned.
  - GIVEN `downloadFile()` called for a 10 MB file WHEN the response includes a stream handle THEN the total_bytes field accurately reflects the full file size (even for Range requests returning a partial range).
- [ ] Implement
- [ ] Test (mock stream responses; verify memory overhead is O(chunk_size); test Range request handling)

## 5. Testing

### Task 5.1: Unit tests for the main adapter router (REQ-MGWA-004)

- **spec_ref**: REQ-MGWA-004
- **files**: `tests/Unit/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspaceAdapterTest.php`
- **acceptance_criteria**:
  - GIVEN a ProductivityProviderConfig with `provider = "microsoft_graph"` WHEN an operation is invoked THEN the router dispatches to GraphProvider (verified via mock assertion).
  - GIVEN a ProductivityProviderConfig with `provider = "google_workspace"` WHEN the same operation is invoked THEN the router dispatches to WorkspaceProvider.
  - GIVEN any operation WHEN executed THEN CallLog is written with correct fields (operation, status, latency, errors).
  - GIVEN invalid config or missing auth profile WHEN an operation is invoked THEN a clear error is returned and logged.
- [ ] Implement
- [ ] Test

### Task 5.2: Integration tests for GraphProvider (list_users, upsert_event, send_mail, list_mail, list_files, download_file, find_availability)

- **spec_ref**: REQ-MGWA-002 through REQ-MGWA-010 (Graph scenarios)
- **files**: `tests/Integration/Adapter/Saas/GraphProviderTest.php`
- **acceptance_criteria**:
  - GIVEN a mocked Graph SDK WHEN each canonical operation is invoked THEN the provider correctly translates the request, executes, and returns the canonical response shape.
  - GIVEN `listUsers()` with filter WHEN called THEN OData `$filter` syntax is used; pagination follows `@odata.nextLink`.
  - GIVEN `upsertEvent()` WHEN called THEN `singleValueExtendedProperties` is checked for external_id; existing events are updated, new ones are created.
  - GIVEN `sendMail()` with large attachments (> 3 MB) WHEN called THEN `createUploadSession` is used for resumable upload.
  - GIVEN `listMail()` with delta_cursor WHEN called THEN the cursor is passed as `$deltatoken` to the `/messages/delta` endpoint.
  - GIVEN `downloadFile()` WHEN called THEN the stream is returned without buffering; Range requests are honored.
  - GIVEN `findAvailability()` WHEN called THEN POST `/calendar/getSchedule` is issued with attendees and window.
  - GIVEN `listGroups()` WHEN called THEN group types (unified, security, distribution) are distinguished in ProductivityGroup.group_kind.
- [ ] Implement
- [ ] Test

### Task 5.3: Integration tests for WorkspaceProvider (list_users, upsert_event, send_mail, list_mail, list_files, download_file, find_availability)

- **spec_ref**: REQ-MGWA-002 through REQ-MGWA-010 (Workspace scenarios)
- **files**: `tests/Integration/Adapter/Saas/WorkspaceProviderTest.php`
- **acceptance_criteria**:
  - GIVEN a mocked google/apiclient SDK WHEN each canonical operation is invoked THEN the provider correctly translates the request, executes, and returns the canonical response shape.
  - GIVEN `listUsers()` WHEN called THEN `directory.users.list` is used with `maxResults`; pagination follows `nextPageToken`.
  - GIVEN `upsertEvent()` WHEN called THEN `extendedProperties.private.external_id` is checked; existing events are updated, new ones are created.
  - GIVEN `sendMail()` with large attachments (> 5 MB) WHEN called THEN resumable upload is used.
  - GIVEN `listMail()` with delta_cursor (historyId) WHEN called THEN `gmail.users.history.list` is issued with `startHistoryId`.
  - GIVEN `downloadFile()` WHEN called THEN the stream is returned without buffering; Range requests are honored.
  - GIVEN `findAvailability()` WHEN called THEN POST `calendar.freebusy.query` is issued with attendees and window.
  - GIVEN `listGroups()` WHEN called THEN Cloud Identity Groups API is used; group types are distinguished.
- [ ] Implement
- [ ] Test

### Task 5.4: Parity tests (both providers return same canonical shape for the same input)

- **spec_ref**: REQ-MGWA-002, REQ-MGWA-004
- **files**: `tests/Integration/Adapter/Saas/MicrosoftGraphGoogleWorkspaceParityTest.php`
- **acceptance_criteria**:
  - GIVEN the same operation (e.g., `listUsers(filter="department:Engineering")`) executed against mocked Graph and Workspace WHEN both complete THEN the response shapes are identical (same ProductivityUser[] fields, same pagination behavior).
  - GIVEN `upsertEvent()` for both Graph and Workspace WHEN external_id matches WHEN the second call updates the event THEN both providers return the updated event shape.
  - GIVEN `send_mail()` for both providers WHEN attachments are included THEN both return { message_id, sent_at }.
  - GIVEN error scenarios (quota exceeded, auth failure, network timeout) WHEN both providers encounter them THEN both return the same canonical error shape.
- [ ] Implement
- [ ] Test

### Task 5.5: Browser smoke test (integration-registry admin UI, adapter appears and can be configured)

- **spec_ref**: REQ-MGWA-001
- **files**: browser test or manual smoke test
- **acceptance_criteria**:
  - GIVEN the dev container is running WHEN the integration-registry admin UI is opened THEN the "Microsoft Graph + Google Workspace" adapter appears in the connectors list.
  - GIVEN the adapter is clicked WHEN the configuration form is shown THEN fields for tenant_id (Graph) or customer_id (Workspace) are present.
  - GIVEN valid credentials are entered WHEN the form is submitted THEN a ProductivityProviderConfig source is created and appears in the Source registry.
  - GIVEN the source is enabled (lifecycleState=active) WHEN a downstream app invokes an operation THEN the adapter routes correctly.
- [ ] Implement
- [ ] Test (Playwright or manual verification)

## 6. Documentation + Observability

### Task 6.1: Extend prometheus-metrics spec to include openconnector_adapter_invocations_total counter

- **spec_ref**: REQ-MGWA-010, design.md decision D9
- **files**: `openspec/specs/prometheus-metrics/spec.md` (delta: add requirement for per-adapter invocation counter)
- **acceptance_criteria**:
  - GIVEN the prometheus-metrics spec WHEN scanned THEN a new ADDED requirement defines `openconnector_adapter_invocations_total` counter with labels: adapter_id, operation, provider, status.
  - GIVEN any adapter invocation WHEN completed THEN the counter is incremented with the correct labels.
  - GIVEN the `/api/metrics` endpoint WHEN queried THEN the counter is exposed with the adapter's invocation data.
- [ ] Implement (delta change to prometheus-metrics spec)
- [ ] Test (metrics exposed correctly)

### Task 6.2: Author journeydoc tutorial page for the adapter (optional, defer to v1.1)

- **spec_ref**: ADR-030 journeydoc convention
- **files**: `docs/integrations/microsoft-graph-google-workspace.md` (new), with journeydoc capture-spec block
- **acceptance_criteria**:
  - GIVEN the docs site WHEN built THEN the page renders under `/integrations/microsoft-graph-google-workspace/` with at least 1 real screenshot of the integration-registry admin entry.
  - GIVEN the journeydoc capture-spec WHEN executed THEN it captures the credential-entry flow for both Graph and Workspace.
- [ ] Implement (optional — can defer to v1.1)
- [ ] Test (docs site build; capture-spec passes)

## 7. Cross-Cutting Verification

### Task 7.1: Verify per-tenant token cache isolation (REQ-MGWA-011)

- **spec_ref**: REQ-MGWA-011
- **files**: integration test
- **acceptance_criteria**:
  - GIVEN two ProductivityProviderConfigs (Tenant A, Tenant B) WHEN operations are executed in parallel THEN token caches are separate (keyed by config_id); Tenant A's quota exhaustion does not affect Tenant B.
  - GIVEN Tenant A's quota is exhausted WHEN a call to Tenant B is made THEN Tenant B proceeds normally.
- [ ] Implement
- [ ] Test (mock token service; verify cache keys are distinct per tenant)

### Task 7.2: Verify CallLog captures all required fields

- **spec_ref**: design.md decision D9
- **files**: integration test
- **acceptance_criteria**:
  - GIVEN any adapter invocation WHEN logged to CallLog THEN the entry includes: operation, status, latency_ms, quota_remaining (if known), error (if any), request_body (sanitized), response_body (sanitized).
  - GIVEN provider-native error codes WHEN captured THEN they are stored for debugging (Graph error.code, Workspace error.code).
  - GIVEN a SequelizedLogEntry WHEN inspected via mydash THEN per-tenant usage is visible and per-adapter latency is tracked.
- [ ] Implement
- [ ] Test (verify CallLog schema and query behavior)

### Task 7.3: Verify security (no secrets in config, OAuth only in openconnector, etc.)

- **spec_ref**: proposal.md, design.md
- **files**: security audit
- **acceptance_criteria**:
  - GIVEN the ProductivityProviderConfig WHEN inspected THEN no plaintext credentials (passwords, API keys) are stored (use auth_profile_ref only).
  - GIVEN a sibling app WHEN scanned THEN no direct imports of Graph SDK or Workspace SDK exist (all calls route through the adapter).
  - GIVEN OAuth client credentials WHEN checked THEN they live only in openconnector source records, never in sibling apps.
  - GIVEN raw_request escape hatch WHEN invoked THEN it still applies rate-limiting and reliability policies; no unauthenticated raw requests are allowed.
- [ ] Implement
- [ ] Test (code scan; verify no secrets in config; verify sibling app imports)

## Verification

- [ ] All Section 1-7 tasks checked off
- [ ] All PHPUnit tests pass (`composer test`)
- [ ] `npm run check:manifest` exits 0
- [ ] Browser smoke test confirms adapter appears in integration-registry admin UI
- [ ] CallLog instrumentation verified (every operation logged with full details)
- [ ] Per-tenant isolation verified (separate tokens, quotas, logs)
- [ ] Parity tests pass (Graph and Workspace return same canonical shapes)
- [ ] Security audit passes (no secrets in config, OAuth in openconnector only)
- [ ] Documentation updated (or scheduled for v1.1)

## Post-Implementation Follow-Ups (Tracked Separately)

- [ ] Confirm OR's per-user credential surface is production-ready (feature-gate delegated permission if not).
- [ ] Monitor deprecation counter for raw_request usage; extend canonical surface based on real usage patterns.
- [ ] Evaluate Graph $batch endpoint adoption for bulk operation latency optimization (v1.1).
- [ ] Evaluate Teams + Meet messaging, presence, contacts, notebooks, tasks for v2 scope.
