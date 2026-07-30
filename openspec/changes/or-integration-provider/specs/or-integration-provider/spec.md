# or-integration-provider Specification (delta)

---
status: proposed
---

## Purpose

OpenConnector implements OpenRegister's pluggable `IntegrationProvider` contract
and registers a read-only, query-time `SynchronizationContractProvider` with
OR's `IntegrationRegistry`, so that every OR object OpenConnector has synced
surfaces a *"Synced from"* provenance leaf in its sidebar across the fleet — with
zero per-leaf-app coupling, no new persistence, and target-object RBAC inherited.
The provider lives in OpenConnector (the owning app) per the fleet leaf rule
(hydra ADR-019). The implementation already ships on `development`; this delta
formalises the contract it fulfils (openconnector#824).

## ADDED Requirements

### Requirement: SynchronizationContract integration provider (REQ-OCIP-001)

OpenConnector MUST provide a `SynchronizationContractProvider` in
`lib/Service/Integration/` that extends OpenRegister's
`AbstractIntegrationProvider` (implementing the
`\OCA\OpenRegister\Service\Integration\IntegrationProvider` contract) and
declares stable metadata: id `sync-contract`, a translated label `Synced from`,
icon `SyncOutline`, group `workflow`, required app `openconnector`, and storage
strategy `query-time`.

#### Scenario: Provider is discoverable in the registry

- **GIVEN** an instance with a compatible OpenRegister and
  `openconnector.storage_migrated = true`
- **WHEN** OR's `IntegrationRegistry` enumerates providers for an object that
  OpenConnector has synced
- **THEN** the `sync-contract` provider is returned with its declared metadata
- **AND** OR renders a `Synced from` leaf in the object's sidebar.

### Requirement: Read-only, RBAC-inherited provider (REQ-OCIP-002)

The provider MUST be read-only: `get`, `create`, `update` and `delete` MUST
throw `NotImplementedException` (inherited from `AbstractIntegrationProvider`),
because synchronization contracts are owned by the sync engine, not by end
users. `requiresPermission()` MUST return null so that leaf visibility rides
entirely on the target object's RBAC and adds no independent authorization
surface.

#### Scenario: Mutation verbs are rejected

- **WHEN** any caller invokes `create`/`update`/`delete`/`get` on the provider
- **THEN** a `NotImplementedException` is thrown and no contract state changes.

#### Scenario: Visibility follows the object

- **GIVEN** a user who cannot read a synced OR object
- **WHEN** the sidebar is rendered for that object
- **THEN** the provider contributes no leaf content the user is not already
  entitled to see (no independent RBAC bypass).

### Requirement: Provenance projection via targetId query (REQ-OCIP-003)

On `list(register, schema, objectId, filters)` the provider MUST query OR
storage for `synchronization_contract` objects whose `targetId` equals the
object id, setting register/schema context through `setRegister()`/`setSchema()`
and filtering on `targetId` ONLY — register and schema MUST NOT be passed inside
the `filters` array (doing so leaks slug strings as property filters that match
nothing). It MUST map `_limit`/`_page` onto `limit`/`offset` (page size 50) and
project each contract to a generic-card row (`id`, `title` = resolved
synchronization name, `subtitle` = last-sync summary, `url` = deep-link into the
OpenConnector synchronization detail page) plus raw provenance fields
(`synchronizationId`, `originId`, `originHash`, `targetLast*`,
`sourceLastChecked`). Synchronization display-names MUST be resolved with a
per-call memo and MUST fall back to a short-id label when a synchronization is
unreadable, so the leaf never fails on name resolution.

#### Scenario: Synced object shows its origin

- **GIVEN** an OR object with a `synchronization_contract` whose `targetId`
  matches it
- **WHEN** the provider's `list()` runs for that object
- **THEN** it returns a row titled with the synchronization's name, subtitled
  with the last-sync time, deep-linking to the sync detail page
- **AND** an object with no contract returns an empty list.

### Requirement: Availability gated on storage migration (REQ-OCIP-004)

`isEnabled()` and `health()` MUST read the `openconnector.storage_migrated`
app-config flag and treat the provider as available only when it equals
`'true'`. Before cutover, `list()` MUST return `[]` and `health()` MUST report
`status: unavailable` with an operator-facing message, so the leaf appears only
once synchronization contracts actually live in OR storage.

#### Scenario: Hidden before cutover

- **GIVEN** `openconnector.storage_migrated = false`
- **WHEN** the sidebar renders and `health()` is polled
- **THEN** `list()` returns `[]` and `health()` reports `unavailable`.

### Requirement: Soft-fail registration when OpenRegister is absent (REQ-OCIP-005)

`Application::boot()` MUST guard the `IntegrationRegistry` registration with
`class_exists()` and a try/catch so that OpenConnector boots cleanly on an
instance whose OpenRegister predates the pluggable registry or is not installed
— resulting in no leaf and no fatal error.

#### Scenario: Boot on an incompatible OpenRegister

- **GIVEN** an instance without OR's `IntegrationRegistry` class
- **WHEN** OpenConnector boots
- **THEN** the registration is a no-op, no exception propagates, and the app
  functions normally without the provenance leaf.
