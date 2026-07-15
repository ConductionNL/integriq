# Context Brief: retry-and-circuit-breaker-policies
Source: Specter deep-research 2026-07-14 (insights #1248). VERIFY every code claim against HEAD before writing artifacts.

## Problem
No configurable retry policy or circuit breaker for outbound calls and synchronizations; one throwing job aborts the whole cron sweep. Competitor table stakes (n8n error triggers + per-node retries; Camel/NiFi DLQ; Workato enterprise error handling).
Issues: #863 (ipaas-reliability spec request: retry/error handling), #1005 (HIGH: one throwing job aborts entire cron pass and never advances schedule), #1006 (HIGH: cross-job user-identity bleed — session identity not restored between jobs).

## Current state (verify in HEAD)
- lib/Service/CallService.php: outbound rate-limit detection with backoff creates synthetic 409/429 CallLogs; no generic retry policy (max attempts / exponential backoff / retryable status codes).
- Circuit breaker exists ONLY inside the PDOK adapter (verify lib/Adapters or lib/Sources PDOK classes) — not generalized.
- Events have EventRetryJob + dead-letter replay (EventDeliveries) — data-plane sync failures have NO DLQ.
- lib/Cron/JobTask.php runs scheduled jobs; verify iteration/failure semantics for #1005/#1006.

## In scope
1. RetryPolicy config object on Source (and overridable per Synchronization): maxAttempts, backoff strategy (fixed/exponential), jitter, retryable HTTP codes, retry-on-timeout; enforced centrally in CallService.
2. Circuit breaker generalized into CallService: failure-count threshold, open-state cooldown, half-open probe; per-source state; surfaced in source detail UI + Prometheus metric; manual trip/reset endpoint (user story: "Manually trigger circuit breaker for a failing upstream").
3. Sync-item dead-letter: failed item transformations/writes captured (reuse dead-letter/replay machinery pattern from events) with replay action.
4. Cron isolation: per-job try/catch so one failing job cannot abort the sweep; schedule always advances; user session/identity reset between jobs (#1006).
5. Tests: unit for policy math + breaker state machine; integration for cron isolation.
## Out of scope
- Full execution-trace observability (deferred).
- Inbound rate limiting (exists: lib/Service/RateLimit/InboundRateLimitService).

## Constraints
- Policies stored on existing Source/Synchronization OR objects (schema update via register descriptor openconnector-register-schema).
- Specs to modify via deltas: http-call-engine, synchronization-engine, job-scheduling, dead-letter-replay (extend), prometheus-metrics (new breaker metric).
