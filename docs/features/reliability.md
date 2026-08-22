<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# Reliability: Retry Policy, Circuit Breaker & Sync Dead Letters

Integriq ships operator-facing reliability tooling for outbound dispatch
and per-item synchronization: a configurable **retry policy**, a per-Source
**circuit breaker**, and a **sync-item dead-letter** queue with manual replay.
All three are additive and default to today's behaviour — an existing Source
or Synchronization sees no change until an operator opts in.

## Retry Policy

Every outbound call resolved by `CallService` honours an effective
`RetryPolicy`, merged per-key in this order (later wins):

1. Built-in default: `{ maxAttempts: 1, backoffStrategy: "fixed", baseDelayMs:
   500, maxDelayMs: 30000, jitter: false, retryableStatusCodes: [429, 502, 503,
   504], retryOnTimeout: false }`.
2. The dispatching Source's `retryPolicy` field.
3. A Synchronization's `retryPolicyOverride` (copied into the call config for
   calls made in that synchronization's context only).

`maxAttempts: 1` (the default) reproduces the pre-existing single-attempt
dispatch exactly — retries are strictly opt-in.

### Fields

| Field | Default | Meaning |
|-------|---------|---------|
| `maxAttempts` | `1` | Total attempts (1 = no retry). |
| `backoffStrategy` | `fixed` | `fixed` or `exponential`. |
| `baseDelayMs` | `500` | Base delay between attempts. |
| `maxDelayMs` | `30000` | Cap for exponential backoff. |
| `jitter` | `false` | Adds ±10% uniform jitter to each delay. |
| `retryableStatusCodes` | `[429, 502, 503, 504]` | Response codes that trigger a retry. |
| `retryOnTimeout` | `false` | Retry on a transport-level exception too. |

Backoff:

- `fixed`: `delayMs = baseDelayMs`
- `exponential`: `delayMs = min(baseDelayMs × 2^(attempt-1), maxDelayMs)`

Only the **final** attempt's CallLog is persisted; intermediate retried
attempts do not each produce a CallLog row.

> **Idempotency:** the default `retryableStatusCodes` set is conservative and
> idempotency-safe. Opting a POST-heavy Source into retries on other codes is
> an operator decision — upstreams that do not de-duplicate may see
> double-submission.

### Example (Source)

```json
{
  "retryPolicy": {
    "maxAttempts": 3,
    "backoffStrategy": "exponential",
    "baseDelayMs": 200,
    "maxDelayMs": 5000,
    "jitter": true,
    "retryableStatusCodes": [429, 503],
    "retryOnTimeout": false
  }
}
```

### Example (Synchronization override)

```json
{
  "retryPolicyOverride": { "maxAttempts": 2, "retryableStatusCodes": [500] }
}
```

## Circuit Breaker

Each Source carries a per-Source circuit breaker persisted on the Source OR
object (so it survives across PHP-FPM workers and cron processes, unlike an
in-memory cache). State machine:

- **closed** — normal dispatch. Each retryable failure increments
  `circuitBreakerFailureCount`.
- **open** — reached after `circuitBreakerThreshold` (default `5`) consecutive
  retryable failures. Calls short-circuit with a synthetic `503` CallLog
  (`"Circuit breaker is open for this source"`) — no HTTP request is made — for
  `circuitBreakerCooldownSeconds` (default `30`).
- **half-open** (derived, never persisted) — once the cooldown elapses, exactly
  the next dispatch is allowed through as a probe. Success closes the breaker
  and resets the failure count; failure reopens it with a fresh timestamp.

### Source fields

| Field | Default | Meaning |
|-------|---------|---------|
| `circuitBreakerState` | `closed` | `closed` or `open`. |
| `circuitBreakerFailureCount` | `0` | Consecutive retryable failures. |
| `circuitBreakerOpenedAt` | `null` | Unix timestamp of the last open. |
| `circuitBreakerLastProbeAt` | `null` | Unix timestamp of the last half-open probe. |
| `circuitBreakerThreshold` | `5` | Failures needed to open. |
| `circuitBreakerCooldownSeconds` | `30` | Open duration before a probe. |

### Source detail UI

The Source detail page shows a **circuit-breaker badge** at the top: the state
(open / closed), the failure count, and — when open — a live cooldown
countdown, plus a **Reset breaker** button.

### Manual trip / reset (admin-only)

Two admin-only, CSRF-protected endpoints let an operator force the breaker:

- `POST /api/sources/{id}/circuit-breaker/trip` — set state `open` immediately
  (regardless of failure count).
- `POST /api/sources/{id}/circuit-breaker/reset` — set state `closed`,
  `circuitBreakerFailureCount = 0`.

Both return `404` for an unknown Source id.

### Prometheus

`GET /api/metrics` exposes `integriq_circuit_breaker_state{source="<name>"}`
— `1` when open, `0` when closed or never evaluated.

> Renamed from `openconnector_circuit_breaker_state` with the app id: the metric
> prefix is derived from the app id by the AppHost observability engine. Update
> existing Grafana dashboards and alert rules to the new name.

## Sync-Item Dead Letters

During an extern-to-intern sync pass, a single object that throws during
per-item processing (a mapping or write error) no longer aborts the whole
pass. The failure is isolated: the item is captured as a
`sync_item_dead_letter` entry, `result.objects.invalid` is incremented, and the
remaining objects are still processed. The pass completes and persists its
`synchronization_log`.

Dead-lettered items are **not** auto-retried — unlike event delivery (transient
network failures), sync-item failures are usually deterministic (bad
config/data), so re-running them unattended would just fail identically.
Replay is a deliberate operator action.

### Operator workflow — Sync dead letters view

The Synchronizations section contains a **Sync dead letters** sub-view:

1. Open **Automation → Sync dead letters**. The listing defaults to `failed`
   entries and can be filtered by status and synchronization.
2. Click **Inspect** on a row to open its detail modal — it shows the raw
   source payload, the error, and the full attempt timeline.
3. After fixing the root cause (e.g. a mapping bug), click **Replay**. The item
   is re-processed synchronously: on success it moves to `replayed`; on a
   renewed failure it stays `failed` with an appended attempt and an incremented
   `retryCount`.
4. To retire an item without replaying, click **Discard** (terminal, audited,
   never hard-deleted).
5. Bulk **Replay** / **Discard** apply to a selected set (capped at 100 ids)
   and report per-id outcomes; a partial failure never aborts the batch.

### Endpoints (admin-only, CSRF-protected)

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/sync-dead-letter` | List (default `failed`; `synchronizationId`, `from`/`to`, `status`, `limit`, `offset`). |
| `GET` | `/api/sync-dead-letter/{id}` | Full detail + resolved synchronization context. |
| `POST` | `/api/sync-dead-letter/{id}/replay` | Replay one item. |
| `POST` | `/api/sync-dead-letter/{id}/discard` | Discard one item. |
| `POST` | `/api/sync-dead-letter/replay` | Bulk replay (`{ids: string[]}`, ≤100). |
| `POST` | `/api/sync-dead-letter/discard` | Bulk discard (`{ids: string[]}`, ≤100). |

## Cron isolation (jobs)

`JobService::run()` isolates each job: a throwing job records an ERROR job log,
advances its own `nextRun` by its interval (so it does not stay permanently
"due"), and never blocks the remaining due jobs in the same cron sweep. A
user-scoped job restores the prior session user in a `finally` block, so its
identity never bleeds into the next job in the pass.

## Implementation

- `lib/Service/CallService.php` — retry loop + circuit breaker.
- `lib/Service/SynchronizationService.php` — per-item isolation + dead-letter capture.
- `lib/Service/SyncItemDeadLetterService.php` — capture / replay / discard.
- `lib/Controller/SyncDeadLetterController.php` — dead-letter REST surface.
- `lib/Controller/SourcesController.php` — circuit-breaker trip / reset.
- `lib/Observability/IntegriqMetricsProvider.php` — breaker-state gauge.
- `src/components/CircuitBreakerBadge.vue`, `src/views/Synchronization/SyncDeadLetterPage.vue`, `src/modals/Synchronization/SyncDeadLetterDetailModal.vue` — UI.
