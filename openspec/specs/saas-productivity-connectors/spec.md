# saas-productivity-connectors Specification

## Purpose

SaaS productivity vendors (Microsoft 365, Google Workspace, Slack, Jira,
Salesforce, Exact Online, etc.) register as `IntegrationProvider`s under
`lib/Service/Adapter/Saas/`, each extending the shared
`AbstractCategoryAdapterProvider` (`lib/Service/Adapter/AbstractCategoryAdapterProvider.php`).
`Microsoft365Adapter` (`lib/Service/Adapter/Saas/Microsoft365Adapter.php`) is
the reference implementation, proving `calendar-read` and
`mail-metadata-read` against Microsoft Graph's `me` surface (deliberately
scoped to metadata reads only — no send/write capability, no message-body
access). To add the next vendor: create a new class extending
`AbstractCategoryAdapterProvider`, implement the `IntegrationProvider`
metadata methods plus vendor-specific read methods via `brokeredRequest()`,
and register it in `Application::registerIntegrationProviders()`. The
remaining named vendors stay explicit backlog (see
`openspec/changes/connector-category-adapter-scaffolding`).

## Requirements
### Requirement: SaaS productivity and work-management connector adapters SHALL register through the integration registry per ADR-019 (REQ-SPC-001)

SaaS productivity and work-management connector adapters MUST register through
the integration registry per ADR-019.
Every adapter for a SaaS productivity, communication, work
management, ITSM, CRM, or NL/EU ERP platform — Microsoft 365
(Outlook + Calendar + Teams + Sharepoint metadata),
Google Workspace (Gmail + Calendar + Tasks + Drive metadata),
Slack, Microsoft Teams, Notion, Asana, ClickUp, Monday.com,
Atlassian Jira, Atlassian Confluence, ServiceNow, Salesforce,
HubSpot, Zendesk, plus NL/EU-specific Exact Online, Twinfield,
AFAS, Unit4, e-Boekhouden, MoneyBird — MUST be implemented as
an `IntegrationProvider` registered through OR's integration
registry per ADR-019. Adapter classes MUST live under
`lib/Service/Adapter/Saas/`.

Where a sibling Conduction app already provides equivalent
functionality (e.g. **shillinq for bookkeeping**), the SPC
adapter is the *bridge* to the external SaaS, not a replacement
for the Conduction-side capability. Per ADR-022, sibling apps
consume the SaaS bridge by slug; they do NOT carry their own
HTTP client to the SaaS vendor.

#### Scenario: Reviewer confirms no per-app SaaS SDK in sibling apps

- **GIVEN** any sibling app's `lib/Service/` tree
- **WHEN** scanned for direct imports of
  `Microsoft\\Graph\\*`, `Google\\Service\\*`,
  `JoliCode\\Slack\\*`, `Salesforce\\*`, `ServiceNow\\*`,
  `Atlassian\\*`, `Notion\\*`, `Twinfield\\*`, `Exact\\*`,
  `Afas\\*`
- **THEN** no such imports SHALL exist; SaaS access MUST route
  through integriq by integration slot slug.

### Requirement: Each SPC adapter manifest entry SHALL declare a fixed capability vocabulary across record-crud, event-subscribe, search-lookup, and bulk operations (REQ-SPC-002)

Each SPC adapter manifest entry MUST declare a fixed capability vocabulary.
Per ADR-024 (extending the REQ-DIC-002 manifest shape), every
SPC adapter MUST publish a manifest entry with
`category: saas-productivity`, an explicit `subCategory` of
one of `email-calendar`, `chat-collab`, `work-management`,
`itsm`, `crm`, `helpdesk`, `erp`, `accounting`, `hr-payroll`,
and a `capabilities[]` drawn from the canonical vocabulary:

| Capability | Meaning | Typical scope |
|---|---|---|
| `record-crud` | Create, read, update, delete records on a configured remote entity (Jira issue, Salesforce account, Asana task, Exact transaction) | per remote entity type, declared in the source configuration |
| `record-versioning` | Read change history for a record | per entity type, where the upstream supports it |
| `event-subscribe` | Subscribe to remote change events via webhook | normalised to CloudEvent (per REQ-SPC-005) |
| `search-lookup` | Federated record/document/contact search | normalised hit envelope (per REQ-SPC-004) |
| `bulk-export` | Bulk read of a remote entity into a local register (mappings via OR `Mapping` abstraction) | scheduled via OR `ScheduledWorkflow` |
| `bulk-import` | Bulk write of local records to the remote system | requires explicit operator opt-in per REQ-SPC-006 |
| `oauth-userlevel` | The adapter supports per-user OAuth tokens (in addition to the operator-level Source-stored credentials) | required for "send as the user" patterns |
| `presence` | Read user presence (free/busy / available / dnd) | chat/calendar adapters |
| `attachment-fetch` | Fetch file attachments referenced from records — bytes pass-through only; persistence MUST go through docudesk per REQ-SPC-007 |

Capabilities a given SaaS does not support MUST be omitted. The
manifest validator MUST reject any unknown capability literal.

#### Scenario: A Jira adapter declares the right capability subset

- **GIVEN** the Jira adapter manifest entry
- **WHEN** inspected
- **THEN** `capabilities[]` SHALL include `record-crud`,
  `record-versioning`, `event-subscribe`, `search-lookup`,
  `bulk-export`, `attachment-fetch`; MAY include
  `oauth-userlevel`; MUST NOT include `presence`.

#### Scenario: An Exact Online adapter declares accounting-shaped capabilities

- **GIVEN** the Exact Online adapter manifest entry
- **WHEN** inspected
- **THEN** `subCategory` SHALL equal `accounting`;
  `capabilities[]` SHALL include `record-crud` for bookings /
  invoices / contacts, `bulk-export`, `bulk-import` (opt-in
  per REQ-SPC-006), `attachment-fetch`.

### Requirement: OAuth 2.0 SHALL be the default auth mode; service-account JWT and API key are explicit alternates only (REQ-SPC-003)

Per ADR-005 (security), SPC adapters MUST default to OAuth 2.0
authorization-code flow with PKCE. The OAuth flow MUST be
hosted by integriq — no sibling app MUST open an OAuth
browser window, hold an OAuth client secret, or refresh tokens.
Per ADR-019 §"External integrations route through Integriq",
the integriq source is the canonical credential home.

The manifest entry's `authModes[]` MUST list `oauth2` first
when supported, and MAY include the alternate auth modes
`serviceAccountJwt` (where the SaaS supports server-to-server
JWT — e.g. Google Workspace domain-wide delegation) and
`apiKey` (legacy alternates — e.g. some Exact / Twinfield
configurations, MoneyBird). `basicAuth` MUST NOT appear in
new SPC adapters; legacy basic-auth adapters MUST be flagged
in the manifest entry with `"deprecated": true` and an ADR-005
exception reference.

When the adapter supports `oauth-userlevel` per REQ-SPC-002,
per-user token storage MUST also live in integriq via the
existing `Source` extension for per-user credentials; sibling
apps reference the per-user token by NC user id + source slug,
they MUST NOT cache the token themselves.

#### Scenario: Reviewer confirms no OAuth client secret in any sibling app

- **GIVEN** any sibling app's repository
- **WHEN** scanned for substrings `client_secret`,
  `client-secret`, `oauth_client`, or for environment-variable
  references like `*_OAUTH_CLIENT_SECRET` in `.env` examples
  or appinfo/info.xml descriptions
- **THEN** no such references SHALL exist; OAuth client
  credentials live only in integriq source records.

#### Scenario: A SaaS source supports OAuth as the default auth mode

- **GIVEN** a newly added Slack adapter
- **WHEN** the manifest entry is inspected
- **THEN** `authModes[0]` SHALL equal `oauth2`; the entry MAY
  also list `apiKey` for legacy bot-token compatibility.

### Requirement: Search/lookup federation SHALL share the document-cms hit envelope, extended with `entityType`, `recordKey`, and `actorLabel` for record-shaped hits (REQ-SPC-004)

A federated search across multiple SaaS sources MUST return
the REQ-DCC-004 normalised hit envelope, extended with two
fields for record-shaped hits (Jira issues, Asana tasks,
Salesforce accounts, Notion pages, ServiceNow tickets):

| Field | Type | Purpose |
|---|---|---|
| `entityType` | string | The remote entity kind (`jira-issue`, `asana-task`, `salesforce-account`, etc.) |
| `recordKey` | string | The remote system's human-readable key (e.g. `PROJ-1234`, `OPP-5678`) |
| `actorLabel` | string or null | Best-available "owner" label for the record (assignee, owner, last-modified-by) |

Per ADR-022, the normalisation logic lives in integriq;
sibling apps consume only the envelope shape. The federation
MUST allow mixed-category queries (DCC + SPC + EWC results
in one query) — `sourceSlug` distinguishes the source and the
optional `entityType` distinguishes the kind.

#### Scenario: A federated search returns mixed DCC + SPC hits

- **GIVEN** operator-configured SharePoint (DCC) and Jira (SPC)
  sources
- **WHEN** a sibling app issues a federated search for
  `"customer onboarding"`
- **THEN** every hit MUST conform to the envelope; SharePoint
  hits MUST omit `entityType` / `recordKey`; Jira hits MUST
  include `entityType: jira-issue` and `recordKey: <PROJ-NNN>`;
  the caller MUST be able to merge-sort by `score`.

### Requirement: Event subscriptions SHALL normalise to CloudEvents and route through the standard event dispatcher per ADR-022 (REQ-SPC-005)

Event subscriptions MUST normalise to CloudEvents and route through the
standard event dispatcher per ADR-022.
Per REQ-EWC-004 (same shape across the connector-categories
family) and ADR-022 §"Events + webhooks", inbound webhook
events from SaaS sources MUST be normalised to CloudEvents of
type `com.conduction.saas.<sub-category>.<event-kind>` (e.g.
`com.conduction.saas.work-management.issue-status-changed`,
`com.conduction.saas.crm.opportunity-stage-changed`,
`com.conduction.saas.chat-collab.message-mentioned`).

Integriq MUST NOT author a new event table for SPC
events — every event lands in the existing `CallLog` (for
raw transport audit) and in the CloudEvent dispatcher (for
sibling-app reaction). Sibling apps subscribe through the
standard CloudEvent contract; persistence for long-term
retention is the sibling app's choice and MUST go through OR's
audit-trail-immutable per ADR-022.

#### Scenario: A Jira issue-status webhook reaches a sibling app via CloudEvent

- **GIVEN** a Jira source declaring `event-subscribe`
- **WHEN** Jira POSTs an issue-updated webhook
- **THEN** integriq MUST normalise to a CloudEvent of type
  `com.conduction.saas.work-management.issue-status-changed`;
  any subscribed sibling app MUST receive it; no SPC-specific
  event table SHALL be created in integriq.

### Requirement: Mutative bulk operations SHALL be opt-in per source AND gated by an admin-configured action authorisation per ADR-023 (REQ-SPC-006)

Mutative bulk operations MUST be opt-in per source AND gated by an
admin-configured action authorisation per ADR-023.
Per REQ-EWC-005 (same shape across the family) and the
in-flight ADR-023, every mutative bulk operation against a SaaS
source — `bulk-import`, any `record-crud` invoked through a
scheduled workflow rather than a user-initiated request — MUST
be:

1. Opt-in per `Source` record via an explicit
   `enabledMutativeBulkActions: string[]` configuration field;
   omitting the action name MUST cause the adapter to throw
   `MutativeBulkActionDisabledException` on invocation.
2. Gated by an admin-configured action-to-group mapping per
   ADR-023 — the integriq admin settings MUST list the
   bulk action and let the operator bind it to a Nextcloud
   group; the adapter MUST verify membership at invocation
   time.
3. Logged to the existing `CallLog` with outcome
   `mutative-bulk-invoked` regardless of success/failure.

#### Scenario: A `bulk-import` rejected because not opted in

- **GIVEN** a Salesforce source with
  `enabledMutativeBulkActions: []` (default)
- **WHEN** any caller invokes `bulkImport(...)`
- **THEN** the call MUST throw
  `MutativeBulkActionDisabledException`; **AND** an audit row
  MUST land in `CallLog`.

### Requirement: Attachment bytes MUST stream through the adapter without local persistence; long-term storage SHALL go through docudesk per ADR-022 (REQ-SPC-007)

Attachment bytes MUST stream through the adapter without local persistence;
long-term storage MUST go through docudesk per ADR-022.
Per REQ-DCC-006 (same shape across the family), when an SPC
adapter exposes `attachment-fetch`, the bytes MUST be returned
as a stream to the caller without local persistence in
integriq. Sibling apps that need to retain the attachment
MUST route it to docudesk's file API per ADR-022. Integriq
MUST NOT write attachment bytes to its app data directory or to
any integriq-owned table.

#### Scenario: A workflow stores a Jira attachment as a docudesk file

- **GIVEN** a workflow that fetches a Jira issue's attachment
  via the adapter
- **WHEN** the workflow persists the file
- **THEN** the file bytes MUST be POSTed to docudesk; the OR
  object that references the attachment MUST carry a docudesk
  URI; no file bytes SHALL be written under integriq's
  app data or any integriq-owned table.

### Requirement: Individual per-vendor adapters are explicitly out of scope for this spec — each adapter MUST ship in its own `add-openconnector-{slug}-adapter` change (REQ-SPC-008)

Individual per-vendor SPC adapter implementations MUST be out of scope for this spec.
Per REQ-DIC-007 / REQ-DCC-007 / REQ-EWC-007, individual SPC
adapter implementations (e.g. Microsoft 365, Google Workspace,
Slack, Teams, Jira, ServiceNow, Salesforce, Exact Online,
Twinfield, AFAS, Unit4, MoneyBird) MUST land as separate
openspec changes. Per-adapter changes MUST cite REQ-SPC-001
through REQ-SPC-007 and MUST NOT re-derive the category-level
contract.

#### Scenario: A new Microsoft 365 adapter change references this spec

- **GIVEN** a new change folder
  `openspec/changes/add-openconnector-microsoft365-adapter/`
- **WHEN** its proposal.md is inspected
- **THEN** the `Depends on` line MUST include
  `saas-productivity-connectors (integriq)`; the proposal
  MUST cite REQ-SPC-002 (capabilities), REQ-SPC-003 (OAuth
  default), REQ-SPC-005 (CloudEvent normalisation),
  REQ-SPC-006 (bulk-action authorisation), and REQ-SPC-007
  (attachments via docudesk) by REQ id.

