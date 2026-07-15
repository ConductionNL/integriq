# Proposal: cdc-incremental-sync

## Summary
Add a cursor-based `incremental` sync mode to OpenConnector's Synchronization
engine, alongside the existing (default, unchanged) `full` hash-diff mode.
When `syncMode: incremental`, an extern→intern run requests only source
records changed since a stored high-watermark cursor (via the engine's
existing Twig request-config templating), advances that watermark only after
a complete, successful fetch, and never runs the source-diff garbage
collection pass (`deleteInvalidObjects()`) — an incremental fetch never sees
the full source set, so absence from one page is not evidence of deletion.
An explicit reset-cursor action clears the watermark and forces the next run
back to a full sync. This closes a competitive gap against Airbyte-style
incremental/CDC sync for large, high-volume sources where full-scan-per-run
is prohibitively expensive.

## Motivation
OpenConnector's current sync model (`SynchronizationService::
synchronizeExternToIntern()`) always fetches the entire source result set on
every run, computes an order-independent hash per object, and diffs against
stored `SynchronizationContract` hashes to detect changes — a correct but
O(source size) approach on every pass. For large or frequently-polled
sources (e.g. a `nextcloud-table`, TED, or registry-mirror source with tens
of thousands of records) this means every scheduled run re-fetches and
re-hashes records that have not changed since the last run, which is both
slow and — for rate-limited API sources — wasteful of a scarce quota
(`checkRateLimit()`/`rateLimitRemaining` in REQ-002). Airbyte and comparable
integration platforms offer cursor-based incremental sync as a first-class
mode specifically to avoid this. Now is the right time because the
sync-safety-guardrails change (archived 2026-07-14) already added the two
correctness primitives incremental sync must compose with without
regressing: fetch-completeness tracking (REQ-009) and deletion gating
(REQ-010) — this change reuses both rather than inventing parallel ones.

## Affected Projects
- [ ] Project: `openconnector` — new `syncMode` field + cursor watermark
  field on the Synchronization schema, cursor-filtered fetch path in
  `SynchronizationService`, incremental-aware deletion gating, and a
  reset-cursor REST action + SPA control.

## Scope

### In Scope
1. `syncMode` on a Synchronization: `full` (current default, unchanged
   behavior) | `incremental`.
2. Cursor field configuration: which source field is the cursor (e.g.
   `updatedAt`, an id, a page token), a comparator, and the stored
   high-watermark value itself, persisted on the Synchronization OR object
   (see design.md Decision 1).
3. On an incremental run: inject the stored watermark into the outbound
   source request via the engine's existing Twig endpoint-templating
   mechanism (extended to `sourceConfig.query` values — design.md Decision
   2), so the source is asked for only records newer than the watermark;
   process the returned (delta-only) records through the existing
   mapping/write pipeline unchanged; advance the watermark **only** after a
   complete, successful fetch (composing with REQ-009's `fetchInfo` —
   design.md Decision 3); and **never** invoke `deleteInvalidObjects()` for
   an incremental run, regardless of fetch-completeness or deletion ratio —
   an incremental fetch is a strict subset of the source, so non-appearance
   is not deletion evidence.
4. A reset-cursor action (`POST /api/synchronizations/{id}/reset-cursor`)
   that clears the stored watermark so the synchronization's next run
   requests an unfiltered (empty-cursor) fetch — full-equivalent for a
   source whose templated request treats an absent cursor as "no filter."
   This action clears the watermark only; it does **not** change
   `syncMode`, and therefore does **not** re-enable `deleteInvalidObjects()`
   — that stays hard-disabled for as long as `syncMode` is `incremental`
   (see item 3 and design.md Decision 3). Restoring deletion detection
   requires explicitly switching `syncMode` back to `full`.
5. Tests: unit coverage for watermark-advances-only-on-complete-fetch,
   watermark-does-not-advance-on-incomplete/failed fetch, and
   no-deletion-in-incremental-mode; integration coverage for two successive
   incremental runs fetching/writing only the delta between them.

### Out of Scope
- Log-based CDC (database binlog / WAL tailing) — OpenConnector has no
  DB-source adapter today (`getAllObjectsFromSource()`'s `database` branch
  is a documented no-op per the base synchronization-engine spec), so
  binlog-based CDC has no source to attach to. Filed as a follow-up once a
  DB-source adapter exists.
- Automatic cursor-field discovery/inference from a source's schema —
  `cursorField` is admin-configured, same convention as `idPosition`
  (REQ-003's `getOriginId()`).
- Per-page/partial watermark checkpointing within a single in-progress run —
  the watermark advances once, after the whole run's fetch completes (or not
  at all); this is a deliberate consequence of composing with REQ-009's
  all-or-nothing fetch-completeness signal, not an oversight.

## Approach
Add two new fields to the Synchronization OR schema (`syncMode`,
`cursorWatermark`) following the existing convention already used for
`currentPage`/`targetLastSynced` (transient per-pass state stored directly
on the Synchronization object, no separate entity). Branch
`synchronizeExternToIntern()`'s fetch stage on `syncMode`: when
`incremental`, resolve the stored watermark and thread it into the same Twig
context (`{{ cursor }}`) `getAllObjectsFromApi()` already uses for
`{{ data.* }}` endpoint templating, extending that templating to
`sourceConfig.query` values as well (currently endpoint-only). After a
successful, complete fetch, compute the new high-watermark from the fetched
records' configured `cursorField` (a dotted-path extraction mirroring
`getOriginId()`) and persist it onto the Synchronization alongside
`targetLastSynced`. Gate `deleteInvalidObjects()` on `syncMode !== 'incremental'`
at the exact call site that already gates it on `fetchComplete` (REQ-010),
plus a defense-in-depth check inside `deleteInvalidObjects()` itself. Add a
`resetCursor()` controller action mirroring the existing `activate`/
`deactivate` action pattern on `SynchronizationsController`.

## New Dependencies
None — reuses the existing Twig (`MappingService::renderTemplateString()`)
templating engine already wired for endpoint substitution; no new package.

## Impact
- `lib/Service/SynchronizationService.php`: `synchronizeExternToIntern()`,
  `getAllObjectsFromApi()`, `deleteInvalidObjects()`, plus new private
  helpers for cursor extraction/persistence.
- `lib/Settings/openconnector_register.json`: `synchronization` schema gains
  `syncMode` and `cursorWatermark` properties; `sourceConfig`'s free-text
  description gains the new recognised keys (`cursorField`,
  `cursorComparator`).
- `lib/Controller/SynchronizationsController.php` + `appinfo/routes.php`:
  new `resetCursor()` action / route.
- SPA: a "Sync mode" field and "Reset cursor" action on the Synchronization
  edit form (src/modals or equivalent — implementation detail for tasks.md).
- `openspec/specs/synchronization-engine/spec.md`: new requirements above
  REQ-015 (the current highest numbered requirement in this spec).

## Cross-Project Dependencies
None — this is entirely internal to OpenConnector's own sync engine and REST
surface; no other apps-extra project consumes a new API from this change.

## Risks

### Risk 1: A misconfigured `cursorField` silently produces a monotonically-wrong watermark
**Severity:** High — **Mitigation:** `cursorField` extraction reuses
`getOriginId()`'s existing dotted-path-lookup-with-throw pattern (REQ-003):
a record missing the configured cursor field throws rather than silently
treating it as the lowest possible cursor value, which would otherwise
cause that record's siblings to be permanently skipped on every subsequent
run. Also require `cursorField` to be an ISO-8601 timestamp or a
lexicographically/numerically comparable value; document the constraint
in the schema description like `idPosition` already is.

### Risk 2: Deleted-then-recreated source records are invisible to incremental sync
**Severity:** Medium — **Mitigation:** This is an inherent limitation of
cursor-based incremental sync (also true of Airbyte), not something this
change can special-case — document it explicitly in the schema description
and the spec's Notes so admins choose `full` mode for sources where
deletion detection matters, and use the reset-cursor action to periodically
force a full reconciliation pass.

### Risk 3: Watermark advance and deletion-skip must never regress the sync-safety-guardrails invariants
**Severity:** Medium — **Mitigation:** Both new behaviors are implemented
at the exact call site that already threads REQ-009's `$fetchComplete`
through to REQ-010's deletion gate (`synchronizeExternToIntern()` Stage 5,
`lib/Service/SynchronizationService.php` ~line 1781) rather than as a
parallel code path, so the two concerns cannot drift apart. Unit tests
assert both the incomplete-fetch-blocks-watermark-advance case and the
incremental-mode-blocks-deletion case independently.

## Rollback Strategy
`syncMode` defaults to `full` on the schema, and every existing
Synchronization object predates this field, so an unset `syncMode` is
treated as `full` — a no-op rollback requires no data migration; reverting
the code change alone restores prior behavior exactly, since no existing
Synchronization can already be in `incremental` mode. If an operator has
already opted synchronizations into `incremental` mode, running the
reset-cursor action (or manually clearing `syncMode`) before rollback avoids
any confusion from a since-orphaned `cursorWatermark` value being read by
older code (which will simply ignore it, as it is an unrecognised field).

## Open Questions
- Should `cursorComparator` support anything beyond `gt`/`gte` (e.g. a
  source-specific opaque page-token comparator that isn't numerically or
  lexicographically ordered)? Deferred to design.md Decision 2 — `gt`/`gte`
  covers the `updatedAt`-timestamp and monotonic-id cases in scope; a
  token-cursor source can still work by treating the token as an opaque
  string substituted via `{{ cursor }}` without the engine interpreting its
  ordering at all.
