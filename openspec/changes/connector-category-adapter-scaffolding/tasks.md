# Tasks — connector-category adapter scaffolding

## 1. Shared base

- [ ] Create `lib/Service/Adapter/AbstractCategoryAdapterProvider.php` implementing
      the common `IntegrationProvider` contract: capability-vocabulary
      declaration, health-check surface, credential resolution via OR's
      credential broker.
- [ ] Unit tests for the base class's capability-vocabulary + health-check
      contract using a fake adapter.

## 2. Endpoint / workspace connectors (REQ-EWC-001, REQ-EWC-002)

- [ ] Create `lib/Service/Adapter/EndpointWorkspace/AzureVirtualDesktopAdapter.php`
      extending the shared base; implement session-enumeration + user-mapping +
      audit-event ingestion per REQ-EWC-002's fixed capability vocabulary.
- [ ] Register it as an `IntegrationProvider` in `lib/AppInfo/Application.php`.
- [ ] Unit tests covering session-enumeration and audit-event ingestion.

## 3. Document / CMS connectors (REQ-DCC-001)

- [ ] Create `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php`
      extending the shared base; fetched documents MUST be persisted via
      docudesk's attachment API per ADR-022 (no local file store).
- [ ] Register it as an `IntegrationProvider`.
- [ ] Unit tests covering document fetch + docudesk hand-off.

## 4. SaaS productivity connectors (REQ-SPC-001)

- [ ] Create `lib/Service/Adapter/Saas/Microsoft365Adapter.php` extending the
      shared base; scope to calendar + mail metadata read.
- [ ] Register it as an `IntegrationProvider`.
- [ ] Unit tests covering the calendar/mail read path.

## 5. Data-infra connectors (REQ-DIC-001)

- [ ] Create `lib/Service/Adapter/DataInfra/S3Adapter.php` extending the shared
      base; scope to read/write/list against an S3-compatible bucket.
- [ ] Register it as an `IntegrationProvider`.
- [ ] Unit tests covering read/write/list.

## 6. Spec + docs

- [ ] Fill in the `## Purpose` section of all four spec files (currently
      `TBD - created by archiving change add-openconnector-connector-categories`)
      describing the resolved scaffolding pattern and how to add the next
      vendor adapter.
- [ ] Confirm `hydra-gate-redundant-controller` and `hydra-gate-spdx` pass on
      all new files.

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
