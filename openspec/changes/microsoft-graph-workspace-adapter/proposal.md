# Proposal: microsoft-graph-workspace-adapter

`kind: adapter` — unified provider-abstraction bridge to Microsoft 365 (Graph) and Google Workspace, exposing canonical operations for identity, calendar, mail, and file integration with consistent error handling, pagination, and throttle semantics across both vendors.

## Summary

Introduce a **unified productivity-platform adapter** that normalises the most common Microsoft 365 (Microsoft Graph) and Google Workspace operations behind a single canonical API surface. Instead of every integration project implementing both Graph AND Workspace clients separately (with per-vendor error handling, pagination quirks, OData vs query-string syntax, throttle recovery strategies), a workflow designer in pipelinq or decidesk picks "send email" or "create calendar event" once, and the adapter routes to the correct vendor implementation based on the configured tenant (Microsoft 365 or Google Workspace).

The adapter exposes six canonical operations in v1:

- **list_users** — enumerate users in a tenant (with paging normalisation)
- **upsert_event** — create or update a calendar event (with idempotency via external_id)
- **send_mail** — send mail with attachments (large-file resumable upload support)
- **list_mail** — sync inbox changes (delta/sync-token support for incremental pulls)
- **list_files** — enumerate drive contents (OneDrive or Google Drive)
- **download_file** — stream file bytes (Range-request support for partial downloads)

A **raw-passthrough escape hatch** (`raw_request`) lets consumers issue provider-specific operations when the canonical surface is incomplete, with deprecation tracking to guide future canonical extensions.

Authentication supports **application permission** (admin consent at tenant level, e.g. "create events as a service") and **delegated permission** (per-user OAuth2 authorization_code with PKCE, e.g. "user grants their mailbox to the integration"). Per-tenant config is captured once at provider-configuration time; multi-tenant deployments get clean isolation: each customer's tokens and rate-limit counters live under their own provider-config key.

The spec normalises per-provider quirks (Graph delta queries vs Google sync tokens, OData filter syntax, $batch vs batch HTTP bundling, 429-with-Retry-After vs quotaExceeded quotas) behind the unified interface. The result is portable integrations: a municipal workflow built on Microsoft 365 works without rework on a Google Workspace tenant (or vice versa), and procurement teams can satisfy "must support M365 AND/OR Workspace" tender requirements without code duplication.

## Motivation

Today, Dutch municipalities (≈80% on Microsoft 365) and schools (many on Google Workspace) each need mail, calendar, and file integration with their case management and notification pipelines. Each integration project handcrafts both Graph and Workspace clients — separate error recovery loops, separate pagination handlers, separate attachment upload strategies, separate token-refresh timers. Per-vendor quirks are re-learned per project and re-implemented per project.

When a municipality migrates from M365 to Workspace (or vice versa), all downstream integrations break and must be rewritten. Procurement teams routinely tender "must integrate with Microsoft 365 AND/OR Google Workspace" — today that means every vendor adds both implementations in-house or doesn't bid.

This adapter closes that gap. The canonical surface is authored once; per-vendor implementations are isolated in pluggable Provider classes; downstream consumers (decidesk for notification mails, pipelinq for appointment scheduling, opencatalogi for identity, docudesk for document workflows) pick operations by name and are routed transparently to the correct vendor.

As the business adds Teams/Meet messaging, presence, contacts, notebooks (OneNote/Keep), and task (Planner/Tasks) operations in v2, the canonical surface extends without rework in the consuming apps — they consume the new operations by the same unified interface.

## Affected Projects

- [x] **openconnector** — primary: adds the microsoft-graph-workspace adapter implementation under `lib/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspace.php`, a manifest entry per ADR-024, one DI tag, and seed source configurations for dev/test. Registers via the existing integration-registry contract per ADR-019 (inherited from the `saas-productivity-connectors` category spec).
- [ ] **openregister** — no source changes; this adapter consumes the existing OR abstractions (Source registry, Mapping, ScheduledWorkflow, CloudEvent dispatcher, audit-trail-immutable, RBAC per ADR-022). Per-user OAuth token storage (delegated permission) relies on OR's per-user credential surface — if missing, a follow-up OR issue is filed.
- [ ] **decidesk** — no source changes; uses the adapter to send notification mails about case decisions. Consumes the `send_mail` operation through the integration-registry slot.
- [ ] **pipelinq** — no source changes; uses the adapter for appointment scheduling (create/update event + find availability). Consumes `upsert_event` and `find_availability` operations.
- [ ] **docudesk** — no source changes; attachment bytes pass through the adapter but persist on the Conduction side via docudesk per ADR-022. The file POST endpoint is sufficient; no changes required.
- [ ] **opencatalogi** — no source changes; uses `list_users` to back the People tab when a tenant uses Graph/Workspace as the identity source.
- [ ] **mydash** — no source changes; visualises adapter usage, quota consumption, and error rates per tenant.

## Scope

### In Scope

- **One adapter implementation**: `lib/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspace.php` implementing `IntegrationProvider` per ADR-019, with nested Provider classes for Graph and Workspace (composition, not inheritance).
- **Six canonical operations**: list_users, upsert_event, send_mail, list_mail, list_files, download_file, plus find_availability for scheduling use cases.
- **Raw-passthrough escape hatch**: `raw_request(provider_config, method, path, body, headers)` for operations the canonical surface doesn't cover yet, with deprecation counters.
- **Manifest entry**: `src/manifest.json` with all required fields per REQ-SPC-002 (category, subCategory, authModes, capabilities, rateLimits, pollingMode, schemaDiscovery).
- **Two Provider classes**: one for Graph (using `microsoft/graph-sdk` + `microsoft/graph-beta-sdk`), one for Workspace (using `google/apiclient` with Admin SDK, Gmail, Calendar, Drive, Directory).
- **Per-tenant configuration**: ProductivityProviderConfig seeds for dev/test environments (one Graph tenant, one Workspace customer).
- **Webhook subscription support** (REQ-012): Graph subscriptions and Google push channels, with openregister-based renewal tracking.
- **Webhook → CloudEvent normalisation** (REQ-SPC-005): inbound change notifications normalised to CloudEvent schema and dispatched via OR dispatcher.
- **Delta/sync-token tracking** (REQ-005): DeltaCursor stored in Redis for incremental mail syncs, with cursor management per (config, resource_type, user).
- **Retry + throttle resilience** (REQ-011): honours Graph Retry-After and Google quotaExceeded hints, respects ipaas-reliability retry policy, surfaces remaining quota via `X-OC-Quota-Remaining` headers.
- **Structured logging + CallLog**: every adapter invocation logged to `CallLog` with tenant tagging, errors, latencies, and quota consumption.

### Out of Scope

- **Teams + Meet messaging** — deferred to v2 (`add-microsoft-teams-google-meet-adapter` follow-up).
- **Presence + free/busy availability** — `find_availability` for calendar scheduling is in scope; full presence reading is v2.
- **Contacts / address book** — v2.
- **Notebooks (OneNote / Keep)** — v2.
- **Tasks (Planner / Tasks)** — v2.
- **Migration tools** for existing Graph or Workspace clients in sibling apps — each app owns its own migration story if one exists.
- **Frontend UI for provider configuration** — per ADR-019, the integration-registry admin UI (openregister-driven) already surfaces adapter configuration; no per-adapter Vue components land here.
- **Per-vendor docs/tutorials** — user-facing journeydoc pages (e.g. "How to connect your M365 tenant") land with the per-adapter implementation, not here.

## Approach

Implement the adapter in a single change that ships all six canonical operations and both provider implementations (Graph + Workspace) together. This achieves:

1. **Unified surface from day one** — consuming apps get a consistent API immediately; no halfway state where some operations are Graph-only or Workspace-only.
2. **Symmetry testing** — every canonical operation runs through both provider codepaths; parity failures surface in PR review.
3. **Clean Git history** — one coherent change record with all operations' rationale, rather than six separate follow-ups that each add one operation.
4. **De-risked rollback** — if the adapter surfaces a critical bug, a single revert removes all operations at once (though in practice, the adapter is consumed incrementally by downstream apps, so operational rollback is app-by-app).

### Execution

1. **Implement the adapter class** implementing `IntegrationProvider`.
2. **Implement two Provider classes** (Graph + Workspace) with a common interface for the six canonical operations; both use Google/Microsoft SDKs (not home-grown HTTP).
3. **Define seed source records** in `lib/Settings/seeds/sources/` (one Microsoft tenant config, one Google customer config) — both in `lifecycleState: paused` so they don't auto-activate in test environments.
4. **Manifest entry** per ADR-024, listing all six operations as `capabilities` and both auth modes (oauth2 first per REQ-SPC-003, serviceAccountJwt for Graph application permission).
5. **CallLog instrumentation** for every operation (inputs, latency, errors, quota remaining).
6. **Tests**: unit tests for adapter contract, integration tests for both provider codepaths against mocked SDKs or sandbox tenants, browser smoke test in the dev container confirming the adapter appears in the integration-registry admin UI.

## New Dependencies

**Direct code dependencies**:
- `microsoft/graph-sdk` (v1.x, stable REST surface)
- `microsoft/graph-beta-sdk` (for operations ahead of v1.0, e.g. mail delta queries)
- `google/apiclient` (v2.x, includes Admin SDK, Gmail, Calendar, Drive APIs)
- `google/auth-library-php` (for domain-wide delegation JWT signing)

**Architectural dependencies (no new packages)**:
- `openconnector` auth-protocol-suite — OAuth2 flows, PKCE, token caching, refresh rotation
- `openconnector` ipaas-reliability — retry policy, circuit breaker, idempotency tracking
- `openregister` integration-registry (ADR-019) — `IntegrationProvider` contract
- `openregister` per-user credential surface — for delegated permission token storage
- `openregister` ScheduledWorkflow (ADR-031) — for scheduled mail/file syncs
- `openregister` CloudEvent dispatcher (ADR-022) — for webhook normalization
- `openconnector` CallLog — audit trail

## Impact

- `lib/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspace.php` — new, ≈800 LOC
- `lib/Service/Adapter/Saas/Provider/GraphProvider.php` — new, ≈500 LOC
- `lib/Service/Adapter/Saas/Provider/WorkspaceProvider.php` — new, ≈500 LOC
- `src/manifest.json` — additive `connectors[]` entry (Microsoft Graph + Google Workspace, shared id `microsoft-graph-google-workspace`)
- `lib/AppInfo/Application.php` — one DI tag line
- `lib/Settings/seeds/sources/microsoft-graph.json` — new seed source (paused)
- `lib/Settings/seeds/sources/google-workspace.json` — new seed source (paused)
- `tests/Unit/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspaceAdapterTest.php` — new unit test
- `tests/Integration/Adapter/Saas/MicrosoftGraphGoogleWorkspaceTest.php` — new integration test

## Cross-Project Dependencies

- **openregister** — stable integration-registry contract (ADR-019), per-user OAuth token surface (if needed for delegated permission). If the surface is missing, a follow-up OR issue is filed and the delegated permission scenario is omitted from the initial release.
- **decidesk, pipelinq, opencatalogi, mydash** — no changes. They consume the adapter by integration-registry slot slug (`microsoft-graph-google-workspace`). The adapter's operation names and canonical response shapes are the integration contract.

## Risks

### Risk 1: OAuth2 per-user token storage in OR may not be production-ready

**Severity**: Medium
**Mitigation**: The delegated permission scenario (per-user OAuth) is feature-gated per REQ-009. If OR's per-user credential surface is not available at implementation time, the initial release ships with application permission only (admin consent for service integrations like "send mail as a shared account"). Delegated permission is deferred to a v1.1 release once OR ships the per-user surface. Consuming apps handle the absence by displaying a "connect your account" prompt that routes through openconnector's OAuth flow and populates the per-user token.

### Risk 2: Provider SDK incompatibilities (Microsoft.Graph.Beta vs v1.0, Google API versioning)

**Severity**: Low
**Mitigation**: The adapter uses the stable v1.0 API surface for Graph (with Beta only for operations that have no v1.0 equivalent, e.g. mail delta queries). Google uses the stable Admin SDK + Gmail v1 + Calendar v3 + Drive v3. Both SDKs are actively maintained and widely deployed. Integration tests run against both stable surfaces; breaking changes in either SDK are caught in CI.

### Risk 3: Rate-limit quotas and throttle recovery may vary significantly between Graph and Workspace

**Severity**: Medium
**Mitigation**: REQ-011 defines the canonical throttle response (429 with Retry-After for both vendors, or synthetic Retry-After for Google). The adapter respects both vendors' hints and applies the ipaas-reliability retry policy uniformly. If a vendor's throttle semantics are genuinely incompatible with the others, the adapter logs and surfaces remaining quota via headers; consuming apps can decide whether to backoff further. This is a graceful degradation, not a failure.

### Risk 4: Webhook subscription lifecycle (3-day expiry for Graph, no explicit expiry for Google) complicates renewal

**Severity**: Low
**Mitigation**: REQ-012 defines renewal 12h before expiry. For Graph, this is straightforward (3-day window, renew at 2.5 days). For Google, which provides no explicit expiry, the adapter enforces a canonical expiry (e.g. 30 days) in the openregister subscription record and renews on the same schedule. If a webhook goes stale (provider doesn't send events), the consumer retries the subscription creation; idempotency is achieved via per-(config, user, resource, callback) uniqueness in the subscription record.

### Risk 5: File download streaming may buffer large files in memory if not implemented carefully

**Severity**: Low
**Mitigation**: REQ-007 specifies streaming without full in-memory buffering. The provider implementations use PHP streams (`fopen`, `fread` in chunks) and pass through the HTTP response body directly. Tests verify that a 200 MB download does not exhaust memory (test with a mock stream, not a real large file).

## Rollback Strategy

Standard per-adapter pattern:

1. Revert the commit: `git revert <sha>`.
2. Remove `src/manifest.json` `connectors[]` entry for `microsoft-graph-google-workspace`.
3. Remove the DI tag from `lib/AppInfo/Application.php`.
4. Delete `lib/Service/Adapter/Saas/MicrosoftGraphGoogleWorkspace.php` and provider classes.
5. Clean up seed sources in `lib/Settings/seeds/sources/`.

Consuming apps (decidesk, pipelinq, opencatalogi) fail gracefully when the adapter is unavailable: they surface the integration error in their own logs and UI. No cascading failures.

## Open Questions

1. **Per-user OAuth token storage in OR** — confirm with OR team whether the per-user credential surface (needed for delegated permission) is production-ready, or whether a follow-up OR change is required. If deferred, the initial release ships application permission only.
2. **Google service account subject impersonation constraints** — Google's domain-wide delegation allows a service account to impersonate ANY user in the customer realm. Should the adapter enforce a whitelist of allowed subjects (e.g. only a designated service account principal), or trust the openconnector operator to configure it safely? Recommendation: openregister enforces the whitelist via a per-source configuration field, not the adapter.
3. **Graph $batch endpoint for bulk operations** — Graph offers a `$batch` endpoint that can bundle up to 20 requests in one HTTP call, reducing latency for list_users + upsert_event chains. Should the adapter use it for parallel operations, or keep each operation as a single API call? Recommendation: start with single calls (simpler error handling), add batching in a v1.1 optimization pass if latency data warrants it.
4. **Conflict resolution for upsert_event** — when upserting a calendar event (REQ-003), if the external_id points to an existing event but the current request's fields differ, should the adapter merge fields or replace them outright? Recommendation: RFC 6902 JSON Merge Patch semantics — merge fields, so the request can update a subset of fields without re-specifying the whole event.

## Success Criteria

- ✅ All six canonical operations (list_users, upsert_event, send_mail, list_mail, list_files, download_file) work end-to-end for both Microsoft Graph and Google Workspace.
- ✅ Canonical response shapes match the ProductivityUser, ProductivityEvent, ProductivityMail, ProductivityFile models defined in the spec.
- ✅ Per-tenant config isolation verified: separate tokens, separate rate-limit counters, separate CallLog tags.
- ✅ Application permission and delegated permission scenarios both work (or delegated permission is feature-gated if OR support is missing).
- ✅ Webhook subscriptions created and renewed correctly; webhook events normalised to CloudEvent.
- ✅ Integration-registry admin UI lists the adapter with correct capabilities + auth modes.
- ✅ Consuming apps (decidesk for mail, pipelinq for calendar) can invoke operations and receive normalised responses.
- ✅ All tests pass; no regressions in sibling apps.
