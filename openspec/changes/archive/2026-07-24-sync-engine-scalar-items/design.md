# Design: sync-engine-scalar-items

## Architecture Overview
No architectural change. This is a localized fix inside
`SynchronizationService`'s existing extern→intern pull pipeline
(`synchronizeExternToIntern()` → per-item loop → `processSynchronizationObject()`
→ `getOriginId()`/mapping/write) plus a text-only correction to the
`synchronization-engine` capability spec. No new services, controllers, or
data model changes.

```
getAllObjectsFromSource()  →  $objectList (array of items; items MAY be scalar)
        │
        ▼
foreach ($objectList as $object)          ◄── NEW: coercion guard lands here
        │  if (is_array($object) === false) { $object = ['value' => $object]; }
        ▼
processSynchronizationObject($object, ...)  ◄── array-hinted, now always satisfied
        │
        ▼
getOriginId($synchronization, $object)      ◄── array-hinted, now always satisfied
```

## Goals / Non-Goals
**Goals:**
- Make a bare-scalar source item flow through the existing pipeline instead
  of dying at the `array`-typed method boundary.
- Correct REQ-002 of the `synchronization-engine` spec to match the actual,
  verified behaviour of `getAllObjectsFromSource()`.

**Non-Goals:**
- Implementing `sourceType: "array"` dispatch (explicitly deferred, see
  proposal Out of Scope).
- Touching identity-hash semantics for object-shaped sources.
- Any frontend/UI change.

## Decisions

### Decision 1: Coerce at the per-item loop boundary, not inside `getAllObjectsFromSource()` / `getAllObjectsFromArray()`
**Chosen:** add the `is_array($object) === false` guard as the first
statement inside the `foreach ($objectList as $object)` loop in
`synchronizeExternToIntern()`, immediately before the `try` block that calls
`processSynchronizationObject()`.

**Why:** every item from every `sourceType` (api, nextcloud-table,
nextcloud-form, and any future source) passes through this exact loop before
reaching the `array`-hinted methods — it is the single earliest point common
to all callers. Placing the guard inside a specific fetch method (e.g.
`getAllObjectsFromApi()`) would only protect that one source type and would
need to be duplicated per fetcher.

**Alternatives considered:**
- *Coerce inside `getOriginId()` and `processSynchronizationObject()`
  themselves* — rejected: both are `array`-hinted, so a scalar argument
  already throws a `TypeError` at the call boundary before either method
  body executes; coercion has to happen strictly before the call, not
  inside the callee.
- *Widen the type hints to `array|string|int|float $object`* — rejected:
  this pushes the "what is a valid item shape" question into every method
  that touches `$object` downstream (mapping, hashing, related-id rewriting
  all assume array access), multiplying the surface that needs
  scalar-awareness instead of normalizing once at the boundary.
- *Reject at config-validation time when `sourceConfig` implies a scalar
  result* — rejected per the brief: this would newly hard-block any
  synchronization already pointed at a scalar source, which today at least
  partially "runs" (it just dead-letters every item). Coercion is strictly
  additive/non-regressive for that case.

### Decision 2: Coerced shape is `['value' => $scalar]`, requiring `sourceConfig.idPosition: 'value'`
**Chosen:** wrap the scalar under a single `value` key. Do not attempt to
auto-detect or default `idPosition` to `'value'` when the item is scalar.

**Why:** `getOriginId()`'s `idPosition` contract already supports an
explicit override via `sourceConfig.idPosition` (default `'id'`, dotted-path
lookup via `adbario/php-dot-notation`). Reusing that existing, documented
mechanism means no new configuration surface is introduced — a scalar
source is just a source whose items happen to need `idPosition: 'value'`.
Auto-defaulting the `idPosition` specifically for scalar items would create
an implicit, type-dependent config-resolution rule that the rest of the
`idPosition` contract does not have (it does not vary by item shape today).

**Alternatives considered:**
- *Auto-set `idPosition` to `'value'` internally when the item was
  scalar-coerced* — rejected: adds an implicit, hard-to-discover special
  case to `getOriginId()`; an admin who does configure `idPosition`
  explicitly for another reason (e.g. hashing a different derived key) would
  have that silently overridden.
- *Hash the scalar itself for identity instead of requiring `idPosition`* —
  rejected: bypasses the existing `idPosition` contract entirely for one
  item shape, and the existing `getOriginId()` exception-on-missing-key
  behaviour already gives a clear, actionable error when `idPosition` is
  misconfigured (`Could not find origin id in object for key: <position>`).

### Decision 3: Spec correction reframes `array` as recognised-but-not-dispatched, matching `register/schema`/`database`
**Chosen:** amend REQ-002's SHALL text to drop the claim that the system
"SHALL support `array` (static) sources directly," and instead state that
`array`, like `register/schema` and `database`, falls through
`getAllObjectsFromSource()`'s switch with no matching case (silently
returns the initialised empty `$objects` array). Remove the "array source is
read without an HTTP call" scenario (it describes behaviour that does not
exist) and add a scenario asserting the fall-through/empty-result behaviour
for an unrecognised `sourceType` including `array`.

**Why:** the evidence (verified independently, see final report) shows the
`case 'array':` dispatch has never existed in this codebase's history, was
already fabricated when the 2026-05-25 retrofit spec was generated from
then-current code, and was never exposed as a selectable option in the
editor UI. Reframing it identically to how `register/schema` and `database`
are already (correctly) described keeps REQ-002 internally consistent — all
three are "known `sourceType` values with no working dispatch today" rather
than singling `array` out as a removed/never-existed case, which would read
as a behaviour change when it is a documentation correction.

**Alternatives considered:**
- *Remove all mention of `array` from REQ-002 entirely* — considered
  viable, but keeping a corrected mention (grouped with `register/schema`/
  `database`) documents the `sourceType` value's existence in the UI/config
  contract search space and avoids a future re-fabrication of the same
  claim from someone assuming `array` was never a considered value at all.
  Chosen over silent removal for discoverability.

## Risks / Trade-offs
- [Risk] A scalar-sourced synchronization configured before this change
  (which today dead-letters 100% of items) will start actually writing
  objects once deployed, using whatever `idPosition`/mapping is currently
  configured — if that config was never validated against real coerced
  shapes, the first live run could behave unexpectedly (e.g. `idPosition`
  still defaulted to `'id'`, which will throw a clear, actionable
  `Could not find origin id in object for key: id` per item instead of
  silently dead-lettering with a raw `TypeError`) → Mitigation: this is a
  strict improvement (actionable error vs. opaque error) and is called out
  explicitly in the proposal's Impact section; no data loss, no silent
  corruption.
- [Trade-off] The pre-existing `is_array($object) === false` defensive block
  inside `processSynchronizationObject()` (~lines 7064-7079) becomes even
  more unreachable than before (the loop-level guard now runs first) — left
  in place rather than removed, since deleting genuinely-dead defensive code
  that guards against a different theoretical caller path is out of scope
  for this fix and not requested by #1050/#1051.

## Migration Plan
No database/schema migration. Deploy as a normal code + spec change.
Rollback is a plain revert (see proposal's Rollback Strategy) — no forward-
only state is created.

## Open Questions
None.
