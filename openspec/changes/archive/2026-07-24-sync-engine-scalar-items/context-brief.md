# Context Brief: sync-engine-scalar-items

## What
Two related synchronization-engine corrections, one code fix and one spec correction. Closes openconnector **#1050** and **#1051**.

## #1050 — bare-scalar source items dead-letter the whole per-item pipeline (CODE FIX)
`SynchronizationService::getOriginId()` (~line 2315) and `::processSynchronizationObject()` (~line 7049) are type-hinted `array $object`. PHP's `array` hint never coerces, so a source returning a bare array of strings (e.g. `https://endoflife.date/api/all.json`, which returns `["php","nodejs",...]`) throws a `TypeError` **at the call boundary** — before the method body runs.

Consequence: `processSynchronizationObject()`'s own defensive `is_array($object) === false` graceful-skip block (~lines 7064-7079, comment: *"We can only deal with arrays (based on the source empty values or string might be returned)"*) is **unreachable dead code**. The outer per-item `try/catch (\Throwable)` in `synchronizeExternToIntern()` (~lines 1713-1730) catches the TypeError and dead-letters via `captureSyncItemFailure()`, so the batch survives — but every scalar item lands in `sync_item_dead_letter` with a raw `"must be of type array, string given"` message, and a fully-scalar source yields zero successful syncs with no actionable error.

**Chosen fix: coerce, do not reject.** Wrap scalars into a canonical shape at the earliest boundary (the per-item loop or `getAllObjectsFromSource()`):
```php
if (is_array($object) === false) { $object = ['value' => $object]; }
```
Coercion is safer than config-validation-reject for existing synchronizations: rejecting at config time would newly hard-block any sync already pointed at a scalar source (today it partially runs). Document that scalar sources need `sourceConfig.idPosition` set to `'value'` (or that the scalar itself is hashed for identity) so the existing `idPosition` contract (default `'id'`) still holds. Guard the coercion behind `is_array() === false` so identity-hash semantics for existing non-scalar sources are untouched.

## #1051 — `sourceType: "array"` is spec'd but never dispatched (SPEC FIX, do NOT implement)
`getAllObjectsFromSource()`'s `switch ($type)` (~lines 4023-4060) has cases for `register/schema`, `api`, `database`, `nextcloud-table`, `nextcloud-form` — **no `case 'array':`**. A synchronization configured with `sourceType: "array"` silently falls through to `$objects = []` and returns `found: 0`. The `synchronization-engine` spec REQ-002 SHALLs this behaviour and names `getAllObjectsFromArray()` as the implementer.

**Evidence says the spec is fabricated, not drifted — correct the spec:**
1. `git log -S "case 'array':"` across all history returns **zero** hits — the case never existed.
2. The identical claim appears verbatim in the 2026-05-25 **reverse-engineered/retrofit** spec (`openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/...`), i.e. it was already wrong when generated from the then-current code.
3. `EditSynchronization.vue`'s `typeOptions` (~lines 402-410) only ever offered Database / API / File / Register-Schema — `array` was never user-selectable, so there is no product-intent evidence.

`getAllObjectsFromArray()` **does** exist (~lines 5417-5455) but is wired only inside `getAllObjectsFromApi()`'s response-body parsing (extracting an item list via `resultsPosition`), which is a different concern.

Action: amend the canonical `synchronization-engine` spec so REQ-002 matches reality — reframe `array` the way `register/schema` and `database` are already framed ("recognised but not implemented / no-op"), or drop the `array` mention entirely. **Do not implement the dispatch.** Note in the proposal that if the product later wants a real static-array source, #1050 must land first (a JSON array literal is the most likely source of bare scalars).

## Scope
IN: the scalar coercion + its unit tests; the `synchronization-engine` spec correction; a regression test that a mixed scalar+object source list syncs rather than dead-letters.
OUT: implementing `sourceType: "array"`; any change to identity-hash semantics for existing object sources; frontend changes.

## Current state (read first)
- `lib/Service/SynchronizationService.php` — `getOriginId()`, `processSynchronizationObject()`, `synchronizeExternToIntern()` per-item loop, `getAllObjectsFromSource()`, `getAllObjectsFromArray()`.
- `openspec/specs/synchronization-engine/spec.md` — REQ-002 is the requirement to correct.
- Existing dead-letter machinery: `captureSyncItemFailure()`, `sync_item_dead_letter`.

## Design constraints
- Follow openconnector's own `openspec/config.yaml` rules and existing spec conventions.
- Hot per-item loop — the coercion must be a cheap `is_array()` guard, no behaviour change for existing sources.
- Flag in the PR that pre-existing dead-letter rows for scalar items will now have a different message shape (message-format change, not data loss).
- SPDX docblocks on changed PHP; ADR-009 tests.
- Spec deltas: `### Requirement: <name>` headers, MUST/SHALL on the FIRST physical line, no angle brackets in requirement bodies.
- `@spec` anchors → canonical `openspec/specs/<capability>/spec.md#requirement-<kebab>`, never a change dir.
