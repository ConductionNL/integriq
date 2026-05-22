# Design — Microsoft Graph + Google Workspace Adapter

## Context

Today, integration projects needing Microsoft 365 or Google Workspace integration hand-craft both Graph and Workspace HTTP clients, each with its own error recovery, pagination handling, authentication flows, and rate-limit tracking. Per-vendor quirks — Graph delta queries, Google sync tokens, OData filter syntax, $batch bundling, 429 vs quotaExceeded throttle signals — are re-implemented per project. When a municipality migrates from M365 to Workspace (or vice versa), all downstream integrations break and must be rewritten.

This adapter normalises the most common operations (identity, calendar, mail, files) behind a single canonical API surface. The provider routing is transparent to consumers: a workflow designer picks "send mail" and the adapter routes to the correct vendor implementation based on the configured tenant. Per-vendor quirks are abstracted behind the unified interface.

The adapter follows the `saas-productivity-connectors` category spec (from `add-openconnector-connector-categories`), inheriting the integration-registry contract per ADR-019, manifest shape per ADR-024, OAuth-first auth mode per REQ-SPC-003, CloudEvent normalization per REQ-SPC-005, and delegated permission support (if OR's per-user credential surface is available).

## Goals

- **Single canonical surface** for the six most common operations (list_users, upsert_event, send_mail, list_mail, list_files, download_file), normalizing per-vendor API quirks (pagination, filtering, throttle recovery, auth modes).
- **Portable integrations** — downstream consumers (decidesk, pipelinq, opencatalogi) invoke operations by name; the adapter routes transparently based on the configured tenant's provider. A workflow built on M365 works without code changes on a Workspace tenant.
- **Provider symmetry** — both Graph and Workspace implementations ship together for every canonical operation. No halfway state where an operation is Graph-only or Workspace-only.
- **Clean token + quota isolation** — each tenant gets its own credential cache, rate-limit counters, and CallLog entries. Multi-tenant deployments are secure by construction.
- **Extensibility** — a raw-passthrough escape hatch allows consumers to use provider-specific features today (Graph extension properties, Google appProperties) while the canonical surface catches up. Deprecation counters guide future expansions.
- **Graceful degradation** — if a provider is down or quota-exceeded, the adapter surfaces the error clearly; consuming apps decide whether to backoff, notify the user, or escalate.

## Non-Goals

- Individual user onboarding flows for delegated permission — the adapter integrates with openconnector's OAuth machinery; the consuming app (e.g. a citizen self-service portal) owns the UI for "connect your mailbox."
- Teams + Meet messaging, presence, contacts, notebooks, tasks — these are v2 operations, deferred to follow-up changes.
- Migration of existing Graph or Workspace clients in sibling apps — each app owns its own modernization story.
- Frontend UI for provider configuration — the integration-registry admin UI (openregister-driven, per ADR-019) already surfaces adapter configuration.

## Decisions

### D1 — Provider composition, not inheritance; shared interface

The adapter uses two Provider classes (GraphProvider + WorkspaceProvider) implementing a common interface, composed together in the main adapter. This gives:

- **Clear separation** — each provider's SDK usage, error handling, and quirk workarounds are isolated.
- **Symmetry testing** — each operation runs through both provider codepaths in CI; parity failures surface early.
- **No super-class coupling** — if Graph's error semantics diverge from Workspace's, each provider handles its own quirks without fighting a shared base class.

```
MicrosoftGraphGoogleWorkspaceAdapter (implements IntegrationProvider)
  ├─ GraphProvider (Microsoft.Graph.SDK)
  │  ├─ listUsers()
  │  ├─ upsertEvent()
  │  ├─ sendMail()
  │  ├─ listMail()
  │  ├─ listFiles()
  │  └─ downloadFile()
  └─ WorkspaceProvider (google/apiclient)
     ├─ listUsers()
     ├─ upsertEvent()
     ├─ sendMail()
     ├─ listMail()
     ├─ listFiles()
     └─ downloadFile()
```

Router (dispatch by provider_config.provider):
```
when provider = "microsoft_graph" → use GraphProvider
when provider = "google_workspace" → use WorkspaceProvider
```

### D2 — Canonical models are minimal, not exhaustive

The ProductivityUser, ProductivityEvent, ProductivityMail, ProductivityFile models are the **intersection** of what both Graph and Workspace expose natively, plus a few universally useful fields (e.g., external_id for idempotency, etag for optimistic locking, download_url_ref for file streaming). They deliberately omit vendor-specific extensions (Graph extension properties, Google appProperties) to keep the surface narrow and portable. Consumers needing vendor-specific fields use the `raw_request` escape hatch.

Example: ProductivityUser includes `photo_url` (available from both Graph `/me/photo/$value` and Workspace `Drive` profile photos), but does NOT include Graph's `extensionProperties` or Workspace's `customSchemas`.

### D3 — Per-tenant config captures provider type, auth profile, rate-limit budget once

ProductivityProviderConfig lives in the openconnector Source registry as a standard source record:

```json
{
  "id": "uuid",
  "slug": "microsoft-365-tenant-acme",
  "name": "ACME Corp Microsoft 365",
  "provider": "microsoft_graph",
  "tenant_id": "00000000-0000-0000-0000-000000000000",
  "auth_profile_ref": "oauth2_cc",
  "default_user_principal": "noreply@acme.onmicrosoft.com",
  "reliability_profile_ref": "standard-retry",
  "rate_limit_profile_ref": "shared-bucket",
  "lifecycleState": "active"
}
```

Providers are selected at runtime by inspecting `source.provider`. Tokens, quotas, and logs are keyed by `(source.id, auth_profile_id, scopes)`, ensuring multi-tenant isolation.

### D4 — OAuth2 is the default auth mode; application permission is a secure second

Per REQ-SPC-003, the manifest entry lists `oauth2` first. For delegated permission (per-user OAuth), the adapter uses openconnector's existing OAuth2 authorization-code flow with PKCE; per-user tokens are stored in openregister's per-user credential surface.

For application permission (admin consent for service integrations), the adapter uses:
- **Graph**: client_credentials flow, tenant-wide tokens cached per (tenant_id, auth_profile_id, scopes).
- **Workspace**: domain-wide delegation via service-account JWT with `subject` impersonation of a specific user principal.

Both flows are delegated to the auth-protocol-suite; the adapter receives tokens from a TokenService and uses them immediately.

### D5 — Delta / sync-token tracking in Redis for incremental mail syncs

REQ-005 (list_mail with delta/sync support) requires the adapter to remember the provider's native delta token (Graph `/messages/delta` cursor, Google `historyId`) across invocations. This is stored in Redis under a DeltaCursor key:

```
key: productivity:delta:{provider_config_id}:{resource_type}:{user_id}
value: { provider_delta_token: "...", last_position: "2024-05-22T12:34:56Z" }
```

On each `list_mail()` call, the adapter checks Redis for the cursor; if found, it's passed to the provider to fetch only changes since the last call. The response includes the new cursor, which is written back to Redis for the next invocation.

### D6 — Webhook subscriptions tracked in openregister, renewed 12h before expiry

REQ-012 (webhook subscription for inbound change notifications) requires the adapter to:

1. Create a Graph subscription (`POST /subscriptions`) or Google push channel (`gmail.users.watch`).
2. Track the subscription in openregister with an `expirationDateTime` (Graph: explicit, up to 3 days; Google: synthetic, e.g., 30 days).
3. Auto-renew 12h before expiry using a background job (OR ScheduledWorkflow per ADR-031).
4. Dispatch inbound webhook events to the consumer's callback endpoint, normalised to CloudEvent schema.

Idempotency is achieved via per-(provider_config, resource_type, user, callback_endpoint) uniqueness in the subscription record.

### D7 — Streaming file downloads without full in-memory buffering

REQ-007 specifies that file downloads are streamed directly to the consumer (e.g., for a 200 MB file, the adapter does NOT buffer it in memory). Both Graph and Workspace provide streaming:

- **Graph**: `GET /users/{id}/drive/items/{item_id}/content` returns the raw bytes; the HTTP client streams the response body.
- **Workspace**: `GET https://www.googleapis.com/drive/v3/files/{file_id}?alt=media` returns raw bytes; similarly streamed.

The adapter opens a stream handle and returns it to the caller (e.g., a controller that pipes it to the response body). Range requests are honored by passing `Range` headers to the provider's API.

### D8 — Throttle + quota awareness with circuit breaker fallback

REQ-011 requires the adapter to respect both vendors' throttle signals and apply ipaas-reliability policies:

- **Graph**: HTTP 429 with `Retry-After` header (seconds).
- **Google**: HTTP 403 with `userRateLimitExceeded` or `quotaExceeded` in the error; no standard Retry-After header, so the adapter applies exponential backoff (first retry: 1s, then 2s, 4s, 8s, ..., cap at 60s).

Both responses are translated into the canonical ipaas-reliability error model; the retry policy respects the Retry-After hint and surfaces remaining quota via `X-OC-Quota-Remaining` headers when known.

If throttling is sustained (e.g., 5+ consecutive 429s), the per-tenant circuit breaker (per ADR-... in ipaas-reliability) opens and all subsequent calls fail fast, alerting the operator to the quota exhaustion.

### D9 — CallLog instrumentation captures inputs, latency, errors, quota per invocation

Every call to a canonical operation is logged to openconnector's CallLog:

```
{
  "id": "uuid",
  "source_id": "...",
  "adapter_id": "microsoft-graph-google-workspace",
  "operation": "send_mail",
  "status": "success",
  "request_body": { "to": "...", "subject": "...", "attachments_count": 2 },
  "response_body": { "message_id": "..." },
  "latency_ms": 450,
  "quota_remaining": 1250,
  "error": null,
  "created_at": "2024-05-22T12:34:56Z"
}
```

Errors are logged with full exception details, including provider-native error codes (Graph error `ActivityLimitReached`, Google error `dailyLimitExceeded`) for debugging. These logs feed into mydash for usage visualization and alerting.

## Seed Data

### Microsoft Graph Tenant (Development)

```json
{
  "id": "graph-dev-tenant",
  "slug": "microsoft-365-localhost",
  "name": "Microsoft 365 Localhost Dev Tenant",
  "provider": "microsoft_graph",
  "tenant_id": "00000000-0000-0000-0000-000000000001",
  "auth_profile_ref": "oauth2_cc",
  "default_user_principal": "appservice@localhost.onmicrosoft.com",
  "reliability_profile_ref": "standard-retry",
  "rate_limit_profile_ref": "shared-bucket-1k",
  "lifecycleState": "paused",
  "_meta": {
    "seed_provider": "microsoft-graph-workspace-adapter",
    "seed_version": "1.0.0",
    "notes": "Development seed; paused on initial install. Enable in the integration-registry admin UI if you have a real Microsoft 365 tenant."
  }
}
```

### Google Workspace Customer (Development)

```json
{
  "id": "workspace-dev-customer",
  "slug": "google-workspace-localhost",
  "name": "Google Workspace Localhost Dev Customer",
  "provider": "google_workspace",
  "customer_id": "C0000000000",
  "service_account_subject": "service-account@localhost.iam.gserviceaccount.com",
  "auth_profile_ref": "oauth2_cc",
  "default_user_principal": "noreply@localhost.com",
  "reliability_profile_ref": "standard-retry",
  "rate_limit_profile_ref": "shared-bucket-1k",
  "lifecycleState": "paused",
  "_meta": {
    "seed_provider": "microsoft-graph-workspace-adapter",
    "seed_version": "1.0.0",
    "notes": "Development seed; paused on initial install. Enable in the integration-registry admin UI if you have a real Google Workspace customer."
  }
}
```

Both are paused so they don't interfere with a fresh install. A developer who wants to test against a real tenant enables the relevant seed source in the UI and populates the real credentials.

## Reuse Analysis

| Abstraction | Origin | Reused by this adapter? | Notes |
|---|---|---|---|
| `IntegrationProvider` contract | ADR-019 (openregister) | ✅ Yes — main adapter class implements it | Standard integration-registry registration |
| `Source` registry | openconnector | ✅ Yes — credential storage | ProductivityProviderConfig lives here as a source record |
| `Mapping` abstraction | openconnector | ⏸️ Deferred — not used in v1 | v2 mail sync will use Mapping to normalize inbox schema |
| `CallLog` | openconnector | ✅ Yes — every operation logged | Captures inputs, latency, errors, quota, provider-native error codes |
| `ScheduledWorkflow` | openregister (ADR-031) | ✅ Yes — webhook subscription renewal | Background job to auto-renew Graph subscriptions 12h before expiry |
| `CloudEvent` dispatcher | openregister (ADR-022) | ✅ Yes — webhook event normalization | Inbound webhooks translated to CloudEvent and routed through OR dispatcher |
| `auth-protocol-suite` | openconnector | ✅ Yes — OAuth2 + PKCE, token caching | Delegated (per-user) and application (tenant-wide) permission flows |
| `ipaas-reliability` | openconnector | ✅ Yes — retry policy, circuit breaker | Throttle + quota-exceeded resilience; Retry-After respect |
| `prometheus-metrics` | openconnector | ✅ Yes (extension) | `openconnector_adapter_invocations_total` counter per adapter + operation |
| Per-user credential surface | openregister | ⏸️ Pending OR availability | Delegated permission tokens stored per-user; feature-gated if OR support is missing |

## Migration Plan

**Legacy Graph / Workspace clients**: Today, decidesk, pipelinq, and opencatalogi may have inline Graph or Workspace clients (if they do). This adapter does NOT require them to migrate immediately. The migration path is:

1. The adapter ships in openconnector with all six operations.
2. Each consuming app (decidesk, pipelinq, opencatalogi) incrementally replaces its inline client with adapter calls. This can happen in parallel for different operations (e.g., decidesk migrates `send_mail` first, then `list_mail` later).
3. Once all consuming apps have migrated, the inline clients are removed in a separate cleanup change.

The adapter is additive; it does not break existing inline clients. Migration is opportunistic, driven by consuming-app development cycles, not forced by this change.

## Standards + Compliance

- **Microsoft Graph REST API v1.0** — OData v4 query semantics, JSON responses.
- **Microsoft Graph Beta** — for operations not yet in v1.0 (e.g., mail delta queries).
- **Google Workspace Admin SDK Directory API v1** — users and groups.
- **Gmail API v1** — mail operations.
- **Google Calendar API v3** — calendar operations.
- **Google Drive API v3** — file operations.
- **RFC 5322** — Internet Message Format (MIME) for mail composition.
- **RFC 7233** — HTTP Range Requests for partial file downloads.
- **RFC 3986** — URI syntax (for provider-native API paths in raw_request).
- **OAuth 2.0 Authorization Code Grant with PKCE** — per AUTH-PROTOCOL-SUITE.
- **Microsoft identity platform v2.0** — token exchange, consent flows.
- **Google Cloud Identity & Domain-Wide Delegation** — service-account token exchange + subject impersonation.
- **ISO 8601** — date/time serialization in canonical models.
- **CloudEvents v1.0** — webhook normalization.
- **AVG / GDPR** — personal data minimization (photo_url is anonymized unless user is flagged for high-sensitivity contexts).

## Observability

**Logging**:
- Every call logged to CallLog with operation, status, latency, quota, errors.
- Provider-native error codes (Graph `error.code`, Google `error.code`) captured for debugging.
- Full request/response bodies (sanitized: auth headers redacted, large payloads truncated) logged at DEBUG level.

**Metrics**:
- `openconnector_adapter_invocations_total` counter incremented per (adapter_id, operation, status, provider) labels.
- `openconnector_adapter_latency_seconds` histogram tracking p50/p95/p99 latencies per operation.
- `openconnector_adapter_quota_remaining` gauge tracking remaining quota per tenant.

**Alerting**:
- Circuit breaker opens (sustained throttle) → alert operator to review quota + rate-limit budget.
- Per-tenant token refresh failure → alert operator to re-authorize the credential.

## Risk Mitigation Summary

| Risk | Mitigation |
|---|---|
| OAuth2 per-user token storage missing in OR | Feature-gate delegated permission; start with application permission only. |
| Provider SDK incompatibilities | Use stable API surfaces (Graph v1.0, Workspace stable APIs); integration tests run both codepaths. |
| Rate-limit quotas differ significantly | Canonical throttle response + ipaas-reliability policy; consumers see consistent error shape. |
| Webhook subscription lifecycle quirks | 12h pre-renewal rule + idempotency via per-(config, resource, user, callback) uniqueness. |
| Large file downloads buffering in memory | Streaming implementation + tests verify memory overhead is O(chunk_size), not O(file_size). |
