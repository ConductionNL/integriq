# Spec: endpoint-workspace-connectors

**Status:** proposed
**Scope:** openconnector
**Tier:** connector-categories
**Depends on:** data-infra-connectors (openconnector, for the shared registration contract REQ-DIC-001/002), prometheus-metrics (openconnector), hydra ADR-019 (integration-registry), hydra ADR-022 (apps consume OR abstractions), hydra ADR-024 (app manifest), hydra ADR-031 (schema-declarative business logic), hydra ADR-005 (security)

## ADDED Requirements

### Requirement: Endpoint and virtual-desktop / workspace connectors SHALL register through the integration registry per ADR-019 (REQ-EWC-001)

Endpoint and virtual-desktop / workspace connectors MUST register through the
integration registry per ADR-019.
Every adapter for a virtual-desktop, app-virtualisation, or
endpoint-management platform — Citrix Virtual Apps and Desktops,
Citrix Cloud, VMware/Omnissa Horizon, Amazon WorkSpaces, Azure
Virtual Desktop (AVD), Windows 365, Nutanix Frame, Liquit /
Recast, Microsoft Intune, Jamf Pro, VMware Workspace ONE UEM —
MUST be implemented as an `IntegrationProvider` registered
through OR's integration registry per ADR-019. Adapter classes
MUST live under `lib/Service/Adapter/EndpointWorkspace/` and
MUST NOT be embedded in any sibling app (mydash, etc.).

#### Scenario: Reviewer confirms no per-app workspace SDK in sibling apps

- **GIVEN** any sibling app's `lib/Service/` tree
- **WHEN** scanned for direct imports of
  `Citrix\\*`, `VMware\\Horizon\\*`, `Aws\\WorkSpaces\\*`,
  `Microsoft\\Graph\\DeviceManagement\\*`, `Jamf\\*`, or any
  vendor-specific endpoint-management client
- **THEN** no such imports SHALL exist; the capability MUST be
  consumed from openconnector by integration slot slug.

### Requirement: Each EWC adapter manifest entry SHALL declare a fixed capability vocabulary scoped to session-enumeration, user-mapping, and audit-event ingestion (REQ-EWC-002)

Each EWC adapter manifest entry MUST declare a fixed capability vocabulary.
Per ADR-024 (extending the REQ-DIC-002 manifest shape), every
EWC adapter MUST publish a manifest entry with
`category: endpoint-workspace`, an explicit `subCategory` of
one of `virtual-desktop`, `app-virtualization`,
`endpoint-management`, `mobile-device-management`, and a
`capabilities[]` drawn from the canonical vocabulary:

| Capability | Meaning |
|---|---|
| `session-enumerate` | List active user sessions / virtual desktops / running app launches |
| `session-disconnect` | Terminate a session by id (opt-in per source — see REQ-EWC-005) |
| `entitlement-resolve` | Resolve "what apps / desktops / devices is this user entitled to?" |
| `user-mapping` | Resolve a Nextcloud user to the upstream identity (UPN, sAMAccountName, GUID) per REQ-EWC-003 |
| `device-inventory` | Enumerate managed devices (Intune / Jamf / WS1) |
| `device-compliance` | Read device compliance posture |
| `audit-event-pull` | Pull audit/security events on a polling cadence |
| `audit-event-stream` | Receive audit/security events via webhook subscribe |
| `launch-deeplink` | Generate a launch URL or token a sibling app can hand to the end user |

Capabilities a given platform does not support MUST be omitted.
The manifest validator MUST reject any unknown literal.

#### Scenario: A Liquit / Recast adapter declares the right capability subset

- **GIVEN** the Liquit/Recast adapter manifest entry
- **WHEN** inspected
- **THEN** `capabilities[]` SHALL include
  `entitlement-resolve`, `user-mapping`, `launch-deeplink`;
  MAY include `audit-event-pull`; MUST NOT include
  `session-disconnect` unless the underlying API supports it.

#### Scenario: An Intune adapter declares device-side capabilities only

- **GIVEN** the Intune adapter manifest entry
- **WHEN** inspected
- **THEN** `capabilities[]` SHALL include `device-inventory`,
  `device-compliance`, `user-mapping`, `audit-event-pull`;
  MUST NOT include `session-enumerate` or `launch-deeplink`
  (Intune does not host sessions).

### Requirement: User mapping SHALL go through a single declarative `UserMapping` shape — never per-app PHP (REQ-EWC-003)

User mapping MUST go through a single declarative `UserMapping` shape.
Mapping a Nextcloud user to the upstream identity (e.g. an
Active Directory UPN, an Azure AD object id, an Intune managed
identity, a Jamf username) MUST be declared as a `UserMapping`
record on the openconnector source per the existing openconnector
`Mapping` abstraction (extended for EWC purposes) and per
ADR-031. The mapping MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `sourceSlug` | string | Yes | The openconnector source the mapping applies to |
| `ncUserAttribute` | enum | Yes | One of `uid`, `email`, `displayName`, `customClaim:<name>` — the NC-side key |
| `remoteUserAttribute` | enum | Yes | One of `upn`, `objectGuid`, `samAccountName`, `email`, `username`, `custom:<name>` — the remote key |
| `transformChain` | string[] | No | Optional list of named transforms (e.g. `lowercase`, `domain-substitute`, `regex-strip`) |
| `fallbackBehaviour` | enum | Yes | One of `error`, `skip`, `placeholder` — what happens when a mapping is missing |

Sibling apps MUST NOT author per-app user-mapping PHP services.
Per ADR-031, the mapping IS the logic — `MappingService::resolve()`
in openconnector is the single PHP entry point and it consumes
the declarative `UserMapping` records.

#### Scenario: Reviewer confirms no per-app user-mapping PHP

- **GIVEN** any sibling app's `lib/Service/` tree
- **WHEN** scanned for classes whose name matches
  `*UserMapping*Service*` / `*IdentityResolve*` / `*UpnResolver*`
- **THEN** no such classes SHALL exist; user mapping MUST go
  through openconnector's `MappingService::resolve()` via the
  EWC integration provider.

#### Scenario: A configured mapping resolves an NC user to an Intune device record

- **GIVEN** a `UserMapping` record with
  `ncUserAttribute: email`, `remoteUserAttribute: upn`,
  `transformChain: [lowercase]`
- **WHEN** an `entitlement-resolve` call comes in for NC user
  `Jan.de.Vries@municipality.nl`
- **THEN** the adapter MUST resolve to Intune UPN
  `jan.de.vries@municipality.nl` (lowercase transform applied)
  and return that user's device entitlements.

### Requirement: Audit-event ingestion SHALL deposit events as CloudEvents per ADR-022, not as openconnector-local event tables (REQ-EWC-004)

Audit-event ingestion MUST deposit events as CloudEvents per ADR-022.
When an EWC adapter declares `audit-event-pull` or
`audit-event-stream`, the inbound events MUST be normalised to
CloudEvents (per ADR-022 §"Events + webhooks") with type
`com.conduction.endpoint-workspace.<sub-category>.<event-kind>`
(e.g. `com.conduction.endpoint-workspace.virtual-desktop.session-started`)
and dispatched through NC's existing event dispatcher.

Sibling apps that need to react (e.g. mydash audit widget,
decidesk security board) MUST subscribe through the standard
CloudEvent contract. Openconnector MUST NOT author a new
event table specific to EWC events — every event lands in
the existing `CallLog` (for raw transport audit) and in the
CloudEvent dispatcher (for sibling-app reaction). Persistence
of the normalised event for long-term retention is the sibling
app's choice and MUST go through OR's audit-trail-immutable
or docudesk per ADR-022.

#### Scenario: A Citrix session-started event reaches mydash via CloudEvent

- **GIVEN** a Citrix source declaring `audit-event-stream`
- **WHEN** Citrix POSTs a session-started webhook
- **THEN** openconnector MUST normalise it to a CloudEvent of
  type `com.conduction.endpoint-workspace.virtual-desktop.session-started`,
  enrich with the resolved NC user via REQ-EWC-003, and dispatch;
  mydash (subscribed by event type) MUST receive it and render
  on its security widget — no openconnector-local event table
  created.

### Requirement: Destructive operations (session-disconnect, device-wipe, force-logout) SHALL be opt-in per source AND gated by an admin-configured action authorisation per ADR-023 (REQ-EWC-005)

Destructive operations MUST be opt-in per source AND gated by an
admin-configured action authorisation per ADR-023.
Per ADR-005 (security) and the in-flight ADR-023 (action-level
authorization), every mutative EWC capability that affects an
end-user device or session — `session-disconnect`, any
device-wipe, force-logout, app-uninstall — MUST be:

1. Opt-in per `Source` record via an explicit
   `enabledDestructiveActions: string[]` configuration field;
   omitting the action name MUST cause the adapter to throw
   `DestructiveActionDisabledException` on invocation.
2. Gated by an admin-configured action-to-group mapping per
   ADR-023 — the openconnector admin settings MUST list the
   destructive action and let the operator bind it to a
   Nextcloud group; the adapter MUST verify membership at
   invocation time.
3. Logged to the existing `CallLog` with outcome
   `destructive-action-invoked` regardless of success/failure;
   the audit entry MUST include the invoking NC user, the
   target identity, the action, and the source slug.

#### Scenario: A `session-disconnect` rejected because not opted in

- **GIVEN** a Citrix source with
  `enabledDestructiveActions: []` (default)
- **WHEN** any caller invokes `disconnectSession(...)`
- **THEN** the call MUST throw `DestructiveActionDisabledException`;
  **AND** an audit row MUST land in `CallLog` with outcome
  `destructive-action-rejected-disabled`.

#### Scenario: A `device-wipe` rejected because the caller is not in the bound group

- **GIVEN** an Intune source with
  `enabledDestructiveActions: ["device-wipe"]` AND the action
  bound to NC group `intune-emergency-responders`
- **WHEN** a user not in `intune-emergency-responders` invokes
  `wipeDevice(...)`
- **THEN** the call MUST throw
  `DestructiveActionUnauthorisedException` per ADR-023;
  **AND** the audit row MUST capture the rejected invocation;
  **AND** no remote-side state SHALL change.

### Requirement: Scheduled audit-event pulls SHALL run as OpenRegister `ScheduledWorkflow` records — no openconnector `TimedJob` per adapter (REQ-EWC-006)

Scheduled audit-event pulls MUST run as OpenRegister `ScheduledWorkflow` records.
Per REQ-DIC-005 (inherited shape across the category) and
ADR-031 §"Background jobs" path 2, every polling-mode
audit-event pull MUST be a `ScheduledWorkflow` record
referencing the adapter slug. Openconnector MUST NOT author a
per-adapter `TimedJob`. The workflow normalises the pulled
events into CloudEvents per REQ-EWC-004.

#### Scenario: Reviewer confirms no per-adapter TimedJob for audit pulls

- **GIVEN** the openconnector codebase
- **WHEN** scanned for classes extending `OCP\BackgroundJob\TimedJob`
  in `lib/BackgroundJob/` whose name matches
  `*Workspace*` / `*Endpoint*` / `*Intune*` / `*Jamf*` /
  `*Citrix*` / `*Horizon*` / `*Audit*Pull*`
- **THEN** no such classes SHALL exist; audit pulls MUST be
  driven by `ScheduledWorkflow` records.

### Requirement: Individual per-platform adapters are explicitly out of scope for this spec — each adapter MUST ship in its own `add-openconnector-{slug}-adapter` change (REQ-EWC-007)

Individual per-platform EWC adapter implementations MUST be out of scope for this spec.
Per REQ-DIC-007 / REQ-DCC-007, individual EWC adapter
implementations (e.g. Citrix, Horizon, Intune, Jamf, Liquit)
MUST land as separate openspec changes. Per-adapter changes
MUST cite REQ-EWC-001 through REQ-EWC-006 and MUST NOT
re-derive the category-level contract.

#### Scenario: A new Intune adapter change references this spec

- **GIVEN** a new change folder
  `openspec/changes/add-openconnector-intune-adapter/`
- **WHEN** its proposal.md is inspected
- **THEN** the `Depends on` line MUST include
  `endpoint-workspace-connectors (openconnector)`; the proposal
  MUST cite REQ-EWC-002 (capabilities), REQ-EWC-003 (user
  mapping), REQ-EWC-005 (destructive-action authorisation),
  and REQ-EWC-006 (no per-app TimedJob) by REQ id.
