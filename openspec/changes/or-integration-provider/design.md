# Design — or-integration-provider

## Context

OpenRegister's `pluggable-integration-registry` change established a generic
"linked things" contract so any app can attach a leaf to an OR object without OR
core knowing about that app. The contract
(`openregister/lib/Service/Integration/IntegrationProvider.php`) speaks in terms
of metadata methods (`getId`, `getLabel`, `getIcon`, `getGroup`,
`getRequiredApp`, `getStorageStrategy`, `getOpenConnectorSource`, `isEnabled`,
`requiresPermission`, `authRequirements`, `health`) plus a CRUD-shaped data
surface (`list`/`get`/`create`/`update`/`delete`). Providers register with a
single per-request `IntegrationRegistry` via `addProvider()` from their owning
app's bootstrap.

Integriq's synchronization engine writes a `SynchronizationContract` per
(source-object → target-object) pairing. Post chain-B/C cutover these contracts
live as OR objects under register `openconnector`, schema
`synchronization_contract`, carrying `targetId` (the OR object that was synced
into), `synchronizationId`, `originId`/`originHash`, and `targetLast*`
timestamps. The provenance is fully present in OR storage — it just wasn't
projected onto the target object's sidebar.

## Goals / Non-Goals

**Goals**
- Surface a read-only *"Synced from"* leaf on every OR object integriq has
  synced, across the whole fleet, with zero per-leaf-app coupling.
- Reuse the existing contract objects as the data source — no parallel link
  table, no duplicated state.
- Fail closed and quietly when the environment isn't ready (OR absent, migration
  not run).

**Non-Goals**
- No mutation of contracts from the sidebar (the sync engine owns lifecycle).
- No new REST endpoints or Vue components in integriq — rendering is OR /
  nc-vue's job driven by registry metadata.
- No independent RBAC surface — inherit the object's access control.
- Consumer / EventSubscription leaves are explicitly deferred (local ADR-013).

## Decisions

### D1 — Provider lives in integriq, extends OR's `AbstractIntegrationProvider`

The provider is `OCA\Integriq\Service\Integration\SynchronizationContractProvider`
extending `OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider`. The
owning app hosts the provider (fleet leaf rule / ADR-019) because only
integriq knows the contract schema and how to resolve a synchronization's
display name. Extending the abstract base means the read-only verbs
(`get`/`create`/`update`/`delete`) inherit the `NotImplementedException` default
for free.

### D2 — `query-time` storage strategy, not `link-table` or `external`

The four strategies are `magic-column | link-table | external | query-time`.
Contracts already live in OR storage, so there is nothing to store in a parallel
table (rules out `link-table`/`magic-column`) and no live upstream call is made
per render (rules out `external`, which would route through
`ExternalIntegrationRouter` and require a declared OpenConnector source).
`query-time` is exactly right: the registry reads live from OR on each `list()`,
and the base's mutation-verb defaults throw, which the interface *mandates* for
query-time providers.

### D3 — Metadata contract

| Method | Value | Rationale |
|---|---|---|
| `getId()` | `'sync-contract'` | stable, kebab-case, unique in the registry |
| `getLabel()` | `l10n->t('Synced from')` | translated; registry does not call `t()` |
| `getIcon()` | `'SyncOutline'` | MDI name (no `mdi-` prefix); nc-vue resolves it |
| `getGroup()` | `'workflow'` | clusters with process/automation leaves |
| `getRequiredApp()` | `'integriq'` | stage-1 existence filter |
| `getStorageStrategy()` | `'query-time'` | see D2 |
| `getOpenConnectorSource()` | `null` (base default) | only `external` providers declare one |
| `requiresPermission()` | `null` (base default) | inherit object RBAC (D5) |

### D4 — `list()` query shape and the register/schema-context trap

Context is set via `objectService->setRegister('openconnector')->setSchema('synchronization_contract')`
and the `findAll()` `filters` carry **only** `targetId => $objectId`. Passing
`register`/`schema` *inside* `filters` sets context but *also* leaks them as
object-property filters — slug strings compared against the numeric
register/schema columns — which silently matches nothing. Pagination maps
`_limit` / `_page` onto `limit` / `offset` (page size 50). Each contract is
projected to a generic-card row (`id`, `title` = sync name, `subtitle` = last-sync
summary, `url` = SPA deep-link) plus raw provenance fields
(`synchronizationId`, `originId`, `originHash`, `targetLast*`, `sourceLastChecked`)
for bespoke consumers. Sync display-names are resolved via a per-call memo to
avoid N+1 lookups against the `synchronization` schema.

### D5 — Availability gated on `storage_migrated`; RBAC inherited

`isEnabled()` returns `appConfig->getAppValueString('storage_migrated', 'false') === 'true'`.
Before the chain-C cutover materialises contracts in OR, `list()` short-circuits
to `[]` and `health()` reports `status: unavailable` with an operator-facing
message. `requiresPermission()` stays null so contract visibility rides entirely
on the target object's RBAC — no second, drift-prone authorization surface.

### D6 — Soft-fail registration

`Application::boot()` guards the registry call with
`class_exists(IntegrationRegistry::class)` and a try/catch, so integriq
boots cleanly on an instance whose OpenRegister predates the pluggable registry
(no leaf, no fatal) — matching the AppHost load-order lesson that a missing OR
symbol must never brick the app.

## Risks / Trade-offs

- **query-time read on every sidebar render** — bounded by the `targetId`
  filter + page size 50 and OR's own object-query indexing; acceptable for a
  sidebar leaf. If a hot object accrues thousands of contracts, pagination
  caps the payload.
- **Name resolution depends on a second query** — mitigated by the per-call
  memo and a graceful short-id fallback; a missing sync never breaks the leaf.
- **Coupling to OR internals** (`ObjectService`, `IntegrationRegistry`,
  `AbstractIntegrationProvider`) — inherent to the fleet leaf pattern; the
  `class_exists` guard keeps it non-fatal.
