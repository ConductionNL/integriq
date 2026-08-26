# ADR-012: Strangler-fig migration pattern for legacy mapper elimination

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

Integriq has 15 typed `lib/Db/<Entity>.php` classes (Source, Job, Mapping,
…) and 15 corresponding `lib/Db/<Entity>Mapper.php` classes backed by
`oc_openconnector_*` MySQL tables. Hydra ADR-001 mandates that all domain data
move to OpenRegister `ObjectEntity` storage; leaving integriq with a
parallel entity/mapper layer is a known violation of that rule.

The naive fix is an atomic rewrite: change storage, delete entities, delete
mappers, rewrite the 40+ service and controller call sites all in one pull
request. This was rejected on three grounds:

1. A single PR touching 31 files of entity/mapper/migration code PLUS 40+ files
   of consumer rewrites creates an unbounded review surface. Any regression is
   hard to bisect.
2. The storage migration (chain B) requires a `LegacyToRegisterMigrator` OCC
   command and an app-config feature flag (`openconnector.storage_migrated`).
   That infrastructure cannot ship in the same PR as the consumer rewrite or a
   failing migration leaves callers with no functioning storage layer.
3. ADR-032 (hydra-level) prohibits mixed proposals that combine schema
   declaration, storage code, and service rewrites in a single change unit.

The two-phase "strangler-fig" splits the work at the only safe boundary: the
mapper API surface. The mappers are the sole consumer-facing abstraction over
storage. Chain B can migrate the storage while keeping that API identical; chain
C can then cut over the API once the storage is proven stable.

## Decision

Adopt a two-phase strangler-fig migration:

| Phase | Change slug | Storage layer | Caller surface |
|-------|-------------|---------------|----------------|
| Strangle | `openconnector-register-storage` (chain B) | OR objects + legacy tables (frozen) | Existing mapper API preserved by `ObjectMapperFacade` |
| Cut-over | `openconnector-services-direct-or-usage` (chain C) | OR objects only | OR `ObjectService` directly; facade and mappers deleted |
| Cleanup | Issue B-001 (follow-up) | OR objects only | Tables `oc_openconnector_*` dropped |

**Chain B** introduces `lib/Service/Storage/ObjectMapperFacade.php` as a shim
that exposes the same `find`, `findAll`, `createFromArray`, `updateFromArray`,
`delete` interface but delegates internally to OR `ObjectService`. The 15
`lib/Db/*Mapper.php` bodies are rewritten as thin wrappers over the facade;
result hydration maps `ObjectEntity` back to the original typed entity class so
that every service still receives, say, a `Source` object. The feature flag
`openconnector.storage_migrated = "true"` is set by the migrator on success; the
facade reads it at boot and routes to the legacy table path or the OR path
accordingly.

**Chain C** deletes the facade AND the 15 mapper files AND the 15 entity files
(31 files total), and rewrites every consumer in `lib/Service/`,
`lib/Controller/`, and `lib/Cron/` to use `ObjectService` directly. After chain
C ships, integriq contains no `lib/Db/*Mapper.php` or `lib/Db/<Entity>.php`
files for domain data. The `storage_migrated` flag becomes load-bearing rather
than a toggle: the legacy branches inside the chain-B facade are gone.

Either chain is independently shippable and rollbackable:
- Chain B can ship and run in production without chain C being present.
- Chain B can be rolled back (by clearing the flag) without touching chain C
  code.
- Chain C MUST NOT ship before chain B's migrator has been run successfully (the
  chain-C spec enforces this via a startup assertion).

## Consequences

- Each chain is reviewable and rollbackable in isolation; the facade is the
  explicit containment boundary.
- The facade is a **known regression vector**: it introduces a second code path
  for every mapper operation (legacy vs. OR). The quality gate added in chain C
  (`grep -rn "Mapper \$" lib/Service/` must return zero matches after chain C
  lands) protects against facade usage re-accreting in new code.
- The cost is one additional release cycle versus an atomic rewrite. The gain is
  two independently reviewable PRs with bounded blast radius.
- A developer who sees `ObjectMapperFacade` in the chain-B codebase and is
  tempted to add features against it MUST read this ADR: the facade is a
  transitional artefact scheduled for deletion in chain C, not a new abstraction
  layer.
- Cross-app consumers that import integriq PHP entity classes
  (`OCA\Integriq\Db\Source`, etc.) must migrate to OR `ObjectEntity` before
  chain C ships; that migration is chain C Out-of-Scope item and is tracked
  separately.
- After chain C ships, the Issue B-001 cleanup change (dropping
  `oc_openconnector_*` tables) is unblocked.

## Evidence

- `openspec/changes/openconnector-register-storage/proposal.md:82-92` —
  introduction of `ObjectMapperFacade` and the 15-mapper rewrite rationale in
  chain B.
- `openspec/changes/openconnector-register-storage/proposal.md:141` —
  Phase 5 "facade switch" description of the `storage_migrated` flag.
- `openspec/changes/openconnector-services-direct-or-usage/proposal.md:52-57` —
  the strangler-fig table naming chain B as "Strangle" and chain C as "Cut over".
- `openspec/changes/openconnector-services-direct-or-usage/proposal.md:70-94` —
  motivational section explaining why chain B left integriq in deliberate
  violation of ADR-001 and what chain C fixes.
- `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md:230-258` —
  the 31-types quality gate specification that enforces the post-chain-C
  invariant (no `Mapper $` injections in services or controllers).
