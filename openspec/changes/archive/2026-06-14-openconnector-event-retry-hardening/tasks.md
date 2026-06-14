# Tasks — event-delivery retry hardening

## 1. Schema

- [x] 1.1 Add `attempts` (array of `{at, statusCode, error}`) to the
  `event_message` schema in `lib/Settings/openconnector_register.json`;
  tighten the `status` description to the enforced enum
  `pending | delivered | failed | abandoned`
- [x] 1.2 Validate the register JSON still parses
  (`python3 -c "import json;json.load(open('lib/Settings/openconnector_register.json'))"`)

## 2. EventService — deliverMessage failure path

- [x] 2.1 Increment `retryCount`, write `lastAttempt`, append `attempts[]`
  entry in one save on every failure (non-2xx and exception paths)
- [x] 2.2 Compute `nextAttempt = lastAttempt + min(60s × 4^(retryCount−1), 6h)`
  as named constants (`RETRY_BASE_SECONDS`, `RETRY_FACTOR`, `RETRY_CAP_SECONDS`)
- [x] 2.3 Parse `Retry-After` (seconds + HTTP-date forms) and take
  `max(backoff, retryAfter)` for `nextAttempt`
- [x] 2.4 Transition to `status='abandoned'` + `nextAttempt=null` when the
  incremented `retryCount >= maxRetries`
- [x] 2.5 Success path: also persist `nextAttempt=null` and append the success
  `attempts[]` entry

## 3. EventService — processRetries

- [x] 3.1 Rework selection to `status IN ('pending','failed')` AND
  `retryCount < $maxRetries` AND (`nextAttempt` null OR `<= now`). DB filter
  narrows by status; the authoritative re-check (status, cap, due) runs in PHP
  so the sweep is correct regardless of how OR interprets array filters.
- [x] 3.2 Confirm `delivered`/`abandoned` are never selected (filter + unit test)

## 4. Background job

- [x] 4.1 Add `lib/Cron/EventRetryJob.php` (TimedJob, 300s) calling
  `processRetries()` with catch-and-log containment
- [x] 4.2 Register the job via `<background-jobs>` in `appinfo/info.xml`
  (NOT `IRegistrationContext` — no `registerJob()` exists there). **This closes
  the re-eval "event retries never ran (0 callers)" latent bug.**

## 5. Tests

- [x] 5.1 PHPUnit: failure increments retryCount + schedules backoff; Retry-After
  override; terminal abandoned transition; sweep selection matrix
  (pending/failed/delivered/abandoned × due/not-due × under/over cap);
  attempts[] ordering (EventServiceTest + EventRetryJobTest, 14 tests)
- [~] 5.2 Newman: deliver-fail-retry-abandon round trip against a failing sink
  fixture — deferred: requires a live failing-sink fixture + cron tick on a
  running instance; the round trip is covered deterministically at the unit
  level (success → fail/backoff → abandon → sweep-skip).
- [~] 5.3 Verify the `delivery-retries-exhausted` notification threshold rule
  fires once `retryCount` reaches 5 — deferred to a live instance check; the
  threshold rule already exists in the register JSON and this change is what
  makes `retryCount` actually move toward it.

## Acceptance criteria

- A message failing against a dead sink is attempted exactly `maxRetries` times
  with growing intervals, then sits in `abandoned` with `nextAttempt=null` and
  is never re-attempted by the sweep.
- A transiently-failing sink results in eventual `delivered` with a complete
  `attempts[]` history.
- `processRetries` runs from cron without any manual call site.
- No existing `events-cloudevents` scenario regresses except the two replaced
  by this delta (5xx-marks-failed and pending-only sweep cap).
