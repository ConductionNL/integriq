# Design — event-delivery retry hardening

## Context

The `event_message` schema (lib/Settings/openconnector_register.json) already
declares the full intended lifecycle:

- `status`: "Delivery state (pending, delivered, failed, abandoned)"
- `retryCount`: integer
- `lastAttempt`: date-time
- `nextAttempt`: date-time, "Scheduled time of next retry (null when terminal
  state reached)"

`EventService` writes only `status`, `deliveredAt`, `deliveryResponse`/`error`.
This change is therefore **not** a data-model redesign — it makes the service
implement the contract the schema (and local ADR-013) already documents. No
migration of existing rows is needed: existing `pending`/`failed` rows have
`retryCount` 0/null and re-enter the corrected state machine naturally.

## State machine

```
            create (processEvent match)
                      │
                      ▼
                  ┌─────────┐   2xx                    ┌───────────┐
   sweep/push ───▶│ pending │──────────────────────────▶│ delivered │ (terminal)
                  └─────────┘                           └───────────┘
                      │ non-2xx / exception
                      ▼
                  ┌────────┐  retryCount < max: nextAttempt = backoff
        ┌────────▶│ failed │─────────────┐
        │         └────────┘             │ sweep at nextAttempt
        │             │                  └────────▶ (re-attempt → 2xx ⇒ delivered)
        │             │ retryCount >= max
        │             ▼
        │         ┌───────────┐
        └─────────│ abandoned │ (terminal; nextAttempt = null)
                  └───────────┘
```

- `delivered` and `abandoned` are terminal: the sweep never selects them.
- Replay (the `openconnector-dead-letter-replay` change) is the only way out of
  `abandoned`, and it works by resetting the message to `pending` — i.e. it
  re-enters this machine at the top rather than adding a fifth state.

## Decisions

### D1 — Backoff schedule: exponential with cap, derived not stored

`nextAttempt = lastAttempt + min(60s × 4^retryCount, 6h)` evaluated at failure
time, i.e. ~1m, 4m, 16m, ~1h, ~4.5h between the 5 default attempts — a sink
outage of minutes is absorbed cheaply, and a dead sink stops consuming
resources within a day. The *schedule* is a service constant, not per-
subscription configuration: per-subscription backoff tuning is speculative
(no consumer has asked) and would bloat the subscription schema. The cap and
base are named constants so a future delta can make them configurable without
re-speccing the machine.

**Alternative considered**: fixed-interval retry (sweep interval = retry
interval). Rejected — hammers a struggling sink and couples retry pacing to
the cron cadence.

### D2 — `Retry-After` wins when larger

When the sink answers 429/503 with a `Retry-After` header (seconds or
HTTP-date), `nextAttempt` is `max(backoff, retryAfter)`. The retrofit spec
flagged the header as ignored; respecting it is required for polite-client
behaviour against rate-limited sinks (and matches the source-side rate-limit
handling already specced in `http-call-engine`). The header never *shortens*
the backoff — that would let a sink force a tight retry loop.

### D3 — Sweep selects on `nextAttempt`, not on status alone

`processRetries()` selects messages where `status IN ('failed','pending')`
AND `retryCount < maxRetries` AND (`nextAttempt` is null OR `nextAttempt <=
now`). Including `pending` covers two real populations: pull-style messages
are excluded by `deliverMessage`'s own style guard (returns false early, no
state change), and push messages stranded `pending` by a crash between
persist and first delivery get picked up instead of leaking. Selecting on
`nextAttempt <= now` makes the sweep idempotent and safe at any cron cadence.

### D4 — Terminal transition happens in `deliverMessage`, not in the sweep

The increment-and-decide logic lives in the failure path of `deliverMessage`
so that *immediate* push delivery failures (from `processEvent`) follow
exactly the same accounting as sweep-driven retries. If the sweep did the
bookkeeping, the first (immediate) attempt would be free, making the real
attempt count `maxRetries + 1` and the semantics caller-dependent.

### D5 — `attempts[]` audit trail, capped

Each attempt appends `{at, statusCode|null, error|null}`. The array is
naturally bounded by `maxRetries + 1` entries, so no growth concern. This is
the inspection payload the dead-letter UI needs ("died with 4×503 then 1×
timeout" vs "DNS failure from attempt one"); `deliveryResponse`/`error` keep
only the *last* outcome, which is not enough to diagnose intermittent sinks.
Stored on the message itself (OR object) — no new schema, no new table, per
ADR-022.

### D6 — Cron via `info.xml` background-jobs + TimedJob

`lib/Cron/EventRetryJob.php` (`TimedJob`, interval 300s) calling
`EventService::processRetries()`. Registration via `<background-jobs>` in
`info.xml` — NOT `$context->registerJob()` (does not exist on
`IRegistrationContext`; this exact bug silently disabled jobs in docudesk,
procest and shillinq). Interval 5 minutes is deliberately shorter than the
first backoff step (1m is reachable on the *next* sweep tick) but cheap: the
selection query is indexed on status and returns nothing when the bus is
healthy.

### D7 — No `event_subscription` changes

Retry policy is delivery-side, uniform across subscriptions. The subscription
schema stays untouched; `openconnector-webhook-signing` (separate change) is
the one that extends `protocolSettings`.

## Reuse analysis

| Need | Existing surface | Strategy |
|---|---|---|
| Message persistence + querying | OR objects (`event_message` in the openconnector register) | Reused — all new fields already exist except `attempts` (additive) |
| Exhaustion alerting | `delivery-retries-exhausted` threshold rule (openconnector-notifications) | Reused unchanged — this change makes its trigger field actually move |
| Scheduled execution | NC TimedJob + info.xml background-jobs | Standard fleet pattern |
| Replay out of `abandoned` | `openconnector-dead-letter-replay` | Deliberately out of scope here; that change depends on this one |

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Existing `failed` rows suddenly start retrying after upgrade | They retry at most `maxRetries` times then go `abandoned` — bounded; release note |
| Concurrent sweep + immediate delivery double-attempt a message | Sweep cadence (5m) ≫ delivery timeout (30s); attempt accounting is idempotent per attempt; acceptable at-least-once semantics for an event bus |
| Backoff constants wrong for some deployment | Named constants; configurability deferred until demanded |
| `attempts[]` bloats messages | Bounded at `maxRetries + 1` entries of ~3 keys |
