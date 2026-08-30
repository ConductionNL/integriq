# Test Plan: cdc-incremental-sync

## Test Cases

### TC-1: incremental run injects stored watermark into a templated endpoint
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016`
- **type**: api
- **persona**: N/A (backend engine behavior)
- **preconditions**: Synchronization with `syncMode: incremental`,
  `sourceConfig.endpoint: ".../items?updatedAfter={{ cursor }}"`,
  `cursorWatermark: "2026-07-01T00:00:00Z"`
- **steps**: trigger a run (`POST /api/synchronizations/{id}/run`) against
  a mocked/stub source that echoes the requested URL
- **expected result**: the source receives a request to
  `.../items?updatedAfter=2026-07-01T00:00:00Z`
- **test command**: `/test-api` (PHPUnit unit test on
  `getAllObjectsFromApi()` is the primary coverage; Newman covers the
  outer `run` endpoint contract)

### TC-2: incremental run injects stored watermark into a templated query parameter
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016`
- **type**: api
- **preconditions**: Synchronization with `syncMode: incremental`,
  `sourceConfig.query.updatedAfter: "{{ cursor }}"`, `cursorWatermark: "42"`
- **steps**: trigger a run against a mocked source capturing outbound query
  parameters
- **expected result**: outbound `updatedAfter` query parameter equals `"42"`
- **test command**: `/test-api`

### TC-3: full-mode run is unaffected by the cursor templating extension
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016`
- **type**: regression
- **preconditions**: Synchronization with `syncMode` absent (pre-existing
  fixture, unmodified)
- **steps**: trigger a run
- **expected result**: request endpoint/query are byte-identical to
  pre-change behavior; no `cursor` context key present
- **test command**: `/test-regression`

### TC-4: watermark advances after a complete fetch
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental`,
  `sourceConfig.cursorField: "updatedAt"`, source returns records with
  `updatedAt` up to `2026-07-15T09:00:00Z`, fetch completes normally
- **steps**: trigger a run; inspect persisted Synchronization afterward
- **expected result**: `cursorWatermark === "2026-07-15T09:00:00Z"`
- **test command**: `/test-functional`

### TC-5: watermark does not advance after a page failure mid-fetch
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental` and
  existing `cursorWatermark: "2026-07-01T00:00:00Z"`; mocked source returns
  HTTP 500 on page 2 of 3
- **steps**: trigger a run
- **expected result**: fetch marked incomplete (REQ-009); `cursorWatermark`
  unchanged at `"2026-07-01T00:00:00Z"` after the run
- **test command**: `/test-functional`

### TC-6: watermark does not advance after a 429 rate-limit
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental`; source
  returns HTTP 429 on first page
- **steps**: trigger a run
- **expected result**: `TooManyRequestsHttpException` (429) thrown to
  caller; `cursorWatermark` unchanged
- **test command**: `/test-functional`

### TC-7: watermark does not advance for a test run
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental`
- **steps**: `POST .../synchronizations/{id}/test`, fetch completes
  successfully
- **expected result**: `cursorWatermark` unchanged (REQ-011 parity)
- **test command**: `/test-functional`

### TC-8: missing cursorField throws rather than computing a wrong watermark
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-cursor-watermark-advances-only-after-a-complete-successful-fetch-req-017`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental`,
  `sourceConfig.cursorField: "updatedAt"`; one fetched record has no
  `updatedAt` value
- **steps**: trigger a run
- **expected result**: `Exception` thrown naming the missing field; no
  `cursorWatermark` change persisted
- **test command**: `/test-functional`

### TC-9: incremental mode blocks deletion even on a complete fetch
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental`, 100
  existing contracts; a complete incremental fetch returns 5 changed
  records (cursor-filtered, so 95 are absent from this run by design)
- **steps**: trigger a run
- **expected result**: `deleteInvalidObjects()` not invoked; 0 objects
  deleted; `result.objects.deletionGuard.reason === "incremental_mode"`
- **test command**: `/test-functional`

### TC-10: forceDeletion cannot override the incremental-mode block
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018`
- **type**: functional
- **preconditions**: same as TC-9
- **steps**: trigger a run with `forceDeletion: true`
- **expected result**: deletion still blocked; 0 objects deleted
- **test command**: `/test-functional`

### TC-11: deleteInvalidObjects() called directly still refuses on incremental
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental`
- **steps**: call `deleteInvalidObjects()` directly with
  `fetchComplete: true, forceDeletion: true`
- **expected result**: returns `0`; warning logged;
  `SynchronizationDeletionGuardedEvent` dispatched with
  `reason: incremental_mode`
- **test command**: `/test-functional` (PHPUnit-level; exercised via a
  direct service-layer test, not browser)

### TC-12: event-driven single-object delete path unaffected on incremental
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018`
- **type**: regression
- **preconditions**: Synchronization with `syncMode: incremental`,
  `sourceConfig.restrictDeletion: true`
- **steps**: fire an OpenRegister `ObjectDeletedEvent` for a synced object
- **expected result**: the single matching target object is deleted,
  unaffected by REQ-018 (this path never reaches the bulk-diff branch)
- **test command**: `/test-regression`

### TC-13: reset-cursor clears the watermark without touching syncMode
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019`
- **type**: api
- **preconditions**: Synchronization with `syncMode: incremental`,
  `cursorWatermark: "2026-07-10T00:00:00Z"`
- **steps**: `POST /api/synchronizations/{id}/reset-cursor`
- **expected result**: `200`; persisted `cursorWatermark` is null/absent;
  `syncMode` still `incremental`
- **test command**: `/test-api`

### TC-14: next run after reset requests an unfiltered fetch
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019`
- **type**: functional
- **preconditions**: watermark just cleared via TC-13,
  `sourceConfig.endpoint: ".../items?updatedAfter={{ cursor }}"`
- **steps**: trigger the next run
- **expected result**: rendered endpoint is `.../items?updatedAfter=`
  (empty cursor)
- **test command**: `/test-functional`

### TC-15: reset-cursor does not perform or re-enable deletion
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental`, 100
  existing contracts
- **steps**: `POST .../reset-cursor`, then trigger the next run (source
  honors empty cursor and returns its full set)
- **expected result**: reset-cursor itself deletes nothing; the subsequent
  run also does not invoke `deleteInvalidObjects()` (REQ-018 still applies)
- **test command**: `/test-functional`

### TC-16: reset-cursor against a missing synchronization returns 404
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019`
- **type**: api
- **preconditions**: no Synchronization with the given id
- **steps**: `POST /api/synchronizations/{bogus-id}/reset-cursor`
- **expected result**: `404`
- **test command**: `/test-api`

### TC-17: two successive incremental runs fetch/write only the delta (integration)
- **spec_ref**: proposal.md Scope item 5 / `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016`
- **type**: functional
- **preconditions**: Synchronization with `syncMode: incremental` against a
  synthetic paginated source with a mutable dataset
- **steps**: run 1 fetches/writes the full initial dataset and advances the
  watermark; mutate the source (add N new/changed records with newer
  `updatedAt`); run 2 executes
- **expected result**: run 2's fetch request is cursor-filtered to the new
  watermark; only the N new/changed records are fetched and written;
  contracts for the unrelated, unchanged records are untouched
- **test command**: `/test-functional`

### TC-18: sync mode field and reset-cursor action render in the SPA
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016`
- **type**: functional
- **preconditions**: authenticated admin on the Synchronizations page
- **steps**: open the Synchronization edit form; select `incremental`;
  configure a cursor field; save; open the detail/actions view
- **expected result**: the "Sync mode"/"Cursor field" fields persist
  correctly; a "Reset cursor" action is visible with clarifying help text
  for an incremental Synchronization
- **test command**: `/test-functional`

### TC-19: reset-cursor action is reachable and labeled clearly for a non-technical operator
- **spec_ref**: `openspec/changes/cdc-incremental-sync/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019`
- **type**: persona
- **persona**: Noor Yilmaz (Municipal CISO / Functional Admin) — needs to
  understand that reset-cursor does not delete data and does not restore
  deletion detection, per design.md's explicit caveat
- **preconditions**: incremental Synchronization configured
- **steps**: locate and read the reset-cursor action's tooltip/help text
- **expected result**: the text makes clear (a) only the cursor is cleared,
  (b) no data is deleted by this action, and (c) deletion detection stays
  off until `syncMode` is explicitly switched to `full`
- **test command**: `/test-persona-noor`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-016 (cursor-filtered fetch request) | TC-1, TC-2, TC-3, TC-18 |
| REQ-017 (watermark advance gating) | TC-4, TC-5, TC-6, TC-7, TC-8 |
| REQ-018 (deletion hard-blocked in incremental mode) | TC-9, TC-10, TC-11, TC-12 |
| REQ-019 (reset-cursor action) | TC-13, TC-14, TC-15, TC-16, TC-19 |
| REQ-004 (MODIFIED — deletion gate composition) | TC-9, TC-10, TC-12, TC-15 |
| Integration (two-run delta-only behavior) | TC-17 |

## Out of Scope
- Log-based CDC / binlog tailing — no DB-source adapter exists to test
  against (proposal.md Out of Scope); no test cases written.
- Automatic cursor-field inference — not implemented, nothing to test.
- Sub-run (per-page) watermark checkpointing — deliberately not
  implemented (design.md Non-Goals); no test cases written.
