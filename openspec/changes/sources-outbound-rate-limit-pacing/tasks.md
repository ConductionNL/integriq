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
- [ ] Test (PHPUnit with a fake clock and an in-memory cache; one parallel test with two processes sharing the bucket)
  - Done: `tests/Unit/Service/Call/SourcePacerTest.php` (fake clock, in-memory memcache): 30 calls at 10/s start 0.1 s apart and end at 2.9 s, none refused; 5 announced calls in 10 s spaced 2 to 2.9 s, all before the reset; the larger gap wins; burst; nothing remaining waits for the reset; two pacers on one cache share one budget; a lost swap reads again; per-process without a distributed cache; unpaced sources never wait. `tests/Unit/Service/CallServicePacingTest.php`: the second background call waits 5 s and logs `pacedMs` 5000.
  - Owed: a test with two real processes sharing a live cache backend (the shared-cache case is covered with two pacers on one in-memory cache).

### Task 2: Live and background wait limits
- **spec_ref**: `openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-a-live-call-waits-briefly-background-work-waits-for-the-window-req-rlpc-002`
- **files**: `lib/Service/CallService.php`, `lib/Service/EndpointService.php`, `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a live gateway call that would wait 5 seconds WHEN the live limit is 2 seconds THEN it answers 429 with Retry-After and nothing is sent upstream
  - GIVEN a synchronization that hits the background limit WHEN it stops THEN its next run resumes from its cursor
- [x] Implement
  - `EndpointService` proxies with `pacing: live`, as does the interactive source test (`persistLog: false`); everything else is background. A live call waits at most `pace.maxLiveWaitMs` (2,000), background work `pace.maxBackgroundWaitSeconds` (900). A longer wait is not sent and not reserved: CallService records a 429 call log with `retryAfter`, and the endpoint answers 429 with `Retry-After`. For a synchronization that 429 ends the fetch as a rate-limited run already does; a paced source now keeps its page cursor like a rate-limited one (`SynchronizationService::resumesFromPageCursor()`), so the next run resumes where it stopped.
- [ ] Test (PHPUnit; Newman for the live 429)
  - Done: `CallServicePacingTest` (a live call five seconds from its turn is refused with `retryAfter` 5 and not sent; the interactive test is live; `pacing` never reaches the request), `tests/Unit/Service/EndpointServicePacingTest.php` (429 with `Retry-After: 5`; a source's own 429 passes unchanged), and the `paced-source-page` case in `SynchronizationGetAllObjectsFromApiTest` (resumes from the stored page, like a rate-limited source).
  - Owed: the Newman request for the live 429 against a live instance.

### Task 3: The source page
- **spec_ref**: `openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001`
- **files**: the source detail page, `lib/Settings/integriq_register.json` (aggregation), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a paced source WHEN the administrator opens it THEN the pace, the remaining budget and the last day's waiting time are shown
- [x] Implement (SourceDetail widget `src-kpis-pacing`: the announced budget left and the time waited for a paced turn over the last day, from the `pacedMsPerSource` aggregation on `call_log`; the configured pace shows as a source field in the Connection widget. Checked by `tests/vitest/sourcePacingManifest.spec.js`)
- [ ] Test (Playwright)

## Verification
- [ ] `openspec validate sources-outbound-rate-limit-pacing --type change --strict` passes
- [ ] Existing rate limit and retry tests pass unchanged
- [ ] PHPUnit, Newman and Playwright run, exit codes read
