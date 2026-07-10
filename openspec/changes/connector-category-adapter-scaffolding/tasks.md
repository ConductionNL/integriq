# Tasks — connector-category adapter scaffolding

## 1. Shared base

- [x] Create `lib/Service/Adapter/AbstractCategoryAdapterProvider.php` implementing
      the common `IntegrationProvider` contract: capability-vocabulary
      declaration, health-check surface, credential resolution via OR's
      credential broker. Extends OR's `AbstractIntegrationProvider`
      (`OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider`) and
      wraps `CredentialBrokerService::request()` (`OCA\OpenRegister\Service\Credential`)
      behind a `brokeredRequest()` helper that resolves a per-adapter
      `<id>_credential_id` app-config key — no adapter ever holds a secret.
- [x] Unit tests for the base class's capability-vocabulary + health-check
      contract using a fake adapter
      (`tests/Unit/Service/Adapter/AbstractCategoryAdapterProviderTest.php`, 9 tests,
      `FakeCategoryAdapter` fixture).

## 2. Endpoint / workspace connectors (REQ-EWC-001, REQ-EWC-002)

- [x] Create `lib/Service/Adapter/EndpointWorkspace/AzureVirtualDesktopAdapter.php`
      extending the shared base; implement session-enumeration + user-mapping +
      audit-event ingestion per REQ-EWC-002's fixed capability vocabulary. Calls
      the real Azure ARM `userSessions` REST endpoint (`api-version=2023-09-05`).
- [x] Registered as an `IntegrationProvider` in `lib/AppInfo/Application.php`
      (`registerIntegrationProviders()`).
- [x] Unit tests covering session-enumeration and audit-event ingestion
      (`tests/Unit/Service/Adapter/AzureVirtualDesktopAdapterTest.php`, 6 tests).

## 3. Document / CMS connectors (REQ-DCC-001)

- [x] Create `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php`
      extending the shared base; calls the real Microsoft Graph `drive` API
      (`/v1.0/sites/{siteId}/drive/root/children`, `.../drive/items/{itemId}/content`).
      PARTIAL on "fetched documents MUST be persisted via docudesk's attachment
      API per ADR-022": no such public attachment-ingestion route/controller
      exists in the docudesk app today (confirmed: no `attachment`-named
      controller/route in `docudesk/appinfo/routes.php` or `docudesk/lib/Controller/`).
      Persisting against a route that doesn't exist would be fabricated, so
      instead the adapter persists fetched bytes into the user's own Nextcloud
      Files storage via `IRootFolder` (a real NC storage backend, not local-disk
      or a bespoke table) and documents the docudesk hand-off as a follow-up gap
      in the spec's `## Purpose` section — per "don't silently patch a
      spec/reality mismatch."
- [x] Registered as an `IntegrationProvider`.
- [x] Unit tests covering document fetch + Files persistence
      (`tests/Unit/Service/Adapter/SharePointOnlineAdapterTest.php`, 5 tests).

## 4. SaaS productivity connectors (REQ-SPC-001)

- [x] Create `lib/Service/Adapter/Saas/Microsoft365Adapter.php` extending the
      shared base; scoped to calendar + mail metadata read via Microsoft
      Graph (`/v1.0/me/events`, `/v1.0/me/messages?$select=...` — metadata
      fields only, message bodies never read).
- [x] Registered as an `IntegrationProvider`.
- [x] Unit tests covering the calendar/mail read path
      (`tests/Unit/Service/Adapter/Microsoft365AdapterTest.php`, 4 tests).

## 5. Data-infra connectors (REQ-DIC-001)

- [x] Create `lib/Service/Adapter/DataInfra/S3Adapter.php` extending the shared
      base; scoped to read/write/list against an S3-compatible bucket via
      path-style HTTP + real `ListObjectsV2` XML response parsing.
      PARTIAL: targets S3-COMPATIBLE endpoints using simple bearer/API-key auth,
      NOT unmodified AWS S3 — OR's `CredentialBrokerService::injectAuth()` only
      supports templated-header secret injection, not AWS Signature Version 4
      request signing, which native AWS S3 requires for every request. Faking
      SigV4 support without the broker actually computing it would silently
      produce requests that fail against real AWS S3; documented as a
      broker-side follow-up in the spec's `## Purpose` section instead.
- [x] Registered as an `IntegrationProvider`.
- [x] Unit tests covering read/write/list
      (`tests/Unit/Service/Adapter/S3AdapterTest.php`, 9 tests).

## 6. Spec + docs

- [x] Filled in the `## Purpose` section of all four spec files (previously
      `TBD - created by archiving change add-openconnector-connector-categories`)
      describing the resolved scaffolding pattern, the reference adapter, and
      how to add the next vendor adapter (including the two documented
      known-gaps above).
- [ ] `hydra-gate-redundant-controller` / `hydra-gate-spdx` NOT RUN via the
      hydra harness itself (out of this worktree's scope per task instructions
      — "Do NOT touch any other app or the hydra/ repo"). Manually verified the
      equivalent: every new file carries `@license`/`@copyright` in its main
      docblock (spdx-headers shape), and no new controller is a redundant
      pass-through wrapper (these are `IntegrationProvider`s, not controllers,
      each with real HTTP-call-construction + response-normalisation logic, not
      thin delegation).

## Acceptance criteria

- Each of the four category directories exists with exactly one working
  reference adapter, registered through OR's integration registry (ADR-019).
- A sibling app can resolve any of the four reference adapters by integration
  slot slug without importing openconnector PHP directly (ADR-022).
- No vendor SDK credentials are hardcoded; all credential resolution goes
  through the shared OR credential broker.
- The remaining vendors named in each spec stay explicitly backlog — this
  change does not claim to close REQ-EWC-001/REQ-DCC-001/REQ-SPC-001/REQ-DIC-001
  in full, only to prove and ship the registration pattern.
