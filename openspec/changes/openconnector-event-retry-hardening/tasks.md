# Tasks — event-delivery retry hardening

## 1. Schema

- [ ] 1.1 Add `attempts` (array of `{at, statusCode, error}`) to the
  `event_message` schema in `lib/Settings/openconnector_register.json`;
  tighten the `status` description to the enforced enum
  `pending | delivered | failed | abandoned`
- [ ] 1.2 Validate the register JSON still parses
  (`python3 -c "import json;json.load(open('lib/Settings/openconnector_register.json'))"`)

## 2. EventService — deliverMessage failure path

- [ ] 2.1 Increment `retryCount`, write `lastAttempt`, append `attempts[]`
  entry in one save on every failure (non-2xx and exception paths)
- [ ] 2.2 Compute `nextAttempt = lastAttempt + min(60s × 4^(retryCount−1), 6h)`
  as named constants (`RETRY_BASE_SECONDS`, `RETRY_FACTOR`, `RETRY_CAP_SECONDS`)
- [ ] 2.3 Parse `Retry-After` (seconds + HTTP-date forms) and take
  `max(backoff, retryAfter)` for `nextAttempt`
- [ ] 2.4 Transition to `status='abandoned'` + `nextAttempt=null` when the
  incremented `retryCount >= maxRetries`
- [ ] 2.5 Success path: also persist `nextAttempt=null` and append the success
  `attempts[]` entry

## 3. EventService — processRetries

- [ ] 3.1 Rework selection to `status IN ('pending','failed')` AND
  `retryCount < $maxRetries` AND (`nextAttempt` null OR `<= now`)
- [ ] 3.2 Confirm `delivered`/`abandoned` are never selected (filter + unit test)

## 4. Background job

- [ ] 4.1 Add `lib/Cron/EventRetryJob.php` (TimedJob, 300s) calling
  `processRetries()` with catch-and-log containment
- [ ] 4.2 Register the job via `<background-jobs>` in `appinfo/info.xml`
  (NOT `IRegistrationContext` — no `registerJob()` exists there)

## 5. Tests

- [ ] 5.1 PHPUnit: failure increments retryCount + schedules backoff; Retry-After
  override; terminal abandoned transition; sweep selection matrix
  (pending/failed/delivered/abandoned × due/not-due × under/over cap);
  attempts[] ordering
- [ ] 5.2 Newman: deliver-fail-retry-abandon round trip against a failing sink
  fixture (tests/integration collection)
- [ ] 5.3 Verify the `delivery-retries-exhausted` notification threshold rule
  fires once `retryCount` reaches 5 (engine smoke, may be deferred to a live
  instance check)

## Acceptance criteria

- A message failing against a dead sink is attempted exactly `maxRetries` times
  with growing intervals, then sits in `abandoned` with `nextAttempt=null` and
  is never re-attempted by the sweep.
- A transiently-failing sink results in eventual `delivered` with a complete
  `attempts[]` history.
- `processRetries` runs from cron without any manual call site.
- No existing `events-cloudevents` scenario regresses except the two replaced
  by this delta (5xx-marks-failed and pending-only sweep cap).
