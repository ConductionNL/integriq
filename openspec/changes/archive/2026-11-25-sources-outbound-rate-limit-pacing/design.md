# Design: sources-outbound-rate-limit-pacing

Kind: code. Size S to M. `CallService`'s precondition guard, a new `SourcePacer`, source fields, and the source page.

## Context at development 92f282bc

- `CallService::sourceRateLimit()` (`lib/Service/CallService.php:3340-3406`) writes `rateLimitRemaining` and `rateLimitReset` on the source from response headers.
- `guardCallPreconditions()` phase 6 (`:1938-1946`) returns a synthetic 429 call log when `rateLimitRemaining <= 0`.
- `checkAndResetRateLimit()` (`:797-822`) clears the fields once the reset has passed.
- Backoff sleeps for retries (`:2047-2079`), and 429 is retryable (`:101`).

## D1. A pacer in front of the guard

`SourcePacer::acquire(source, context)` runs just before phase 6. It returns a wait in milliseconds, or refuses. `CallService` sleeps for the wait, then calls. Phase 6 stays as the last safety net: if a source still reports zero remaining, the call is refused as today.

## D2. Two sources of pace

- Configured: `source.pace` `{ maxCalls, perSeconds, burst }`. A token bucket per source in Nextcloud's distributed memory cache (`ICacheFactory::createDistributed('integriq.source.pace')`), using atomic increment so parallel workers share one budget. Without a distributed cache the bucket is per process, and the source page says so.
- Adaptive: when the source has announced `rateLimitRemaining` and `rateLimitReset`, the minimum gap between calls is `(reset - now) / remaining`. The larger of the two gaps wins.

## D3. How long a caller may wait

The call context says whether the caller is live or background. `EndpointService` calls are live: they wait at most `source.pace.maxLiveWaitMs` (default 2,000). Beyond that they answer 429 with a `Retry-After` computed from the bucket, and the call is not sent. Synchronizations, jobs and flows are background: they wait as long as the window requires, up to `maxBackgroundWaitSeconds` (default 900) per call, after which the run pauses and resumes on its next schedule with its cursor, as the synchronization engine already does for interrupted runs.

## D4. Visible waiting

The call log gains `pacedMs`. The source page shows the pace, the current remaining budget and reset time, and the waiting time of the last day, from `x-openregister-aggregations` on `call_log`.

## Declarative versus imperative

| behaviour | path | why |
|---|---|---|
| waiting time per source on the page | declarative: `x-openregister-aggregations` on `call_log` summing `pacedMs` | a sum |
| pacing | imperative, in `SourcePacer` | request-time timing |

## Seed data

The seeded KvK source gets `pace` `{ maxCalls: 100, perSeconds: 60 }` as an example; other seeded sources stay unpaced.

## Risks

- Sleeping ties up a PHP worker. Mitigation: the short live wait; long waits happen only in background work.
- Clock skew against a source's reset time. Mitigation: `Retry-After` in seconds is preferred over an absolute reset when both are sent.
