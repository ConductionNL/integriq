# Proposal: sync-engine-scalar-items

## Summary
Two related corrections to the synchronization engine's source-fetch/per-item
pipeline. First, a code fix: bare-scalar source items (e.g. a source
returning `["php", "nodejs", ...]`) currently throw a `TypeError` at the
`array`-typed method boundary before any graceful-skip logic can run, so
every scalar item is dead-lettered with an unhelpful low-level type error
and a fully-scalar source syncs nothing. Second, a spec-only correction: the
canonical `synchronization-engine` spec (REQ-002) currently SHALLs that
`sourceType: "array"` is dispatched by `getAllObjectsFromSource()`, but no
such dispatch exists anywhere in the codebase's history — the spec text is
fabricated, not drifted, and must be corrected to match reality.

## Motivation
`#1050`: a source that legitimately returns a bare array of scalars (strings,
numbers) is unusable today — every item throws before
`processSynchronizationObject()`'s own `is_array($object) === false`
graceful-skip block can run, because both `getOriginId()` and
`processSynchronizationObject()` are hinted `array $object` and PHP does not
coerce scalars across a strict type hint. The outer per-item `try/catch`
survives the batch but every item lands in `sync_item_dead_letter` with a raw
`TypeError` message, and there's no way for an admin to make such a sync
succeed. Coercing the scalar into a canonical `['value' => $scalar]` shape at
the earliest per-item boundary makes these items flow through the existing
mapping/identity/write pipeline like any other object.

`#1051`: the same spec section also documents a `sourceType: "array"`
dispatch case in `getAllObjectsFromSource()`'s switch statement that has
never existed in this codebase (`git log -S "case 'array':"` across all
history returns zero hits for that dispatch), was already wrong when the
2026-05-25 retrofit spec generated it from then-current code, and was never
exposed as a selectable option in `EditSynchronization.vue`'s `typeOptions`
(Database/API/File/Register-Schema only) — so there is no product intent
trail for it either. The spec must be corrected to describe what
`getAllObjectsFromSource()` actually does: recognise `register/schema` and
`database` as no-op branches and fall through to an empty array for any
other unrecognised `sourceType`, including `array`.

## Affected Projects
- [x] Project: `openconnector` — `lib/Service/SynchronizationService.php`
  (per-item scalar coercion) and `openspec/specs/synchronization-engine/spec.md`
  REQ-002 (spec correction)

## Scope

### In Scope
- Coerce a bare scalar source item into `['value' => <scalar>]` at the
  earliest per-item boundary (before `getOriginId()`/
  `processSynchronizationObject()` are called), guarded so array-shaped
  objects are completely unaffected.
- Document that a scalar-sourced synchronization needs
  `sourceConfig.idPosition` set to `'value'` for the coerced shape's identity
  extraction to resolve.
- Unit tests: a scalar item is coerced and synced instead of dead-lettered; a
  mixed scalar+object source list syncs both; existing object-source
  identity behaviour (hash, idPosition resolution) is unchanged.
- Correct `synchronization-engine` spec REQ-002 so it matches observed
  `getAllObjectsFromSource()` behaviour: `array` is not a recognised/
  dispatched `sourceType` (removed from the SHALL text and from the
  "array source is read without an HTTP call" scenario).

### Out of Scope
- Implementing `sourceType: "array"` dispatch in
  `getAllObjectsFromSource()`. If the product later wants a real
  static-array source type, this change (#1050's coercion) is a
  prerequisite, since a JSON array literal is the most likely source of
  bare scalars.
- Any change to identity-hash semantics (`hashObject()`, `sortNestedArray()`)
  for existing non-scalar sources.
- Frontend changes — `EditSynchronization.vue`'s `typeOptions` already
  excludes `array` and is left untouched.

## Approach
Add a single `is_array($object) === false` guard at the top of the per-item
loop in `synchronizeExternToIntern()` (the earliest point every item, from
every source type, passes through before hitting the `array`-hinted
methods), wrapping a bare scalar into `['value' => $object]`. This is a
one-line, cheap, allocation-free-on-the-common-path guard that changes
nothing for the (overwhelmingly common) array-shaped item case. Correct the
spec text for REQ-002 as a MODIFIED requirement, reframing `array` the same
way `register/schema` and `database` are already framed: recognised as a
`sourceType` value in principle but not dispatched by
`getAllObjectsFromSource()` today (falls through the switch to the
zero-objects default, same as any unrecognised type).

## New Dependencies
None.

## Impact
- `lib/Service/SynchronizationService.php`: `synchronizeExternToIntern()`'s
  per-item loop gains a scalar-coercion guard. `getOriginId()` and
  `processSynchronizationObject()` are unchanged in signature; their
  existing `array` type hints and dead-code defensive check are unaffected
  (the defensive `is_array() === false` block in
  `processSynchronizationObject()` remains as pre-existing dead code for a
  different, still-theoretically-possible caller path — removing it is out
  of scope for this change).
- `openspec/specs/synchronization-engine/spec.md`: REQ-002 text and its
  "array source is read without an HTTP call" scenario are corrected.
- Pre-existing `sync_item_dead_letter` rows created for scalar items before
  this change carried a raw `TypeError` message
  (`... must be of type array, string given`). After this change, genuinely
  scalar items no longer dead-letter at all (they coerce and sync); any
  *new* dead-letter rows for what used to be scalar-TypeError failures will
  only occur for a different downstream reason and will carry that reason's
  message instead — the message shape for this failure class changes
  because the failure class itself is eliminated at the source.

## Cross-Project Dependencies
None — self-contained within openconnector.

## Risks

### Risk 1: Coercion silently changes identity for a scalar source that relied on the old (always-failing) behaviour
**Severity:** Low — **Mitigation:** the old behaviour was 100% failure (every
scalar item dead-lettered), so there is no existing successful sync to
regress. The coercion is guarded by `is_array() === false`, so any
already-working array-shaped source is provably untouched — verified by a
regression unit test asserting existing object-source identity behaviour is
byte-for-byte unchanged.

### Risk 2: `idPosition` default (`'id'`) will not resolve on the coerced `['value' => ...]` shape
**Severity:** Low — **Mitigation:** documented in this proposal and in the
coercion's docblock: a scalar-sourced synchronization MUST set
`sourceConfig.idPosition` to `'value'`. `getOriginId()` already throws a
clear `Exception` naming the missing key when a configured `idPosition` does
not resolve, so a misconfigured scalar source fails loudly and
descriptively instead of dead-lettering with a raw `TypeError`.

## Rollback Strategy
Revert the coercion guard in `synchronizeExternToIntern()` and the REQ-002
spec text. No data migration, no schema change, no persisted-state change —
a straight code/spec revert fully restores prior behaviour (scalar items
dead-letter again with the old `TypeError` message shape).

## Open Questions
None.
