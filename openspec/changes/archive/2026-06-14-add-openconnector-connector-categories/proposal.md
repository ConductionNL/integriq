# Proposal: add-openconnector-connector-categories

`kind: config` per ADR-032 — the centre of mass is declarative
manifest entries + integration-registry registration shapes + a
small amount of seed data (per-source configuration JSON shapes).
No new per-adapter PHP service classes are authored by *this*
change; per-adapter implementations land in follow-up
`add-openconnector-{slug}-adapter` changes that each consume one
of these four category specs by REQ reference.

## Summary

Introduce **four category-level capability specs** that codify how
openconnector ships connector adapters for the four
specter-discovered connector categories:

- `data-infra-connectors` — infrastructure + domain data sources
  (databases, NoSQL, warehouses, event streams, object stores)
- `document-cms-connectors` — external document-management /
  content-management systems (SharePoint, Alfresco, Box, Drive,
  VIP-files, NLX, etc.) — strictly EXTERNAL DMS; documents that
  need to land on the Conduction side go through docudesk
- `endpoint-workspace-connectors` — virtual-desktop / app-virt /
  endpoint-management platforms (Citrix, Horizon, Intune, Jamf,
  Liquit, AWS WorkSpaces, AVD, Frame, Workspace ONE)
- `saas-productivity-connectors` — SaaS productivity, work
  management, ITSM, CRM, and NL/EU ERP (Microsoft 365, Google
  Workspace, Slack, Jira, Salesforce, ServiceNow, Exact Online,
  Twinfield, AFAS, Unit4, etc.)

Each category spec defines the **registration contract**: how
adapters declare themselves to the integration registry per
ADR-019, how their manifest entries shape into `src/manifest.json`
per ADR-024, where credentials live (always in openconnector's
existing `Source` registry, never on consuming-app records), how
auth modes are declared, how polling vs push is captured, how
schema/entity discovery works, how mutative actions are gated per
ADR-005 + ADR-023, and how operational health surfaces through
the existing `prometheus-metrics` spec.

The category specs are **shape-neutral about individual systems**.
Per-system adapter implementations (Snowflake, SharePoint, Citrix,
Jira, Exact, etc.) land in follow-up
`add-openconnector-{slug}-adapter` changes that cite the relevant
category spec by REQ id. This mirrors the existing openconnector
convention (`add-pdok-adapter`, `stuf-adapter`, `dso-omgevingsloket`,
`ibabs-notubiz-connector`) — each per-system change owns its
adapter slice; this change owns the shared contract those slices
plug into.

This change conforms to openconnector's `openspec/config.yaml`
rules (proposal references shared `nextcloud-app` patterns,
specs include configuration shapes and source-registration
contracts, design notes openconnector's operational
independence from openregister).

## Motivation

openconnector today ships per-system adapter changes
(`add-pdok-adapter`, `stuf-adapter`, etc.) without a written
category-level contract. As Specter discovered, the fleet is
about to grow by dozens of adapters spanning four well-defined
categories. Without a shared contract:

- Every per-adapter change re-derives the same registration
  shape (manifest entry, credential storage rules, mutative-action
  gating) by copy-paste from an arbitrary predecessor.
- Sibling apps (mydash, decidesk, opencatalogi, shillinq, ...)
  have no single place to read "what shape do I consume an
  openconnector adapter as?" — they read individual adapter
  classes and guess the intended pattern.
- Hydra reviewers gate on inconsistent expectations between
  adapters that should be uniform (e.g. some adapters store
  credentials in `IAppConfig`, others on the source record,
  others in env vars).
- The integration-registry pattern from ADR-019 is invoked in
  prose but never spelled out as a category-level contract that
  per-adapter authors must follow.

The four category specs in this change close that gap. Each one
specifies the registration contract once; every per-adapter change
inherits it by REQ reference. The shape across the four categories
is **deliberately the same** at the manifest/registration layer
(same `category` / `subCategory` / `authModes` / `capabilities` /
`rateLimits` / `pollingMode` fields) and **deliberately different
where the category demands it** (DMS adapters have ACL bridging,
EWC adapters have destructive-action gating, SPC adapters default
to OAuth, etc.).

This is the openconnector equivalent of what shillinq's
T4 envelope did for bookkeeping capabilities: a single
multi-capability change that ships the foundation shape, with
per-leaf implementations following separately.

## Affected Projects

- [x] Project: openconnector — adds 4 new capability specs (folder
  `add-openconnector-connector-categories/specs/`). When per-adapter
  implementations follow, each contributes its own
  `IntegrationProvider` class under
  `lib/Service/Adapter/{DataInfra|DocumentCms|EndpointWorkspace|Saas}/`,
  a new `connectors[]` entry in `src/manifest.json` per ADR-024,
  one DI tag in `lib/AppInfo/Application.php`, and optional source
  configuration JSON seeds under `lib/Settings/seeds/sources/`.
- [ ] Project: openregister — no source changes; this change
  consumes the integration-registry contract already shipped in
  `openregister/openspec/changes/pluggable-integration-registry/`
  (per ADR-019). If a needed extension shape on the registry is
  missing (e.g. per-user OAuth token storage, ACL subject surface
  for DCC), an OR issue is filed and the relevant requirement is
  kept shape-neutral pending OR-side resolution.
- [ ] Project: docudesk — no source changes; DCC and SPC adapters
  reference docudesk for the persistence side of attachments. The
  contract docudesk already exposes (file POST + URI return) is
  sufficient.
- [ ] Project: nextcloud-vue — no source changes; the manifest
  consumer pattern is already documented in ADR-024 and shipped
  in `@conduction/nextcloud-vue`'s `CnAppRoot` /
  `useAppManifest`. This change extends `src/manifest.json` with a
  new top-level `connectors[]` block (additive); the canonical
  schema at
  `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
  MAY need an additive extension to validate it — tracked as a
  follow-up issue, not blocking.
- [ ] Project: sibling apps (mydash, decidesk, opencatalogi,
  shillinq, procest, pipelinq, larpingapp, zaakafhandelapp) —
  no source changes from this change. Per ADR-022, these apps
  consume connector slot slugs at runtime; nothing about that
  consumption changes here. Each app already references
  openconnector by slug for the integrations it uses.

## Scope

### In Scope

- Four new capability specs:
  - `data-infra-connectors` (REQ-DIC-*)
  - `document-cms-connectors` (REQ-DCC-*)
  - `endpoint-workspace-connectors` (REQ-EWC-*)
  - `saas-productivity-connectors` (REQ-SPC-*)
- The shared registration contract: every adapter, regardless of
  category, registers via DI-tagged `IntegrationProvider` (per
  ADR-019) and ships a `connectors[]` manifest entry (per
  ADR-024) with the fixed minimum field set described in
  REQ-DIC-002 and inherited / extended by the other three category
  specs.
- The shared credential rule: all adapter credentials live in
  openconnector's `Source` registry; no sibling app mirrors them
  (REQ-DIC-003, REQ-DCC-003, REQ-EWC-005, REQ-SPC-003).
- The shared scheduled-pull rule: every scheduled pull is an OR
  `ScheduledWorkflow` record, never a per-adapter `TimedJob`
  (REQ-DIC-005, REQ-EWC-006, inherited shape across categories).
- The shared event-normalisation rule: webhook events from every
  category normalise to CloudEvents and route through OR's
  dispatcher; no openconnector-local event table per category
  (REQ-EWC-004, REQ-SPC-005).
- The shared attachment-persistence rule: attachment bytes pass
  through openconnector but persist on the Conduction side only
  via docudesk per ADR-022 (REQ-DCC-006, REQ-SPC-007).
- The shared metrics extension: every adapter invocation
  increments `openconnector_adapter_invocations_total` on the
  existing `/api/metrics` endpoint (REQ-DIC-006); no new endpoint.
- The per-category specifics: DMS-side ACL bridging
  (REQ-DCC-003), federated-search hit envelope (REQ-DCC-004,
  REQ-SPC-004), EWC user mapping (REQ-EWC-003), destructive-action
  gating per ADR-023 (REQ-EWC-005, REQ-SPC-006), OAuth as the SPC
  default (REQ-SPC-003).

### Out of Scope

- **Implementation code** — this is a spec-only change. No PHP
  `IntegrationProvider` classes for individual systems land here;
  no `src/manifest.json` `connectors[]` entries land here; no
  source-configuration JSON seeds land here. Each individual
  system adapter ships in its own follow-up change named
  `add-openconnector-{slug}-adapter`.
- **Per-adapter test fixtures, mocks, contract tests** — also
  per-adapter, also follow-up.
- **The OR-side `IntegrationProvider` contract** — already shipped
  in `openregister/openspec/changes/pluggable-integration-registry/`.
  This change consumes that contract; it does not extend it. If a
  consuming need surfaces a contract gap (e.g. per-user OAuth token
  surface), an OR issue is filed.
- **Migration of existing adapters (`pdok`, `stuf`,
  `dso-omgevingsloket`, `ibabs-notubiz`) to the new category specs**
  — these are mature and predate this contract. They MAY be
  retrofitted in a separate cleanup change; this change does NOT
  force a migration.
- **Frontend UI for adapter administration** — per ADR-019, the
  integration registry already drives the admin UI for
  registry-aware integrations. No new Vue components for
  per-category admin land here.
- **Per-vendor docs / journeydoc tutorials** — per-adapter, per
  ADR-030, land with the per-adapter change.

## Approach

Four spec deltas, each adding ADDED Requirements to a brand-new
capability spec, in registration-shape dependency order:

1. **`data-infra-connectors`** — declares the shared adapter
   registration contract first (REQ-DIC-001 through REQ-DIC-007).
   This is the spec the other three specs inherit from at the
   manifest-shape layer; the cross-references say
   "extending the REQ-DIC-002 manifest shape."
2. **`document-cms-connectors`** — inherits the registration
   shape, adds DMS-specific contracts: scope to EXTERNAL DMS only
   (REQ-DCC-001), the four canonical DMS capabilities
   (REQ-DCC-002), ACL bridging defaults read-only (REQ-DCC-003),
   the federated-search hit envelope (REQ-DCC-004), the
   `readOnly` posture for blast-radius audit (REQ-DCC-005),
   docudesk for any Conduction-side persistence (REQ-DCC-006).
3. **`endpoint-workspace-connectors`** — inherits the registration
   shape, adds workspace-specific contracts: the canonical
   capability vocabulary (REQ-EWC-002), declarative
   `UserMapping` (REQ-EWC-003), audit events as CloudEvents
   (REQ-EWC-004), destructive-action gating per ADR-023
   (REQ-EWC-005), no per-adapter TimedJob (REQ-EWC-006).
4. **`saas-productivity-connectors`** — inherits the registration
   shape, adds SaaS-specific contracts: the canonical SaaS
   capability vocabulary (REQ-SPC-002), OAuth 2.0 as the default
   auth mode (REQ-SPC-003), the extended search hit envelope
   that adds `entityType` / `recordKey` / `actorLabel` to
   REQ-DCC-004 (REQ-SPC-004), CloudEvent normalisation
   (REQ-SPC-005), mutative-bulk-action gating per ADR-023
   (REQ-SPC-006), attachments via docudesk (REQ-SPC-007).

All four specs follow the conduction-schema format (RFC 2119,
`### REQ-{Abbrev}-NNN: <name>`, `#### Scenario:` with exactly 4
hashtags, GIVEN/WHEN/THEN). REQ abbreviations: `REQ-DIC-*`,
`REQ-DCC-*`, `REQ-EWC-*`, `REQ-SPC-*`.

## New Dependencies

None. This change consumes existing openregister abstractions
(integration registry per ADR-019, ScheduledWorkflow per ADR-031,
CloudEvent dispatcher per ADR-022), the existing docudesk file
API (per ADR-022), the existing `@conduction/nextcloud-vue`
manifest renderer + schema (per ADR-024), the existing
openconnector `Source` registry + `Mapping` abstraction +
`CallLog`. No new libraries, no new services, no version bumps.

## Impact

- `openspec/changes/add-openconnector-connector-categories/`
  is a new folder containing one `proposal.md`, one `design.md`,
  one `tasks.md`, and four `specs/{slug}/spec.md` files.
- No change to `lib/`, `src/`, `appinfo/`, `tests/`, or any
  runtime file. This is spec-only.
- Future per-adapter changes (`add-openconnector-{slug}-adapter`)
  will each touch:
  - `lib/Service/Adapter/{DataInfra|DocumentCms|EndpointWorkspace|Saas}/{Slug}Adapter.php`
    (new — one file)
  - `src/manifest.json` (additive `connectors[]` entry)
  - `lib/AppInfo/Application.php` (one DI tag line)
  - optionally `lib/Settings/seeds/sources/{slug}.json` (seed
    source record for dev/test environments)
  - optionally `docs/integrations/{slug}.md` (per-adapter
    operator + developer docs per ADR-030)

## Cross-Project Dependencies

- **openregister** — depends on the existing integration-registry
  contract (ADR-019) being stable, on `ScheduledWorkflow` +
  CloudEvent dispatcher being callable from adapter code, on
  `IInitialState` per ADR-001 patterns (when surfacing adapter
  status to admin UI in the per-adapter changes).
- **docudesk** — depends on docudesk's file POST endpoint being
  callable for any attachment that must persist Conduction-side.
  Today this works; no docudesk change required for the category
  specs themselves.
- **@conduction/nextcloud-vue** — depends on the manifest renderer
  (ADR-024) being able to consume an additive top-level
  `connectors[]` block. The renderer currently ignores top-level
  keys it does not know; the canonical schema MAY need an
  additive extension to validate `connectors[]`. Tracked as a
  follow-up.
- **Sibling apps** — depends on every sibling app continuing to
  consume openconnector by slot slug per ADR-022. This change does
  not modify that consumption; it codifies the producer side so
  the consumption stays stable.

## Risks

### Risk 1: The four category specs may overconstrain the per-adapter changes that follow

**Severity**: Medium
**Mitigation**: Each category spec is deliberately
shape-neutral about which systems land first and which order.
The `capabilities[]` vocabulary is closed within a category but
extensible by ADDED Requirements in a future delta (`add-*-cap`
change). Per-adapter authors who hit a missing capability literal
file a category-spec delta change rather than working around it
in the adapter — this keeps the contract honest.

### Risk 2: The OAuth-first rule (REQ-SPC-003) may not be achievable for legacy SaaS adapters (Exact Online, Twinfield, AFAS)

**Severity**: Low-Medium
**Mitigation**: REQ-SPC-003 explicitly permits `serviceAccountJwt`
and `apiKey` as alternates and marks `basicAuth` as deprecated.
Legacy adapters that genuinely need basic-auth flag it with
`"deprecated": true` and an ADR-005 exception reference. The
per-adapter change documents the exception in its own design.md.

### Risk 3: The ADR-023 dependency (action-level authorisation) is in-flight; gating mutative actions on it may be premature

**Severity**: Medium
**Mitigation**: REQ-EWC-005 and REQ-SPC-006 cite ADR-023 by
reference but specify the per-source `enabled*Actions[]`
opt-in mechanism declaratively. If ADR-023 lands a different
group-binding shape than expected, the category specs absorb the
change via a small delta; the per-adapter changes consume the
final shape regardless. Until ADR-023 ships, mutative actions
remain opt-in via the source configuration and are logged to
`CallLog` — the audit-and-opt-in side works today even without
the group-binding side.

### Risk 4: The shared federated-search hit envelope (REQ-DCC-004 +
REQ-SPC-004) may not fit every search API perfectly (e.g. some
SaaS APIs return relevance scores that are not normalised to 0..1)

**Severity**: Low
**Mitigation**: The envelope mandates a normalised `score` in
`0..1`; adapters with raw scores in other ranges apply a
per-adapter normalisation (typically min-max within the result
page). Where this is genuinely impossible (e.g. a search API
that returns no relevance signal), the adapter MUST populate
`score` with a deterministic placeholder (e.g. ordinal rank /
result count) and document the deviation in its own design.md.

### Risk 5: Per-user OAuth tokens (REQ-SPC-003 `oauth-userlevel`)
require an OR-side per-user credential surface that may not yet
be production-ready

**Severity**: Medium
**Mitigation**: The category spec describes the contract
(per-user tokens stored in openconnector, referenced by NC user
id + source slug). If OR's per-user credential surface lacks any
required shape, an OR issue is filed and `oauth-userlevel` is
omitted from the manifest entry of affected per-adapter changes
until the OR side ships. Sibling apps consuming user-level
actions handle the absence by surfacing a "connect your account"
prompt that completes the OAuth flow through openconnector and
populates the per-user token.

### Risk 6: The "no per-adapter TimedJob" rule (REQ-DIC-005,
REQ-EWC-006) cuts against the existing pattern in
`add-pdok-adapter` / `stuf-adapter` style

**Severity**: Low
**Mitigation**: The existing adapter changes predate the
`ScheduledWorkflow` abstraction and ADR-031. This change does NOT
require them to migrate; the rule applies to NEW adapters (any
change submitted after this one lands). A follow-up cleanup change
MAY migrate legacy adapters opportunistically. The architectural
target is uniform; the migration is forgiving.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the
change folder; no runtime impact because no implementation lands
until per-adapter `opsx-apply` cycles run against the per-adapter
follow-up changes. After per-adapter implementations land,
rollback follows the standard pattern: revert the per-adapter
PR, remove the adapter's `connectors[]` manifest entry, remove
its DI tag, remove its `IntegrationProvider` class. The category
specs themselves remain valid even when zero adapters are
implemented — they describe the shape, not any specific instance.

## Open Questions

1. **Per-user OAuth token storage in OR** — confirm during the
   first SPC per-adapter change (likely Microsoft 365 or Google
   Workspace) whether OR's existing `Source` extension supports
   per-user credentials, or whether an OR delta is needed. The
   category spec stays shape-neutral.
2. **Manifest schema extension for `connectors[]`** — confirm
   with the nextcloud-vue maintainers whether the canonical
   schema needs an additive entry to validate the new top-level
   key, or whether the renderer's `additionalProperties` posture
   already allows it. Tracked as a follow-up issue, not blocking.
3. **Federated search across all four categories** — should a
   sibling app be able to issue ONE federated search across DCC
   + SPC + (read-only data-infra) at once? REQ-SPC-004 enables
   it at the envelope level, but a top-level orchestration
   entrypoint (e.g. `IntegrationRegistry::federatedSearch()`) is
   not yet specified. Deferred to a follow-up
   `add-openconnector-federated-search` change if a consuming
   need surfaces.
4. **Migration of existing adapters (`pdok`, `stuf`, etc.) to the
   category specs** — open question whether to land a cleanup
   change retrofitting them, or to leave them as legacy adapters
   predating the contract. Recommendation: leave them; flag
   inconsistencies in a future audit only if they cause concrete
   reviewer-time cost.
5. **EWC destructive-action vocabulary completeness** — REQ-EWC-005
   names `session-disconnect` and `device-wipe`; other vendors
   may surface destructive actions not on the canonical list
   (e.g. Citrix `application-uninstall`, Jamf `factory-reset`).
   Open whether to enumerate every action in the category spec
   or let per-adapter changes extend additively. Recommendation:
   per-adapter extension — keeps the category spec stable.
