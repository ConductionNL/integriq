---
kind: code
depends_on:
  - openconnector-register-storage
---

# Proposal: openconnector-services-direct-or-usage

## Why

ADR-001 mandates that all openconnector domain data lives in OpenRegister
objects and that the app owns NO custom `Entity` / `Mapper` classes for that
data. Chain B (`openconnector-register-storage`) deliberately stopped short of
that end-state: it kept the 15 `lib/Db/*Mapper.php` files alive as a
transitional `ObjectMapperFacade` so the 20+ services and ~18 controllers that
consume them did not all have to change in lockstep. Chain C is where that
abstraction comes down.

## What Changes

- Delete `lib/Service/Storage/ObjectMapperFacade.php` (the chain-B bridge).
- Delete all 15 `lib/Db/*Mapper.php` files plus the 15 `lib/Db/*.php` entity
  classes that backed them.
- Inject `OCA\OpenRegister\Service\ObjectService` into every service and
  controller that previously consumed a mapper, and rewrite the call sites to
  use OR's register-slug / schema-slug / uuid surface.
- Replace typed-entity return values with `\OCA\OpenRegister\Db\ObjectEntity`
  for reads, and introduce thin per-resource **input DTOs** for write paths
  that still want type-checking on incoming arrays.
- Update `lib/AppInfo/Application.php` DI bindings; remove every mapper alias
  from `composer.json` autoload entries and `psalm.xml`/`phpstan.neon`
  exclusions tied to the deleted classes.
- Rewrite all tests under `tests/Unit/` and `tests/Http/` that mocked the
  deleted mappers to mock `ObjectService` instead.

## Impact

- 15 mapper files + 15 entity files + 1 facade = **31 files deleted** in
  `lib/Db/` and `lib/Service/Storage/`.
- ~20 service files in `lib/Service/` rewritten to call `ObjectService`
  directly.
- ~18 controller files in `lib/Controller/` rewritten.
- 2 cron tasks in `lib/Cron/` rewritten.
- All `tests/Unit/` files that import the deleted types rewritten.
- `composer.json` autoload + quality-gate configs touched.
- No DB schema changes (storage already migrated in chain B).
- The `openconnector.storage_migrated` flag introduced by chain B becomes
  load-bearing: the legacy branches inside the chain-B facade are gone, so
  this change MUST NOT be applied to an environment where the flag is not
  `true`. A startup assertion enforces this.

This is the **second step of a strangler-fig migration**:

| Phase | Change | Storage layer | Caller surface |
|-------|--------|---------------|----------------|
| Strangle | chain B (`openconnector-register-storage`) | OR objects (new) + legacy tables (frozen) | Old mapper API preserved by `ObjectMapperFacade` |
| Cut over | **chain C (this change)** | OR objects only | OR `ObjectService` directly, no facade |
| Cleanup | follow-up [#820](https://github.com/ConductionNL/openconnector/issues/820) | OR objects only | (already done in C) — also drops `oc_openconnector_*` tables |

## Summary

This change removes the transitional `ObjectMapperFacade` introduced by chain
B and rewrites every consumer in openconnector to use OpenRegister's
`ObjectService` API directly. After this change ships, openconnector contains
zero `lib/Db/*Mapper.php` files and zero `lib/Db/<Entity>.php` domain-data
entity classes — domain data is JSON inside `\OCA\OpenRegister\Db\ObjectEntity`
instances, read via `getObject()`. The 15 schemas declared by chain A and the
data migrated by chain B remain unchanged; only the PHP call-site shape moves.

## Motivation

ADR-001 ("ALL domain data → OpenRegister objects. NO custom Entity/Mapper for
domain data.") is the load-bearing architectural rule for the Conduction app
fleet. Chain B left openconnector in deliberate violation of that rule — a
parallel mapper-shaped surface kept on top of OR-backed storage — to bound the
blast radius of the storage migration. Without chain C:

- Every new feature in openconnector accretes more code against the legacy
  mapper API, increasing the eventual rewrite cost.
- New IDE auto-completes on `Source`/`Job`/etc. mislead authors into thinking
  these are still authoritative types. They are not — the authoritative shape
  is the JSON inside the OR object.
- Cross-app consumers that import openconnector entities directly (the
  `OCA\OpenConnector\Db\Source` PHP class) become un-deletable, blocking the
  ADR-001 end-state for the fleet.
- Two follow-up apps (decidesk and pipelinq) want to subscribe to OR's
  generic `ObjectUpdatedEvent` for openconnector resources; today those
  events are emitted but consumers still need to know about
  `OCA\OpenConnector\Db\Source` to interpret them. After chain C, the only
  consumer-facing type is `\OCA\OpenRegister\Db\ObjectEntity`.

Chain B's follow-up issue [#820](https://github.com/ConductionNL/openconnector/issues/820) ("drop legacy tables + remove storage_migrated flag")
is unblocked by this change shipping — once the facade is gone, no code path
reads from `oc_openconnector_*` tables, and the cleanup change can run.

## Affected Projects

- [x] Project: `openconnector` — major: deletes 31 files, rewrites ~40
  service/controller/cron files, rewrites all unit/HTTP tests touching those
  call sites. No public REST API change (controllers still expose the same
  endpoints; only their internals change).
- [x] Project: `openregister` — passive consumer. No openregister code is
  added or changed. The change pins the minimum `openregister` version
  required by openconnector to `^v0.2.10` (chain A baseline) and asserts that
  `ObjectService::find()`, `::saveObject()`, `::delete()`, and `::findAll()`
  with filter array exist with the signatures used here.
- [ ] Project: `decidesk`, `pipelinq`, `procest`, `docudesk` — no change
  required by this change (their existing read paths went through OR's
  generic surface already). Listed as passive beneficiaries because the
  ADR-001 end-state is unblocked.

## Scope

### In Scope

- **Delete** 15 mapper files: `lib/Db/SourceMapper.php`,
  `ConsumerMapper.php`, `EndpointMapper.php`, `EventMapper.php`,
  `EventMessageMapper.php`, `EventSubscriptionMapper.php`, `JobMapper.php`,
  `JobLogMapper.php`, `MappingMapper.php`, `RuleMapper.php`,
  `SynchronizationMapper.php`, `SynchronizationContractMapper.php`,
  `SynchronizationContractLogMapper.php`, `SynchronizationLogMapper.php`,
  `CallLogMapper.php`.
- **Delete** 15 entity files: `lib/Db/Source.php`, `Consumer.php`,
  `Endpoint.php`, `Event.php`, `EventMessage.php`, `EventSubscription.php`,
  `Job.php`, `JobLog.php`, `Mapping.php`, `Rule.php`, `Synchronization.php`,
  `SynchronizationContract.php`, `SynchronizationContractLog.php`,
  `SynchronizationLog.php`, `CallLog.php`.
- **Delete** the facade: `lib/Service/Storage/ObjectMapperFacade.php`.
- **Rewrite** every caller in `lib/Service/`, `lib/Controller/`, `lib/Cron/`,
  and `lib/AppInfo/Application.php` to inject `ObjectService` and call its
  API directly (`find($register, $schema, $uuid)`, `saveObject($register,
  $schema, $data, $uuid?)`, `delete($register, $schema, $uuid)`,
  `findAll($register, $schema, $filters, $limit, $offset, …)`).
- **Introduce input DTOs** under `lib/Db/Dto/` — one per resource (~15
  files), e.g. `SourceDto`, `JobDto`. These are PHP-typed wrappers used
  ONLY to validate inbound write payloads in controllers and one or two
  services; they are NOT used on the read side and have no persistence.
  This is the explicit ADR-001 trade-off: read-side is `getObject()['key']`
  on `ObjectEntity`; write-side has an optional DTO layer for input
  validation.
- **Update DI** in `lib/AppInfo/Application.php`: remove every mapper alias,
  add an `ObjectService` consumer alias if/where needed.
- **Rewrite tests** under `tests/Unit/Controller/`, `tests/Unit/Service/`,
  `tests/Http/`: every test that mocked a mapper now mocks `ObjectService`.
- **Preserve** the `Synchronization.sourceId` per-row branching logic (3
  formats: integer-PK, register/schema slug-pair, uuid) that lived inside
  `SynchronizationService` — chain C moves it verbatim into the rewritten
  service, with the same regex chain and same skip-on-unrecognised
  semantics that chain B's migrator used.
- **Preserve** all HTTP REST endpoints and their response shapes —
  controllers return arrays/JSON, which today serialise from typed entities
  and after this change serialise from `ObjectEntity::jsonSerialize()`
  output; identical wire format.
- **Add a quality gate** to `composer check:strict` that fails the build if
  `OCA\OpenConnector\Db\<DomainType>` (any of the 15 deleted entity names)
  or `OCA\OpenConnector\Db\<DomainType>Mapper` appears in a `use` statement
  anywhere under `lib/` or `tests/`.

### Out of Scope

- **Dropping `oc_openconnector_*` tables** — addressed by chain B's follow-up
  cleanup change tracked at [#820](https://github.com/ConductionNL/openconnector/issues/820).
  This change unblocks it but does not do it. Issue #820 is referenced as the cleanup gate.
- **Removing `openconnector.storage_migrated` app-config flag** — same
  cleanup change.
- **Frontend (Vue) store changes** — separate change
  (`openconnector-frontend-vue-rewrite` already in the queue). Stores
  continue to read REST endpoints whose wire format is unchanged.
- **Renaming `*Id` legacy FK fields to target-schema-name** — separate
  follow-up change (chain B Out-of-Scope item 2).
- **CallLog.actionId target schema** — chain B left this as opaque integer
  with a parallel `action` string field; chain C consumers handle both
  forms unchanged, deferring resolution to the follow-up that addresses the
  rename.
- **Reorganising controllers to be slim REST shells with no business
  logic** — controllers ARE rewritten here, but the rewrite is mechanical
  (mapper → ObjectService), NOT a slim-down. Architectural refactor of
  controllers is in `openconnector-adopt-or-abstractions`.
- **Hardcoded retention constants in services** — handled by
  `openconnector-adopt-or-abstractions`. This change leaves them in place
  if still present.
- **OCC commands** — no new commands. The existing `migrate-storage`
  command from chain B is preserved untouched; it still calls the migrator
  service which is unchanged.

## Approach

1. **Pre-flight gate.** Application boot adds a one-time assertion at app
   register time: if `IAppConfig::getValue('openconnector',
   'storage_migrated', 'false') !== 'true'`, raise a `\LogicException` with
   a clear "run chain B's storage migration before deploying chain C"
   message. Without this, the deleted mappers' callers would dereference
   missing classes against legacy tables.
2. **Introduce thin DTOs (additive).** Ship `lib/Db/Dto/<Resource>Dto.php`
   for each of the 15 resources. Each DTO has typed properties matching the
   schema, a `fromArray(array): self` constructor, and a `toArray(): array`
   serialiser. DTOs are used ONLY for write-side input validation in
   controllers — read paths go straight to `ObjectEntity::getObject()`.
3. **Rewrite services bottom-up.** Start with leaf services (no other
   service depends on them): `MappingService`, `RuleService`,
   `EventService`. Move up through `SynchronizationService`, `JobService`,
   `CallService`, `EndpointService` (`SourceMappingService` after the
   `openconnector-adopt-or-abstractions` rename, if it lands first). DI
   each service to receive `ObjectService` instead of the resource mapper.
4. **Rewrite controllers.** ~18 controllers, each owning 1–2 resources.
   Each controller's constructor changes from `<Resource>Mapper` to
   `ObjectService`. Each handler uses `ObjectService::find/findAll/saveObject/delete`
   with the appropriate register slug (`'openconnector'`) and schema slug
   (`'source'`, `'job'`, etc.). The HTTP wire format (response JSON shape)
   is preserved by using `ObjectEntity::jsonSerialize()` (which already
   outputs the legacy shape — chain A's `x-openregister` annotations ensure
   parity).
5. **Rewrite cron tasks.** `lib/Cron/JobTask.php` and
   `LogCleanUpTask.php` move from mappers to `ObjectService` with the same
   filter logic.
6. **Update DI bindings.** Remove every `$context->registerService(<Mapper>::class, …)`
   call from `Application.php`. Verify via `psalm` that no DI alias dangles.
7. **Delete mappers + entities + facade.** Single commit at the end of the
   service/controller rewrite. The build MUST pass `composer check:strict`
   before this commit.
8. **Rewrite tests.** Every test that imported a deleted type is updated to
   mock `ObjectService` and assert against `ObjectEntity` shape.
9. **Add quality-gate rule.** Custom PHPCS sniff (or psalm regex) that fails
   if `OCA\OpenConnector\Db\<Resource>` (one of 15) or `<Resource>Mapper` is
   present in `use` statements under `lib/` or `tests/`. Prevents
   regression.

## New Dependencies

None. `openregister` is already a peer dependency declared in chain A's
descriptor (`x-openregister.minVersion: ^v0.2.10`); this change does NOT
bump that floor. No composer or npm additions.

## Cross-Project Dependencies

- **Depends on**: `openconnector-register-storage` (chain B) for the
  `ObjectMapperFacade` to exist, and the `storage_migrated` flag to be set
  to `'true'` on every target environment. The pre-flight assertion (step
  1 above) prevents accidental deployment to a non-migrated environment.
- **Depends on**: `openconnector-register-schema-declaration` (chain A)
  transitively — the schema descriptor on disk is what `ObjectService`
  uses to validate writes. Chain A's `lib/Settings/openconnector_register.json`
  is unchanged by this change.
- **Affects**: chain B's follow-up [#820](https://github.com/ConductionNL/openconnector/issues/820) cleanup change — gated on this change
  shipping. After chain C lands, the legacy `oc_openconnector_*` tables
  have no in-process readers and can be dropped.
- **Affects**: `openconnector-frontend-vue-rewrite` — orthogonal, but
  benefits from the wire-format guarantee in this change's "In Scope"
  list (response JSON shape preserved).

## Risks

### Risk 1: Blast radius across ~40 service/controller files

**Severity:** High — **Mitigation:**
- Land per-resource (15 resources × ~3 files each), not per-file. One
  feature flag (`openconnector.facade_removed`) gates the legacy-import
  guard in the pre-flight check, allowing partial rollouts during the apply
  window. Per-resource branches keep PHPCS/PHPMD/Psalm/PHPStan strict gates
  green at every commit.
- `composer check:strict` MUST pass on every per-resource branch before
  merge.
- The `tasks.md` apply order is bottom-up (leaf services first, then up the
  dependency tree, then controllers, then cron, then deletes).
- A regression detection: `composer check:strict` runs in CI on the chain
  C branch on every push, gating merge.

### Risk 2: Loss of typed entity autocomplete + PHPStan type-checking on JSON access

**Severity:** High — **Mitigation:**
- Introduce per-resource **input DTOs** under `lib/Db/Dto/` (e.g.
  `SourceDto`, `JobDto`). DTOs are used ONLY for write-side validation in
  controllers; read paths use `$obj->getObject()['key']`. This is the
  explicit ADR-001 trade-off: typed-write, untyped-read. PHPStan still
  checks DTO usage on write paths.
- Per the user's "long-term-app feature decisions favor unification"
  preference, this design unifies on ONE write path (always go through a
  DTO before calling `ObjectService::saveObject`) and ONE read path
  (`getObject()['key']`).
- Document the read-side untyped-access pattern in a new
  `openspec/architecture/adr-002-or-direct-usage.md` (the background agent
  authoring ADRs will produce this).

### Risk 3: Encrypted columns on Source (`secret`, `password`, `apikey`, `jwt`) leak on read

**Severity:** Medium — **Mitigation:**
- Chain B kept encrypted bytes in the OR object's JSON body verbatim
  (column-level encryption, per chain B Risk 1's resolution path).
  Consumers in chain C MUST NOT decrypt on read unless the caller has
  decryption permission.
- Verified by reading OR's `ObjectService` for an authenticated read-side
  decryption hook: `ObjectService::find()` does NOT decrypt encrypted
  fields by default. Decryption is an explicit caller-side step via
  `EncryptionService::decrypt()` (preserved from chain B).
- Acceptance: every callsite that previously did `$source->getApikey()`
  (which invoked `EncryptionService::decrypt` via the entity setter
  override) now does `$encryptionService->decrypt($obj->getObject()['apikey'])`
  explicitly. The transformation is mechanical and is checked by an apply
  acceptance criterion in `tasks.md`.
- See DEFERRED_QUESTIONS C-1.

### Risk 4: Performance regression vs raw SQL mappers (ObjectService routes through permission + audit + hook layers per call)

**Severity:** Medium — **Mitigation:**
- Measure p95 latency on `find(uuid)` and `findAll(filters)` on the chain
  B baseline (already through facade → ObjectService) vs the chain C path
  (direct ObjectService). Delta SHOULD be ≤ 5% (same underlying call;
  chain C removes the facade hop).
- Add an in-process per-request memoisation cache on hot-path lookups
  (`find($register, $schema, $uuid)` returns identical results within a
  single HTTP request) — wraps `ObjectService` calls in a request-scoped
  service, NOT cross-request caching.
- A PR-time PHPUnit perf test enforces no regression beyond 50% vs chain
  B baseline. Regression > 50% blocks merge.

### Risk 5: Synchronization.sourceId per-row branching logic gets lost in the rewrite

**Severity:** Medium — **Mitigation:**
- Chain B's migrator already extracted the branching into a private
  `resolveSyncRef(string)` helper. Chain C moves the same helper verbatim
  into `SynchronizationService` (or its renamed equivalent
  `SourceMappingService`). Per chain B REQ-004, the behaviour is
  unit-tested with 4 scenarios; those tests survive the rewrite intact.
- Apply acceptance criterion in `tasks.md`: `SynchronizationService` MUST
  contain a `resolveSyncRef` method (private OR a dedicated
  `Helper\SyncRefResolver` service); the chain B tests are copy-pasted to
  the new test location and pass unchanged.

### Risk 6: Pre-flight assertion blocks legitimate dev-env where flag is unset

**Severity:** Low — **Mitigation:**
- The assertion message includes the exact OCC command to run:
  `occ openconnector:migrate-storage`. Dev-env operators see this and run
  it.
- An override env var `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1`
  bypasses the check for unit tests and fresh-install scenarios where the
  test bootstrap migrates the storage explicitly.

### Risk 7: DTO sprawl — 15 DTO classes are themselves "domain types in app code"

**Severity:** Low — **Mitigation:**
- DTOs are scoped to write-side input validation, not domain persistence.
  They do NOT have an `id`, `uuid`, `created`, `updated`, `owner`, or any
  other OR-managed field — only the user-supplied properties.
- DTO files are ≤30 LOC each (typed properties + `fromArray` + `toArray`).
- Future work (filed as Issue C-001) can auto-generate DTOs from the
  chain A schema descriptors, eliminating the hand-rolled boilerplate. Out
  of scope for this change.

## Rollback Strategy

Chain C deletes files and rewrites callers. Rollback is via `git revert`:

1. **Pre-merge**: standard `git revert` of the chain C merge commit
   restores the deleted files and the pre-rewrite caller bodies. The
   chain-B facade is intact in revert history.
2. **Post-merge but pre-cleanup-change**: same `git revert`. The
   `storage_migrated` flag remains `'true'`; chain B's facade routes
   through OR on the legacy path. No data is lost — only the call-site
   shape regresses.
3. **Post-cleanup-change**: NOT REVERSIBLE without first reverting the
   cleanup change. The cleanup change drops `oc_openconnector_*` tables;
   reverting chain C alone without the tables would re-introduce code that
   expects either the facade or the legacy mappers. Cleanup change MUST
   document this gate in its release notes.

The pre-flight assertion (step 1 of Approach) handles a forgotten chain B
deployment by failing fast at app boot. There is NO automatic fallback to
the legacy path inside chain C — the facade is gone and chain C is a
one-way door.

## Open Questions

See DEFERRED_QUESTIONS for the canonical list. Key items affecting
implementation:

1. **Encrypted column read-side handling** — confirm OR's `ObjectService::find()`
   does NOT decrypt by default; confirm `EncryptionService::decrypt()`
   remains the canonical caller-side path. See Risk 3.
2. **Pre-flight assertion location** — `Application::register()` vs each
   service's constructor. Application-level is preferred (one assertion);
   per-service is more defensive. Pick one in design.md.
3. **DTO auto-generation** — manual DTOs in this change; auto-generation
   from schema descriptors is Issue C-001 for a follow-up.
4. **CallLog.actionId opaque-integer handling** — chain C consumers
   continue to handle both `actionId` (int) and `action` (string) shapes
   from chain B. Resolution to a single `$ref` relation is the responsibility
   of the FK-rename follow-up change (chain B Out-of-Scope item 2).
