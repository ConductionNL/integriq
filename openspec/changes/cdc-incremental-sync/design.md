# Design: cdc-incremental-sync

## Architecture Overview
Incremental sync is a mode flag on the existing Source → Synchronization →
SynchronizationContract triad (ADR-005) — it does not introduce a new
entity or a parallel sync path. It changes three things inside the existing
extern→intern flow in `SynchronizationService`:

```
synchronize()
  └─ synchronizeExternToIntern()
       ├─ Stage 2: getAllObjectsFromSource() → getAllObjectsFromApi()
       │     [NEW] when syncMode=incremental: inject stored cursorWatermark
       │           into the Twig context already used for {{ data.* }}
       │           endpoint templating, extended to sourceConfig.query too
       ├─ Stage 4: per-object processSynchronizationObject() loop  (unchanged)
       ├─ Stage 5: deleteInvalidObjects() gate
       │     [NEW] syncMode=incremental short-circuits this call entirely,
       │           at the same site fetchComplete (REQ-009/REQ-010) already
       │           gates it
       └─ end-of-run persistSynchronization()
             [NEW] when syncMode=incremental AND fetchComplete: compute and
                   persist the new cursorWatermark from the fetched records
```

This mirrors exactly how `currentPage` (pagination-in-progress) and
`targetLastSynced` (last successful pass) already round-trip through
`$synchronization` as plain array/OR-object fields — `cursorWatermark` is a
third field in that same family, not a new subsystem.

## Goals / Non-Goals

**Goals:**
- Let large/high-volume `api` sources skip already-synced records on every
  run after the first, via a stored high-watermark cursor.
- Guarantee the watermark can never advance past data the engine has not
  durably processed (composes with REQ-009 fetch-completeness).
- Guarantee `deleteInvalidObjects()` never runs against a partial view of
  the source (incremental mode is *always* a partial view, by definition).
- Give operators an explicit, auditable way back to a full baseline
  (reset-cursor).

**Non-Goals:**
- Log-based/binlog CDC (no DB-source adapter exists to attach to — see
  proposal.md Out of Scope).
- Automatic cursor-field inference.
- Sub-run (per-page) watermark checkpointing — REQ-009's fetch-completeness
  signal is already whole-fetch, all-or-nothing; incremental sync composes
  with it as-is rather than adding a second, finer-grained completeness
  concept.
- Deletion detection for incremental mode by any means (e.g. a "soft"
  ratio-based partial guard) — REQ-010's existing ratio guard is explicitly
  a *bulk full-fetch* diff mechanism; giving it a partial-fetch input would
  silently reintroduce exactly the false-positive deletions the guardrails
  change was written to prevent. Incremental mode's deletion answer is "not
  supported, use `full` mode periodically or the reset-cursor action."

## Decisions

### Decision 1: Cursor watermark storage — Synchronization object field, not a `sync_cursor` object
**Choice:** Add `cursorWatermark` (string) and `syncMode` (string enum
`full`|`incremental`, default `full`) as top-level properties on the
existing `synchronization` schema in
`lib/Settings/integriq_register.json`, alongside the pre-existing
`currentPage` (pagination-in-progress cursor) and `targetLastSynced` fields.

**Why:** The codebase already has an established, working convention for
exactly this kind of "small piece of per-pass state that belongs to one
Synchronization" data: `currentPage` is read/reset directly on
`$synchronization` inside `getAllObjectsFromApi()`
(`SynchronizationService.php` ~L3941-3980), and `targetLastSynced` is
written directly onto `$synchronization` at the end of
`synchronizeExternToIntern()` (~L1861-1864) via
`persistSynchronization()`. A cursor watermark is the same shape of fact —
one value, one owner, updated at the same point in the same method. Reusing
the field-on-Synchronization pattern means:
- No new OR schema, no new register entry, no new REST surface to fetch/
  list watermarks.
- No new join/lookup on every fetch — the watermark is already in memory
  wherever `$synchronization` is (it is loaded once per run via
  `toSynchronization()`).
- Rollback is free (see proposal.md Rollback Strategy) — an unset field is
  just `null`/absent, exactly like every pre-existing Synchronization today
  has no opinion on `currentPage` beyond its default.

**Alternative considered — separate `sync_cursor` OR object (1 per
Synchronization, or 1 per Synchronization × cursor-field):** Rejected.
A separate object would need its own schema, its own find-or-create
resolution on every run (an extra OR round-trip per sync pass, on the hot
path), and — worse — a second place where "did this run's watermark update
actually commit" could diverge from whether the run itself committed,
reintroducing a variant of the exact split-state problem
sync-safety-guardrails REQ-011 (test runs make no writes) was written to
close for contracts/targets. A dedicated object would earn its keep if
watermarks needed independent versioning/history (e.g. audit trail of every
watermark value ever set) — not needed here; `targetLastSynced` doesn't get
that either, and this field is symmetric with it.

### Decision 2: Cursor filter injection — extend the existing Twig endpoint-templating context, not a second templating mechanism
**Choice:** `getAllObjectsFromApi()` already Twig-renders
`sourceConfig.endpoint` when it contains `{{`/`}}`, via
`MappingService::renderTemplateString(template: $endpoint, context: ['data'
=> $contextData])` (SynchronizationService.php ~L3888-3904). This change
adds a `cursor` key to that same context —
`context: ['data' => $contextData, 'cursor' => $cursorWatermark]` — so an
admin can write `sourceConfig.endpoint: ".../items?updatedAfter={{ cursor
}}"`. It also extends the identical `{{`/`}}`-detection-then-
`renderTemplateString()` treatment to each scalar value in
`sourceConfig.query` (currently passed through to `$config['query']`
verbatim, untemplated), so an admin can instead write
`sourceConfig.query.updatedAfter: "{{ cursor }}"` when the source takes the
cursor as a query parameter rather than a path/endpoint segment. `cursor`
resolves to an empty string on a synchronization's first-ever incremental
run (no prior watermark) — sources whose API treats an absent/empty cursor
parameter as "give me everything" get a correct first full-ish incremental
baseline for free; sources that require a non-empty value document that in
their `sourceConfig.query` default (e.g.
`sourceConfig.query.updatedAfter: "{{ cursor|default('1970-01-01') }}"`,
which Twig's `default` filter already supports with no engine change).

**Why:** "Reuse the existing request-config templating" is an explicit
proposal constraint. Endpoint templating is the only templating already
wired into the fetch path; the minimal, lowest-risk change is widening its
context by one key and widening its application from one field (endpoint)
to one more (query values) using the exact same
detect-`{{`-then-`renderTemplateString()` idiom already proven at
L3889-3904 — not introducing a second engine, a second context-building
function, or a bespoke cursor-substitution mini-language.

**Alternative considered — a dedicated `{{cursor}}` placeholder syntax
resolved by string-replace, bypassing Twig:** Rejected. Twig is already a
hard dependency of this file (`use Twig\Error\LoaderError;` etc. at the top
of `SynchronizationService.php`) and `renderTemplateString()` already
supports filters/defaults for free (as shown above); a bespoke
string-replace would be strictly less capable while adding a second
code path to maintain and explain.

**Alternative considered — a dedicated `cursorQueryParam` config key that
the engine sets directly into `$config['query']`, no templating:**
Rejected as the sole mechanism (though effectively a special case of the
templating approach still applies) because it cannot express
endpoint-path-segment cursors (e.g. `/items/since/{{ cursor }}`) or
composite values (e.g. a cursor embedded in a JSON request body via
`useDataAsRequestBody`), while the templating approach handles all three
injection points (endpoint, query, and — already possible today with zero
further change, since `$config['body']` is built from `$data` which callers
control — body) through one mechanism.

### Decision 3: Composition point with the sync-safety guard — the existing `$fetchComplete` local in `synchronizeExternToIntern()` Stage 5
**Choice:** Both new behaviors attach to the exact code that already exists
at `synchronizeExternToIntern()` Stage 5 (SynchronizationService.php
~L1766-1802):

```php
$fetchComplete = ($rateLimitException === null && ($fetchInfo['complete'] ?? true));

$deletedCount = 0;
$guardInfo    = null;
if ($isTest === false) {
    // [NEW] incremental mode never runs deletion — checked BEFORE the
    // existing fetchComplete-gated call, so it short-circuits deletion
    // for its own explicit reason ('incremental_mode') rather than
    // reusing fetchComplete's 'fetch_incomplete' reason, which would be
    // misleading (the fetch can be perfectly complete for what it asked
    // for — it just didn't ask for everything).
    $syncMode = (string) ($synchronization['syncMode'] ?? 'full');
    if ($syncMode !== 'incremental') {
        $deletedCount = $this->deleteInvalidObjects(
            synchronization: $synchronization,
            synchronizedTargetIds: $synchronizedTargetIds,
            deleteRestriction: $deleteRestriction,
            data: $deleteData,
            fetchComplete: $fetchComplete,
            forceDeletion: ($forceDeletion ?? false),
            guardInfo: $guardInfo
        );
    } else {
        $guardInfo = ['guarded' => true, 'reason' => 'incremental_mode', 'ratio' => null, 'threshold' => null];
    }

    // [NEW] watermark advance — same $fetchComplete boolean REQ-010
    // already computed above; a rate-limited or otherwise incomplete
    // fetch (REQ-009) blocks the watermark exactly as it blocks deletion.
    if ($syncMode === 'incremental' && $fetchComplete === true) {
        $newWatermark = $this->computeCursorWatermark(synchronization: $synchronization, objectList: $objectList);
        if ($newWatermark !== null) {
            $synchronization['cursorWatermark'] = $newWatermark;
        }
    }
}
```

**Why:** REQ-009 (fetch-completeness tracking) and REQ-010 (deletion
gating) already compute and thread a single `$fetchComplete` boolean to
exactly this point — it is the one place in the method that knows, with
certainty, "did this run's fetch see everything it was supposed to." Both
new invariants (never advance the watermark on an incomplete fetch; never
delete in incremental mode) are correctness rules *about that same fact*,
so attaching them here means:
- There is no way for a future change to update `$fetchComplete`'s
  computation (e.g. adding a new failure mode) without both the deletion
  guard and the watermark guard picking it up automatically — they read
  the same variable.
- The incremental-mode deletion block is unconditional (checked first, not
  folded into `$fetchComplete`) so it cannot be defeated by
  `forceDeletion: true` the way the ratio guard can — deleting in
  incremental mode is not a "the operator explicitly overrode a soft
  guard" situation, it is "the data needed to make this decision correctly
  was never fetched," which no override can fix (proposal.md Risk 3).
- `deleteInvalidObjects()` itself also gets a defense-in-depth check
  (`$synchronization['syncMode'] === 'incremental'` → return 0 immediately,
  mirroring its existing `$fetchComplete === false` early-return at
  L2533-2551) so a future caller that reaches it directly (bypassing
  `synchronizeExternToIntern()`) cannot accidentally delete against a
  partial incremental fetch either.

**Alternative considered — a separate `syncMode`-only guard clause
independent of `$fetchComplete`, placed earlier in the method (e.g. right
after Stage 2 fetch):** Rejected for the watermark half — advancing the
watermark logically depends on the fetch being *complete*, not merely on
mode, so it must read `$fetchComplete` regardless of where it's placed;
placing it right next to the deletion gate (which already needs the same
variable) avoids computing or threading `$fetchComplete` to two different
locations in the method.

## Risks / Trade-offs
- [Risk] An incremental synchronization whose source has no reliable
  monotonic field (flaky clocks, non-monotonic ids) silently misses
  records → [Mitigation] Documented in `sourceConfig.cursorField`'s schema
  description as an admin responsibility, same as `idPosition` today;
  Risk 1 in proposal.md covers the missing-field case specifically (throws
  rather than silently skipping).
- [Risk] Extending Twig templating to `sourceConfig.query` values is a
  small surface-area increase (any query value containing `{{`/`}}` is now
  template-evaluated, not passed through literally) → [Mitigation] Uses the
  exact same evaluation function and trust boundary as the pre-existing
  endpoint templating (both operate on admin-authored `sourceConfig`, never
  on source-returned data), so this does not cross a new trust boundary —
  it is the same boundary, one more field.
- [Risk] Operators may expect `reset-cursor` to also retroactively delete
  now-possibly-stale target objects, or to restore deletion-based garbage
  collection once the next fetch happens to cover the whole source →
  [Mitigation] `reset-cursor` only clears `cursorWatermark`; it deliberately
  does **not** change `syncMode`. Per Decision 3, `deleteInvalidObjects()`
  is skipped unconditionally whenever `syncMode === 'incremental'`, with no
  exception for "this particular fetch happened to be full" — the engine
  has no reliable way to verify that an admin-templated `{{ cursor }}`
  placeholder resolving to an empty string actually caused the source to
  return its complete set (that is a semantic guarantee about the source's
  API, not something the engine can structurally confirm). An operator who
  wants deletion detection back MUST explicitly switch the Synchronization's
  `syncMode` to `full` — a separate, deliberate action, not a side effect of
  `reset-cursor`. Document both of these (what `reset-cursor` does and does
  not do) explicitly in the SPA tooltip/help text (tasks.md).

## Migration Plan
No Nextcloud database migration — see `migration.md` (skipped, with
rationale) and Decision 1: both new fields are additive, optional JSON
schema properties on an OpenRegister-persisted object, not columns on an
NC-managed table. Deploy is: ship the schema change + code together;
existing Synchronizations are unaffected (`syncMode` absent ⇒ treated as
`full`, byte-identical to current behavior — no code path changes for any
Synchronization that does not explicitly opt into `incremental`).

## Open Questions
- Should the SPA surface the current `cursorWatermark` value read-only (for
  operator visibility/debugging) in addition to the reset action? Deferred
  to tasks.md as a small, low-risk addition — not a design decision, since
  it changes no backend behavior.
- Should `deleteInvalidObjects()`'s defense-in-depth `syncMode` check log a
  warning (mirroring the `fetchComplete === false` branch's warning +
  `SynchronizationDeletionGuardedEvent` dispatch) if it is ever actually
  reached via a direct caller, or silently return 0? Recommendation: mirror
  the existing pattern exactly (warning + event, `reason:
  'incremental_mode'`) for observability parity — captured as a task
  acceptance criterion rather than left open at implementation time.
