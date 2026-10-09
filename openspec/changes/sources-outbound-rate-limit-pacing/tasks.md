# Tasks: sources-outbound-rate-limit-pacing

Kind: code. Size S to M. Row `integriq:src-ratelimit-out`.

## Implementation tasks

### Task 1: The pacer and the shared bucket
- **spec_ref**: `openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001`
- **files**: `lib/Service/Call/SourcePacer.php`, `lib/Service/CallService.php`, `lib/Settings/integriq_register.json` (source `pace`, call_log `pacedMs`)
- **acceptance_criteria**:
  - GIVEN a pace of 10 calls per second WHEN 30 background calls are made THEN they take about three seconds and none is refused
  - GIVEN a source that announced 5 remaining calls and a reset in 10 seconds WHEN 5 calls are made THEN they are spaced about 2 seconds apart
- [ ] Implement
- [ ] Test (PHPUnit with a fake clock and an in-memory cache; one parallel test with two processes sharing the bucket)

### Task 2: Live and background wait limits
- **spec_ref**: `openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-a-live-call-waits-briefly-background-work-waits-for-the-window-req-rlpc-002`
- **files**: `lib/Service/CallService.php`, `lib/Service/EndpointService.php`, `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a live gateway call that would wait 5 seconds WHEN the live limit is 2 seconds THEN it answers 429 with Retry-After and nothing is sent upstream
  - GIVEN a synchronization that hits the background limit WHEN it stops THEN its next run resumes from its cursor
- [ ] Implement
- [ ] Test (PHPUnit; Newman for the live 429)

### Task 3: The source page
- **spec_ref**: `openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001`
- **files**: the source detail page, `lib/Settings/integriq_register.json` (aggregation), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a paced source WHEN the administrator opens it THEN the pace, the remaining budget and the last day's waiting time are shown
- [ ] Implement
- [ ] Test (Playwright)

## Verification
- [ ] `openspec validate sources-outbound-rate-limit-pacing --type change --strict` passes
- [ ] Existing rate limit and retry tests pass unchanged
- [ ] PHPUnit, Newman and Playwright run, exit codes read
