# or-integration-provider

## Why

OpenRegister ships a pluggable `IntegrationProvider` registry (OR change
`pluggable-integration-registry`, AD-1..AD-23): each app that owns a "linked
thing" registers a provider class with OR's `IntegrationRegistry` at app boot,
and OR's object detail pages surface those registrations as **leaves** in the
object's sidebar. Built-in leaves cover audit-trail / tags / files / notes;
external leaves attach NC-native entities (Talk, XWiki, Polls, Photos,
Collectives, …). See `openregister/lib/Service/Integration/IntegrationProvider.php`.

OpenConnector was absent from that registry. It stores `SynchronizationContract`
objects that point at OR objects (via `targetId`) but nothing surfaced the link
*back* to the data object — a user opening a synced record anywhere in the fleet
had no way to see *"this came from External API X via sync Y, last pulled 2 days
ago"*. The provenance existed in the sync engine but was invisible at the point
of consumption.

Per the fleet leaf rule (integration-provider implementations live in the owning
app and plug into OR's core registry — see `hydra` ADR-019), the provider belongs
in **openconnector**, not in OR core and not in each leaf app. This change is the
canonical spec for that capability (GitHub issue openconnector#824). The
implementation is already shipped on `development`
(`lib/Service/Integration/SynchronizationContractProvider.php`, registered in
`lib/AppInfo/Application.php::boot()`); this spec formalises the contract it
fulfils.

## What Changes

- Add the `or-integration-provider` capability: openconnector registers a
  `SynchronizationContractProvider` implementing OR's
  `\OCA\OpenRegister\Service\Integration\IntegrationProvider` contract (via the
  `AbstractIntegrationProvider` base) with OR's `IntegrationRegistry` at app
  boot.
- The provider declares stable metadata — id `sync-contract`, label
  *"Synced from"*, icon `SyncOutline`, group `workflow`, required app
  `openconnector`, storage strategy `query-time` — so OR's three-stage filter
  can discover, route, and render it.
- On `list(register, schema, objectId, filters)` the provider queries OR
  storage for `synchronization_contract` objects whose `targetId === objectId`
  and returns lightweight sidebar summaries (sync display-name, last-synced
  subtitle, deep-link into the OpenConnector synchronization detail page, plus
  raw provenance fields).
- The provider is **read-only** (`query-time`): `get`/`create`/`update`/`delete`
  inherit the `AbstractIntegrationProvider` default that throws
  `NotImplementedException` — contracts are managed by the sync engine, not by
  end users.
- Availability is gated on the chain-C OR-cutover: `isEnabled()` and `health()`
  read the `openconnector.storage_migrated` app-config flag so the leaf only
  appears once SyncContract objects actually live in OR storage.
- Registration soft-fails: if OR's `IntegrationRegistry` class is unavailable
  the boot hook is a no-op, so openconnector still boots on an instance without
  a compatible OpenRegister.

Spec-only change documenting shipped code; no code changes ship with this
proposal.

## Capabilities

### New Capabilities
- `or-integration-provider`: openconnector implements OpenRegister's pluggable
  `IntegrationProvider` contract and registers a read-only, query-time
  `SynchronizationContractProvider` with OR's `IntegrationRegistry`, so that
  every OR object synced by openconnector surfaces a *"Synced from"* provenance
  leaf in its sidebar across the fleet.

## Impact

- **File (shipped):** `lib/Service/Integration/SynchronizationContractProvider.php`
  — the provider implementation.
- **File (shipped):** `lib/AppInfo/Application.php::boot()` — registers the
  provider with `IntegrationRegistry` (soft-fail when OR is absent).
- **Runtime dependency:** OpenRegister must expose
  `\OCA\OpenRegister\Service\Integration\IntegrationRegistry` and the
  `IntegrationProvider` contract. Registration is guarded by `class_exists()` so
  an older OR degrades gracefully (no leaf, no fatal).
- **Cross-app UX:** the leaf renders in *any* app's OR object sidebar
  (opencatalogi, decidesk, openconnector itself, …) with no per-app coupling.
- **No new tables, routes, or Vue changes in openconnector** — the leaf is
  rendered by OR / nc-vue from the registry metadata; the provider is
  query-time, so it adds no persistence.

## Caveats

- **Gated on the storage migration.** The leaf is invisible until
  `openconnector.storage_migrated === 'true'` (set by the chain-C cutover
  migration). Before that, `list()` returns `[]` and `health()` reports
  `status: unavailable` with a message pointing at `occ upgrade`.
- **RBAC is inherited, not re-checked.** `requiresPermission()` returns null:
  contract visibility rides on the underlying OR object's RBAC — a user who can
  see the object can see where it was synced from. The provider adds no
  independent authorization surface.
- **`resolveSynchronizationName()` best-effort.** A deleted or unreadable
  synchronization falls back to a short-id label (`Synchronization <8-char>`);
  the leaf never fails because a sync name can't be resolved.
- **query-time, so no mutation.** All CRUD verbs throw `NotImplementedException`
  by design — the sync engine owns contract lifecycle. A future iteration could
  additionally expose Consumer/EventSubscription as leaves (local ADR-013).
