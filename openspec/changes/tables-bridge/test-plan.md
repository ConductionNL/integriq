# Test Plan: tables-bridge

## Test Cases

### TC-1: First sync creates rows with contract originId↔rowId mapping
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001`
- **type**: api
- **persona**: n/a (backend engine behaviour)
- **preconditions**: a `nextcloud-table` target synchronization, `sourceType: api`, empty target table, no existing contracts
- **steps**: run the synchronization against 3 source objects
- **expected result**: 3 rows created via the Tables API; 3 contracts persisted with `originId` = source id, `targetId` = returned Tables row id
- **test command**: `/test-api`

### TC-2: Re-sync writes only changed rows (hash-based no-op)
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001`
- **type**: api
- **preconditions**: TC-1 state, one source object's mapped output changed since last sync
- **steps**: run the synchronization again
- **expected result**: exactly one `PUT .../rows/{rowId}` call; other rows receive no write call and their `targetHash` is unchanged
- **test command**: `/test-api`

### TC-3: Title-keyed column mapping resolves to current columnId
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001`
- **type**: functional
- **preconditions**: table has a column titled "Amount" (id 7); `columnMapping` references "Amount"
- **steps**: run a create/update
- **expected result**: write payload's `data` object keys use the numeric column id (`"7"`), not the title
- **test command**: `/test-functional`

### TC-4: Ambiguous column title is a hard config error, never a guess
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001`
- **type**: functional
- **preconditions**: table has two columns both titled "Status"; mapping references "Status"
- **steps**: attempt a row write
- **expected result**: that row's write fails with a config-error log naming the ambiguous title and match count; no first-match guess occurs
- **test command**: `/test-functional`

### TC-5: Tables-as-source rows feed the mapping pipeline
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002`
- **type**: api
- **preconditions**: a `sourceType: nextcloud-table` synchronization against a table with 50 rows across multiple pages
- **steps**: run the synchronization
- **expected result**: all 50 rows are fetched (paginated) and each row's `data` passes through `MappingService` like any other source
- **test command**: `/test-api`

### TC-6: Unchanged source row produces no downstream write
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002`
- **type**: api
- **preconditions**: TC-5 state, re-run with no row content changes
- **steps**: run the synchronization again
- **expected result**: `hashObject()` matches `sourceHash` for every row; no downstream target write occurs
- **test command**: `/test-api`

### TC-7: Number column coercion respects numberDecimals
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003`
- **type**: functional
- **preconditions**: target column `type: number, numberDecimals: 2`; mapped value `"19.999"`
- **steps**: write the row
- **expected result**: value coerced to a float represented per `numberDecimals` before being sent
- **test command**: `/test-functional`

### TC-8: Non-numeric value fails only that row, run continues
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003`
- **type**: functional
- **preconditions**: target `number` column; one of several rows in the run has mapped value `"not-a-number"`
- **steps**: run the synchronization
- **expected result**: the offending row is skipped with a logged coercion-failure entry; the other rows are still written
- **test command**: `/test-functional`

### TC-9: Selection value with no matching option fails that row
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003`
- **type**: functional
- **preconditions**: target `selection` column with options `open/paid/overdue`; mapped value `"cancelled"`
- **steps**: write the row
- **expected result**: write skipped with a logged entry naming the column, offending value, and allowed options
- **test command**: `/test-functional`

### TC-10: Tables app absent hides the type in the editor
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-feature-detection--tables-app-absence-hides-the-type-entirely-req-004`
- **type**: functional
- **preconditions**: Tables app not installed
- **steps**: open the synchronization editor's source/target kind selector
- **expected result**: `nextcloud-table` is not offered
- **test command**: `/test-functional`

### TC-11: Run against nextcloud-table fails cleanly when Tables is disabled
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-feature-detection--tables-app-absence-hides-the-type-entirely-req-004`
- **type**: api
- **preconditions**: a synchronization already configured with `nextcloud-table`; Tables subsequently disabled
- **steps**: run the synchronization
- **expected result**: run fails with a config-error log naming the missing dependency; zero HTTP calls to any Tables endpoint (assert via CallLog absence)
- **test command**: `/test-api`

### TC-12: Absent-from-source rows are deleted after a complete, successful fetch
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-source-deleted-rows-are-removed-only-under-the-shared-deletion-safety-guard-req-005`
- **type**: api
- **preconditions**: 10 existing contracts; source fetch completes successfully returning 9 of 10 origin ids
- **steps**: run `deleteInvalidObjects()`
- **expected result**: the one missing row is deleted; its contract removed
- **test command**: `/test-api`

### TC-13: A failed or partial source fetch does not trigger row deletion
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-source-deleted-rows-are-removed-only-under-the-shared-deletion-safety-guard-req-005`
- **type**: api
- **preconditions**: source fetch for the run errors or returns a partial page set (incomplete run)
- **steps**: attempt `deleteInvalidObjects()`
- **expected result**: no row deletion attempted; run log records deletion skipped due to incomplete fetch
- **test command**: `/test-api`

### TC-14: Permission denied on first write aborts the run with no partial writes
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-permission-denied-writes-fail-the-run-not-a-partial-subset-of-rows-req-006`
- **type**: security
- **preconditions**: configured Source credential lacks write access; run has 5 objects to write
- **steps**: run the synchronization
- **expected result**: run fails on the first 403; remaining 4 rows never attempted; no contract created/updated for any unattempted write
- **test command**: `/test-security`

### TC-15: Table list reflects the configured identity's access
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007`
- **type**: api
- **preconditions**: a Source credential that can see 2 of 5 existing tables
- **steps**: call the table-list discovery endpoint with that Source's id
- **expected result**: exactly the 2 accessible tables are returned
- **test command**: `/test-api`

### TC-16: Column list includes type metadata for the mapping helper
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007`
- **type**: api
- **preconditions**: a table with a `number` column and a `selection` column
- **steps**: call the column-list discovery endpoint
- **expected result**: response includes `type`/`subtype`/constraints (e.g. `selectionOptions`) for each column
- **test command**: `/test-api`

### TC-17: nextcloud-table dispatch does not affect other target types
- **spec_ref**: `openspec/changes/tables-bridge/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-006`
- **type**: regression
- **preconditions**: existing `register/schema`, `api`, `database`, and unrecognised-type synchronizations
- **steps**: run each
- **expected result**: behaviour unchanged from pre-change baseline — `register/schema`/`api` continue to work, `database` remains a no-op, an unrecognised type still throws `Unsupported target type`
- **test command**: `/test-regression`

### TC-18: Table picker end-to-end in the editor
- **spec_ref**: `openspec/changes/tables-bridge/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006`
- **type**: functional
- **preconditions**: Tables app enabled, at least one accessible table
- **steps**: open the synchronization editor, select `nextcloud-table`, pick a Source, pick a table
- **expected result**: `tableId` is set in the relevant config object and persists on save
- **test command**: `/test-functional`

### TC-19: Column-mapping helper renders type hints and saves title-keyed mapping
- **spec_ref**: `openspec/changes/tables-bridge/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007`
- **type**: functional
- **preconditions**: TC-18 state, table has a `number` and a `selection` column
- **steps**: open the column-mapping helper, map the "Amount" column, save
- **expected result**: helper displays type/constraints for both columns; saved `columnMapping` is keyed by column title
- **test command**: `/test-functional`

### TC-20: Accessibility of the table picker and column-mapping helper
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: TC-18/TC-19 UI rendered
- **steps**: run WCAG AA checks against the new selector/inputs
- **expected result**: labelled controls, keyboard-navigable, matches existing `SyncConfigWidget.vue` register/schema picker conventions
- **test command**: `/test-accessibility`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| tables-bridge REQ-001 (target) | TC-1, TC-2, TC-3, TC-4 |
| tables-bridge REQ-002 (source) | TC-5, TC-6 |
| tables-bridge REQ-003 (coercion) | TC-7, TC-8, TC-9 |
| tables-bridge REQ-004 (feature detection) | TC-10, TC-11 |
| tables-bridge REQ-005 (safe deletion) | TC-12, TC-13 |
| tables-bridge REQ-006 (permission denied) | TC-14 |
| tables-bridge REQ-007 (discovery API) | TC-15, TC-16 |
| synchronization-engine REQ-014 (dispatch) | TC-17 (+ TC-1 through TC-14 exercise dispatch implicitly) |
| sync-editor-ui REQ-SYNCUI-006 (picker) | TC-18 |
| sync-editor-ui REQ-SYNCUI-007 (mapping helper) | TC-19 |
| tables-bridge Non-Functional (a11y/i18n) | TC-20 (i18n string presence verified in Task 12 of tasks.md, not a standalone TC) |

## Out of Scope

- `usergroup`-type column write coercion — not specced (tables-bridge REQ-003 explicitly excludes it); no test case.
- Cross-instance (federated) Tables sync UX beyond "enter a base URL and credential" — no dedicated pairing/discovery flow exists to test (design.md Non-Goals).
- Column/table auto-creation — out of scope per proposal.md; no test case.
- The deletion-ratio/incomplete-run guard's own threshold behaviour (e.g. "abort when >10% of contracts would be deleted") is `sync-safety-guardrails`'s test surface, not this change's — TC-12/TC-13 verify only that `nextcloud-table` composes with whatever that guard's entry point does, not the guard's internal threshold logic.
