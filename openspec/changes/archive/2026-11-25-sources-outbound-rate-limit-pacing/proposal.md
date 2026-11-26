---
kind: code
depends_on: []
---

# Proposal: sources-outbound-rate-limit-pacing

## Summary

When a source says it allows a hundred calls a minute, integriq spends them as fast as it can and then refuses its own next call with a 429 until the window resets. A nightly synchronization of five thousand records against such a source fails halfway, every night. This change paces calls: integriq spreads them over the window the source announces, or over a pace the administrator sets, so they arrive spaced out instead of being refused.

## Why

Row `integriq:src-ratelimit-out` (rated no, built built), sources area (the core area), decided `build` in the OpenSpec pass of 2026-09-27. One competitor rates yes, four rate partial; the core area rule carries it.

- APISIX 3.18.0 `apisix/plugins/limit-req.lua:46` "burst plus :63 nodelay (default false) delays excess requests in a leaky bucket instead of refusing them, so calls reach the upstream spaced out".

The matrix note: "The engine tracks a source's rate limit and reacts to it, but the described outcome (stay under the limit so calls are spaced out rather than refused) is the opposite of the implemented behaviour."

## What integriq already has

- `CallService::sourceRateLimit()` (`lib/Service/CallService.php:3340-3406`) reads `X-RateLimit-*`, `RateLimit-*` and `Retry-After` into `rateLimitRemaining` and `rateLimitReset` on the source.
- `guardCallPreconditions()` refuses the next call with a synthetic 429 once `rateLimitRemaining` is 0 (`:1938-1946`), until `checkAndResetRateLimit()` clears it after the reset (`:797-822`).
- A retry policy with fixed or exponential backoff (`:2047-2079`) retries failures, including 429 (`retryableStatusCodes`, `:101`). It does not pace.

## What this change builds

1. A pace on a source: calls per second or per minute, held in a shared token bucket so every worker and job counts against the same budget.
2. Adaptive pacing from the headers the source already sends: the remaining calls are spread evenly over the time left in the window.
3. Waiting instead of refusing, bounded: background work (synchronizations, jobs, flows) waits as long as the window needs; a live gateway call waits at most a short configured time, then answers 429 with `Retry-After`.
4. The call log and the source page show time spent waiting and the current budget.

## Out of scope

- Inbound consumer rate limits. They exist (`consumer-management`) and are unchanged.
- Queuing calls across restarts. A paced call waits in the running process.
