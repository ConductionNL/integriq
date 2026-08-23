# Design: retry-and-circuit-breaker-policies

## Architecture Overview
Three independent additions to the existing Integriq engine layer, all following the established `Controller → Service → OR ObjectService` layering (ADR-008):

1. **Retry + circuit breaker in `CallService`** — the single dispatch attempt inside `CallService::call()` (which currently delegates to the private `dispatchRequest()` helper exactly once) is wrapped in a bounded retry loop, gated by a per-Source circuit breaker check. Both the `RetryPolicy` and the breaker state are resolved from/persisted to the `source` OR object, reusing the exact `saveObject(register: 'openconnector', schema: 'source', uuid: ..., _rbac: false, _multitenancy: false)` pattern `sourceRateLimit()` already uses for rate-limit bookkeeping (`lib/Service/CallService.php:545-553`).
2. **Sync-item dead-letter** — a new `sync_item_dead_letter` OR schema plus a new `SyncItemDeadLetterService` (mirroring `EventService`'s `recordFailure`/`replayMessage`/`discardMessage` shape) and controller endpoints, reusing the `EventsController` dead-letter UI pattern already shipped (`openconnector-dead-letter-replay`). `SynchronizationService::synchronizeExternToIntern()`'s per-object `foreach` loop (currently un-guarded — verified at `lib/Service/SynchronizationService.php:1449-1460`) gets a `try/catch (\Throwable)` around the `processSynchronizationObject()` call that captures the failure and continues.
3. **Cron isolation verification** — `JobService::run()` and `JobService::executeJob()` already implement #1005 (per-job try/catch, schedule always advances) and #1006 (prior-session-user restored in a `finally` block) — confirmed reading HEAD. No functional code changes are planned here; this change corrects the now-stale `job-scheduling` spec text (which still documents the pre-fix "session-clobber" bug) and adds the missing regression tests that pin the fix.

## Goals / Non-Goals

**Goals:**
- Zero behavior change for any Source/Synchronization that does not opt into a `RetryPolicy` (default: `maxAttempts: 1`, i.e. today's single-attempt dispatch).
- Circuit breaker state that survives across PHP-FPM workers and separate cron processes (unlike `PdokConnector`'s APCu-backed breaker), and is visible in the Source detail UI without a new read path.
- Sync-item failures isolated per-object so one bad record does not abort an entire sync pass, with an audited manual-replay path.
- Documentation catches up with code for the already-fixed #1005/#1006 cron bugs, with tests that would fail if either regressed.

**Non-Goals:**
- Distributed locking for the circuit breaker's half-open probe (best-effort single-probe guard only — see Risks).
- Automatic scheduled retry sweeps for `sync_item_dead_letter` (manual/bulk replay only — see Decision 5).
- Fine-grained phase classification (`fetch` vs `mapping` vs `write`) for sync-item dead-letter entries in v1 (see Decision 5).
- Fixing the pre-existing, unrelated IDOR findings already documented in `job-scheduling` REQ-002 and `synchronization-engine` REQ-005 Notes — out of scope for this change.

## Decisions

### Decision 1: RetryPolicy lives on `source.retryPolicy`, with an optional `synchronization.retryPolicyOverride`
**Chosen:** Add a `retryPolicy` object field to the `source` schema:
```json
{
  "maxAttempts": 1,
  "backoffStrategy": "fixed | exponential",
  "baseDelayMs": 500,
  "maxDelayMs": 30000,
  "jitter": false,
  "retryableStatusCodes": [429, 502, 503, 504],
  "retryOnTimeout": false
}
```
Add a `retryPolicyOverride` object field (same shape, all keys optional) to the `synchronization` schema. `CallService::call()` resolves the **effective policy** as a per-key merge: built-in default (`maxAttempts: 1`, i.e. no retries) ← `Source.retryPolicy` ← `$config['retryPolicy']`. The last layer (`$config['retryPolicy']`) is populated by the *caller* — `SynchronizationService` copies `Synchronization.retryPolicyOverride` into the `$config` array it builds before calling `CallService::call()`, exactly like it already does for `pagination`/`logBody`/`preRequest` directives. This keeps `CallService::call()`'s signature unchanged (no new required parameter) and is consistent with the existing convention of piggybacking per-call directives on the `$config` array (REQ-001/REQ-002 of `http-call-engine` already do this for `preRequest`, `postRequest`, `pagination`, `logBody`).

**Alternatives considered:**
- *New `$retryPolicy` parameter on `CallService::call()`.* Rejected — would require updating every one of the method's existing callers (SynchronizationService, EndpointService, mapping test endpoints, PdokConnector-adjacent code) even when they don't care about retries; the `$config`-array convention already solves exactly this "optional per-call directive" problem.
- *RetryPolicy as a standalone OR schema/entity referenced by id.* Rejected — over-engineered for a config blob with no independent lifecycle; every other per-Source operational knob (rate-limit fields, retention overrides) lives as inline fields on `source` today.

### Decision 2: Circuit breaker state persisted on the `source` OR object, not APCu/ICache
**Chosen:** Add four fields to `source`: `circuitBreakerState` (`closed|open`), `circuitBreakerFailureCount` (integer), `circuitBreakerOpenedAt` (unix timestamp, nullable), `circuitBreakerLastProbeAt` (unix timestamp, nullable), plus two configurable thresholds: `circuitBreakerThreshold` (default 5, mirrors `PdokConnector::BREAKER_THRESHOLD`) and `circuitBreakerCooldownSeconds` (default 30, mirrors `PdokConnector::BREAKER_OPEN_SECONDS`). State is read/written via the same `ObjectService::saveObject()` calls `CallService` already makes for rate-limit bookkeeping — no new persistence mechanism.

`half-open` is **never persisted** — it is a derived read: when `circuitBreakerState === 'open'` and `now - circuitBreakerOpenedAt >= circuitBreakerCooldownSeconds`, the breaker is treated as half-open for exactly the next dispatch attempt (identical semantics to `PdokConnector::circuitState()`, `lib/Connectors/PdokConnector.php:618-640`).

**Alternatives considered:**
- *Reuse `PdokConnector`'s APCu (`ICache`) pattern, one cache key per Source uuid.* Rejected: (a) APCu is per-machine shared memory — it does not survive a horizontally-scaled Nextcloud deployment (multiple app servers behind a load balancer) or an APCu flush/deploy, so breaker state would silently reset; (b) the Source detail UI needs to *display* breaker state, and every other piece of Source operational state the UI already shows (`rateLimitRemaining`, `lastCall`, `status`) is read directly off the OR object — a cache-backed breaker would need a brand-new read path; (c) `config.yaml`'s project rule is explicit: "OpenRegister is a required runtime dependency; every entity is persisted as an OpenRegister object and app-local reimplementation of OpenRegister capabilities is forbidden." Breaker state is now a first-class piece of a Source's operational health, same tier as its rate-limit fields.
- *New `circuit_breaker_state` OR schema, one row per Source.* Rejected as unnecessary indirection — it's 1:1 with Source, has no independent lifecycle, and would require an extra lookup on every dispatch; inline fields match the existing rate-limit precedent exactly.
- *App config (`IAppConfig`) keyed by source uuid.* Rejected — `IAppConfig` is a flat app-wide key/value store with no query/list/UI-binding support; would require reimplementing lookup-by-source that OR objects already give for free.

### Decision 3: Retry loop and breaker check wrap the existing single-attempt `dispatchRequest()` call, unchanged signature
**Chosen:** Inside `CallService::call()`, replace the single `dispatchRequest(...)` invocation with a loop:
1. Resolve effective `RetryPolicy` (Decision 1) and current breaker state (Decision 2) for the Source.
2. If breaker state resolves to `open` (not half-open), short-circuit with a synthetic `503` `call_log` (same style as the existing 409/429 short-circuits in `guardCallPreconditions()`) — **no HTTP call is made and no retry attempt is consumed**.
3. Otherwise, dispatch via the existing `dispatchRequest()`. On a response whose status is in `retryableStatusCodes`, or a transport exception when `retryOnTimeout === true`: record a breaker failure (Decision 2), and — if attempts remain — sleep for the computed backoff and retry; if attempts are exhausted, record the breaker failure and return the last response as today.
4. On a non-retryable outcome (success, or a failure status not in `retryableStatusCodes`): if it was a success, reset the breaker (`circuitOnSuccess`); persist and return exactly as today.

Backoff formula (mirrors `PdokConnector::sleepBackoff()` for consistency, `lib/Connectors/PdokConnector.php:701-712`):
- `fixed`: `delayMs = baseDelayMs`
- `exponential`: `delayMs = min(baseDelayMs * 2^(attempt-1), maxDelayMs)`
- `jitter: true` adds ±10% uniform jitter to either formula's result, same ratio `PdokConnector` already uses.

**Alternatives considered:**
- *Retry inside `dispatchRequest()` itself.* Rejected — `dispatchRequest()` also handles the brokered-credential and SOAP branches, which have their own dispatch semantics; keeping the loop at the `call()` level means the same retry/breaker logic applies uniformly across all three dispatch modes without duplicating it three times.

### Decision 4: Breaker short-circuit reuses the existing synthetic-CallLog pattern; status 503 (not a new code)
**Chosen:** An open-breaker short-circuit persists a `call_log` with `statusCode: 503` and `statusMessage: "Circuit breaker is open for this source"`, following the exact shape of the existing disabled-source (409) and rate-limit (429) short-circuits in `guardCallPreconditions()`. This keeps CallLog consumers (metrics, sync error handling) working against a status code they already know how to interpret as "the call didn't go through."

### Decision 5: Sync-item dead-letter is capture + manual/bulk replay only — no automatic retry sweep, no fine-grained phase classification in v1
**Chosen:** `try/catch (\Throwable)` around the single `processSynchronizationObject()` call site inside `synchronizeExternToIntern()`'s object loop. On catch: persist a `sync_item_dead_letter` object (synchronization uuid, best-effort originId when resolvable, the raw source object as `payload`, the exception message as `error`, `phase: 'item-processing'`, `status: 'failed'`), increment the run's `result.objects.invalid` counter, and continue to the next object. No scheduled sweep re-attempts these — unlike event delivery (transient network/sink failures that often self-heal), sync-item failures are typically deterministic (bad mapping config, malformed source data) and re-running them unattended would just fail identically every time. Replay is a deliberate operator action (single or bulk, mirroring `dead-letter-replay` REQ-DLR-003/005) that re-invokes `processSynchronizationObject()` for that one item.

Phase classification (`fetch` vs `mapping` vs `write`) is **not** attempted in v1: `processSynchronizationObject()` is a single ~150-line method with no internal phase boundaries exposed to a caller today, and instrumenting it precisely would mean threading phase markers through `synchronizeContract()`/`updateTarget()`/`processMapping()` — a materially larger refactor of an already very large service (`SynchronizationService.php` is ~6,700 lines). `phase: 'item-processing'` is stored as a fixed value for v1; the field is a string (not a locked enum) so a follow-up change can populate it more precisely without a schema migration.

**Alternatives considered:**
- *Auto-retry sweep identical to `EventRetryJob`.* Rejected per the reasoning above — would spam retries against deterministic failures with no chance of success, burning cron cycles.
- *Full phase instrumentation now.* Rejected as scope creep for this change; flagged as a follow-up (documented in Open Questions).

### Decision 6: Manual trip/reset endpoints are admin-only, not `@NoAdminRequired`
**Chosen:** New `SourcesController` methods `POST .../sources/{id}/circuit-breaker/trip` and `POST .../sources/{id}/circuit-breaker/reset` carry **no** `@NoAdminRequired` / `@NoCSRFRequired` annotations (i.e. default NC admin-required + CSRF-protected). This deliberately does **not** follow the `@NoAdminRequired` + no-authorization-guard pattern already flagged as a HIGH/IDOR finding elsewhere in this app (`job-scheduling` REQ-002, `synchronization-engine` REQ-005 Notes) — it follows the newer, already-shipped `dead-letter-replay` posture (admin-only, CSRF-protected, REQ-DLR-001) instead. Tripping/resetting a breaker is an operationally sensitive action (it can black-hole or re-open traffic to an upstream) and should not inherit the older IDOR-prone convention.

## API Design

### `POST /api/sources/{id}/circuit-breaker/trip`
**Auth**: Nextcloud admin session (no `@NoAdminRequired`), CSRF-protected.
**Request:** `{}` (no body required)
**Response (200):**
```json
{ "uuid": "<source-uuid>", "circuitBreakerState": "open", "circuitBreakerOpenedAt": 1752470400 }
```
**Errors:** `404` unknown source id.

### `POST /api/sources/{id}/circuit-breaker/reset`
**Auth**: Nextcloud admin session, CSRF-protected.
**Response (200):**
```json
{ "uuid": "<source-uuid>", "circuitBreakerState": "closed", "circuitBreakerFailureCount": 0 }
```
**Errors:** `404` unknown source id.

### `GET /api/sync-dead-letter`
**Auth**: Nextcloud admin session, CSRF-protected. Mirrors `GET /api/events/dead-letter` (REQ-DLR-001): filters `synchronizationId`, `from`/`to`, `status` (default `failed`); pagination `limit`/`offset`.

### `GET /api/sync-dead-letter/{id}`
Full detail: payload, error, attempts history, resolved synchronization context. Mirrors REQ-DLR-002.

### `POST /api/sync-dead-letter/{id}/replay`
Re-invokes `processSynchronizationObject()` for this item's payload against its synchronization; `failed` → `replayed` on success (item re-enters the normal contract flow) or stays `failed` with an appended attempt on repeat failure. Mirrors REQ-DLR-003 semantics adapted for manual-only replay (no automatic re-entry into a pending/backoff state).

### `POST /api/sync-dead-letter/{id}/discard`
Mirrors REQ-DLR-004: terminal `discarded` state, audited, no hard delete.

### `POST /api/sync-dead-letter/replay` / `POST /api/sync-dead-letter/discard`
Bulk variants, `{ids: string[]}` capped at 100, per-id outcome map. Mirrors REQ-DLR-005.

## Database Changes
No Nextcloud `lib/Migration/` classes — schema changes flow entirely through the OR register descriptor (`lib/Settings/integriq_register.json`), consistent with every other schema change in this app (see `openconnector-register-schema` spec). Changes:
- `source`: + `retryPolicy` (object), + `circuitBreakerState` (string, default `closed`), + `circuitBreakerFailureCount` (integer, default 0), + `circuitBreakerOpenedAt` (integer, nullable), + `circuitBreakerLastProbeAt` (integer, nullable), + `circuitBreakerThreshold` (integer, default 5), + `circuitBreakerCooldownSeconds` (integer, default 30).
- `synchronization`: + `retryPolicyOverride` (object, optional).
- New schema `sync_item_dead_letter`: `uuid`, `synchronization` (FK → `synchronization`, CASCADE), `synchronizationContract` (FK → `synchronization_contract`, SET NULL, nullable), `originId` (string, nullable), `phase` (string, default `item-processing`), `payload` (object), `error` (string), `status` (enum `failed|replayed|discarded`), `retryCount` (integer, default 0 — counts manual replays, not automatic attempts), `attempts` (array, same per-attempt shape as `event_message.attempts`), `replayedBy`/`replayedAt`, `discardedBy`/`discardedAt`, `created`/`updated`.

All new fields on existing schemas are additive with safe defaults — no backfill needed, no data loss on rollback (removing the fields from the descriptor simply stops surfacing them; existing Source/Synchronization rows are unaffected since OR objects are schemaless JSON underneath the descriptor).

## Nextcloud Integration
- Controllers: `SourcesController` (+2 methods), new `SyncDeadLetterController` (list/detail/replay/discard/bulk-replay/bulk-discard — mirrors `EventsController`'s dead-letter methods), `MetricsController` (+1 gauge).
- Services: `CallService` (retry loop + breaker), new `SyncItemDeadLetterService`, `SynchronizationService` (+ try/catch at the object-loop call site).
- Mappers/Entities: none new — all persistence via `OCA\OpenRegister\Service\ObjectService` (ADR: no app-local reimplementation of OR capabilities).
- Events/Hooks: none new.

## Security Considerations
- Manual trip/reset and sync-dead-letter endpoints are admin-only + CSRF-protected (Decision 6) — deliberately avoiding this app's existing IDOR pattern rather than extending it.
- `sync_item_dead_letter.payload` stores the raw source object at failure time, which may contain the same categories of sensitive data the source objects themselves carry; it inherits the same admin-only read gate as the rest of the dead-letter surface (no new exposure beyond what `event_message.payload` already accepts as precedent).
- The breaker's open-state short-circuit does not bypass or weaken any existing guard in `guardCallPreconditions()` (disabled-source 409, rate-limit 429) — it is evaluated after those, as an additional guard.
- No new secrets are introduced; `RetryPolicy` and breaker-state fields carry no credentials.

## File Structure
```
lib/
  Controller/
    SourcesController.php        (+ tripCircuitBreaker(), + resetCircuitBreaker())
    SyncDeadLetterController.php (new)
    MetricsController.php        (+ circuit breaker gauge)
  Service/
    CallService.php              (+ retry loop, + breaker resolve/record helpers)
    SyncItemDeadLetterService.php (new)
    SynchronizationService.php   (+ try/catch at object-loop call site)
  Settings/
    integriq_register.json  (+ source.retryPolicy/circuitBreaker*, + synchronization.retryPolicyOverride, + sync_item_dead_letter schema)
src/
  views/synchronizations/SyncDeadLetter.vue (new, mirrors Events "Event deliveries" sub-view)
  modals/SyncDeadLetterDetailModal.vue (new)
tests/
  Unit/Service/CallServiceTest.php          (+ retry policy math, + breaker state machine cases)
  Unit/Service/SyncItemDeadLetterServiceTest.php (new)
  Integration/Cron/JobServiceIsolationTest.php   (new — pins #1005/#1006)
  Integration/Service/SynchronizationItemIsolationTest.php (new)
```

## Seed Data
Not applicable — this change adds configuration fields (defaulting to today's behavior) and operational state to existing `source`/`synchronization` schemas, plus a dead-letter schema that is populated only by failures at runtime (mirrors `event_message`, which also carries no seed data — dead-letter rows are inherently generated by failure conditions, not meaningful as static seed content).

## Trade-offs
- **Half-open probe concurrency (accepted limitation):** `circuitBreakerLastProbeAt` bounds — but does not fully eliminate — multiple concurrent requests each treating themselves as "the" half-open probe during a race window. A full fix needs a distributed lock (e.g. an OR optimistic-concurrency compare-and-set on `circuitBreakerState`), deferred as unnecessary complexity for a feature whose worst case is "a few extra probe requests hit a still-down upstream," not data corruption.
- **`phase: 'item-processing'` instead of true phase granularity (accepted limitation):** operators lose "was this a mapping bug or a write failure" at a glance in v1; the `payload` + `error` message usually still make it diagnosable manually. Follow-up tracked in Open Questions.
- **No automatic sync-item retry sweep:** matches the reasoning in Decision 5, but means an operator must notice and act on dead-lettered items — mitigated by the existing dead-letter UI pattern (badge/counts) this change reuses, and is a reasonable candidate for a future Prometheus alert on `sync_item_dead_letter` count, out of scope here.

## Open Questions
- Should `sync_item_dead_letter.phase` gain real `fetch|mapping|write` granularity in a follow-up, and is that worth refactoring `processSynchronizationObject()`'s internals for? Deferred — track as a follow-up issue at implementation time.
- Should the circuit breaker threshold/cooldown be globally configurable (app config default) in addition to the per-source override, the way `errorRetention`/`successRetention` already have both a global `IAppConfig` default and a per-source override? Leaning yes for consistency, left to the implementer to confirm during `tasks.md` execution — does not change the schema shape (per-source fields already have safe built-in defaults of 5/30s matching `PdokConnector`).
