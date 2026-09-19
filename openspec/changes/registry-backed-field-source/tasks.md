# Tasks: registry-backed-field-source

Kind: code. Size L. Round 4 discovery cluster 26 and depth-study cluster
CT-5, candidates C-integrations-9, C-intake-10, C-parties-and-contacts-15,
C-parties-and-contacts-4, C-integrations-11 and C-integrations-43.
Decision D2. Waits on openregister's property key, to be specified in
openregister, wave 1, and on dossiq CT-1 for the declaration.

## Implementation tasks

### Task 1: The provider contract and its registry
- **spec_ref**: `openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001`
- **files**: `lib/PropertySource/PropertySourceProviderInterface.php`, `lib/PropertySource/PropertySourceRegistry.php`, `lib/AppInfo/Application.php` (DI tag)
- [x] Implement (`suggest`, `resolve`, `describe`; DI tag discovery; first-wins collision policy as `IntegrationRegistry`)
- [x] Test (an unknown provider id fails naming itself and returns nothing)

### Task 2: Suggest and resolve keep their separate guarantees
- **spec_ref**: `openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-suggest-and-resolve-are-separate-calls-with-separate-guarantees-req-rfs-002`
- **files**: `lib/PropertySource/PropertySourceResolver.php`, the resolve-before-save path
- [x] Implement (a suggestion is resolved by its identifier before it is stored)
- [x] Test

### Task 3: Provenance on every resolved value
- **spec_ref**: `openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003`
- **files**: `lib/PropertySource/ResolvedValue.php`, the value shape returned to openregister
- [x] Implement (provider id, source identifier, read timestamp, cache age; `manual` for a typed value)
- [x] Test (a typed value carries no provider id; provenance is a field and not a description)

### Task 4: The staleness budget and the fresh read
- **spec_ref**: `openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-live-means-a-stated-staleness-budget-req-rfs-004`
- **files**: `lib/PropertySource/PropertySourceResolver.php`, the per-provider budget configuration
- [x] Implement (budget per provider, cache bypass on demand, no value described as live past its budget)
- [x] Test

### Task 5: Degradation that says what happened
- **spec_ref**: `openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-an-unreachable-source-degrades-to-a-labelled-last-value-req-rfs-005`
- **files**: `lib/PropertySource/PropertySourceResolver.php`, reusing the `pdok-adapter` circuit breaker and stale-result path
- [x] Implement (last known value with its age and an unreachable state; never a blank, never a silent stale value)
- [x] Test (unreachable with a cache, and unreachable with nothing cached)

### Task 6: The BAG, BRP and KvK bindings
- **spec_ref**: `openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006`
- **files**: `lib/PropertySource/Provider/BagPropertySource.php`, `BrpPropertySource.php`, `KvkPropertySource.php`
- [x] Implement (each over its existing source through `CallService`; no new HTTP client, per ADR-005 and ADR-011)
- [x] Test (a binding with no source fails before any HTTP call)

### Task 7: On-demand resync for list-shaped providers
- **spec_ref**: `openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-list-shaped-source-resyncs-on-demand-req-rfs-007`
- **files**: `lib/PropertySource/ListResyncService.php`, the administration screen
- [x] Implement (last resync shown, change count reported, a failed resync keeps the previous list)
- [x] Test

### Task 8: Coordination, docs and the hand-offs
- **files**: `docs/`, Dutch and English strings, the catalog entry, this change's row in `competitor-parity-2026-09`
- [~] Ask the openregister lane for `x-openregister-property-source` on a schema property, by that name, and for the slug it opens the change under. Record whichever slug it picks here.
  - MEASURED 2026-09-18 RATHER THAN ASKED, against an openregister clone on its
    `parity/round2`: `x-openregister-property-source` is NOT in the property
    vocabulary. `grep -rn 'property-source\|propertySource'
    lib/Service/Schemas/PropertyValidatorHandler.php` returns nothing, and that
    table is what `vocabularyKeys()` publishes, so the key would fail a schema
    save today.
  - So the answer to "has it shipped" is no, and the integriq half above is
    built and waiting. Recorded here so the next lane reads a measurement
    instead of repeating the question.
- [ ] Say in the same message that it is not `x-openregister-object-source`, which `object-source-providers` already uses for a whole schema
- [ ] Hand dossiq the declaration half: the source input on `propertyDefinition`, which rides dossiq's CT-1 change, and the fixed slots it retires
- [x] Test (`tests/e2e/registry-backed-field-source.spec.ts`, `openspec validate registry-backed-field-source --strict`)
