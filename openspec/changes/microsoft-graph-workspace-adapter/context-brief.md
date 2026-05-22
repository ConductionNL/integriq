---
status: draft
spec: microsoft-graph-workspace-adapter
app: openconnector
owner: openconnector-core
depends_on:
  - openconnector-base
  - auth-protocol-suite
---

# Microsoft Graph + Google Workspace Adapter

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Adapters > Adapter-catalogus (Productiviteit) / Adapters

**Rationale:** Adapter type  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Most Dutch and European organisations run their productivity stack on either Microsoft 365 (via Microsoft Graph) or Google Workspace (via the Workspace Admin SDK and individual product APIs — Gmail, Calendar, Drive, Directory, Meet). Integration projects routinely need the same handful of operations against either platform: list users in a tenant, create or update a calendar event, send an email, list inbox items, list files in a drive, and download a file. Today these flows are implemented twice — once per provider — with subtly different error handling, pagination semantics, throttling behaviour, and authentication models. End users then need different openconnector configurations and different downstream workflows depending on which provider their tenant uses, and adapter maintenance cost doubles for every new operation added.

This spec defines a single unified provider-abstraction adapter that exposes a consistent API surface for the most common productivity operations and dispatches to the correct provider implementation (Microsoft Graph or Google Workspace) based on the configured tenant. The abstraction covers identity (users, groups), calendar (events, attendees, availability), mail (send, list, search, attachments), and files (drive listing, metadata, download, upload). Per-provider quirks — Graph delta queries vs Google sync tokens, OData filter syntax vs Google query syntax, Graph $batch endpoints vs Google batch HTTP bundling, Graph's 429-with-Retry-After throttle vs Google's exponential-backoff hint headers — are normalised behind the unified interface. The result is that a workflow designer building a "send confirmation email" step in pipelinq or decidesk picks the operation once and the platform routes correctly regardless of whether the tenant is on Microsoft 365 or Google Workspace.

Authentication uses two flavours, both delegated to the auth-protocol-suite: **application permission** (admin consent at tenant level, used for unattended service integrations like "create calendar events on behalf of any user", "send mail as a shared service account", or "sync the company directory nightly") and **delegated permission** (per-user OAuth2 authorization_code with PKCE, used when an end user grants their personal mailbox or calendar to the integration — common for personal-productivity workflows, mailbox monitoring, and per-user document signing). Per-tenant config (`tenant_id` for Microsoft, `customer_id` + `service_account_subject` for Google domain-wide delegation) is captured once at provider-configuration time and reused across all consumers within that tenant. Multi-tenant deployments (a SaaS product serving many customer organisations) get clean isolation: each customer's tokens, quotas, and rate-limit counters live under their own provider-config key.

The spec covers six canonical operations in v1: list users, create/update event, send mail, list mail, list files, download file. These were chosen because they account for the vast majority of integration requests across Conduction's customer base: appointment scheduling for case handling, sending citizen notifications, fetching documents for processing, and synchronising employee directories. Future versions will extend to Teams/Meet messaging, presence, contacts, notebook (OneNote/Keep), and Tasks/Planner operations. A raw-passthrough escape hatch ensures consumers needing provider-specific features today are never blocked by the canonical surface being incomplete.

## Data Model

- **ProductivityProviderConfig** (openregister schema): id, slug, provider (microsoft_graph | google_workspace), tenant_id (Microsoft) | customer_id (Google), auth_profile_ref, default_user_principal (used for application-permission calls when a "from" is required), reliability_profile_ref, rate_limit_profile_ref, created, updated.
- **ProductivityUser** (canonical model returned by `list_users`): id, provider_user_id, principal_name, email, display_name, given_name, family_name, job_title, department, manager_id, enabled, photo_url.
- **ProductivityEvent** (canonical model for calendar): id, provider_event_id, calendar_id, subject, body_html, start_at (ISO8601 with tz), end_at, location, attendees[{email, response}], organiser_email, online_meeting_url, recurrence_rrule, etag.
- **ProductivityMail** (canonical model): id, provider_message_id, conversation_id, from_email, to[], cc[], bcc[], subject, body_html, body_text, attachments[{id, name, content_type, size}], received_at, has_attachments, importance, is_read.
- **ProductivityFile** (canonical model): id, provider_file_id, name, mime_type, size_bytes, parent_id, path, web_url, download_url_ref (resolvable token), created_at, modified_at, modified_by_email, version.
- **DeltaCursor** (Redis): per (provider_config_id, resource_type, user) — provider-native delta/sync token plus our normalised position pointer.

## Requirements

### REQ-001: Provider selection drives implementation routing

- **GIVEN** a ProductivityProviderConfig with `provider = microsoft_graph` and another with `provider = google_workspace`
- **WHEN** any canonical operation is invoked against either config
- **THEN** openconnector routes to the provider-specific implementation, translates inputs into the provider's native request shape, executes via the configured auth_profile, and translates the response into the canonical model defined in this spec; consumers never see provider-native fields unless they explicitly request the raw passthrough mode

### REQ-002: List users with paging normalisation

- **GIVEN** a tenant with thousands of users
- **WHEN** `list_users(filter?, page_size=100)` is invoked
- **THEN** for Microsoft Graph, openconnector calls `GET /v1.0/users` with `$top=100` and follows `@odata.nextLink` cursors; for Google Workspace, it calls `directory.users.list` with `maxResults=100` and follows `nextPageToken`; both return the same canonical `{items: ProductivityUser[], next_cursor: opaque_string?}` shape, and `next_cursor` can be passed back as `cursor` to fetch the next page

### REQ-003: Create or update calendar event with idempotency

- **GIVEN** a request to create an event on a user's calendar with a client-supplied `external_id`
- **WHEN** `upsert_event(user_email, event, external_id)` is invoked
- **THEN** for Microsoft Graph, openconnector sets `singleValueExtendedProperties` with the external_id and queries on it before deciding insert-vs-update; for Google, it uses an `extendedProperties.private.external_id` annotation similarly; on update, the existing event is fetched, the canonical fields are merged, and a PATCH/UPDATE is issued; the response is mapped back to the canonical ProductivityEvent

### REQ-004: Send mail with attachments

- **GIVEN** a request to send mail from a user with two attachments totalling 6 MB
- **WHEN** `send_mail(from, to, cc, subject, body_html, attachments[])` is invoked
- **THEN** for Microsoft Graph, openconnector POSTs `users/{from}/sendMail` with MIME-encoded message including base64-encoded attachments (using `largeAttachments` upload session if any single attachment > 3 MB); for Google, it POSTs `gmail.users.messages.send` with the full RFC 5322 MIME message (using resumable upload for messages > 5 MB); both return `{message_id, sent_at}` in canonical form

### REQ-005: List mail with delta/sync support

- **GIVEN** an integration that periodically syncs inbox changes
- **WHEN** `list_mail(user_email, folder='inbox', delta_cursor?)` is invoked
- **THEN** for Microsoft Graph, openconnector uses the `/messages/delta` endpoint; for Google, it uses `gmail.users.history.list` with the stored `historyId`; both return `{items: ProductivityMail[], next_cursor, delta_cursor}` where `delta_cursor` is the token to pass on the next invocation to receive only changes since the last call

### REQ-006: List files in a drive

- **GIVEN** a user's OneDrive or Google Drive
- **WHEN** `list_files(user_email, folder_id?, page_size=100)` is invoked
- **THEN** for Microsoft Graph, openconnector calls `users/{user}/drive/root/children` (or `items/{folder_id}/children`) and follows `@odata.nextLink`; for Google, it calls `drive.files.list` with `q="'{folder_id}' in parents"` and follows `nextPageToken`; results are normalised into the canonical ProductivityFile shape with `path` reconstructed for Microsoft (Google does not expose paths natively, so `path` is best-effort built from parent chain)

### REQ-007: Download file with streaming

- **GIVEN** a 200 MB file in Drive
- **WHEN** `download_file(user_email, file_id)` is invoked
- **THEN** openconnector resolves the provider-specific download URL (Graph `/content` endpoint, Google `files.get?alt=media`), streams the response body directly to the caller (no full in-memory buffering), honours `Range` requests for partial downloads, and surfaces total bytes plus a stream handle; downloads count against tenant quotas tracked by the rate_limit_profile

### REQ-008: Per-tenant configuration and isolation

- **GIVEN** two ProductivityProviderConfigs targeting different Microsoft tenants
- **WHEN** parallel calls execute against both
- **THEN** openconnector ensures separate token caches keyed by `(tenant_id, auth_profile_id, scopes)`, separate rate-limit counters, and separate CallLog entries with tenant tagging; cross-tenant token leakage is impossible by construction (different cache keys, different KeyVault references)

### REQ-009: Delegated vs application permission selection

- **GIVEN** a ProductivityProviderConfig whose auth_profile is `oauth2_ac` (delegated) and another whose auth_profile is `oauth2_cc` (application)
- **WHEN** an operation requires `Mail.Send`
- **THEN** for delegated, openconnector requires a per-user authorization (cached refresh token) and acts on behalf of the authenticated user; for application, it uses the tenant-wide token but requires the operation to specify `on_behalf_of=<user_principal>` (Graph: `users/{user}/sendMail`; Google: domain-wide delegation `subject=<user>`); the adapter rejects operations that need user context when neither mode supplies it

### REQ-010: Provider-native passthrough escape hatch

- **GIVEN** a consumer needs a provider-specific feature not covered by the canonical surface (e.g. Microsoft Graph `extension properties` or Google `appProperties`)
- **WHEN** the consumer invokes `raw_request(provider_config, method, path, body, headers)`
- **THEN** openconnector authenticates with the configured auth_profile, applies rate-limiting and reliability policies, executes the raw request against the provider's API, and returns the raw response untouched; a deprecation counter increments to flag passthrough usage so the canonical surface can be extended over time

### REQ-011: Throttle and quota awareness

- **GIVEN** Microsoft Graph returns `429 Too Many Requests` with `Retry-After: 30` or Google returns `403` with `userRateLimitExceeded`
- **WHEN** the adapter receives the response
- **THEN** openconnector translates the provider-native throttle response into the canonical reliability layer (honours Retry-After for Microsoft; converts Google's quotaExceeded into a synthetic Retry-After of 60s if absent), respects the ipaas-reliability retry policy, and surfaces remaining quota as `X-OC-Quota-Remaining` headers when known; sustained throttling triggers the per-tenant circuit breaker

### REQ-012: Webhook subscription for inbound change notifications

- **GIVEN** a consumer wants near-real-time notifications of inbox changes for a user
- **WHEN** they invoke `subscribe(provider_config, user_email, resource='mail', callback_endpoint)`
- **THEN** openconnector creates a Microsoft Graph subscription (POST `/subscriptions` with notificationUrl, lifecycleNotificationUrl, expirationDateTime ≤ 3 days for mail) or a Google push channel (`gmail.users.watch` with topicName), tracks the subscription expiry in openregister, auto-renews 12h before expiry, and dispatches inbound notifications to the consumer's callback_endpoint with the canonical change payload

### REQ-013: Availability and free/busy lookup

- **GIVEN** a request to find a 30-minute meeting slot for 5 attendees next week
- **WHEN** `find_availability(attendees[], duration_minutes, window_start, window_end)` is invoked
- **THEN** for Microsoft Graph, openconnector POSTs `users/{organiser}/calendar/getSchedule` with all attendee emails and the time window; for Google, it calls `calendar.freebusy.query` with the same window; both return a canonical `{slots: [{start, end, attendee_status}]}` shape ranked by attendee fit, suitable for direct rendering in scheduling UIs

### REQ-014: Group + membership operations

- **GIVEN** an integration needs to manage tenant groups (security groups for M365, Google Groups for Workspace)
- **WHEN** `list_groups`, `create_group`, `add_member`, `remove_member` are invoked
- **THEN** openconnector dispatches to the provider's groups API (Graph `/groups` + `/members/$ref`; Google Cloud Identity Groups API + `members.insert`), maintains a canonical `ProductivityGroup{id, provider_group_id, name, description, email, members[]}` shape, and surfaces provider-specific group types (M365 unified groups, security-enabled groups; Google security groups, Google Groups for Business) through a `group_kind` enum

## Standards

- **Microsoft Graph v1.0** — REST API surface, OData v4 query semantics
- **Google Workspace Admin SDK Directory API v1**
- **Gmail API v1**, **Calendar API v3**, **Drive API v3**
- **RFC 5322** — Internet Message Format (MIME)
- **RFC 7233** — HTTP Range Requests
- **OAuth 2.0** + **OIDC** (delegated to auth-protocol-suite)
- **Microsoft identity platform v2.0** — tenant tokens, admin consent
- **Google Cloud Identity / Domain-Wide Delegation** — service account subject impersonation
- **ISO 8601** — date/time in canonical models
- **AVG / GDPR** — personal data minimisation in canonical user model

## Cross-app Integration

- **openconnector base** — HTTP transport, adapter framework, CallLog
- **auth-protocol-suite** — OAuth2 application + delegated flows, PKCE, token caching, refresh rotation
- **ipaas-reliability** — retry, circuit-breaker, idempotency, DLQ for productivity API calls
- **openregister** — ProductivityProviderConfig, canonical user/event/mail/file shapes registered as schemas
- **opentalk sidecar** — uses the adapter for Teams + Google Meet meeting creation and chat sync
- **docudesk** — uses the adapter to fetch documents from OneDrive/Drive for signing workflows
- **decidesk** — uses the adapter to send notification mails about case decisions
- **opencatalogi** — uses `list_users` to back the People tab when a tenant uses Graph/Workspace as the identity source
- **mydash** — visualises productivity-adapter usage, quota consumption, and error rates per tenant

## Target Users

- **Enterprise IT teams** integrating openconnector with corporate Microsoft 365 or Google Workspace tenants, typically as the messaging+calendaring backbone for business-process applications
- **Dutch municipalities** running Microsoft 365 (the majority — roughly 80% of gemeenten are on M365) who need calendar + mail + file integration with their case management and notification workflows; the unified surface means municipal workflows are portable to a future Google Workspace tenant without rework
- **Schools and educational institutions** on Google Workspace for Education who need the same integration surface for student/staff workflows
- **Service integrators** building cross-tenant SaaS products that need a single API surface working against either provider, with per-customer auth profiles, per-customer rate-limit budgets, and clean log separation
- **Workflow designers** in pipelinq or decidesk who want to drag a "send mail" or "create event" step into a workflow without knowing which productivity stack the tenant runs underneath
- **Procurement teams** evaluating openconnector against tender requirements covering "must integrate with Microsoft 365 AND/OR Google Workspace" — increasingly common boilerplate in 2025–2026 tenders
- **Citizen self-service application developers** who need a citizen to optionally connect their personal mailbox or calendar (delegated permissions) for appointment booking with the municipality
- **CIO offices** rolling out cross-tenant migrations (M365→Workspace or vice versa) who want the upstream change to be transparent to existing integrations
