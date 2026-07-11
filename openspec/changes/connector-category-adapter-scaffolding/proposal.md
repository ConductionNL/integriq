---
kind: code
depends_on: []
---

## Why

Four openconnector specs declare fleet-wide MUST requirements for connector
adapters, created by archiving `add-openconnector-connector-categories`:

- `openspec/specs/endpoint-workspace-connectors/spec.md` (REQ-EWC-001) — Citrix,
  VMware/Omnissa Horizon, AWS WorkSpaces, AVD, Windows 365, Intune, Jamf, etc.
  MUST be `IntegrationProvider`s under `lib/Service/Adapter/EndpointWorkspace/`.
- `openspec/specs/document-cms-connectors/spec.md` (REQ-DCC-001) — SharePoint,
  OneDrive, Google Drive, Alfresco, Box, etc. MUST be `IntegrationProvider`s
  under `lib/Service/Adapter/DocumentCms/`.
- `openspec/specs/saas-productivity-connectors/spec.md` (REQ-SPC-001) —
  Microsoft 365, Google Workspace, Slack, Jira, Salesforce, Exact Online, etc.
  MUST be `IntegrationProvider`s under `lib/Service/Adapter/Saas/`.
- `openspec/specs/data-infra-connectors/spec.md` (REQ-DIC-001) — Postgres,
  MongoDB, S3, Kafka, etc. MUST be `IntegrationProvider`s under
  `lib/Service/Adapter/DataInfra/`.

None of the four `lib/Service/Adapter/{EndpointWorkspace,DocumentCms,Saas,DataInfra}/`
directories exist. `lib/Service/Integration/` — the only directory currently
registering an `IntegrationProvider` — contains exactly one provider,
`SynchronizationContractProvider.php`, which surfaces openconnector's own
synchronization-contract widget to OR, not an external SaaS/DMS/EWC/data-infra
system. Zero external-vendor adapters exist anywhere in the codebase (confirmed:
`lib/Connectors/` holds only `PdokConnector.php`, `lib/Adapters/` holds only
`Pdok/` and `Berichtenbox/`). Every one of the four specs' MUST requirements is
currently unmet — there is no code, so there is also no `IntegrationProvider`
base pattern for these categories to plug into, and no enforcement of "reviewer
confirms no per-app SDK in sibling apps" is possible because there is nothing
for a sibling app to consume instead of rolling its own client (the exact
anti-pattern ADR-022's audit already found in pipelinq/procest/decidesk).

Building all ~40 named vendor integrations is out of scope for one change (and
arguably belongs to a longer roadmap driven by actual demand). What is missing
today, and is a reasonably-scoped fix, is the **shared adapter registration
contract** each category needs plus **one reference adapter per category** —
proving the pattern end-to-end so future vendor adapters are additive
(new class + manifest entry) rather than a fresh architecture exercise each
time.

## What Changes

- Add a shared abstract base, `AbstractCategoryAdapterProvider`, under
  `lib/Service/Adapter/` implementing the common `IntegrationProvider` scaffolding
  (capability-vocabulary declaration, health-check surface, credential resolution
  via OR's credential-broker per `project_credential-broker`) that every
  category-specific adapter extends.
- Create the four category directories with one reference adapter each,
  proving REQ-EWC-001 / REQ-DCC-001 / REQ-SPC-001 / REQ-DIC-001 against a real
  vendor per category:
  - `lib/Service/Adapter/EndpointWorkspace/AzureVirtualDesktopAdapter.php`
  - `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php`
  - `lib/Service/Adapter/Saas/Microsoft365Adapter.php`
  - `lib/Service/Adapter/DataInfra/S3Adapter.php`
- Register each reference adapter as an `IntegrationProvider` through OR's
  integration registry per ADR-019, with a manifest entry declaring its
  capability vocabulary (session-enumeration / user-mapping / audit-event
  ingestion for EWC; document fetch/list for DCC; calendar/mail/task for SPC;
  read/write/list for DIC).
- Document the extension pattern in each spec's `## Purpose` section (currently
  `TBD - created by archiving change add-openconnector-connector-categories` —
  all four) so future adapters have a worked example to follow instead of a
  70-vendor bullet list with zero code.
- **No BREAKING changes** — purely additive; no existing endpoint, route, or
  schema changes.

## Impact

- `lib/Service/Adapter/AbstractCategoryAdapterProvider.php` — new shared base.
- `lib/Service/Adapter/EndpointWorkspace/AzureVirtualDesktopAdapter.php` — new.
- `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php` — new.
- `lib/Service/Adapter/Saas/Microsoft365Adapter.php` — new.
- `lib/Service/Adapter/DataInfra/S3Adapter.php` — new.
- `lib/AppInfo/Application.php` — register the four reference adapters as
  `IntegrationProvider`s (pattern already used for `SynchronizationContractProvider`).
- `openspec/specs/{endpoint-workspace,document-cms,saas-productivity,data-infra}-connectors/spec.md` —
  `## Purpose` filled in with the resolved scaffolding pattern; MUST language
  unchanged (the remaining ~36 vendors stay backlog, tracked by follow-up
  issues per vendor, not blocked by this change).
