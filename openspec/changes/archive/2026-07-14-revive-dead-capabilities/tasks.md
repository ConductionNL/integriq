# Tasks — revive-dead-capabilities (openconnector)

## 1. Verify (done during triage)

- [x] 1.1 Re-grep all 9 flagged methods at current HEAD; confirm
      `exportConfiguration` is already routed (`configuration#export`) — stale FP.
- [x] 1.2 Confirm the 5 `SearchService` builders have 0 callers and `search()`
      uses Elastic + directory fan-out (no MySQL/MongoDB query path).
- [x] 1.3 Confirm `clearCache` has 0 callers though REQ-EP-004 requires it be
      invoked on endpoint create/update/delete.
- [x] 1.4 Confirm `exportRegister` has 0 callers; intended route documented in
      routes.php comment; REQ-002 covers it.
- [x] 1.5 Classify `createAgendapunt` as a spec-required outbound seam (unbuilt
      pipeline).

## 2. Delete superseded search builders (code)

- [x] 2.1 Remove the 5 `create*` builders from `SearchService`; keep
      `unsetSpecialQueryParams` and `search`; trim the class docblock.

## 3. Wire exportRegister (code)

- [x] 3.1 Add `ConfigurationController::exportRegister(string $id)` mirroring
      `export()` (auth gate `configuration.export`, attachment download).
- [x] 3.2 Add route `configuration#exportRegister` — `GET /api/registers/{id}/export`.
- [x] 3.3 Add `ConfigurationControllerTest` cases: happy-path bundle download,
      403 for unmapped non-admin, 401 unauthenticated.

## 4. Wire clearCache (code)

- [x] 4.1 Add `EndpointCacheInvalidationListener` (SPDX header) gating on register
      slug `openconnector` + schema slug `endpoint`; call `clearCache()`.
- [x] 4.2 Register it for ObjectCreated/Updated/Deleted events in `Application.php`.
- [x] 4.3 Add `EndpointCacheInvalidationListenerTest`: fires on endpoint
      create/update/delete; no-op for non-endpoint / unrelated / unresolvable.

## 5. Document the iBabs seam

- [x] 5.1 Add `@orphaned-write-capability exclude` marker + prose to
      `createAgendapunt`; file a follow-up issue for the outbound pipeline.

## 6. Verify + spec + ship

- [x] 6.1 `php -l` + scoped phpcs clean on all edited lib files.
- [x] 6.2 Full unit suite in php:8.3-cli shows no new failures vs baseline (1203 → 1211, +8; ship-time re-measure — dev advanced past the 1115→1123 draft baseline).
- [x] 6.3 Spec deltas: `mapping-and-search` REQ-005, `configuration-export-import`
      REQ-002, `endpoint-runtime` REQ-EP-004.
- [x] 6.4 Push, PR base development, merge, archive, update issue #165.
