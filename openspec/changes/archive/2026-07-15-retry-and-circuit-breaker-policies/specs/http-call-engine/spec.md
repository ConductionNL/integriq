# http-call-engine Specification Delta

## ADDED Requirements

### Requirement: Configurable retry policy for outbound dispatch (REQ-007)

`CallService::call(...)` MUST resolve an effective `RetryPolicy` for every
dispatch by merging, in order (later layers override earlier ones on a
per-key basis): a built-in default of `{maxAttempts: 1, backoffStrategy:
"fixed", baseDelayMs: 500, maxDelayMs: 30000, jitter: false,
retryableStatusCodes: [429, 502, 503, 504], retryOnTimeout: false}`; the
dispatching Source's `retryPolicy` object field; and, when present, the
caller-supplied `$config['retryPolicy']` override (populated by
`SynchronizationService` from `Synchronization.retryPolicyOverride` when a
call is made in a synchronization context). When the effective
`maxAttempts` is `1` (the default, and the value for every Source that has
not configured a `retryPolicy`), dispatch behavior MUST be identical to the
pre-existing single-attempt behavior — no new delay, no new CallLog shape
change.

When `maxAttempts > 1`, `CallService` MUST retry a dispatch whose outcome is
either an HTTP response whose status code is in `retryableStatusCodes`, or a
transport-level exception when `retryOnTimeout === true`, up to
`maxAttempts` total attempts, sleeping between attempts for a delay computed
from `backoffStrategy`:
- `fixed`: `delayMs = baseDelayMs`
- `exponential`: `delayMs = min(baseDelayMs * 2^(attempt-1), maxDelayMs)`
When `jitter === true`, the computed delay MUST be adjusted by ±10% using a
uniform random offset (mirroring `PdokConnector::sleepBackoff()`). Only the
CallLog for the **final** attempt MUST be persisted; intermediate retried
attempts MUST NOT each produce a separate CallLog row.

#### Scenario: default policy preserves single-attempt behavior

- **GIVEN** a Source with no `retryPolicy` configured AND an upstream that
  returns `503` on every call
- **WHEN** `call(...)` runs
- **THEN** exactly one HTTP request SHALL be dispatched
- **AND** the persisted `call_log.statusCode` SHALL be `503`

#### Scenario: exponential backoff retries a 503 up to maxAttempts

- **GIVEN** a Source with `retryPolicy = {maxAttempts: 3, backoffStrategy:
  "exponential", baseDelayMs: 100, maxDelayMs: 1000, jitter: false,
  retryableStatusCodes: [503]}` AND an upstream returning `503` on the first
  two calls and `200` on the third
- **WHEN** `call(...)` runs
- **THEN** three HTTP requests SHALL be dispatched, with delays of ~100ms
  then ~200ms between them
- **AND** the persisted `call_log.statusCode` SHALL be `200`

#### Scenario: a non-retryable status code returns immediately

- **GIVEN** a Source with `retryPolicy = {maxAttempts: 3, retryableStatusCodes:
  [429, 503]}` AND an upstream returning `404`
- **WHEN** `call(...)` runs
- **THEN** exactly one HTTP request SHALL be dispatched (404 is not in
  `retryableStatusCodes`)
- **AND** the persisted `call_log.statusCode` SHALL be `404`

#### Scenario: synchronization-level override widens the retryable set

- **GIVEN** a Source with `retryPolicy = {maxAttempts: 1}` AND a
  Synchronization with `retryPolicyOverride = {maxAttempts: 2,
  retryableStatusCodes: [500]}`
- **WHEN** the synchronization dispatches a call that returns `500` then `200`
- **THEN** two HTTP requests SHALL be dispatched for that call
- **AND** a direct (non-synchronization) call against the same Source SHALL
  still use `maxAttempts: 1`

### Requirement: Per-source circuit breaker generalized into CallService (REQ-008)

`CallService` MUST maintain a per-Source circuit breaker persisted on the
`source` OR object via `circuitBreakerState` (`closed|open`),
`circuitBreakerFailureCount`, `circuitBreakerOpenedAt`, and
`circuitBreakerLastProbeAt`, using the configurable
`circuitBreakerThreshold` (default 5) and `circuitBreakerCooldownSeconds`
(default 30) fields on the same Source. Before every dispatch, the engine
MUST evaluate the breaker: when `circuitBreakerState === 'open'` and fewer
than `circuitBreakerCooldownSeconds` have elapsed since
`circuitBreakerOpenedAt`, the call MUST be short-circuited with a synthetic
`call_log` (`statusCode: 503`, `statusMessage: "Circuit breaker is open for
this source"`) and no HTTP request MUST be dispatched. When
`circuitBreakerCooldownSeconds` have elapsed, the breaker is treated as
half-open and exactly the next dispatch is allowed through as a probe (not
persisted as a distinct `half-open` state value). A retryable failure (per
REQ-007) MUST increment `circuitBreakerFailureCount`; when the count reaches
`circuitBreakerThreshold`, the engine MUST set `circuitBreakerState = 'open'`
and `circuitBreakerOpenedAt = now()`. Any successful (non-retryable, non-4xx
excluding configured retryable codes) response MUST reset
`circuitBreakerState = 'closed'` and `circuitBreakerFailureCount = 0`.

#### Scenario: five consecutive failures open the breaker

- **GIVEN** a Source with default breaker thresholds and
  `retryPolicy.retryableStatusCodes` including `503`
- **WHEN** five consecutive calls each return `503`
- **THEN** after the fifth failure `circuitBreakerState` SHALL be `open` AND
  `circuitBreakerOpenedAt` SHALL be set

#### Scenario: an open breaker short-circuits without dispatching

- **GIVEN** a Source with `circuitBreakerState = 'open'` and
  `circuitBreakerOpenedAt` 10 seconds ago (cooldown 30s)
- **WHEN** `call(...)` runs
- **THEN** no HTTP request SHALL be dispatched
- **AND** a `call_log` SHALL be persisted with `statusCode = 503` and message
  `"Circuit breaker is open for this source"`

#### Scenario: a successful half-open probe closes the breaker

- **GIVEN** a Source with `circuitBreakerState = 'open'` and
  `circuitBreakerOpenedAt` 35 seconds ago (cooldown 30s)
- **WHEN** `call(...)` runs AND the upstream returns `200`
- **THEN** exactly one HTTP request SHALL be dispatched (the probe)
- **AND** `circuitBreakerState` SHALL be reset to `closed` with
  `circuitBreakerFailureCount = 0`

#### Scenario: a failed half-open probe reopens the breaker immediately

- **GIVEN** a Source with `circuitBreakerState = 'open'` and
  `circuitBreakerOpenedAt` 35 seconds ago (cooldown 30s)
- **WHEN** `call(...)` runs AND the upstream again returns `503`
- **THEN** `circuitBreakerState` SHALL remain/return to `open` AND
  `circuitBreakerOpenedAt` SHALL be reset to the probe's timestamp

#### Notes

- The half-open probe guard (`circuitBreakerLastProbeAt`) is best-effort:
  under concurrent requests during the cooldown window, more than one
  request may treat itself as "the" probe. This is an accepted limitation
  (see design.md Decision 2/Trade-offs), not a distributed-lock guarantee.

### Requirement: Manual circuit breaker trip and reset (REQ-009)

The engine MUST expose admin-only, CSRF-protected endpoints
`POST /api/sources/{id}/circuit-breaker/trip` and
`POST /api/sources/{id}/circuit-breaker/reset` on `SourcesController`. Trip
MUST set `circuitBreakerState = 'open'`, `circuitBreakerOpenedAt = now()`,
and `circuitBreakerFailureCount = circuitBreakerThreshold` regardless of
prior state. Reset MUST set `circuitBreakerState = 'closed'`,
`circuitBreakerFailureCount = 0`, `circuitBreakerOpenedAt = null`. Both
endpoints MUST return `404` for an unknown source id and MUST NOT carry
`@NoAdminRequired` or `@NoCSRFRequired`.

#### Scenario: an admin manually trips the breaker for a misbehaving upstream

- **GIVEN** a healthy Source (`circuitBreakerState = 'closed'`)
- **WHEN** an admin calls `POST .../sources/{id}/circuit-breaker/trip`
- **THEN** the Source's `circuitBreakerState` SHALL become `open`
- **AND** subsequent calls to that Source SHALL short-circuit per REQ-008
  until the cooldown elapses or an admin resets it

#### Scenario: an admin manually resets an open breaker

- **GIVEN** a Source with `circuitBreakerState = 'open'`
- **WHEN** an admin calls `POST .../sources/{id}/circuit-breaker/reset`
- **THEN** the Source's `circuitBreakerState` SHALL become `closed` with
  `circuitBreakerFailureCount = 0`
- **AND** the next call SHALL dispatch normally (no short-circuit)

#### Scenario: a non-admin is rejected

- **GIVEN** an authenticated non-admin user
- **WHEN** they call either circuit-breaker endpoint
- **THEN** the request SHALL be rejected by NC's admin requirement
