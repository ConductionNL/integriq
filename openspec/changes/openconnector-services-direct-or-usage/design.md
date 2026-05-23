# Design: openconnector-services-direct-or-usage

## Architecture Overview

```
                              ╔════════════════════════════════════════════╗
   BEFORE (chain B)            ║      AFTER (chain C, this change)            ║
                              ╚════════════════════════════════════════════╝

   HTTP /api/sources           ─→ Same HTTP endpoint, byte-identical response
        │                                            │
        ▼                                            ▼
   SourcesController            ────────────►   SourcesController (rewritten)
        │ uses SourceMapper            │             │ uses ObjectService directly
        ▼                              │             ▼
   SourceMapper                        │       OCA\OpenRegister\Service\ObjectService
   (chain-B facade)                    │             │
        │                              │             ▼
        ▼                              │      oc_openregister_objects
   ObjectMapperFacade                  │      (chain A schemas, chain B data)
        │                              │
        ▼                              ▼
   OCA\OpenRegister\Service\
   ObjectService                  THE TWO HOPS ABOVE COLLAPSE INTO ONE.
        │                         The facade and the mapper are DELETED.
        ▼                         No facade. No mapper. Just ObjectService.
   oc_openregister_objects
```

Storage layer is identical before and after — the data already lives in
OpenRegister thanks to chain B. The change is **purely a code-side
refactor** that removes the transitional layer above storage.

The 15 deleted entity types (`Source`, `Job`, `CallLog`, …) collapse into
the generic `\OCA\OpenRegister\Db\ObjectEntity`. The 15 deleted mappers
(`SourceMapper`, …) and the chain-B `ObjectMapperFacade` collapse into
`\OCA\OpenRegister\Service\ObjectService`.

## Context

- **Chain A** (`openconnector-register-schema-declaration`, done) declared
  the OR descriptor at `lib/Settings/openconnector_register.json` with 15
  schemas covering the openconnector domain.
- **Chain B** (`openconnector-register-storage`, done) migrated data from
  `oc_openconnector_*` tables to `oc_openregister_objects`, kept the 15
  `lib/Db/*Mapper.php` classes as a transitional facade
  (`ObjectMapperFacade`), and set the `openconnector.storage_migrated`
  app-config flag to `'true'` on success.
- **Chain C** (this change) is the textbook second step of a strangler-fig
  migration: remove the transitional abstraction now that the storage
  layer has stabilised. ADR-001's end-state is reached when chain C ships.
- **Constraint**: must not regress the external HTTP wire format. Vue
  stores and downstream apps depend on it.
- **Constraint**: must pass `composer check:strict` (PHPCS, PHPMD, Psalm,
  PHPStan) at every commit.
- **Constraint**: must preserve `SynchronizationService`'s 3-format
  `sourceId`/`targetId` branching logic verbatim.

## Goals

- Delete all 15 `lib/Db/*Mapper.php` files.
- Delete all 15 `lib/Db/<Entity>.php` files.
- Delete `lib/Service/Storage/ObjectMapperFacade.php`.
- Rewrite every caller in `lib/Service/`, `lib/Controller/`, `lib/Cron/`,
  `lib/AppInfo/Application.php` to inject and call
  `OCA\OpenRegister\Service\ObjectService` directly.
- Introduce 15 thin input DTO classes under `lib/Db/Dto/` for write-side
  validation.
- Preserve the external HTTP wire format byte-for-byte (chain B
  guarantees this via OR's `ObjectEntity::jsonSerialize()` over the chain
  A schema).
- Add a pre-flight assertion at app boot: `storage_migrated === 'true'`
  must hold; otherwise raise `\LogicException`.
- Add a quality gate (PHPCS custom sniff or regex linter) that fails the
  build if any deleted PHP type re-appears in a `use` statement.

## Non-Goals

- **No DB schema changes.** Storage is unchanged. The
  `oc_openconnector_*` tables remain on disk (until chain B's cleanup
  change ships).
- **No new REST endpoints.** Every controller endpoint is preserved.
- **No new external dependencies.** No new composer / npm package.
- **No Vue store changes.** Frontend code is untouched in this change.
- **No new OCC commands.** Chain B's `migrate-storage` command remains;
  no new commands are added.
- **No retention-constant cleanup** — that is
  `openconnector-adopt-or-abstractions`.
- **No controller architectural refactor** — chain C is a mechanical
  rewrite, not a thin-controller pattern adoption.

## Declarative-vs-imperative decision (ADR-031)

**All decisions in this change are imperative (refactor); no declarative
behaviour or annotations are introduced.**

ADR-031 distinguishes declarative work (schema descriptors, annotations,
seed JSON) from imperative work (PHP code, controllers, services). Chain
C is 100% imperative: it deletes code, rewrites callers, and adds DTO
classes. The declarative descriptor work for openconnector lives in
chain A (`lib/Settings/openconnector_register.json`), and chain C does
NOT touch that file. The data migration work lives in chain B
(`LegacyToRegisterMigrator`), and chain C does NOT touch that either.

There is no new declarative surface in this change. Specifically:
- No new schema, register, or capability is declared.
- No new `x-openregister-*` annotation is added.
- No new seed JSON is shipped (chain A owns seed data; chain C's
  consumers read seed data unchanged via `ObjectService`).
- No new event listener annotation is added (the existing OR-event
  listeners in `lib/EventListener/` are preserved unchanged; only their
  internal call sites change from mapper to ObjectService).

ADR-031's checklist for imperative changes is satisfied here:
- Imperative because the change is by definition a code refactor —
  cannot be expressed as data.
- No declarative alternative is reasonable (you cannot "delete a class
  declaratively").
- The imperative work is bounded and finite: ~40 files rewritten, 31
  deleted.

## Decisions

### D1: Direct `ObjectService` calls, NO new facade

**Chosen.** Every service/controller/cron is wired up to receive
`OCA\OpenRegister\Service\ObjectService` via constructor injection.
Callers invoke `find/findAll/saveObject/delete` with explicit
`'openconnector'` register slug and per-resource schema slug.

**Alternatives considered:**

- *Keep a thin per-resource facade* (e.g.
  `SourceRepository extends BaseRepository`). Rejected: pushes the
  abstraction back up by one layer; defeats ADR-001's "no app-side
  domain layer" rule. Adds 15 files we'd later delete.
- *Generic `OpenConnectorObjectService` wrapper that pre-binds
  `'openconnector'` register slug.* Rejected: marginal DI ergonomics
  improvement, but it's a layer of indirection that hides which schema
  is being accessed. The current ADR-001 idiom is explicit register +
  schema slugs at every call site.

### D2: Input DTOs (write-side only), `ObjectEntity::getObject()` on read

**Chosen.** 15 DTO classes under `lib/Db/Dto/<Resource>Dto.php`. Each
is `final`, has typed read-only properties, a `fromArray(array): self`
constructor, and a `toArray(): array` serialiser. Used by controllers
on write paths (`POST`, `PUT`) for input validation. Read paths use
`$obj->getObject()['key']` directly on the `ObjectEntity` returned by
`ObjectService::find()`.

**Alternatives considered:**

- *Typed entity classes on read too* (revive `Source`, `Job`, etc. as
  thin read-side wrappers). Rejected: this is exactly what chain B's
  facade did. Re-introducing it negates chain C's purpose.
- *No DTOs at all; raw `array` in/out.* Rejected: loses PHPStan
  type-checking on write paths; risk-2 mitigation requires SOME typed
  surface.
- *Auto-generate DTOs from chain A schemas at build time.* Considered;
  filed as **Issue C-001** as a follow-up. Manual DTOs ship in this
  change for traceability — the apply agent writes 15 PHP files
  alongside the rewrites. Future auto-generation can replace them
  without external API change.

### D3: Pre-flight assertion at app boot, not per-service

**Chosen.** A single `IAppConfig::getValue('openconnector',
'storage_migrated', 'false') === 'true'` check inside
`Application::register()`. On failure, raise `\LogicException` with the
operator-runbook command:

```
OpenConnector chain C requires storage_migrated === 'true'.
Run: occ openconnector:migrate-storage
```

An override env var `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1`
bypasses the check (for unit tests + fresh-install bootstrap scenarios
that handle their own migration).

**Alternatives considered:**

- *Per-service assertion.* Rejected: 20+ services × one assertion each
  = noise. App-boot-level is one assertion that all consumers share.
- *No assertion at all — fail loudly when the first ObjectService call
  errors.* Rejected: error surface is ambiguous (which call? which
  schema?). Operators would not know the root cause without reading
  Nextcloud logs.

### D4: Quality gate (PHPCS sniff or regex linter)

**Chosen.** A custom PHPCS sniff (preferred) or, if the sniff framework
proves too heavy, a `composer.json` `scripts:` entry running
`grep -rln "OCA\\\\OpenConnector\\\\Db\\\\(Source|…)" lib/ tests/` and
failing non-zero on any match. The list of forbidden types is the 30
deleted classes (15 entities + 15 mappers + the facade).

**Alternatives considered:**

- *PHPStan custom rule.* Rejected: too heavy for what is essentially a
  `grep`. PHPStan is already busy enforcing type correctness; piling
  type-presence checks on top wastes CI time.
- *No quality gate; rely on code review.* Rejected: the user's "fix all
  issues encountered" and "feedback_quality-workflow-patterns" rules
  point to automated gates as the canonical preventive mechanism.

### D5: Wire-format preservation via OR's `ObjectEntity::jsonSerialize()`

**Chosen.** Controllers return `$objectEntity->jsonSerialize()` directly
(or a `findAll` result mapped through `jsonSerialize()`). OR's
`jsonSerialize()` implementation outputs the legacy typed-entity shape
because chain A's schema declarations include the legacy property names
1:1.

**Alternatives considered:**

- *Re-introduce typed-entity hydration on read* purely to shape the
  response. Rejected: pulls back the deleted entity types under a
  different name; ADR-001 violation.
- *Add a controller-level response shaper that builds JSON manually.*
  Rejected: 18 controllers × hand-rolled shapers = 18 places to
  drift from the schema. OR's `jsonSerialize` is the canonical source
  of truth.

### D6: `SynchronizationService` keeps the 3-format branching helper

**Chosen.** Move the helper from chain B's `LegacyToRegisterMigrator`
(where it lived as `resolveSyncRef`) into a dedicated
`lib/Service/Helper/SyncRefResolver.php` service consumed by
`SynchronizationService`. Same regex chain, same skip-on-unrecognised
semantics. Existing chain B tests are copied to a new location and pass
unchanged.

**Alternatives considered:**

- *Inline the regex chain inside `SynchronizationService::run()`.*
  Rejected: tests get harder to read; shared logic with the migrator
  goes stale; separating the helper is the small-cost / large-readability
  win.
- *Delete the 3-format branching entirely and require sourceId to be a
  uuid post-migration.* Rejected: existing data still has
  register/schema slug pairs (legacy installs migrated long ago).
  Removing the branching breaks production.

## API Design

This change does NOT introduce new HTTP endpoints. Every existing
openconnector REST endpoint is preserved with byte-for-byte response
shape parity. See contract.md for the full list and a representative
endpoint.

The internal PHP API changes are:

- 30 PHP types DELETED (15 mappers + 15 entities) + 1 facade DELETED.
- 15 PHP types ADDED (per-resource input DTOs).
- All other internal types unchanged.

## Database Changes

**None.** Storage layer is identical to chain B's post-migration state.
The `oc_openregister_*` tables already contain every openconnector
object, and the chain A descriptor is already provisioned. The
`oc_openconnector_*` legacy tables remain on disk (read-only by
convention since chain B; chain B's cleanup change will drop them in a
later release).

No Nextcloud migration class is added by this change. The chain B
migration class (`Version2Date20260520xxxxxx.php`) and its
`postSchemaChange` hook are unchanged.

## Nextcloud Integration

- **Controllers** (`lib/Controller/`, ~18 files): each rewritten to
  inject `ObjectService` (and the relevant `<Resource>Dto` classes on
  write paths) instead of the deleted `<Resource>Mapper`.
- **Services** (`lib/Service/`, ~20 files): each rewritten to inject
  `ObjectService` instead of mappers. The
  `lib/Service/Storage/ObjectMapperFacade.php` is DELETED. A new
  `lib/Service/Helper/SyncRefResolver.php` is added (per D6).
- **Cron** (`lib/Cron/`, 2 files): `JobTask.php` and `LogCleanUpTask.php`
  rewritten to use `ObjectService::findAll(filters: [...])` for the
  due-jobs / expired-logs queries.
- **AppInfo/Application.php**: every `$context->registerService(<Mapper>::class, …)`
  call REMOVED. `ObjectService` is already registered by the openregister
  app; consumers receive it via the standard DI container. No new alias
  is added.
- **Pre-flight assertion**: `Application::register()` adds a
  `IAppConfig::getValue` check; raises `\LogicException` if
  `storage_migrated !== 'true'` (per D3).
- **Mappers/Entities**: all 15 of each DELETED. The 15 DTO classes under
  `lib/Db/Dto/` are added (per D2).
- **Events/Hooks**: `lib/EventListener/Object*EventListener.php` already
  consume `\OCA\OpenRegister\Db\ObjectEntity` directly (chain B kept
  these on the OR-native event payload). No change.

## Security Considerations

- **Pre-flight assertion** (D3) prevents operating on a chain-B-incomplete
  database. Without it, code paths would dereference deleted classes and
  fail in confusing ways at request time. The assertion gives a clear
  operator runbook message.
- **Encrypted columns on `Source`** (chain B Risk 1) — read-side
  decryption is the caller's responsibility per chain B's column-level
  encryption resolution. Chain C consumers MUST call
  `EncryptionService::decrypt($obj->getObject()['apikey'])` explicitly
  before using a decrypted value. The transformation is mechanical (the
  pre-rewrite code did the same thing via Entity setter overrides) but
  is now explicit at the call site. Audit point: every place that
  reads a `source.apikey`, `source.password`, `source.secret`, or
  `source.jwt` MUST go through `EncryptionService::decrypt`. Documented
  in `tasks.md` Task 12 acceptance criterion.
- **OR's permission layer** is now in the request path on every read.
  This is the same as chain B (the facade routed through `ObjectService`
  too); chain C does not weaken or strengthen the permission model. The
  pre-existing OR permission rules (system-owned objects, null `owner`
  per chain B REQ-011) apply.
- **DTO validation**: `fromArray()` throws `\InvalidArgumentException`
  on missing/invalid input, mapped to HTTP 400 by the controller.
  Defence-in-depth: OR's schema-level validation also rejects bad
  payloads (HTTP 422). Both layers run.
- **No new auth surface.** All controllers preserve their existing
  `@AuthorizedAdminSetting` / `@NoCSRFRequired` / `@PublicPage`
  annotations.
- **No new CORS rules.** No new origin handling.
- **Quality gate** (D4) prevents accidental reintroduction of deleted
  types. The gate is part of `composer check:strict` so CI enforces it.

## File Structure

```
openconnector/
├── lib/
│   ├── AppInfo/
│   │   └── Application.php                                [MODIFIED — DI bindings updated, pre-flight assertion added]
│   ├── Command/                                           [UNCHANGED — chain B's migrate-storage command stays]
│   ├── Controller/                                        [~18 files MODIFIED]
│   │   ├── ConsumersController.php
│   │   ├── DashboardController.php
│   │   ├── DSOController.php
│   │   ├── EndpointsController.php
│   │   ├── EventsController.php
│   │   ├── ExportController.php
│   │   ├── HealthController.php
│   │   ├── ImportController.php
│   │   ├── JobsController.php
│   │   ├── LogsController.php
│   │   ├── MappingsController.php
│   │   ├── MetricsController.php
│   │   ├── RulesController.php
│   │   ├── SettingsController.php
│   │   ├── SourcesController.php
│   │   ├── SynchronizationContractsController.php
│   │   ├── SynchronizationsController.php
│   │   ├── UiController.php                               [no mapper usage — minimal change or untouched]
│   │   └── UserController.php                             [no mapper usage — possibly untouched]
│   ├── Cron/                                              [2 files MODIFIED]
│   │   ├── JobTask.php
│   │   └── LogCleanUpTask.php
│   ├── Db/
│   │   ├── Dto/                                           [NEW DIRECTORY]
│   │   │   ├── CallLogDto.php                             [NEW]
│   │   │   ├── ConsumerDto.php                            [NEW]
│   │   │   ├── EndpointDto.php                            [NEW]
│   │   │   ├── EventDto.php                               [NEW]
│   │   │   ├── EventMessageDto.php                        [NEW]
│   │   │   ├── EventSubscriptionDto.php                   [NEW]
│   │   │   ├── JobDto.php                                 [NEW]
│   │   │   ├── JobLogDto.php                              [NEW]
│   │   │   ├── MappingDto.php                             [NEW]
│   │   │   ├── RuleDto.php                                [NEW]
│   │   │   ├── SourceDto.php                              [NEW]
│   │   │   ├── SynchronizationContractDto.php             [NEW]
│   │   │   ├── SynchronizationContractLogDto.php          [NEW]
│   │   │   ├── SynchronizationDto.php                     [NEW]
│   │   │   └── SynchronizationLogDto.php                  [NEW]
│   │   ├── CallLog.php                                    [DELETED]
│   │   ├── CallLogMapper.php                              [DELETED]
│   │   ├── Consumer.php                                   [DELETED]
│   │   ├── ConsumerMapper.php                             [DELETED]
│   │   ├── Endpoint.php                                   [DELETED]
│   │   ├── EndpointMapper.php                             [DELETED]
│   │   ├── Event.php                                      [DELETED]
│   │   ├── EventMapper.php                                [DELETED]
│   │   ├── EventMessage.php                               [DELETED]
│   │   ├── EventMessageMapper.php                         [DELETED]
│   │   ├── EventSubscription.php                          [DELETED]
│   │   ├── EventSubscriptionMapper.php                    [DELETED]
│   │   ├── Job.php                                        [DELETED]
│   │   ├── JobLog.php                                     [DELETED]
│   │   ├── JobLogMapper.php                               [DELETED]
│   │   ├── JobMapper.php                                  [DELETED]
│   │   ├── Mapping.php                                    [DELETED]
│   │   ├── MappingMapper.php                              [DELETED]
│   │   ├── Rule.php                                       [DELETED]
│   │   ├── RuleMapper.php                                 [DELETED]
│   │   ├── Source.php                                     [DELETED]
│   │   ├── SourceMapper.php                               [DELETED]
│   │   ├── Synchronization.php                            [DELETED]
│   │   ├── SynchronizationContract.php                    [DELETED]
│   │   ├── SynchronizationContractLog.php                 [DELETED]
│   │   ├── SynchronizationContractLogMapper.php           [DELETED]
│   │   ├── SynchronizationContractMapper.php              [DELETED]
│   │   ├── SynchronizationLog.php                         [DELETED]
│   │   ├── SynchronizationLogMapper.php                   [DELETED]
│   │   └── SynchronizationMapper.php                      [DELETED]
│   ├── EventListener/                                     [UNCHANGED — already uses OR's ObjectEntity]
│   ├── Migration/                                         [UNCHANGED — chain B's migration class stays]
│   ├── Settings/
│   │   └── openconnector_register.json                    [UNCHANGED — chain A]
│   └── Service/                                           [~20 files MODIFIED + 1 helper NEW + 1 facade DELETED]
│       ├── AuthenticationService.php                      [no mapper usage if applicable]
│       ├── AuthorizationService.php                       [MODIFIED]
│       ├── CallService.php                                [MODIFIED]
│       ├── ConfigurationHandlers/                         [check per file]
│       ├── ConfigurationService.php                       [MODIFIED]
│       ├── DSOParserService.php
│       ├── EndpointCacheService.php                       [MODIFIED]
│       ├── EndpointService.php                            [MODIFIED]
│       ├── EventService.php                               [MODIFIED]
│       ├── ExportService.php                              [MODIFIED]
│       ├── Helper/
│       │   └── SyncRefResolver.php                        [NEW — per D6]
│       ├── IBabsConnectorService.php                      [MODIFIED if uses SourceMapper]
│       ├── ImportService.php                              [MODIFIED]
│       ├── JobService.php                                 [MODIFIED]
│       ├── MappingService.php                             [MODIFIED]
│       ├── ObjectService.php                              [UNCHANGED — this is openconnector's own; chain D2 may rename]
│       ├── OrganisationBridgeService.php
│       ├── RuleService.php                                [MODIFIED]
│       ├── SearchService.php                              [MODIFIED if applicable]
│       ├── SecurityService.php                            [MODIFIED if applicable]
│       ├── SettingsService.php                            [no mapper usage]
│       ├── SoftwareCatalogueService.php                   [MODIFIED if applicable]
│       ├── Storage/
│       │   └── ObjectMapperFacade.php                     [DELETED]
│       ├── StorageService.php
│       ├── StUFFieldMapper.php                            [a field-mapper, unrelated to deleted *Mapper.php]
│       ├── SOAPService.php
│       ├── SynchronizationService.php                     [MODIFIED — uses SyncRefResolver]
│       └── UserService.php
└── tests/
    ├── Unit/
    │   ├── Controller/                                    [4 files MODIFIED — all that mock mappers]
    │   ├── Service/                                       [10 files MODIFIED]
    │   └── Db/
    │       └── Dto/                                       [NEW — 15 DTO unit tests, one per resource]
    │           ├── CallLogDtoTest.php                     [NEW]
    │           ├── ConsumerDtoTest.php                    [NEW]
    │           ├── …                                       [NEW × 13]
    └── Http/                                              [unchanged or minor updates]
```

## Seed Data

**No new seed data in this change.** Chain A owns the seed data; chain B
migrated existing legacy rows into OR; chain C does not touch storage and
does not introduce new schemas or seed objects.

**ADR-016 / ADR-001 deviation rationale (documented per ADR-001 line 46):**
This change is a pure code refactor (per ADR-031 "imperative work,
no declarative annotations introduced"). The existing chain A seed data
remains the canonical seed set. The DTO classes added by this change are
not domain data — they are input-validation utilities — so they have no
seed data of their own.

## Trade-offs

| Alternative considered                                                                  | Chosen? | Reasoning |
|-----------------------------------------------------------------------------------------|---------|-----------|
| Strangler-fig second step: delete the facade + mappers, callers use ObjectService directly | Yes    | The textbook completion of chain B's strangler-fig. Reaches ADR-001's end-state.                                                                          |
| Keep `ObjectMapperFacade` forever; only delete the 15 mappers                            | No     | Defeats ADR-001; a per-resource mapper-shaped surface is still domain code in the app. The facade is the chain-B bridge, NOT a long-term abstraction.   |
| Replace mappers with per-resource Repository classes                                     | No     | Same as above — a renamed layer. Adds 15 files we'd later delete.                                                                                          |
| No DTOs; use raw `array` in/out on write paths                                           | No     | Loses PHPStan-level type-checking on inbound writes. Risk-2 mitigation requires SOME typed surface.                                                       |
| Auto-generate DTOs from chain A schemas at build time                                    | Future | Issue C-001 captures this for follow-up. Manual DTOs in this change keep the apply work explicit and reviewable.                                          |
| Per-service `storage_migrated` assertion instead of app-boot                             | No     | 20+ duplicated checks; one app-boot assertion is the obvious DRY choice.                                                                                   |
| No quality gate (rely on code review)                                                    | No     | The user's "fix all issues encountered" + workflow patterns prefer automated gates. PHPCS sniff is cheap.                                                  |
| Implement quality gate as PHPStan custom rule                                            | No     | Heavyweight for what is fundamentally a `grep`. PHPCS sniff (or composer scripts entry) is sufficient.                                                     |
| Keep typed entity classes on read (revive `Source`, `Job`, … as thin wrappers)           | No     | Renames the deleted entities, doesn't actually delete them. ADR-001 violation.                                                                              |
| Move the 3-format branching logic to a Helper service                                    | Yes     | Per D6 — `SyncRefResolver` is shared between `SynchronizationService` (the new home) and any future call site (e.g. the chain-B migrator if re-run).      |
| Inline the 3-format branching back into `SynchronizationService::run()`                  | No     | Makes the service hard to read; shared logic with the migrator goes stale.                                                                                  |
| Override env var for the pre-flight assertion (`OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT`) | Yes | Test bootstrap needs to bypass the check (unit tests construct services in isolation; integration tests provision OR storage explicitly via a fixture).   |
| No override env var; force fresh installs to run chain B manually                        | No     | Breaks the `composer test` flow inside a fresh CI container where no OCC is invoked.                                                                       |
| Delete `lib/Db/<Entity>.php` typed entities                                              | Yes     | Per the user's scope clarification (top of this conversation): "DELETE these too. Domain data is JSON in OR objects; consumers receive ObjectEntity."     |
| Keep typed entities, delete only mappers + facade                                        | No      | Per the user's scope: typed entities are deleted. ADR-001's "no custom Entity for domain data" rule demands their removal.                                |

## Migration Plan

This change has no DB schema migration. Code-level migration is per
`tasks.md` and lives in the apply phase. High-level apply order:

1. **Pre-flight assertion** (DI + `Application::register()`).
2. **Add 15 DTO classes** (additive; no breakage).
3. **Add `SyncRefResolver` helper service** (additive).
4. **Rewrite leaf services** (`MappingService`, `RuleService`,
   `EventService` — no other service consumes them).
5. **Rewrite mid-tier services** (`SynchronizationService` now uses
   `SyncRefResolver`; `JobService`, `CallService`, `EndpointService`).
6. **Rewrite controllers** (~18 files; ordered by resource to match
   service-rewrite order).
7. **Rewrite cron tasks** (2 files).
8. **Update `Application.php` DI bindings** — remove mapper registrations.
9. **Rewrite tests** for every modified service/controller — mock
   `ObjectService` instead of mappers.
10. **DELETE** the 31 files (15 mappers + 15 entities + 1 facade) as a
    single commit. The build MUST be green before this commit.
11. **Add quality gate** (PHPCS sniff or grep-based composer script).

## Rollback Strategy

See proposal.md's "Rollback Strategy" section. Summary: `git revert` of
the chain C merge commit restores all 31 deleted files and reverts the
caller rewrites. Storage layer is unaffected by chain C, so a revert is
data-safe. The pre-flight assertion (D3) fails fast on environments
where chain B is not deployed.

## Open Questions

See DEFERRED_QUESTIONS for the canonical list. Items resolvable by
inspection during apply:

1. **Encrypted column read-side handling (Risk 3)** — confirm OR's
   `ObjectService::find()` does NOT decrypt by default; confirm
   `EncryptionService::decrypt()` is the canonical caller-side path.
   Resolve in apply Task 12 by reading
   `openregister/lib/Service/ObjectService.php` and
   `openconnector/lib/Service/AuthenticationService.php`.
2. **Are `UiController.php` and `UserController.php` actually
   mapper-free?** They were not in the grep result for mapper imports,
   but verify during apply. If they consume entities indirectly, add
   them to the rewrite list.
3. **`DSOParserService.php`, `OrganisationBridgeService.php`,
   `SoftwareCatalogueService.php`** — listed as having no `Mapper`
   import per the grep, but they may consume the deleted ENTITY types
   (e.g. `\OCA\OpenConnector\Db\Source` as a type hint). Verify during
   apply by greping for entity-class imports too.
