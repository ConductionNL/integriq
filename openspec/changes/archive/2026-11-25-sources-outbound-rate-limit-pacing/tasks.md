# Tasks: sources-outbound-rate-limit-pacing

Kind: code. Size S to M. Row `integriq:src-ratelimit-out`.

## Implementation tasks

### Task 1: The pacer and the shared bucket
- **spec_ref**: `openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001`
- **files**: `lib/Service/Call/SourcePacer.php`, `lib/Service/CallService.php`, `lib/Settings/integriq_register.json` (source `pace`, call_log `pacedMs`)
- **acceptance_criteria**:
  - GIVEN a pace of 10 calls per second WHEN 30 background calls are made THEN they take about three seconds and none is refused
  - GIVEN a source that announced 5 remaining calls and a reset in 10 seconds WHEN 5 calls are made THEN they are spaced about 2 seconds apart
- [x] Implement
  - `SourcePacer` (`lib/Service/Call/`) keeps each source's budget as the time its next call is due, in the distributed cache `integriq.source.pace`, moved with compare-and-swap so every worker spends one budget; without a distributed memcache the budget is per process and `isShared()` says so. The gap is the larger of the configured `pace` (`maxCalls` per `perSeconds`, `burst` back to back) and the announced `rateLimitRemaining` spread over the time to `rateLimitReset`; with nothing remaining a call waits for the reset. `CallService::guardCallPreconditions()` takes the turn as phase 5b, waits, re-checks the window, and keeps phase 6 as the last safety net. The call log records `pacedMs` when a call waited. The register fragment `register.d/sources-outbound-rate-limit-pacing.json` adds `source.pace` and `call_log.pacedMs` / `retryAfter`; the seeded KvK source gets `pace` `{maxCalls: 100, perSeconds: 60}`.
- [x] Test (PHPUnit with a fake clock and an in-memory cache; one parallel test with two processes sharing the bucket)
  - Done: `tests/Unit/Service/Call/SourcePacerTest.php` (fake clock, in-memory memcache): 30 calls at 10/s start 0.1 s apart and end at 2.9 s, none refused; 5 announced calls in 10 s spaced 2 to 2.9 s, all before the reset; the larger gap wins; burst; nothing remaining waits for the reset; two pacers on one cache share one budget; a lost swap reads again; per-process without a distributed cache; unpaced sources never wait. `tests/Unit/Service/CallServicePacingTest.php`: the second background call waits 5 s and logs `pacedMs` 5000.
  - Live, 25 Nov 2026 (sandbox: Nextcloud 34.0.6, MariaDB, OpenRegister main, `memcache.distributed` Redis, `php -S` with 8 worker processes): a source paced at 1 call per 30 s behind a gateway endpoint, six concurrent calls served by at least five different worker processes: one went upstream and five answered 429; the source's call log holds 1 sent call and 5 entries with status 429. The budget is one budget across real processes on a real cache backend.

### Task 2: Live and background wait limits
- **spec_ref**: `openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-a-live-call-waits-briefly-background-work-waits-for-the-window-req-rlpc-002`
- **files**: `lib/Service/CallService.php`, `lib/Service/EndpointService.php`, `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a live gateway call that would wait 5 seconds WHEN the live limit is 2 seconds THEN it answers 429 with Retry-After and nothing is sent upstream
  - GIVEN a synchronization that hits the background limit WHEN it stops THEN its next run resumes from its cursor
- [x] Implement
  - `EndpointService` proxies with `pacing: live`, as does the interactive source test (`persistLog: false`); everything else is background. A live call waits at most `pace.maxLiveWaitMs` (2,000), background work `pace.maxBackgroundWaitSeconds` (900). A longer wait is not sent and not reserved: CallService records a 429 call log with `retryAfter`, and the endpoint answers 429 with `Retry-After`. For a synchronization that 429 ends the fetch as a rate-limited run already does; a paced source now keeps its page cursor like a rate-limited one (`SynchronizationService::resumesFromPageCursor()`), so the next run resumes where it stopped.
- [x] Test (PHPUnit; Newman for the live 429)
  - Done: `CallServicePacingTest` (a live call five seconds from its turn is refused with `retryAfter` 5 and not sent; the interactive test is live; `pacing` never reaches the request), `tests/Unit/Service/EndpointServicePacingTest.php` (429 with `Retry-After: 5`; a source's own 429 passes unchanged), and the `paced-source-page` case in `SynchronizationGetAllObjectsFromApiTest` (resumes from the stored page, like a rate-limited source).
  - Newman: `tests/postman/integriq-source-pacing.postman_collection.json` (source paced at 1 call per 10 s, live limit 2 s, a gateway endpoint in front): the second call answers 429 with `Retry-After` 1 to 10 in under 2 s, the call log holds one 429 entry with `retryAfter` and one sent call. Live 25 Nov 2026 on the sandbox above: 7 requests, 12 assertions, 0 failures, exit 0, two consecutive runs. Without a distributed memcache the budget is per process, so the pacing checks report skipped there, not passed.

### Task 3: The source page
- **spec_ref**: `openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001`
- **files**: the source detail page, `lib/Settings/integriq_register.json` (aggregation), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a paced source WHEN the administrator opens it THEN the pace, the remaining budget and the last day's waiting time are shown
- [x] Implement (SourceDetail widget `src-kpis-pacing`: the announced budget left and the time waited for a paced turn over the last day, from the `pacedMsPerSource` aggregation on `call_log`; the configured pace shows as a source field in the Connection widget. Checked by `tests/vitest/sourcePacingManifest.spec.js`)
- [x] Test (Playwright)
  - `tests/e2e/source-pacing.spec.ts`: a source seeded with a pace and an announced budget of 37, and one call log entry that waited 1,234 ms; its page shows the Pacing widget with "37 calls in this window" and "1,234 ms for a paced turn, last day", and the Connection widget shows the pace's three numbers. Live 25 Nov 2026: 1 passed, two runs. The live run found the configured pace was NOT shown: the data widget leaves out object properties, so `src-data` now names `pace.maxCalls`, `pace.perSeconds` and `pace.burst` as dotted field overrides with labels (en and nl in `l10n/*.json`).

## Verification
- [x] `openspec validate sources-outbound-rate-limit-pacing --type change --strict` passes (openspec 2.2.0, 25 Nov 2026)
- [x] Existing rate limit and retry tests pass unchanged
  - Checked on development 60f97d46: the merge of this change (#3596) adds tests and edits no existing rate-limit or retry test (`git diff --stat b6a13df83^1 b6a13df83 -- tests`); `phpunit --filter '/(RateLimit|Retry|RetryPolicy|Backoff)/'` passes, 146 tests.
- [x] PHPUnit, Newman and Playwright run, exit codes read
  - 25 Nov 2026, sandbox Nextcloud 34.0.6 + MariaDB + Redis, OpenRegister main: PHPUnit `--filter '/(SourcePacer|CallServicePacing|EndpointServicePacing|SynchronizationGetAllObjectsFromApi)/'` 22 tests, exit 0; Newman `integriq-source-pacing` 7 requests, 12 assertions, exit 0; Playwright `source-pacing.spec.ts` 1 passed, exit 0.
