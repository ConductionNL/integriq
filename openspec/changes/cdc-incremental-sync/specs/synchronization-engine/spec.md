# synchronization-engine Specification Delta — cdc-incremental-sync

This delta adds a cursor-based `incremental` sync mode alongside the
existing (default, unchanged) `full` hash-diff mode. REQ numbers continue
from the highest currently claimed by any capability in this spec —
`hitl-approval-rule-action` claims REQ-015, the highest in use on `main` at
the time this change was authored — so this change takes REQ-016..REQ-019.

## ADDED Requirements

### Requirement: Incremental sync mode selects a cursor-filtered fetch request (REQ-016)

`SynchronizationService` SHALL support `syncMode: incremental` on a
Synchronization, in addition to the existing (default, unchanged) `full`
mode. When `syncMode` is `incremental` and `sourceType` is `api`,
`getAllObjectsFromApi()` MUST make the Synchronization's stored
`cursorWatermark` — or an empty string when no watermark has been set yet —
available as a `cursor` key in the Twig context already passed to
`MappingService::renderTemplateString()` when rendering `sourceConfig.endpoint`
(alongside the existing `data` key). `getAllObjectsFromApi()` MUST also
apply the identical `{{`/`}}`-presence-detection-then-`renderTemplateString()`
treatment already used for `sourceConfig.endpoint` to each scalar value in
`sourceConfig.query`, using the same `['data' => ..., 'cursor' => ...]`
context, so a source that takes its cursor as a query parameter rather than
an endpoint path segment can also reference `{{ cursor }}`. A Synchronization
with `syncMode` absent or `full` MUST take the exact pre-existing code path —
no `cursor` context key is added and `sourceConfig.query` values are passed
through unrendered, unchanged from current behavior.

#### Scenario: an incremental run injects the stored watermark into a templated endpoint

- GIVEN a Synchronization with `syncMode: incremental`, `sourceConfig.endpoint:
  ".../items?updatedAfter={{ cursor }}"`, and a stored `cursorWatermark` of
  `"2026-07-01T00:00:00Z"`
- WHEN `getAllObjectsFromApi()` runs
- THEN the rendered request endpoint is
  `.../items?updatedAfter=2026-07-01T00:00:00Z`

#### Scenario: an incremental run injects the stored watermark into a templated query parameter

- GIVEN a Synchronization with `syncMode: incremental`,
  `sourceConfig.query.updatedAfter: "{{ cursor }}"`, and a stored
  `cursorWatermark` of `"42"`
- WHEN `getAllObjectsFromApi()` runs
- THEN the outbound request's `updatedAfter` query parameter is rendered to
  `"42"` before the call is made

#### Scenario: an incremental run with no prior watermark passes an empty cursor

- GIVEN a Synchronization with `syncMode: incremental` and no
  `cursorWatermark` set (its first-ever incremental run)
- WHEN `getAllObjectsFromApi()` runs
- THEN `{{ cursor }}` renders to an empty string
- AND a source whose default/fallback (e.g. `{{ cursor|default('1970-01-01')
  }}`, a plain Twig filter requiring no engine change) treats an empty
  cursor as "everything" receives an effectively full fetch on this first
  incremental run

#### Scenario: a full-mode run is unaffected

- GIVEN a Synchronization with `syncMode` absent or `full`
- WHEN `getAllObjectsFromApi()` runs
- THEN the Twig context passed to `sourceConfig.endpoint` templating
  contains only `data` (no `cursor` key), and `sourceConfig.query` values
  are used exactly as configured, byte-identical to pre-existing behavior

**Notes:**

- This requirement extends the fetch-request-shaping mechanics of REQ-002
  (source object fetching and pagination) for the `api` branch only; it does
  not change REQ-002's pagination, rate-limiting, or next-page resolution
  behavior.
- `sourceConfig.cursorField` (a dotted-path lookup mirroring REQ-003's
  `idPosition`/`getOriginId()` convention) identifies which field of a
  fetched record is the comparable cursor value; it is read by REQ-017's
  watermark computation, not by this requirement.
- Methods: `getAllObjectsFromApi()` (extended), `MappingService::
  renderTemplateString()` (reused, unchanged).

### Requirement: Cursor watermark advances only after a complete, successful fetch (REQ-017)

`synchronizeExternToIntern()` MUST, for a Synchronization with `syncMode:
incremental`, compute a new high-watermark value from the fetched records'
configured `sourceConfig.cursorField` and persist it as the Synchronization's
`cursorWatermark` **only when** that run's fetch was marked complete per
REQ-009 (`fetchInfo.complete === true`) **and** no `TooManyRequestsHttpException`
was thrown during the fetch — the same `$fetchComplete` computation REQ-010
already performs at the same point in the method. When the fetch was
incomplete for any reason (partial pagination, a failed page, a rate-limit
response, or the pagination safety cap), the system MUST NOT persist any
change to `cursorWatermark`, so the next run retries from the same
watermark rather than silently skipping the unfetched remainder. A run
invoked with `isTest: true` MUST NOT persist a watermark change regardless
of fetch completeness, consistent with REQ-011 (test runs make no writes).
A record whose configured `cursorField` resolves to `null` MUST cause the
run to throw, mirroring REQ-003's `getOriginId()` behavior for a missing
`idPosition` — silently computing a watermark from an incomplete field
would risk producing an incorrect (too-low) high-watermark that
permanently skips sibling records on every subsequent run.

#### Scenario: watermark advances after a complete fetch

- GIVEN a Synchronization with `syncMode: incremental`,
  `sourceConfig.cursorField: "updatedAt"`, and a fetch that completes
  successfully, returning records with `updatedAt` values up to
  `"2026-07-15T09:00:00Z"`
- WHEN `synchronizeExternToIntern()` finishes the run
- THEN the Synchronization's `cursorWatermark` is persisted as
  `"2026-07-15T09:00:00Z"`

#### Scenario: watermark does not advance after a page failure mid-fetch

- GIVEN a Synchronization with `syncMode: incremental` and an existing
  `cursorWatermark` of `"2026-07-01T00:00:00Z"`
- WHEN a run's fetch is marked incomplete (REQ-009) because page 2 of 3
  returned HTTP 500
- THEN the Synchronization's `cursorWatermark` remains
  `"2026-07-01T00:00:00Z"` after the run, unchanged
- AND the next run requests records with `cursor` still resolving to
  `"2026-07-01T00:00:00Z"`

#### Scenario: watermark does not advance after a 429 rate-limit

- GIVEN a Synchronization with `syncMode: incremental`
- WHEN the source returns HTTP 429 on the first page of a run
- THEN the run's fetch is treated as incomplete (REQ-009)
- AND the Synchronization's `cursorWatermark` is not modified
- AND the caller still receives the `TooManyRequestsHttpException` as before
  (REQ-010's existing behavior for the deletion side is unchanged; this
  requirement adds the equivalent guarantee for the watermark side)

#### Scenario: watermark does not advance for a test run even when the fetch is complete

- GIVEN a Synchronization with `syncMode: incremental`
- WHEN `POST .../synchronizations/{id}/test` runs and its fetch completes
  successfully
- THEN the Synchronization's persisted `cursorWatermark` is unchanged
  (REQ-011: test runs persist no Synchronization state)

#### Scenario: a record missing the configured cursorField throws rather than silently computing a wrong watermark

- GIVEN a Synchronization with `syncMode: incremental` and
  `sourceConfig.cursorField: "updatedAt"`
- WHEN a fetched record has no value at the `updatedAt` path
- THEN the run throws an `Exception` naming the missing cursor field
- AND no `cursorWatermark` change is persisted for that run

**Notes:**

- This requirement composes directly with REQ-009/REQ-010's existing
  `$fetchComplete` computation in `synchronizeExternToIntern()` — it does
  not introduce a second completeness signal.
- Watermark computation takes the maximum `cursorField` value across all
  fetched records in the run (not the last record processed), so
  out-of-order pagination or concurrent per-page fetching (REQ-002's
  optimized parallel mode) cannot regress the watermark.
- Methods added: `computeCursorWatermark()` (private, alongside the
  existing `getOriginId()`/`hashObject()` identity helpers).

### Requirement: Deletion garbage-collection never runs for an incremental sync (REQ-018)

`synchronizeExternToIntern()` MUST NOT invoke `deleteInvalidObjects()` for
any run whose Synchronization has `syncMode: incremental` — unconditionally,
regardless of that run's fetch-completeness (REQ-009), the computed
deletion ratio (REQ-010), or an explicit `forceDeletion` override. An
incremental fetch is, by construction, a filtered subset of the source; the
absence of a target id from `$synchronizedTargetIds` on an incremental run
is not evidence that the corresponding source record was deleted — it may
simply be outside the cursor filter. `deleteInvalidObjects()` MUST also
independently refuse to run when passed a Synchronization whose `syncMode`
is `incremental`, regardless of caller, so a future caller that invokes it
directly (bypassing `synchronizeExternToIntern()`'s gate) cannot
accidentally delete against a partial incremental fetch. On this refusal,
`deleteInvalidObjects()` MUST log a warning-level message and dispatch a
`SynchronizationDeletionGuardedEvent` with `reason: incremental_mode`,
mirroring its existing `fetch_incomplete`-reason guard (REQ-010), and MUST
return `0`.

#### Scenario: incremental mode blocks deletion even on a complete fetch

- GIVEN a Synchronization with `syncMode: incremental`, 100 existing
  contracts, and a run whose fetch completes successfully but — because it
  is cursor-filtered — returns only 5 changed records
- WHEN `synchronizeExternToIntern()` reaches its cleanup stage
- THEN `deleteInvalidObjects()` is not invoked
- AND 0 objects are deleted
- AND the run's `result.objects.deletionGuard` records
  `reason: incremental_mode`

#### Scenario: forceDeletion cannot override the incremental-mode block

- GIVEN the same Synchronization as above
- WHEN the run is invoked with `forceDeletion: true`
- THEN deletion is still not invoked — `forceDeletion` only overrides
  REQ-010's ratio guard on a `full`-mode run and has no effect on this
  unconditional incremental-mode block

#### Scenario: deleteInvalidObjects() called directly against an incremental Synchronization still refuses

- GIVEN a Synchronization with `syncMode: incremental`
- WHEN `deleteInvalidObjects()` is invoked directly (not via
  `synchronizeExternToIntern()`) with `fetchComplete: true` and
  `forceDeletion: true`
- THEN it still returns `0` and deletes nothing
- AND a warning is logged and a `SynchronizationDeletionGuardedEvent` with
  `reason: incremental_mode` is dispatched

#### Scenario: the deleteRestriction single-object delete path is unaffected

- GIVEN an OpenRegister `ObjectDeletedEvent` triggers a synchronization run
  with `mutationType: delete` and `sourceConfig.restrictDeletion: true`
  against a Synchronization with `syncMode: incremental`
- WHEN `synchronizeExternToIntern()` runs
- THEN the single-object delete path (`$data !== null && $mutationType ===
  'delete'`) is taken — this path never calls `deleteInvalidObjects()`'s
  bulk source-diff branch regardless of `syncMode`, so this requirement
  introduces no new behavior here; it is called out only to confirm the
  event-driven single-object delete is not accidentally caught by this
  guard

**Notes:**

- This requirement composes with, and takes priority over, REQ-010's
  ratio/`forceDeletion` guard: the `syncMode` check happens first and
  unconditionally, before REQ-010's `fetchComplete`/ratio logic is ever
  reached, for an incremental Synchronization.
- Restoring deletion-based garbage collection for a Synchronization
  currently in `incremental` mode requires explicitly switching its
  `syncMode` back to `full` — REQ-019's reset-cursor action does not do
  this (see REQ-019).
- Methods: `deleteInvalidObjects()` (extended with the new guard clause,
  ahead of its existing `fetchComplete === false` early return),
  `synchronizeExternToIntern()` (extended call-site check).

### Requirement: Reset-cursor action clears the stored watermark (REQ-019)

`SynchronizationsController` MUST expose `POST
/api/synchronizations/{id}/reset-cursor`, which clears the target
Synchronization's `cursorWatermark` (to `null`/absent) and persists that
change, without altering `syncMode` or any other Synchronization field.
This action MUST NOT itself delete, create, or update any target object or
`SynchronizationContract` — it only clears stored cursor state. Following a
reset, the Synchronization's next run resolves `{{ cursor }}` to an empty
string (REQ-016's "no prior watermark" case), which — for a source whose
templated request treats an absent cursor as unfiltered — yields a
full-equivalent fetch that re-evaluates every currently-reachable source
record for create/update via the existing hash-diff contract mechanism
(REQ-003). This action MUST NOT re-enable `deleteInvalidObjects()` for that
Synchronization: REQ-018's guard is keyed on `syncMode`, not on cursor
state, and a reset-cursor call does not change `syncMode`.

#### Scenario: reset-cursor clears the watermark

- GIVEN a Synchronization with `syncMode: incremental` and
  `cursorWatermark: "2026-07-10T00:00:00Z"`
- WHEN `POST /api/synchronizations/{id}/reset-cursor` is called
- THEN the Synchronization's `cursorWatermark` is persisted as
  `null`/absent
- AND `syncMode` remains `incremental`, unchanged

#### Scenario: the next run after a reset requests an unfiltered fetch

- GIVEN a Synchronization whose `cursorWatermark` was just cleared via
  reset-cursor, with `sourceConfig.endpoint: ".../items?updatedAfter={{
  cursor }}"`
- WHEN the next `synchronize()` run's `getAllObjectsFromApi()` executes
- THEN the rendered endpoint is `.../items?updatedAfter=` (empty cursor
  value)

#### Scenario: reset-cursor does not perform or re-enable deletion

- GIVEN a Synchronization with `syncMode: incremental` and 100 existing
  contracts
- WHEN `POST /api/synchronizations/{id}/reset-cursor` is called, and then
  the Synchronization's next run executes and — because the source
  honored the empty cursor — refetches all currently-existing source
  records
- THEN `reset-cursor` itself deletes nothing
- AND the subsequent run also does not invoke `deleteInvalidObjects()`
  (REQ-018 still applies — `syncMode` is still `incremental`)
- AND restoring deletion detection requires a separate, explicit change of
  `syncMode` to `full`

#### Scenario: a missing synchronization id returns 404

- GIVEN no Synchronization exists with the given `id`
- WHEN `POST /api/synchronizations/{id}/reset-cursor` is called
- THEN the response is `404`, mirroring the existing `run()`/`test()`
  action's not-found handling

**Notes:**

- This action follows the existing `activate`/`deactivate`/`run`/`test`
  action-route convention on `SynchronizationsController`
  (`/api/synchronizations/{id}/<action>`, `POST`).
- **SECURITY:** per REQ-005's existing, pre-existing IDOR note on this
  controller, `reset-cursor` inherits the same `@NoAdminRequired` +
  `@NoCSRFRequired` + no-per-object-ownership-guard posture as every other
  action on `SynchronizationsController` today. This is observed,
  pre-existing behavior this change does not alter or worsen (clearing a
  watermark is a low-severity action relative to `run`/`test`/`execute`
  already available on the same unguarded surface) — flagged for the same
  future authorization follow-up already noted under REQ-005, not
  addressed here.
- Methods added: `SynchronizationsController::resetCursor()`.

## MODIFIED Requirements

### Requirement: Target write, deduplication and file handling (REQ-004)

The system SHALL write each transformed object to its target, branching to an
OpenRegister-specific write when the target is an OR register/schema, and SHALL
maintain one `SynchronizationContract` per object carrying origin/target ids and
hashes for incremental change detection. The system SHALL cascade contract
creation and id rewrites to sub-objects. It SHALL garbage-collect target objects
no longer present in the source (`deleteInvalidObjects()`) unless `force` opts
out, **and unless the run's fetch was incomplete, the run is a test
(`isTest: true`), the computed deletion ratio exceeds the configured
guard threshold without an explicit `forceDeletion` override (REQ-009,
REQ-010, REQ-011), or the Synchronization's `syncMode` is `incremental`
(REQ-018 — this last guard is unconditional and is never bypassed by
`forceDeletion`)**. The system SHALL fetch, persist, and clean up files
referenced by sync objects: download a file via `CallService`, validate the
target object id is a UUID, persist to storage, optionally run async batch
fetching (ReactPHP), and remove orphaned files/attachments no longer
referenced after a sync.

<!-- Previous behavior (as of sync-safety-guardrails): deleteInvalidObjects()
     was gated on fetch-completeness, test-mode, and the deletion-ratio
     guard, but had no awareness of an incremental sync mode — this change
     adds the syncMode === 'incremental' guard as an unconditional
     precondition, on top of (not instead of) the pre-existing guards. -->

@e2e exclude backend target-write internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: OR target write records a contract

- **GIVEN** a transformed object whose target is an OR register/schema
- **WHEN** `updateTarget()` runs
- **THEN** it delegates to `updateTargetOpenRegister()` and a `SynchronizationContract` records the resulting origin/target ids and hashes.

#### Scenario: absent source objects are garbage-collected when the fetch was complete, within the deletion-ratio guard, and syncMode is full

- **GIVEN** a source no longer returns objects that previously had contracts, a complete fetch (REQ-009), a non-test run, a deletion ratio within the configured threshold (REQ-010), and `syncMode` absent or `full` (REQ-018)
- **WHEN** `deleteInvalidObjects()` runs
- **THEN** the now-absent target objects are deleted (garbage-collected).

#### Scenario: referenced file is fetched and persisted

- **GIVEN** a sync object referencing a file URL
- **WHEN** `fetchFile()` runs
- **THEN** the file is downloaded via `CallService`, the object id is validated as a UUID before write, and the file is persisted to storage; a null response throws an `Exception`.

#### Scenario: batch file fetch with cleanup

- **GIVEN** a batch of file references
- **WHEN** `startAsyncFileFetching()` / `executeAsyncFileFetching()` / `processMultipleFilesWithCleanup()` run
- **THEN** files are fetched concurrently and orphaned files are cleaned up afterward via `cleanupOrphanedFiles()`.

#### Scenario: unreferenced attachments are removed

- **GIVEN** a previously-synced object whose attachments are no longer referenced
- **WHEN** `cleanupFilesFromAttachments()` runs
- **THEN** the stale attachments are removed from the object.

**Notes:**

- `fetchFile()` builds the request endpoint from source-supplied
  `location`/`sourceConfiguration` and substitutes `{{ originId }}` into a
  JSON-encoded config. The endpoint is attacker-influenceable via source
  configuration; combined with `base64_decode` of the response body this is a
  surface worth a dedicated SSRF/content-handling review (flagged, not changed).
- `fetchFileSafely()` wraps `fetchFile()` and swallows exceptions so an async
  batch continues past individual file failures — a silent-fail path; failed
  fetches are not surfaced to the caller as a structured error.
- `updateTargetOpenRegister()` is the only fully-wired target-write branch;
  non-OR targets are handled generically by `writeObjectToTarget()`.
- **See REQ-009/REQ-010/REQ-011/REQ-012/REQ-013 (sync-safety-guardrails) for
  the deletion-gating, test-run no-write, ad-hoc Source, and duplicate-contract
  detection behaviour layered onto this requirement, and REQ-016/REQ-017/
  REQ-018/REQ-019 (cdc-incremental-sync) for the incremental-mode fetch
  filtering, watermark, and unconditional deletion-block layered on top of
  those.**
- Methods: `updateTarget()`, `updateTargetOpenRegister()`,
  `writeObjectToTarget()`, `deleteInvalidObjects()`, `processSyncContract()`,
  `updateContractsForSubObjects()`, `processSynchronizationObject()`,
  `writeFile()`, `fetchFile()`, `fetchFileSafely()`, `startAsyncFileFetching()`,
  `executeAsyncFileFetching()`, `processMultipleFilesWithCleanup()`,
  `cleanupOrphanedFiles()`, `cleanupFilesFromAttachments()`,
  `shouldPublishFile()`, `getFileContext()`, `getFilenameFromHeaders()`,
  `synchronizeToTarget()`, `detectDuplicateContracts()`.
