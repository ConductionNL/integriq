# Discovery: openconnector-services-direct-or-usage

## Question

Can openconnector's ~40 services + controllers + cron tasks be rewritten
to use OpenRegister's `ObjectService` directly without:

1. Regressing the external HTTP wire format (Vue stores + downstream
   apps depend on it).
2. Losing typed-input validation that the deleted entity setters
   implicitly provided (PHPStan-level type checking on writes).
3. Breaking the `Synchronization.sourceId`/`targetId` 3-format
   branching logic that lives inside `SynchronizationService`.
4. Causing a performance regression vs the chain-B facade path.
5. Reintroducing the deleted types via accidental future imports.
6. Failing on environments where chain B hasn't been deployed (the
   `storage_migrated` flag is `'false'`).

## Approach Taken

1. **Caller-map** — `grep -rln "Mapper" lib/Service/ lib/Controller/`
   produced 16 services + 11 controllers with at least one mapper
   import. Cross-referenced against `lib/AppInfo/Application.php` to
   confirm every mapper has a DI binding (yes, registered via standard
   constructor DI; nothing exotic).
2. **Wire-format check** — read chain A's `lib/Settings/openconnector_register.json`
   schema for `source`, plus the chain B facade's `hydrate` method to
   confirm `ObjectEntity::jsonSerialize()` over the chain A schema
   outputs the same property names that the deleted `Source` entity
   would have output. Confirmed — chain A's properties match the legacy
   entity property names byte-for-byte by design.
3. **DTO sketch** — wrote a one-page draft `SourceDto` skeleton against
   the chain A schema. Confirmed it fits ≤ 30 LOC for the largest
   resource (Source) with typed read-only constructor properties + a
   `fromArray` factory + a `toArray` serialiser. 15 of these total
   ≈ 450 LOC.
4. **`SyncRefResolver` extraction** — read chain B's
   `LegacyToRegisterMigrator::resolveSyncRef` (~ 30 LOC). Confirmed it
   is a pure function — no `IDBConnection` dependency for the regex
   side; the integer-PK branch needs the source-uuid lookup which is
   `ObjectService::find(register, schema, intId)`. Extraction is
   mechanical.
5. **`ObjectService` API check** — read
   `openregister/lib/Service/ObjectService.php` to confirm method
   signatures: `find($register, $schema, $uuidOrId)`, `findAll($register,
   $schema, $filters, $limit, $offset, $search, $sort, $extend)`,
   `saveObject($register, $schema, $data, $uuid = null)`, `delete($register,
   $schema, $uuid)`. Confirmed all exist and are public in `^v0.2.10`.
6. **Encrypted-column read-path check** — read OR's `ObjectService::find`
   for a per-field decryption hook. **None found.** The encrypted bytes
   are returned verbatim from `getObject()`. This means every chain C
   call site that needs a decrypted `apikey`/`password`/`secret`/`jwt`
   MUST explicitly call `EncryptionService::decrypt(...)` after reading
   the field. The pre-rewrite code did this implicitly via Entity
   setter/getter overrides; chain C makes it explicit.
7. **Pre-flight assertion feasibility** — read Nextcloud's
   `IBootstrap::register()` semantics and confirmed that throwing
   `\LogicException` from `register()` aborts the app boot cleanly. The
   request-scoped `IAppConfig` is available inside `register()` via the
   container.
8. **Quality-gate research** — surveyed PHPCS custom sniff options for
   "forbidden class import" rules. The `Symfony/SniffName/`
   pattern of forbidden-class checks via the `ForbiddenFunctions` sniff
   family works. Alternative fallback: a `composer.json` `scripts:`
   entry running grep, which is what older Conduction apps did before
   PHPCS sniffs landed. Either works; PHPCS preferred for nicer error
   messages.
9. **Performance baseline** — chain B's facade adds one method-call
   hop (`SourceMapper::find` → `ObjectMapperFacade::find` →
   `ObjectService::find`). Chain C removes the middle hop. Expected
   p95 delta: ≤ 5% improvement (one less call). Not a regression risk
   — flagging in design.md only for completeness.
10. **Cross-app PHP import audit** — `grep -rln "OCA\\\\OpenConnector\\\\Db"
    ../*/lib/ ../*/tests/` (across all sibling apps in `apps-extra/`)
    returned only openconnector's own files. No external app imports
    the deleted PHP types directly. The wire-format guarantee (point 2
    above) is the only cross-app coupling, and it is preserved.

## Findings

### Q1: Can wire format be preserved without typed entities?

**Yes.** `\OCA\OpenRegister\Db\ObjectEntity::jsonSerialize()` over chain
A's schema produces the same JSON shape the deleted typed entities
produced. Chain A's `properties` declarations match the legacy property
names 1:1 — this was a chain A design goal precisely so that this
day's rewrite could happen without wire-format churn.

Implication: controllers can do `return new JSONResponse($objectEntity)`
(or `array_map(fn($o) => $o, $results)`) without any custom shaping
code. The response is identical to chain B's.

### Q2: Are typed input DTOs sufficient for write-side validation?

**Yes.** A `final class SourceDto` with typed read-only properties +
`fromArray(array): self` (which throws `\InvalidArgumentException` on
missing/invalid fields) gives:

- PHPStan-level type checking inside the controller / service.
- HTTP 400 mapping on bad input (controller catches the
  `\InvalidArgumentException`).
- Defence-in-depth alongside OR's schema-level validation (HTTP 422 if
  the DTO passes but the schema-level rule rejects).

15 DTOs × ~30 LOC ≈ 450 LOC. Manageable. The future Issue C-001
auto-generation eliminates this boilerplate without breaking the
interface.

### Q3: `SyncRefResolver` extractability

**Confirmed.** Chain B's `resolveSyncRef` is already a clean private
method on `LegacyToRegisterMigrator`. Promoting it to a dedicated
service `lib/Service/Helper/SyncRefResolver.php`:

```php
final class SyncRefResolver {
    public function __construct(
        private readonly \OCA\OpenRegister\Service\ObjectService $objectService,
    ) {}

    /** @return array{value: string, variant: 'integer-pk'|'register-schema'|'uuid'|'unrecognised'} */
    public function resolve(string $value): array { /* … */ }
}
```

— is mechanical. The integer-PK branch's source lookup goes through
`ObjectService::find('openconnector', 'source', $value)` (a uuid in
chain C; the chain B migrator was the only caller that ever passed an
integer here, and chain B has already mapped integers to uuids in
storage). For chain C, the integer-PK case is effectively dead
post-migration — but it stays because legacy `Synchronization.sourceId`
values may still contain integer strings if any post-migration write
re-introduced them.

### Q4: ObjectService method signatures

**All needed methods exist in `openregister >= ^v0.2.10`:**

| Method                                                                              | Used for                          |
|-------------------------------------------------------------------------------------|-----------------------------------|
| `find(string $registerSlug, string $schemaSlug, string $uuid): ObjectEntity`        | controllers' GET-by-id            |
| `findAll(string $registerSlug, string $schemaSlug, array $filters = [], …): array` | controllers' list endpoints       |
| `saveObject(string $registerSlug, string $schemaSlug, array $data, ?string $uuid = null): ObjectEntity` | controllers' POST/PUT |
| `delete(string $registerSlug, string $schemaSlug, string $uuid): void`              | controllers' DELETE               |

The `find($register, $schema, $idOrUuid)` path accepts both integer PK
and UUID via OR's internal type sniffing — confirmed by reading OR's
mapper code. Chain C call sites pass UUIDs by default; the integer-PK
acceptance is an OR backward-compat affordance, not chain C's
concern.

### Q5: Encrypted columns read path

**Resolved with caller-side decryption.** OR's `ObjectService::find()`
returns the OR object's JSON body verbatim, with encrypted bytes
present in the field values (the chain B migrator copied them as-is).
Chain C consumers that need plaintext MUST call
`EncryptionService::decrypt($obj->getObject()['apikey'])` explicitly.

The pre-rewrite code did this implicitly: `$source->getApikey()`
invoked an overridden getter on the deleted `Source` entity that
called `EncryptionService::decrypt()`. After chain C, that magic
disappears — the call is explicit. **Mechanical transformation** but
**must be applied at every callsite**. The tasks.md acceptance
criterion lists this as Task 12's gate.

### Q6: Pre-flight assertion feasibility

**Confirmed.** Throwing `\LogicException` from `Application::register()`
aborts the app boot with a clear error message that Nextcloud surfaces
to the operator. The override env var
`OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1` is a one-line check
inside `register()` that bypasses the assertion for unit tests + fresh
install bootstrap.

### Q7: Performance impact

**Improvement, not regression.** Chain C removes one method-call hop
(facade → ObjectService becomes ObjectService directly). Expected p95
latency delta: ≤ 5% improvement. The risk-4 mitigation (PHPUnit perf
test gate) catches any unexpected regression > 50%.

### Q8: Quality gate implementation

**PHPCS custom sniff (preferred) OR composer scripts grep entry
(fallback).** Both block the build on accidental re-introduction of
deleted types. PHPCS gives nicer error messages; grep is simpler.
Either works; design.md picks PHPCS sniff as the default with grep
fallback noted.

## Recommendation

**Proceed.** All 8 discovery questions resolve to actionable plans:

- ✅ Q1: wire format preserved by `ObjectEntity::jsonSerialize()` over
  chain A schema.
- ✅ Q2: 15 typed DTOs cover write-side validation.
- ✅ Q3: `SyncRefResolver` extraction from chain B is mechanical.
- ✅ Q4: `ObjectService` method signatures are sufficient.
- ✅ Q5: encrypted column read-side is explicit `EncryptionService::decrypt`
  at every callsite.
- ✅ Q6: pre-flight assertion at `Application::register()` is feasible.
- ✅ Q7: performance is an improvement, not a regression.
- ✅ Q8: PHPCS custom sniff (or grep fallback) blocks re-introduction.

Cross-app risk is zero — no sibling app in `apps-extra/` imports the
deleted PHP types. The wire format (the only cross-app coupling) is
preserved.

## Risks Uncovered

1. **Caller-list completeness.** The grep produced 16 services + 11
   controllers. During apply, additional callers in
   `lib/Service/ConfigurationHandlers/` or `lib/Service/Helper/` may
   surface. **Mitigation:** apply tasks include a "rerun grep for ALL
   imports of deleted types" gate before the final delete commit.
   Acceptance criterion: zero matches for any of the 30 deleted classes
   in `lib/` or `tests/` post-rewrite.

2. **`Synchronization.sourceId` integer-PK case is effectively dead
   post-chain-B, but the regex chain is still present.** This is by
   design — chain B's branching code MUST survive in chain C. If a
   future change wants to drop the integer-PK case entirely, that is
   a separate spec.

3. **Test fixtures may import deleted entity types.** `tests/Unit/*Test.php`
   files that pre-existed chain B likely have `use OCA\OpenConnector\Db\Source`
   imports for fixture construction. Apply must rewrite these — see
   Task 14 in tasks.md.

4. **`DSOParserService.php`, `OrganisationBridgeService.php`, etc.** —
   the grep found "Mapper" in them but those may be unrelated mappers
   (e.g. `StUFFieldMapper` is a field-mapping utility, NOT a deleted
   entity mapper). Apply must distinguish; design.md Open Question Q3
   flags this for inspection during apply.

## Next Steps

1. Author the spec file (one capability:
   `openconnector-direct-or-usage`) with REQs covering: pre-flight
   assertion, DTO classes, controller rewrites, service rewrites, cron
   rewrites, deletion of all 31 files, quality gate, encrypted column
   handling.
2. Author migration.md describing the per-resource apply order (no DB
   migration, but a code-rewrite "migration" plan that the apply agent
   follows).
3. Author tasks.md grouping by apply phase: pre-flight assertion → DTOs
   → SyncRefResolver helper → leaf services → mid-tier services →
   controllers → cron → DI updates → tests → delete files → quality
   gate.
4. Author test-plan.md covering unit tests for DTOs and rewritten
   services + integration tests for wire-format parity (Newman/Postman
   comparison pre/post).
5. Spike: write the first DTO (`SourceDto`) + rewrite the smallest
   leaf service (`MappingService`) end-to-end as a sanity check before
   the full apply.
