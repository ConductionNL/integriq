# Design: sync-safety-guardrails

## Architecture Overview

No new architectural layer. This change hardens the existing `SynchronizationService` cleanup/fetch/test/source-resolution paths in place. All state introduced is either (a) transient, threaded through method calls for the duration of a single `synchronize()` run, or (b) written into the already-freeform `result` JSON field of the `synchronization_log` OpenRegister object — no new persisted fields on the `synchronization` entity itself.

```
SynchronizationsController::run()/test()
        │  (+forceDeletion param on run())
        ▼
SynchronizationService::synchronize()
        │  (+forceDeletion threaded through)
        ▼
synchronizeExternToIntern()
        │
        ├─ getAllObjectsFromSource() ──▶ getAllObjectsFromApi() ──▶ fetchAllPages() ──▶ fetchAllPagesOptimized() ──▶ fetchSinglePageData()
        │       (unchanged flat `array` return; NEW optional &$fetchInfo out-param carries complete/failureReason bottom-up)
        │
        ├─ processSynchronizationObject() ──▶ findContractBySyncAndOrigin() (existing, unchanged — see Decision 5)
        │       (NEW: opportunistic detectDuplicateContracts() diagnostic, log-only)
        │
        └─ deleteInvalidObjects()
                (NEW: skipped entirely when isTest===true or fetchInfo.complete===false;
                 NEW: ratio guard + forceDeletion override; unchanged `int` return,
                 NEW optional &$guardInfo out-param)
                        │
                        └─ on guard trip: logger->warning() + dispatch(SynchronizationDeletionGuardedEvent)
```

## Goals / Non-Goals

**Goals:**
- A fetch that did not complete (error, 429, exhausted the pagination safety cap) can never trigger `deleteInvalidObjects()`.
- A `isTest === true` run can never write, update, or delete anything — contract, target object, Source, or the `Synchronization` entity itself.
- A cleanup pass that would remove more than a configurable share of a synchronization's known objects stops and requires an explicit, auditable override.
- An ad-hoc `source` location string never silently becomes a persisted, enabled Source.
- No public method's return type changes (avoids breaking ~13 existing `getAllObjectsFromApi()` test call sites and 6 existing `deleteInvalidObjects()` call sites) and `SynchronizationService`'s public constructor signature is unchanged (avoids breaking every `setConstructorArgs([...])` test fixture in the suite).

**Non-Goals:**
- Retrying a failed page fetch (out of scope — `retry-and-circuit-breaker-policies`).
- Streaming/memory rework of `fetchAllPagesOptimized()` for very large result sets (oc#1010, follow-up only).
- A UI/notification-centre consumer for the new event (no such consumer exists for any OpenConnector event today).
- Rewriting the originId contract-matching flow — static review found no bug (Decision 5).

## Decisions

### Decision 1: Fetch-completeness is threaded via optional pass-by-reference output parameters, not a return-type change

**Chosen:** `getAllObjectsFromSource(array $synchronization, ?bool $isTest=false, ?array $data=null, ?array &$fetchInfo=null): array` and `getAllObjectsFromApi(array $synchronization, ?bool $isTest=false, ?array $data=null, ?array &$fetchInfo=null): array` keep their existing flat-array return type. When the caller passes a variable for `$fetchInfo`, it is populated with `['complete' => bool, 'pagesFetched' => int, 'failureReason' => ?string]` before the method returns.

Internally, the private chain changes shape freely (no external callers, verified via grep — only `SynchronizationService.php` itself calls `fetchAllPages()`/`fetchAllPagesOptimized()`/`fetchSinglePage()`/`fetchSinglePageData()`):
- `fetchSinglePageData()` (private, `SynchronizationService.php:3360`) gains a `failed` flag in its return array. Today it returns `['objects' => [], 'result' => []]` both when the response is null (network failure not classified as 429) and — via the existing JSON/XML parse path — when a 2xx response's body genuinely has no records. These two cases MUST be told apart. Fix: read the status code via the already-existing `callLogStatusCode()` helper (`SynchronizationService.php:3945`) immediately after the null-response/429 check; if a status code is present and `>= 400`, return `['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => $statusCode]`. A null response with no recoverable status code (network/connect failure) also sets `failed => true`. A 2xx (or absent, e.g. static `array` sources with no HTTP layer) response that parses to zero records is unaffected — `failed` stays `false`, preserving the current, correct "natural end of pagination" behaviour.
- `fetchAllPagesOptimized()` (private, `SynchronizationService.php:3173`) checks `$pageData['failed'] ?? false` **before** its existing `empty($pageObjects) === true` end-of-pagination check (`3205-3207`), so a failed page is never conflated with a natural end. On a failed page it breaks the loop and records `$incomplete = true` with a `failureReason`. It also now distinguishes "loop ended because there were no more pages" from "loop ended because the `DEFAULT_MAX_PAGES` (50) safety cap was hit while a next page was still available" — the latter is exactly as dangerous as a mid-pagination failure (an unknown amount of the source was never seen) and is treated as `complete = false, failureReason = 'max_pages_reached'`.
- `fetchAllPages()` (private, `3124`) and the non-paginated `fetchSinglePage()` path both propagate the same `complete`/`failureReason` shape upward instead of discarding it.
- `getAllObjectsFromApi()` unwraps this internal shape into its own `&$fetchInfo` out-parameter before returning the flat object list it always returned; the `register/schema`/`database` no-op branches of `getAllObjectsFromSource()` and the `array` (static) source path set `$fetchInfo = ['complete' => true, 'pagesFetched' => 0, 'failureReason' => null]` (there is nothing that can partially fail there today).

**Rejected alternative — change the return type to `array{objects: array, complete: bool}`:** rejected because `tests/Unit/Service/SynchronizationServiceTest.php` has 13 call sites doing `$objects = $this->service->getAllObjectsFromApi(...)` followed directly by `assertCount()`/array-indexing assertions; changing the return shape breaks all of them for no behavioural gain over the output-parameter approach.

**Rejected alternative — track completeness as a new field on the `Synchronization` OR entity:** rejected because it would require a schema addition to `lib/Settings/openconnector_register.json` (`status` enum is documented as `idle, syncing, error, disabled` and every write site would need auditing to keep it consistent, flagged as a pre-existing open concern in the synchronization-engine spec's REQ-002 notes) for state that is only ever needed for the duration of a single run. A transient, per-call signal is sufficient and strictly simpler.

### Decision 2: `deleteInvalidObjects()` gains `$fetchComplete`, `$forceDeletion`, and `&$guardInfo` — all optional, appended, defaulting to today's behaviour where meaningful

**Chosen signature:**
```php
public function deleteInvalidObjects(
    array|ObjectEntity $synchronization,
    ?array $synchronizedTargetIds=[],
    bool $deleteRestriction=false,
    array $data=[],
    bool $fetchComplete=true,
    bool $forceDeletion=false,
    ?array &$guardInfo=null
): int
```

`tests/Unit/Service/SynchronizationServiceCleanupTest.php` calls this method directly 6 times as `$this->service->deleteInvalidObjects($sync, [])` and asserts `assertSame(<int>, $deleted)`. `$fetchComplete` therefore defaults to `true` (today's implicit assumption — those tests are exercising the scope-check guard, not fetch-completeness, and must keep passing unmodified) so only `synchronizeExternToIntern()` needs to pass `fetchComplete: false` explicitly when it knows the fetch was bad. The return type stays `int` (deleted count) — unchanged — for the same reason.

Inside the method, immediately after normalising `$synchronization` and before the existing `switch ($type)`:
```php
if ($fetchComplete === false) {
    $guardInfo = ['guarded' => true, 'reason' => 'fetch_incomplete', 'ratio' => null, 'threshold' => null];
    $this->logger->warning('deleteInvalidObjects: skipped — preceding fetch did not complete', [...]);
    $this->eventDispatcher()->dispatchTyped(new SynchronizationDeletionGuardedEvent(...));
    return 0;
}
```
This check is unconditional — `forceDeletion` does NOT bypass it. A known-incomplete fetch is never a safe basis for deletion regardless of an admin's override intent; `forceDeletion` only overrides the *ratio* guard below, which is the one legitimate "the source really did shrink a lot" case.

The existing `register/schema` branch (`2040-2148`) is otherwise unchanged up through computing `$targetIdsToDelete = array_diff($allContractTargetIds, $synchronizedTargetIds)` (`2089`). Immediately after that line, and only when `$deleteRestriction === false` (the bulk source-diff cleanup path — see rationale below) and `count($allContractTargetIds) > 0`:
```php
$ratio = count($targetIdsToDelete) / count($allContractTargetIds);
$threshold = $sourceConfig['deletionRatioThreshold'] ?? self::DEFAULT_DELETION_RATIO_THRESHOLD;
if ($ratio > $threshold && $forceDeletion === false) {
    $guardInfo = ['guarded' => true, 'reason' => 'ratio_threshold_exceeded', 'ratio' => $ratio, 'threshold' => $threshold, 'candidateCount' => count($targetIdsToDelete), 'totalContracts' => count($allContractTargetIds)];
    $this->logger->warning('deleteInvalidObjects: deletion ratio guard tripped', [...]);
    $this->eventDispatcher()->dispatchTyped(new SynchronizationDeletionGuardedEvent(...));
    return 0;
}
$guardInfo = ['guarded' => false, 'ratio' => $ratio, 'threshold' => $threshold];
```
`$sourceConfig` here is read the same way the existing `deleteRestriction`/`restrictDeletion` config already is (`applyConfigDot($synchronization['sourceConfig'] ?? [])`), mirroring the exact pattern already used for `maxPages` at `3185` (`$sourceConfig['maxPages'] ?? $this::DEFAULT_MAX_PAGES`). `DEFAULT_DELETION_RATIO_THRESHOLD` is a new class constant, `0.10` (10%).

**Why exempt the `deleteRestriction === true` path from the ratio guard:** that path is the single-object, event-driven delete (`mutationType: delete`, forwarded from an OpenRegister `ObjectDeletedEvent` per REQ-001) — it already narrows `$targetIdsToDelete` to ids explicitly named in `$data` (`2090-2099`). It is inherently a targeted removal of one specific, externally-confirmed-deleted object, not a source-fetch-driven diff; applying a percentage guard to a single explicit deletion request would block legitimate, intentional single-object deletes for no safety gain.

**Why `forceDeletion` is a new, separate parameter and not a reuse of the existing `$force` parameter:** `$force` already has an established, different meaning in this class — "bypass the unchanged-hash skip in `synchronizeContract()`" (`2286-2322`) — and is already passed as `true` automatically by `handleObjectEventSynchronization()` for every event-driven re-sync (spec REQ-001, "run each matching synchronization with `force: true`"). If the ratio guard were bypassed by the same flag, every automatic event-triggered re-sync would silently disable the new safety net, which defeats its purpose. `forceDeletion` must be a deliberate, separate, human-in-the-loop opt-in surfaced only via the REST `run` endpoint's request parameters (Decision 3).

**Rejected alternative — return a richer array `{deletedCount:int, guarded:bool, ...}`:** rejected for the same backward-compatibility reason as Decision 1 — 6 existing direct assertions on an `int` return.

### Decision 3: `forceDeletion` threading through the public API

`SynchronizationsController::run()` (`lib/Controller/SynchronizationsController.php:333-397`) already reads `test`/`force`/`source`/`data` from `$this->request->getParams()` (`~355-359`) and passes them to `synchronize()`. Add one more:
```php
$forceDeletion = filter_var(($parameters['forceDeletion'] ?? false), FILTER_VALIDATE_BOOLEAN);
```
threaded to `synchronize(..., forceDeletion: $forceDeletion)`. `synchronize()` (`1613`) and `synchronizeExternToIntern()` (`1353`) each gain a `?bool $forceDeletion=false` parameter, threaded straight through to the `deleteInvalidObjects()` call at `1523`. `test()` does NOT gain this parameter — a test run never deletes at all (Decision 4), so there is nothing to force.

### Decision 4: Absolute no-write guarantee for `isTest === true`

Three concrete, verified gaps in `synchronizeExternToIntern()` and its helpers, fixed as follows:

1. **`deleteInvalidObjects()` call (`1523-1528`) has no `isTest` guard today.** Fix: wrap the call site — `if ($isTest === false) { $deletedCount = $this->deleteInvalidObjects(...); } else { $deletedCount = 0; }`. This is the primary fix for #1008/#1017 — today a "Test (dry run)" click on a synchronization with N previously-synced objects deletes up to N-1 real objects, because `fetchAllPagesOptimized()` already truncates test-mode fetches to a single object (`3200-3202`) and the unconditional cleanup call diffs that single-object set against every existing contract.
2. **`findOrCreateSourceByLocation()` (`685-729`) persists a new Source (`saveObject(...)`) regardless of `isTest`.** Fixed at the root for both test and non-test ad-hoc calls — see Decision 6; once it never persists a new Source for an unmatched location, there is nothing further to guard here for test mode specifically.
3. **`persistSynchronization()` at the end of `synchronizeExternToIntern()` (`1581-1582`) is unconditional**, mutating the `Synchronization` entity's `targetLastSynced` even during a dry run. Fix: `if ($isTest === false) { $synchronization['targetLastSynced'] = ...; $this->persistSynchronization(synchronization: $synchronization); }`.

Already correct, verified, left unchanged:
- `synchronizeContract()`'s existing `if ($isTest === true) { return [..., 'resultAction' => 'skip']; }` early return (`2363-2390`) already prevents `updateTarget()`/`persistContract()` for both new and changed objects in test mode — confirmed by reading the full method; the branch that distinguishes create-vs-update persistence (`2439-2447`) is unreachable when `isTest === true`. No change needed.
- `getAllObjectsFromApi()`'s existing `if ($isTest === false) { ...; $this->persistSynchronization(...); }` guard around the `currentPage` reset (`3079-3082`) is already correct.
- `SynchronizationContractLogService::createFromArray()` calls inside `synchronizeContract()` (e.g. `2229-2238`) run even when `isTest === true` and are explicitly tagged `'test' => $isTest`. These are audit/history log records of the test itself, not synced business data (no target object, no contract, no Source) — left as-is, out of scope.

### Decision 5: originId contract matching (#1016) — verify, don't rewrite

Static review of `findContractBySyncAndOrigin()` (`426-440`) and `processSynchronizationObject()` (`5699-5846`) found the lookup-before-create flow already correct: `processSynchronizationObject()` calls `findContractBySyncAndOrigin(synchronizationId, originId, ...)` and only builds a fresh in-memory contract array when no match is returned; `synchronizeContract()` only calls `persistContract(..., ensureUuid: true)` for the create path when `$synchronizationContract['id']` is empty. No code path was found that creates a second contract for an `(synchronizationId, originId)` pair that already has one.

**Decision:** do not modify this flow speculatively. Per the task brief for this change ("if the brief's claim doesn't match code, follow the code and note the discrepancy") — this is that discrepancy. Instead:
- Add regression tests (see `tasks.md`) that resync the same `originId` under the same synchronization twice and assert exactly one contract/target object exists afterward, including the `sourceConfig.findContractByOriginIdOnly` variant and a resync-after-out-of-band-target-deletion case.
- Add a new, narrowly-scoped, read-only diagnostic: `detectDuplicateContracts(string $synchronizationId, string $originId): array` — calls `findAllContractObjects(['synchronizationId' => ..., 'originId' => ...])` and, if more than one is returned, logs a warning with both contract ids and returns them. It performs no deletion or mutation — surfacing a pre-existing duplicate (e.g. from data created before this fix, or a race between two concurrent runs) for admin review is strictly safer than an automated cleanup that could itself delete the wrong one, which is exactly the class of bug this whole change exists to prevent. Called opportunistically from `processSynchronizationObject()` immediately after the existing lookup, gated so it only runs when the lookup returned a match (i.e. it is cheap — one extra findAll only in the already-matched case is avoided; it reuses the result already fetched by `findContractBySyncAndOrigin()`'s underlying `findAllContractObjects()` call rather than issuing a second query — see `tasks.md` Task 8 for the exact integration point).

### Decision 6: `findOrCreateSourceByLocation()` never persists a new Source; the "find" half is unchanged

**Chosen:** in `findOrCreateSourceByLocation()` (`685-729`), when `$objects` (the location-match lookup) is empty, build the `$sourceData` array exactly as today (`location`, `name`, `type: 'api'`, `enabled: true`, merged `$defaultData`) plus a generated `uuid`/`id` (`Symfony\Component\Uid\Uuid::v4()`, matching the existing pattern used for new contracts at `2443`), and **return it directly without calling `$this->orObjectService->saveObject(...)`**. The existing find-by-location branch (an admin-configured Source whose `location` matches) is completely unchanged.

This alone does not fully solve the problem: `getAllObjectsFromApi()` (`2991`) re-resolves the source by id via `$this->findSource(id: ...)` — a transient, never-persisted source's id would not be found there, throwing `DoesNotExistException`. Fix: thread the already-resolved source array through instead of re-fetching. `getAllObjectsFromSource()` and `getAllObjectsFromApi()` gain a new optional `?array $resolvedSource=null` parameter; when non-null, it is used directly and the `findSource(id: ...)` call is skipped. `synchronizeExternToIntern()` passes the transient `$source` array it already builds at `1379-1382` (when the caller supplied an ad-hoc `source` location) as `$resolvedSource`; for the normal (non-ad-hoc) path, `$resolvedSource` stays `null` and `getAllObjectsFromApi()` falls back to its existing `findSource(id: ...)` lookup, unchanged.

**Consequence, documented, not hidden:** a fully ad-hoc, never-configured location loses cross-call rate-limit watermark tracking (`checkRateLimit()` already no-ops when `$source['rateLimitLimit']` is unset, `3049`) — this is the correct tradeoff per #1009: a location nobody configured as a real Source should not silently gain first-class, persisted rate-limit tracking either. An admin who needs that should configure it as a real Source (the reviewable, intended path).

**Rejected alternative — persist the ad-hoc Source but mark it `enabled: false` / add an `ephemeral: true` marker field:** rejected because it still writes a real, queryable, permanent OpenRegister object from unreviewed caller input on every ad-hoc call (unbounded growth of orphaned Source objects, one per unique location ever passed), which is precisely the behaviour #1009 flags as undesirable. The transient-array + threaded-resolution approach avoids persistence entirely while keeping the pagination/rate-limit code paths working unchanged for the (unaffected) configured-Source case.

### Decision 7: Notification/event mechanism — new domain event via the existing lazy-container-resolution pattern

**Chosen:** new `lib/Event/SynchronizationDeletionGuardedEvent.php extends \OCP\EventDispatcher\Event`, carrying `synchronizationId`, `reason` (`fetch_incomplete` | `ratio_threshold_exceeded`), `ratio`, `threshold`, `candidateCount`, `totalContracts`. Dispatched from inside `deleteInvalidObjects()` at both guard-trip sites (Decision 2).

`IEventDispatcher` is resolved via `$this->containerInterface->get(IEventDispatcher::class)` **inside the constructor body**, exactly like the existing `SynchronizationContractLogService`/`SynchronizationContractService` lazy resolution (`SynchronizationService.php:182-190`) — not as a new required constructor parameter. This is a deliberate consistency choice: `tests/Unit/Service/SynchronizationServiceCleanupTest.php` and `SynchronizationServiceTest.php` both build the service via `getMockBuilder(...)->setConstructorArgs([8 positional args])`; adding a 9th required constructor parameter would break every existing test fixture in the suite. `IEventDispatcher` is guarded the same way the other lazy dependencies are (`if ($resolved instanceof IEventDispatcher) { $this->eventDispatcher = $resolved; }`), tolerating a bare container mock in tests that don't need it — calls to dispatch are themselves guarded with `if ($this->eventDispatcher !== null)`.

**Rejected alternative — Nextcloud `INotificationManager`:** rejected because no OpenConnector code anywhere uses `INotificationManager` today (verified — zero hits), and building a full notification-app integration (app icon, subject/message l10n keys, notifier class registration) for a single new event is out of proportion to this change; `IEventDispatcher` is the existing, already-adopted mechanism for domain events in this app (`lib/AppInfo/Application.php` registers `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectDeletedEvent` listeners today) and gives any future consumer (notification listener, admin dashboard widget, audit log) a stable extension point without this change needing to build the consumer.

### Decision 8: Deletion-ratio threshold configuration location

**Chosen:** `sourceConfig.deletionRatioThreshold` (float, `0.0`–`1.0`), read via the existing `applyConfigDot($synchronization['sourceConfig'] ?? [])` pattern already used for `restrictDeletion`, with a class constant default `DEFAULT_DELETION_RATIO_THRESHOLD = 0.10`. This exactly mirrors the existing `maxPages` sourceConfig-override pattern (`sourceConfig['maxPages'] ?? $this::DEFAULT_MAX_PAGES`, `3185`) — no schema migration, since `sourceConfig` is already `"type": "object"` (free-form) in `lib/Settings/openconnector_register.json`. The recognised-keys list in that field's `description` is updated to document the new key (documentation-only edit, not a schema/migration change — see `migration.md`).

**Rejected alternative — a top-level `deletionRatioThreshold` field on the `synchronization` schema:** rejected because it would require a schema migration and a decision about backfilling existing synchronizations, for a value that behaves exactly like the existing `maxPages` per-source override and fits the established `sourceConfig` convention.

## Risks / Trade-offs

- [Risk] A synchronization with a legitimately volatile source (frequently loses >10% of records) will trip the guard on every run → [Mitigation] per-synchronization `sourceConfig.deletionRatioThreshold` override; guard trip is logged + evented, discoverable rather than silent.
- [Risk] `forceDeletion` could be scripted/automated by an operator to always bypass the guard, reintroducing the original risk → [Mitigation] it is a new, separate, explicit REST parameter — not the pre-existing `force` flag already used by automatic event-driven triggers — so bypassing it requires a deliberate, auditable API call each time, not a config flag set once.
- [Risk] The ad-hoc-Source transient-resolution change (Decision 6) is the most structurally invasive piece (new `?array $resolvedSource` parameter threaded through 2 public methods) → [Mitigation] fully backward compatible (new param defaults to `null`, existing behaviour preserved when absent); covered by a dedicated regression test asserting an ad-hoc sync against an unmatched location still succeeds and that no new Source object is persisted.
- [Risk] `detectDuplicateContracts()` (Decision 5) adds a query on every matched contract lookup, a minor performance cost → [Mitigation] reuses the result already fetched by the existing lookup rather than issuing a second `findAll` query; documented in `tasks.md` as an implementation constraint, not an option.

## Migration Plan

No database/schema migration — see `migration.md` (skipped, with reason). Deploy is a standard app-code release: revert-safe (Rollback Strategy in `proposal.md`), no data backfill, no OpenRegister register/schema version bump required.

## Open Questions

None outstanding for implementation — see `proposal.md` Open Questions for the deferred notification-UI question, which does not block this change.
