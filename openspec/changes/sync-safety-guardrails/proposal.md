# Proposal: sync-safety-guardrails

## Summary

`SynchronizationService::deleteInvalidObjects()` — the cleanup pass that garbage-collects target objects no longer present in the source — is called unconditionally from `synchronizeExternToIntern()`, with no way to know whether the preceding fetch actually succeeded. When a source errors mid-fetch, is rate-limited (HTTP 429), or fails partway through pagination, the engine still diffs the (empty or truncated) fetch result against every existing `SynchronizationContract` and deletes everything it didn't just see — including on a "Test (dry run)" click, which today deletes real objects because the cleanup call carries no test-mode guard at all. This change adds fetch-completeness tracking, a configurable deletion-ratio guard with an explicit override, an absolute no-write guarantee for test runs, and removes silent auto-persistence of ad-hoc Source objects, closing ConductionNL/integriq#1000, #1001, #1002, #1008, #1009, #1016, and #1017.

## Motivation

This is the synchronization engine's only organic-customer surface today — the WOO publishing pipelines for Vaals, Berkelland, and Zwolle depend on it running unattended against external sources that are not always reliable. A transient upstream 500, a rate limit, or an admin clicking "Test" can currently delete every object that pipeline has ever synced, with no confirmation step and no way to distinguish "the source really deleted these" from "the fetch failed." #732 already documented a real production incident of this class (a cleanup pass that joined a legacy table and deleted live objects post magic-table cutover). Verified against HEAD (`lib/Service/SynchronizationService.php`), the mass-deletion path is real and, for the test-run case, more severe than the originating issue described: `deleteInvalidObjects()` is called at line 1523 of `synchronizeExternToIntern()` with no `isTest` parameter and no guard, so it runs identically whether the preceding fetch returned everything, returned nothing because of a 429, or was never meant to write anything because the caller only asked for a dry run.

## Affected Projects

- [x] Project: `integriq` — `SynchronizationService`, `SynchronizationsController`, and the synchronization-engine cleanup/fetch/test-run/source-resolution paths gain fetch-completeness tracking, a deletion-ratio guard, an absolute test-run no-write guarantee, and non-persistent ad-hoc Source resolution.

## Scope

### In Scope

- Fetch-completeness tracking through the private pagination chain (`fetchSinglePageData()` → `fetchAllPagesOptimized()` → `fetchAllPages()`) surfaced to `synchronizeExternToIntern()` via a backward-compatible output parameter on the public `getAllObjectsFromApi()` / `getAllObjectsFromSource()` methods — no non-2xx page response, rate-limit (429), or exhausted-pagination-safety-cap is ever silently treated as "the source has nothing left."
- `deleteInvalidObjects()` MUST NOT run when the preceding fetch was incomplete, and MUST NOT run at all when `isTest === true` (currently it runs in both cases).
- A configurable per-synchronization deletion-ratio guard (`sourceConfig.deletionRatioThreshold`, default 10%): when a cleanup pass would delete more than the threshold of the synchronization's existing contracts, the deletion is aborted, logged at warning level, and an event is dispatched — unless an explicit `forceDeletion` flag is supplied by the caller.
- An absolute no-write guarantee for `isTest === true` runs: no `SynchronizationContract` created/updated, no target object written or deleted, no Source object persisted, and no mutation of the `Synchronization` entity itself (currently `persistSynchronization()` at the end of `synchronizeExternToIntern()` runs unconditionally, even in test mode).
- Ad-hoc, caller-supplied `source` location strings (`findOrCreateSourceByLocation()`) MUST NOT silently persist a new, enabled Source object; a genuinely unmatched location resolves to a transient, in-memory source configuration for that call only. Matching an already-configured Source by `location` is unaffected.
- originId-based contract matching on re-sync (#1016): static verification against HEAD found the existing `findContractBySyncAndOrigin()` / `processSynchronizationObject()` flow already looks up by `(synchronizationId, originId)` before creating — no reproducible duplication bug was found by reading the code. This change adds regression tests that pin the current (believed-correct) behaviour and a read-only duplicate-contract detector that logs/surfaces (never silently deletes) any pre-existing duplicates found at sync time.
- Unit and integration tests reproducing the #1000/#1001/#1002 (mass-deletion), #1008 (test-run persistence), #1009 (ad-hoc Source auto-creation), and #1016 (originId matching) scenarios using source stubs that return errors, 429s, and partial pages.

### Out of Scope

- Retry/backoff/circuit-breaker behaviour for failed fetches (tracked separately as `retry-and-circuit-breaker-policies`) — this change only ensures a failed/incomplete fetch never triggers deletion; it does not make the fetch itself more resilient.
- `fetchAllPagesOptimized()` memory/streaming rework for very large result sets (oc#1010) — noted as a follow-up, not addressed here.
- A user-facing notification/inbox UI for the new deletion-guard event — this change dispatches an `OCP\EventDispatcher` event; wiring it to Nextcloud's `INotificationManager` or an admin-facing UI is deferred (no such consumer exists in Integriq today for any event).

## Approach

Thread a fetch-completeness signal bottom-up through the private pagination methods without changing any public method's existing return type (avoiding a break to the ~13 existing `getAllObjectsFromApi()` test call sites), using optional pass-by-reference output parameters — the same non-breaking-extension technique already used elsewhere in this class for lazily-resolved dependencies. Gate `deleteInvalidObjects()` on that signal plus a new ratio-guard computed from existing contract counts, with the guard/force logic living entirely inside `deleteInvalidObjects()` (single source of truth) and reported back to the caller via a second optional by-reference parameter, keeping its existing `int` return type intact for the existing PHPUnit assertions. Stop `findOrCreateSourceByLocation()` from persisting a new Source when no existing one matches. See `design.md` for the full parameter/method-level breakdown.

## New Dependencies

None. Reuses `OCP\EventDispatcher\IEventDispatcher`, already used elsewhere in the app (`lib/AppInfo/Application.php`) and resolved via the existing `ContainerInterface` lazy-resolution pattern already used in this class for `SynchronizationContractLogService`/`SynchronizationContractService` — no change to `SynchronizationService`'s public constructor signature.

## Impact

- `lib/Service/SynchronizationService.php` — `deleteInvalidObjects()`, `synchronizeExternToIntern()`, `getAllObjectsFromSource()`, `getAllObjectsFromApi()`, `fetchAllPages()`, `fetchAllPagesOptimized()`, `fetchSinglePageData()`, `findOrCreateSourceByLocation()`, `processSynchronizationObject()`, `synchronize()`.
- `lib/Controller/SynchronizationsController.php` — `run()` gains a `forceDeletion` request parameter, threaded through to `synchronize()`.
- New: `lib/Event/SynchronizationDeletionGuardedEvent.php`.
- `lib/Settings/integriq_register.json` — documentation-only addition of the new recognised `sourceConfig.deletionRatioThreshold` key to the existing free-form `sourceConfig` description; no schema/field migration (the property is already `type: object`).
- Tests: new files under `tests/Unit/Service/` alongside the existing `SynchronizationServiceTest.php` and `SynchronizationServiceCleanupTest.php`.

## Cross-Project Dependencies

None directly — no other apps-extra project calls `SynchronizationService` methods directly. Indirect beneficiaries: any app relying on Integriq-driven pull synchronizations (the WOO publishing pipelines noted above, and any future consumer of the `synchronizations/{id}/run` and `synchronizations/{id}/test` REST endpoints) gain protection from silent mass-deletion without any contract change on their side — `forceDeletion` is a new optional parameter, and the deletion-guard behaviour is a strict narrowing of when deletion happens, not a new required step for existing callers.

## Risks

### Risk 1: A synchronization whose source legitimately shrinks by more than the default 10% threshold will have its cleanup silently skipped until an admin re-runs with `forceDeletion`

**Severity:** Medium — **Mitigation:** the threshold is configurable per-synchronization via `sourceConfig.deletionRatioThreshold`; the guard trip is logged at warning level and dispatched as an event so it is discoverable, not silent; `forceDeletion` provides an explicit, auditable override for the legitimate-shrink case.

### Risk 2: Ad-hoc Source resolution no longer persisting a new Source means rate-limit watermark tracking (`rateLimitLimit`/`rateLimitRemaining`) is unavailable for genuinely un-configured ad-hoc locations

**Severity:** Low — **Mitigation:** this only affects the ad-hoc `source` request parameter path (not synchronizations with a properly configured Source, whose find-by-location match is unchanged); an admin who needs rate-limit tracking for a repeatedly-used location should configure it as a real Source, which is the intended, reviewable path per #1009.

### Risk 3: Existing PHPUnit tests assert exact call counts / argument shapes on the methods being touched

**Severity:** Low — **Mitigation:** every signature change is an appended optional parameter (backward compatible); `tests/Unit/Service/SynchronizationServiceTest.php` and `SynchronizationServiceCleanupTest.php` are run as part of this change's task list before considering it complete.

## Rollback Strategy

Pure PHP application-code change with no schema/data migration. Revert the commit(s) touching `lib/Service/SynchronizationService.php`, `lib/Controller/SynchronizationsController.php`, and the new `lib/Event/SynchronizationDeletionGuardedEvent.php`; no database state, OpenRegister objects, or configuration written by this change needs to be undone (the change only makes deletion/persistence *more* conservative, never less — a rollback returns to the pre-existing, more permissive behaviour, not to a broken state).

## Open Questions

- Should the deletion-guard event eventually surface in Nextcloud's notification centre (`INotificationManager`)? Deferred — no notification consumer exists for any Integriq event today; tracked as a natural follow-up once a UI exists to display it.
