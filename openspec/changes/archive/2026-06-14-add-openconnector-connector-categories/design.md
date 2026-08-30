# Design — OpenConnector Connector Categories

## Context

openconnector's adapter inventory is about to expand by dozens of
external-system connectors across four well-defined categories
that Specter discovered from market intelligence:

- **data-infra** — databases, NoSQL, warehouses, event streams,
  object stores
- **document-cms** — external DMS / CMS
- **endpoint-workspace** — virtual desktop / app virt / endpoint
  management / MDM
- **saas-productivity** — SaaS productivity, work management,
  ITSM, CRM, NL/EU ERP

Today openconnector ships per-system adapter changes
(`add-pdok-adapter`, `stuf-adapter`, `dso-omgevingsloket`,
`ibabs-notubiz-connector`) without a written category-level
contract. Each new adapter re-derives the registration shape by
copy-paste from an arbitrary predecessor; sibling apps consume
adapters by reading individual classes; reviewers gate on
inconsistent expectations.

This change codifies the **producer-side contract** that
per-adapter changes plug into. It is the openconnector equivalent
of what shillinq's T4 envelope did for bookkeeping: ship the
foundation shape in one multi-capability change, with per-leaf
implementations following separately.

The change is **spec-only**. Implementation lands later through
per-adapter `add-openconnector-{slug}-adapter` cycles and the
standard Hydra pipeline; this doc explains *why* the shape of
each capability is what it is.

## Goals

- Express every connector-category surface as **declarative
  metadata** — manifest entries + DI-tagged provider classes per
  ADR-019 + per-source configuration JSON — per ADR-031. No new
  per-category service classes beyond what the integration
  registry already requires.
- Consume every OpenRegister abstraction that already exists for
  the integration registry, `ScheduledWorkflow`, CloudEvent
  dispatch, audit-trail-immutable, RBAC — per ADR-022. No
  reimplementation in openconnector.
- Consume docudesk for every Conduction-side document persistence
  — per ADR-022. openconnector is a transport for files, not a
  store.
- Keep the four category specs narrow enough that per-adapter
  changes can land independently and in parallel without
  cross-coupling.
- Make each category spec a **competent-integrator readable
  contract** — a developer onboarding a new adapter should be
  able to read the relevant category spec end-to-end and produce
  a compliant per-adapter change without reading any other
  category's spec.

## Non-Goals

- No individual adapter implementations (Snowflake, SharePoint,
  Citrix, Jira, Exact, etc.) — those land in
  `add-openconnector-{slug}-adapter` follow-up changes.
- No migration of existing legacy adapters (`pdok`, `stuf`, etc.)
  to the new category contract. They predate it; opportunistic
  cleanup is a separate change.
- No frontend Vue components for category-level admin — the
  integration registry's existing admin surface (per ADR-019)
  already drives this.
- No new `prometheus-metrics` endpoint — extended additively per
  REQ-DIC-006.
- No new OR abstractions — every requirement composes existing
  primitives (integration provider, ScheduledWorkflow, CloudEvent,
  Source, Mapping, CallLog, audit-trail).

## Decisions

### D1 — Declarative-first, per ADR-031, across all four categories

Every category-level behaviour expressible as manifest metadata or
schema declaration MUST be declared in `src/manifest.json` or in
the integration provider's DI tag, not authored as a
category-specific service class. Concretely:

| Behaviour | Declarative form |
|---|---|
| Adapter registration | DI tag `IntegrationProvider` per ADR-019 |
| Adapter capability vocabulary | `connectors[].capabilities[]` in `src/manifest.json` per ADR-024 |
| Adapter auth modes | `connectors[].authModes[]` |
| Adapter rate limits | `connectors[].rateLimits` |
| Adapter polling vs push posture | `connectors[].pollingMode` |
| Adapter schema-discovery strategy | `connectors[].schemaDiscovery` |
| Per-source configuration (e.g. SharePoint `readOnly`, `aclWriteBack`) | source-record JSON configuration in openconnector |
| Per-source enabled destructive actions (EWC) | source-record `enabledDestructiveActions[]` |
| Per-source enabled mutative bulk actions (SPC) | source-record `enabledMutativeBulkActions[]` |
| User mapping shape (EWC) | `UserMapping` records on the source per REQ-EWC-003 |
| Webhook → CloudEvent normalisation type | adapter manifest entry + standard CloudEvent contract per ADR-022 |
| Scheduled adapter pulls | OR `ScheduledWorkflow` referencing the adapter slug per ADR-031 §"Background jobs" path 2 |
| Audit of every adapter invocation | existing openconnector `CallLog` |
| Metrics extension for invocations | `openconnector_adapter_invocations_total` on the existing `/api/metrics` per REQ-DIC-006 |

**Alternative considered**: Author four category-level service
classes (`DataInfraConnectorService`, `DocumentCmsConnectorService`,
`EndpointWorkspaceConnectorService`, `SaasProductivityConnectorService`)
that wrap the integration registry per category. Rejected per
ADR-031 + ADR-019 — that would replicate exactly the anti-pattern
the integration registry was created to eliminate. Per ADR-019,
sibling apps reach the integration registry directly; per-category
wrappers add no value and create a maintenance surface that drifts.

### D2 — The registration contract is uniform across all four categories

REQ-DIC-002 defines the minimum manifest field set
(`id`, `category`, `subCategory`, `adapterClass`, `label`, `icon`,
`authModes`, `capabilities`, `rateLimits`, `pollingMode`,
`schemaDiscovery`, `documentationUrl`). The other three category
specs inherit this shape — they extend `capabilities[]` with
category-specific literals (DMS adds `acl-bridging` etc.; EWC
adds `session-enumerate` etc.; SPC adds `record-crud` etc.) and
they extend the source-record configuration JSON with
category-specific fields (DMS adds `aclWriteBack`; EWC adds
`enabledDestructiveActions[]`; SPC adds
`enabledMutativeBulkActions[]`).

This is intentional. A developer onboarding a Citrix adapter
should not need to learn a different registration shape than a
Snowflake adapter author. The category-level differences are in
the **behavioural** contracts (what mutative actions exist, how
events normalise, where credentials live), not in the
**registration** contract.

**Alternative considered**: Let each category define its own
minimum field set. Rejected — that would force sibling apps to
discover adapters via category-specific paths rather than the
single integration-registry surface ADR-019 mandates.

### D3 — DCC scopes EXTERNAL DMS only; docudesk owns Conduction-side persistence

REQ-DCC-001 is explicit: every DCC adapter targets an EXTERNAL
DMS (SharePoint, Alfresco, Box, Drive, VIP-files, NLX, ...).
Documents that need to land on the Conduction side go through
docudesk per ADR-022 (REQ-DCC-006). openconnector is the
transport, not the store.

This split prevents openconnector from accidentally growing into
a parallel document store; it keeps docudesk as the single
Conduction-side home for files; it lets per-DCC-adapter authors
make a single clear architectural choice ("I stream bytes, I do
not persist").

**Alternative considered**: Let openconnector cache or persist
fetched documents under its app data directory for performance.
Rejected — that creates a synchronisation surface (docudesk vs
openconnector cache) and duplicates docudesk's retention,
RBAC, audit-trail behaviour. Per ADR-022, when a sibling app
provides the abstraction, the app consumes it. docudesk is the
file-storage abstraction.

### D4 — Credentials live in openconnector `Source` records, never in sibling apps

Every category spec restates this rule for its own audience
(REQ-DIC-003, REQ-DCC-003 implicit via ADR-019 reference,
REQ-EWC-005 implicit via opt-in mechanism, REQ-SPC-003 explicit
for OAuth). The rule comes from ADR-019 §"External integrations
route through OpenConnector" — OR's `ExternalIntegrationRouter`
delegates credential handling to openconnector by design.

This split makes credential rotation an openconnector operation
(no sibling-app deploy needed), keeps sibling-app data models
credential-free (auditor-friendly: no risk of leaking secrets via
register exports), and prevents the parallel-credential-table
anti-pattern that ADR-022 §"Anti-patterns" explicitly forbids.

### D5 — Mutative cross-system actions are opt-in per source AND group-gated per ADR-023

REQ-EWC-005 (EWC destructive actions like `session-disconnect`,
`device-wipe`) and REQ-SPC-006 (SPC mutative bulk operations like
`bulk-import`) share the same shape:

1. Per-source `enabled*Actions[]` field — operator must
   explicitly opt the action into the source.
2. Admin-configured action-to-group mapping per ADR-023 — the
   adapter verifies group membership at invocation.
3. `CallLog` audit row for every invocation (success, failure,
   rejected).

This is the canonical openconnector blast-radius pattern. An
operator deploying an adapter for the first time gets the
read-side capabilities for free; mutative cross-system actions
require two explicit opt-ins (per source + per group). Reviewers
auditing "what can this source do?" answer the question by
reading two configuration values.

**Alternative considered**: Make mutative actions a one-level
opt-in via the source's `readOnly: boolean` field (like DCC's
REQ-DCC-005). Rejected for EWC + SPC because the blast radius is
fundamentally different — a Conduction-side mistake reading a
file is recoverable; a Citrix `session-disconnect` or Salesforce
`bulk-import` is not. The two-level opt-in matches the operational
risk.

### D6 — DCC uses a one-level `readOnly` posture because the blast radius is bounded by DMS-side ACLs

REQ-DCC-005 uses a single `readOnly: boolean` source field
rather than the two-level opt-in of EWC + SPC. The rationale:
DMS-side ACLs already gate what the adapter's authenticated
identity can do on the remote system; the worst case for a
non-readonly DMS adapter is that the adapter does what the
authenticated DMS user is allowed to do. For EWC and SPC, the
adapter is typically running with an elevated service identity
(admin-level Citrix account, Salesforce integration user) whose
blast radius is larger than any individual end user.

### D7 — Scheduled adapter pulls run as OR `ScheduledWorkflow`, not as openconnector `TimedJob`

REQ-DIC-005 + REQ-EWC-006 (and implicit in the other two category
specs by inheritance). Per ADR-031 §"Background jobs" path 2,
the canonical periodic-trigger surface is `ScheduledWorkflow` +
the n8n adapter, not per-app `TimedJob` PHP classes.

This unifies the scheduling surface across the fleet: operators
manage all periodic adapter pulls through OR's
`ScheduledWorkflow` UI (the same UI shillinq uses for ECB rate
ingestion and decidesk uses for overdue-action calculation). No
per-app cron, no per-app `cron-*` script, no per-app worker pool.

**Alternative considered**: Keep `TimedJob` for adapter pulls
because openconnector already has the pattern in legacy adapters.
Rejected — see Risk 6. The legacy adapters stay as-is; new
adapters land on the unified surface.

### D8 — Webhook events normalise to CloudEvents, never to category-specific event tables

REQ-EWC-004 + REQ-SPC-005 (and applicable to any DIC adapter
declaring `subscribe-cdc` / `subscribe-events`). Inbound webhook
events from every category normalise to CloudEvents of type
`com.conduction.<category>.<sub-category>.<event-kind>` and
dispatch through NC's existing event dispatcher per ADR-022.

openconnector MUST NOT author a per-category event table.
Sibling apps that need to react subscribe through the standard
CloudEvent contract. Persistence for long-term retention (where a
sibling app needs to keep an event log past the live dispatch) is
the sibling app's choice and MUST route through OR's
audit-trail-immutable or docudesk per ADR-022.

This keeps the openconnector data model bounded: one `CallLog`
table for raw transport audit, plus the existing `Source`,
`Mapping`, `Synchronization`, `Job` tables. No new tables per
category.

### D9 — The federated-search hit envelope is shared across DCC and SPC; DIC stays out of search

REQ-DCC-004 defines the base hit envelope; REQ-SPC-004 extends it
with `entityType` / `recordKey` / `actorLabel` for record-shaped
hits. The two specs share the field set so a sibling app can
issue ONE federated search across BOTH DMS and SaaS sources and
get a uniformly-shaped result list.

DIC adapters do NOT participate in federated search at the
category-spec level (data-infra is structured query, not
unstructured search). A future
`add-openconnector-federated-search` change MAY add a DIC-side
hit shape if a consuming need surfaces; until then the envelope
is DCC + SPC only.

**Alternative considered**: Per-category hit envelopes. Rejected
— a sibling app's UX ("show me hits for `'customer onboarding'`
across all my sources") is value-add only when results merge.
Per-category envelopes force the sibling app to render N lists
or re-normalise, both of which are anti-patterns the category
spec is meant to prevent.

### D10 — The category specs are deliberately verbose about reviewer-gate scenarios

Every REQ has at least one "Reviewer confirms ..." scenario that
names the grep pattern the Hydra reviewer (or a human reviewer)
should run. This is intentional. The category specs are the
single place a reviewer reads to know what to gate on for a
per-adapter PR; the scenarios make the gates mechanical rather
than judgemental.

Per `feedback_use-hydra-skills-not-generic-agents.md`, this is
how the Hydra builder / reviewer pair is expected to consume the
contract — by following the spec scenarios as a checklist, not
by inferring the contract from prose.

## Reuse Analysis

| Capability needed | What already exists | Connector-categories reuse strategy |
|---|---|---|
| Adapter registration to a pluggable registry | OR integration registry per ADR-019, shipped in `openregister/openspec/changes/pluggable-integration-registry/` | Consumed via DI tag `IntegrationProvider`; no parallel registry in openconnector |
| Manifest-driven adapter discovery | nextcloud-vue manifest renderer per ADR-024, `useAppManifest()` in `@conduction/nextcloud-vue` | Consumed via `connectors[]` top-level block in `src/manifest.json` |
| Source/credential storage | openconnector `Source` registry (mature) | Consumed by REQ-DIC-003 et al; credentials never leave openconnector |
| Adapter rate limits | openconnector existing rate-limit middleware | Consumed via `connectors[].rateLimits` |
| OAuth flow hosting | openconnector existing OAuth handler | Consumed for SPC `oauth2` adapters per REQ-SPC-003 |
| Per-user OAuth tokens | openconnector `Source` per-user extension (verify production-ready in first SPC per-adapter cycle) | Consumed if available; otherwise OR issue + omit `oauth-userlevel` |
| Webhook receiver endpoints | openconnector existing webhook layer | Consumed for `push` / `subscribe-*` capabilities |
| Scheduled adapter pulls | OR `ScheduledWorkflow` + n8n adapter per ADR-031 path 2 | Consumed for every periodic pull; no per-adapter TimedJob |
| Audit of every invocation | openconnector existing `CallLog` table | Consumed for the transport-side audit; sibling-app long-term retention routes through OR audit-trail-immutable per ADR-022 |
| Operational metrics | `prometheus-metrics` spec (REQ-PROM-001..004) | Extended additively per REQ-DIC-006; no new endpoint |
| Schema-discovery output | OR `Mapping` abstraction | Adapter-discovered schemas materialise as OR Schema objects via the Mapping path |
| Federated-search hit normalisation | none yet (new contract from REQ-DCC-004 + REQ-SPC-004) | New contract authored in this change; reused across DCC + SPC |
| User mapping (EWC) | openconnector existing `Mapping` abstraction | Extended for EWC purposes via `UserMapping` records (REQ-EWC-003); per ADR-031, the mapping IS the logic |
| Webhook → CloudEvent normalisation | OR CloudEvent dispatcher per ADR-022 | Consumed across all four categories; no per-category event table |
| ACL bridging (DCC) | OR RBAC + new DCC adapter-level surface (REQ-DCC-003) | Read-only by default; write-back opt-in per source |
| Conduction-side document persistence | docudesk file API | Consumed for any DCC/SPC attachment that must persist locally |
| Destructive-action authorisation | ADR-023 action-to-group mapping (in-flight) | Consumed for EWC `session-disconnect` etc. and SPC `bulk-import` etc.; falls back to per-source opt-in until ADR-023 lands |
| Admin UI for adapter administration | OR integration registry's existing admin surface per ADR-019 | Consumed; no new admin Vue components per category |
| i18n for adapter labels | per ADR-024 §6, manifest `label` is an i18n key consumed by the app's `t()` | Consumed; adapters ship keys, not literals |

**Net new code in the category-spec deltas**: zero. Net new code
in each follow-up `add-openconnector-{slug}-adapter`: one
`IntegrationProvider` class, one manifest entry, one DI tag,
optionally one seed source JSON, optionally one journeydoc tutorial.

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, each connector-category behaviour was
classified before this spec was finalised. Single big table here
so the reviewer can sweep all four categories in one read.

| Category | Behaviour | Decision | Why |
|---|---|---|---|
| data-infra | Adapter registration | Declarative (DI tag per ADR-019) | Existing pluggable registry |
| data-infra | Manifest entry shape | Declarative (`src/manifest.json` per ADR-024) | Existing renderer |
| data-infra | Credential storage | Consumed from openconnector `Source` registry (ADR-019) | Existing abstraction |
| data-infra | Scheduled pulls | OR `ScheduledWorkflow` (ADR-031 path 2) | Periodic external trigger |
| data-infra | Push subscriptions | Existing webhook layer + CloudEvent normalisation (ADR-022) | Existing abstraction |
| data-infra | Schema discovery | Declarative (`schemaDiscovery: introspection/manifest/none`) | Per-adapter choice expressed as manifest metadata |
| data-infra | Metrics | Declarative extension on existing `/api/metrics` (REQ-DIC-006) | Existing abstraction |
| document-cms | Adapter registration | Same as data-infra | Inherited shape |
| document-cms | Capability vocabulary | Declarative (closed enum extending REQ-DIC-002) | Schema enum |
| document-cms | ACL bridging read | Declarative (per-source `aclWriteBack: false` default) | Single boolean |
| document-cms | ACL bridging write-back | Declarative opt-in per source | Single boolean |
| document-cms | Federated search hit envelope | Declarative (new shared contract per REQ-DCC-004) | Schema declaration |
| document-cms | Read-only posture | Declarative (per-source `readOnly` field) | Single boolean |
| document-cms | Conduction-side document persistence | Consumed from docudesk (ADR-022) | Existing abstraction |
| endpoint-workspace | Adapter registration | Same as data-infra | Inherited shape |
| endpoint-workspace | Capability vocabulary | Declarative (closed enum) | Schema enum |
| endpoint-workspace | User mapping | Declarative (`UserMapping` records on source) | Per ADR-031, the mapping IS the logic |
| endpoint-workspace | Audit events | CloudEvent normalisation (ADR-022) | Existing abstraction |
| endpoint-workspace | Destructive-action opt-in | Declarative (per-source `enabledDestructiveActions[]`) | Schema enum |
| endpoint-workspace | Destructive-action group binding | Consumed from ADR-023 action-to-group mapping (in-flight) | Existing abstraction (in-flight) |
| endpoint-workspace | Scheduled audit pulls | OR `ScheduledWorkflow` (ADR-031 path 2) | Periodic external trigger |
| saas-productivity | Adapter registration | Same as data-infra | Inherited shape |
| saas-productivity | Capability vocabulary | Declarative (closed enum) | Schema enum |
| saas-productivity | OAuth flow hosting | Consumed from openconnector OAuth handler (existing) | Existing abstraction |
| saas-productivity | Per-user OAuth tokens | Consumed from openconnector `Source` per-user extension | Existing abstraction (verify production-ready in first SPC per-adapter cycle) |
| saas-productivity | Search hit envelope | Declarative (extends REQ-DCC-004 with three fields) | Schema declaration |
| saas-productivity | Webhook events | CloudEvent normalisation (ADR-022) | Existing abstraction |
| saas-productivity | Mutative bulk opt-in | Declarative (per-source `enabledMutativeBulkActions[]`) | Schema enum |
| saas-productivity | Mutative bulk group binding | Consumed from ADR-023 action-to-group mapping (in-flight) | Existing abstraction (in-flight) |
| saas-productivity | Attachment persistence | Consumed from docudesk (ADR-022) | Existing abstraction |
| All categories | Audit trail of every invocation | Existing openconnector `CallLog` | Existing abstraction |
| All categories | Metrics | Existing `/api/metrics` per `prometheus-metrics` spec | Existing abstraction |
| All categories | Admin UI | OR integration registry admin surface per ADR-019 | Existing abstraction |

No new service class is authored by this change. Every per-adapter
follow-up change authors exactly one `IntegrationProvider` class
(per ADR-019) plus its manifest entry plus its DI tag. PHP guards
remain a legitimate ADR-031 exception when an adapter needs a
single-method `~20 LOC` glue (e.g. a CMIS error-code translator,
a Salesforce SOQL escape helper); per-adapter changes cite the
exception in their own design.md.

## Seed Data

Category-spec deltas ship NO seed data. Per-adapter follow-up
changes MAY ship one seed source JSON file each under
`lib/Settings/seeds/sources/{slug}.json` — a paused
example-source record so operators can see what a configured
source looks like for that adapter. Format follows the existing
openconnector `Source` shape.

No category-spec-level seed data exists because there is nothing
category-wide to seed:

- The `connectors[]` manifest block is per-adapter, not per-category.
- The integration-registry providers are per-adapter, not per-category.
- The `Source` records are per-vendor + per-deployment, not per-category.

Future per-adapter seeds carry an `_meta` block (per the same
convention as shillinq T4 seeds):

```
{ "_meta": { "source": "openconnector-adapter-example",
             "adapter": "snowflake",
             "imported": "<iso-timestamp>" } }
```

And ship in `lifecycleState: paused` so operators review and
activate.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Category specs overconstrain per-adapter changes | `capabilities[]` vocabulary is closed within a category but extensible by additive delta; per-adapter authors file a category-spec delta when a missing literal blocks them, not a workaround in their adapter |
| OAuth-first rule (REQ-SPC-003) blocks legacy SaaS adapters | Spec permits `serviceAccountJwt` + `apiKey`; deprecated `basicAuth` documented per-adapter with ADR-005 exception |
| ADR-023 dependency is in-flight; mutative-action gating may need rework | Category specs cite ADR-023 by reference but specify per-source opt-in mechanism declaratively; absorbs ADR-023's final shape via small delta |
| Federated-search score normalisation can't always reach 0..1 | Adapters with raw scores apply per-adapter normalisation; impossible cases use deterministic placeholder (ordinal rank) and document |
| Per-user OAuth tokens (REQ-SPC-003 `oauth-userlevel`) require OR-side surface that may not be production-ready | Category spec describes contract; gap → OR issue + omit `oauth-userlevel` from affected per-adapter manifest entries; sibling app surfaces "connect your account" prompt |
| "No per-adapter TimedJob" rule cuts against legacy `pdok` / `stuf` pattern | Rule applies to NEW adapters only; legacy adapters keep their TimedJobs; optional follow-up cleanup change |
| Reviewer-gate scenarios become out of date as ADR-023 lands | Category specs are the single source of truth; ADR-023 landing produces one delta change against this spec rather than fanning out across every per-adapter change |
| Sibling apps consume an in-flight category spec before it stabilises | Specs ship `Status: proposed`; sibling apps consume only stabilised specs; per-adapter changes start as `Status: proposed` and stabilise alongside the first concrete consumer |

## Migration Plan

Spec-only — no runtime migration in this change. When per-adapter
implementations land:

1. Each per-adapter change patches `src/manifest.json` additively
   with one `connectors[]` entry.
2. Each per-adapter change adds one DI tag line to
   `lib/AppInfo/Application.php`.
3. Each per-adapter change adds one `IntegrationProvider` class
   under `lib/Service/Adapter/{category}/`.
4. Each per-adapter change MAY add one source-seed JSON under
   `lib/Settings/seeds/sources/{slug}.json` for dev/test.
5. Each per-adapter change MAY add operator + developer docs
   under `docs/integrations/{slug}.md` per ADR-030.

Down-direction: each per-adapter rollback removes the manifest
entry, DI tag, provider class, optional seed, optional docs.
The category specs themselves remain valid; the catalog simply
has one fewer registered adapter.

Existing legacy adapters (`pdok`, `stuf`, `dso-omgevingsloket`,
`ibabs-notubiz-connector`) are NOT migrated by this change. They
continue to function as today. A separate
`openconnector-legacy-adapter-cleanup` change MAY retrofit them
later; recommendation in `proposal.md` Risk 6 is to leave them.

## Open Questions

1. **Per-user OAuth token storage in OR** — resolve in
   `opsx-ff` discovery on the first SPC per-adapter change
   (likely Microsoft 365 or Google Workspace). Spec stays
   shape-neutral until then.
2. **Manifest schema extension for `connectors[]`** — confirm
   with nextcloud-vue maintainers; file follow-up issue. Not
   blocking spec acceptance.
3. **Federated search across all four categories** — orchestration
   entrypoint deferred to a future
   `add-openconnector-federated-search` change.
4. **Legacy adapter migration** — recommend leaving them; revisit
   if reviewer-time cost surfaces.
5. **EWC destructive-action vocabulary completeness** — per-adapter
   additive extension; category spec stays stable.
6. **DIC participation in federated search** — deferred; the
   four-category federated search above resolves it.
