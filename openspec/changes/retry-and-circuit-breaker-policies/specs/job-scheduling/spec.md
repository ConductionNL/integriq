# job-scheduling Specification Delta

## MODIFIED Requirements

### Requirement: Job scheduling registration and execution with retention-bounded logs (REQ-004)

`JobService::scheduleJob(ObjectEntity $job): ObjectEntity` MUST inspect
the job's `isEnabled` flag and existing `jobListId`. If `isEnabled ===
false` OR `jobListId !== null`, the method MUST clear `jobListId` to
`null`, save the job via `objectService->saveObject`, and return. (See
Notes on the disable bug below.)

Otherwise the method MUST add a new `JobTask` entry to `IJobList` —
either via `scheduleAfter` (when `scheduleAfter` field is set) or
`add(...)` — using `arguments = $jobData['arguments'] ++ ['jobId' =>
$job->getUuid()]`. It then MUST resolve the new entry's id via
`getJobListId(JobTask::class)`, write it back as `jobListId`, save, and
return the saved entity.

`JobService::executeJob(ObjectEntity $job, bool $forceRun = false):
?ObjectEntity` MUST:

1. Short-circuit (return a WARNING log) if `isEnabled === false` and
   `!$forceRun`.
2. Short-circuit (return `null`, no log) if `nextRun` is in the future
   and `!$forceRun`.
3. If `userId` is set on the job, capture the prior session user
   (`userSession->getUser()`) before overriding it, then set the session
   user to the job's configured `userId`. If the configured `userId` does
   not resolve to an existing user, skip the job with a WARNING log and
   do not touch the session. The action's execution (step 4) MUST be
   wrapped so that, in a `finally` block, the prior session user is always
   restored — regardless of whether the action succeeded or threw —
   before `executeJob` returns or continues to log-writing (#1006: without
   restoration, the first user-scoped job's identity would stick for every
   subsequent job processed by the same PHP worker/process, including
   jobs run later in the same cron pass or a subsequent HTTP-triggered
   `run`/`test` call).
4. Resolve `jobClass` from the DI container and invoke `->run($arguments)`,
   catching any `\Throwable` so a thrown exception from the job's action
   does not prevent step 3's session restoration or step 10's job-log
   write; the caught exception is recorded as an ERROR job log entry
   (existing H3 behavior).
5. Compute execution time in milliseconds.
6. If `isSingleRun` and `!$forceRun`, set `isEnabled = false`.
7. Update `lastRun = now()`; compute `nextRun = now + interval seconds`
   (whether or not the action threw — a thrown exception still advances
   the schedule by the job's interval so a persistently-failing job does
   not permanently block itself as "due"), honouring a rate-limit override
   in `$result['nextRun']` (Unix timestamp) on success, rounding to the
   next minute when the seconds component is non-zero. Set the time to
   top-of-minute.
8. Save the job back to OR.
9. Compute the success / error retention via `calculateExpires` (max of
   per-job + global retention, `null` if either is `0`).
10. Compose `logData` (level/message/executionTime/expires/stackTrace)
    from the action's result and save via `saveJobLog`.

`JobService::run(): array` MUST query OR for `register=openconnector,
schema=job, isEnabled=true`, filter out jobs whose `nextRun` is in the
future, and call `executeJob` on each, collecting non-null logs into
the return array. Because `executeJob` already catches action-level
`\Throwable`s internally (step 4 above) and always writes a job log,
`run()`'s own `try/catch (\Throwable)` around each `executeJob` call
exists as a second line of defense against **infrastructure-level**
failures (e.g. `saveObject`/`saveJobLog` DB errors) that `executeJob`
itself cannot recover from: on such a throw, `run()` MUST skip that job
(the exception is swallowed, not rethrown) and continue to the next due
job in the same pass (#1005: a single job's failure — at either the
action level or the infrastructure level — MUST NOT prevent the
remaining due jobs in the same cron sweep from running).

`JobService::getJobListId(IJob|string $job): ?int` MUST query the
`oc_jobs` table for the most recent row matching the given class
(`ORDER BY id DESC LIMIT 1`) and return the id, or `null` if no match.

`JobService::saveJobLog(...)` (private) MUST compose the log object
(jobId, jobClass, jobListId, arguments, lastRun, nextRun, created) ++
`$logData`, default `expires` based on log level (`INFO`: +1h, `WARNING`
/ `ERROR`: +3d, default: +30d) if not set, and save to OR.

`JobService::truncateMessage(string $message, int $maxLength = 10000):
string` MUST return `$message` unchanged if it fits, otherwise truncate
to `maxLength - 50` chars + the marker `'... [Message truncated -
original length: <N> characters]'`.

`JobService::calculateExpires(...int $retentions): ?DateTime` MUST
return `null` if any retention is `0` (indefinite retention), otherwise
return `new DateTime('now +' . max($retentions) . 'milliseconds')`.

<!-- Previous behavior: step 3 set the session user via setUser($user)
     and never restored the prior session user (the "session-clobber"
     bug, #1006) — this text now documents the fix already present in
     HEAD (JobService::executeJob()'s try/finally around action
     execution). run()'s per-job try/catch (#1005) was also
     undocumented in the prior spec text; it is now described above. No
     code change is introduced by this delta — it corrects spec text
     that had fallen out of sync with already-shipped fixes. -->

#### Scenario: scheduleJob registers a new JobTask entry

- **GIVEN** a job with `isEnabled: true`, `jobListId: null`, no `scheduleAfter`
- **WHEN** `scheduleJob($job)` is called
- **THEN** `IJobList::add(JobTask::class, arguments + {jobId: <uuid>})` is invoked
- **AND** the saved job has the new `jobListId` populated

#### Scenario: executeJob honours nextRun unless forced

- **GIVEN** a job with `nextRun = now + 1 hour`, `isEnabled: true`
- **WHEN** `executeJob($job, forceRun: false)` is called
- **THEN** the method returns `null` (no log)
- **AND** the job action is NOT invoked

#### Scenario: forceRun bypasses both isEnabled and nextRun

- **GIVEN** a job with `isEnabled: false`, `nextRun: now + 1h`
- **WHEN** `executeJob($job, forceRun: true)` is called
- **THEN** the action is invoked
- **AND** a SUCCESS / matching-level log is saved

#### Scenario: rate-limit nextRun is honoured if later than the interval-based one

- **GIVEN** action returns `{ nextRun: <unix-timestamp 30 min from now>, level: 'SUCCESS' }`
- **AND** the job's interval would have produced nextRun 10 min from now
- **WHEN** `executeJob` runs
- **THEN** the saved `nextRun` is the rate-limit timestamp (rounded to next minute)

#### Scenario: truncateMessage truncates with marker

- **GIVEN** a message of 50_000 characters
- **WHEN** `truncateMessage($msg, 10000)` is called
- **THEN** the return is 9_950 chars of original + the truncation marker
- **AND** the marker reports original length 50000

#### Scenario: calculateExpires returns null on indefinite

- **WHEN** `calculateExpires(3600000, 0)` is called
- **THEN** the result is `null`

#### Scenario: a throwing job's identity does not bleed into the next job (#1006)

- **GIVEN** two enabled jobs due in the same `run()` pass — job A configured
  with `userId: 'alice'` whose action throws, and job B with no `userId`
  configured
- **WHEN** `run()` processes job A followed by job B in the same pass
- **THEN** the session user during job A's action execution SHALL be
  `alice`
- **AND** the session user during job B's execution (and after `run()`
  returns) SHALL be the same as the session user in effect before job A ran
  (NOT `alice`)

#### Scenario: a throwing job does not block the rest of the cron sweep (#1005)

- **GIVEN** three enabled, due jobs — job A (throws), job B, job C — in the
  same `run()` pass
- **WHEN** `run()` executes
- **THEN** job B and job C SHALL both execute and produce job logs
- **AND** job A's `nextRun` SHALL be advanced by its configured interval
  (not left unchanged / immediately due again)
- **AND** the returned array SHALL contain job logs for A (ERROR), B, and C

#### Notes

- **HIGH (disable doesn't actually disable):** `scheduleJob`'s disable
  path clears the integriq-side `jobListId` but leaves the
  underlying NC `oc_jobs` row in place (the commented-out
  `removeById` call). The next `JobTask::run` sweep walks OR for
  `isEnabled = true`, so the disabled job will be skipped — but a
  separate `JobTask` row also persists, which could re-run on its own
  cron schedule if its arguments happen to contain a stale `jobId`.
  Documented as observed; the fix is to switch to a public NC API or
  upstream a `IJobList::removeById` method. Unchanged by this delta —
  out of scope.
- **RESOLVED (was "HIGH session-clobber"):** the prior spec text
  documented `executeJob` calling `$userSession->setUser($user)` and
  never resetting it, creating a privilege-escalation chain when
  combined with REQ-002's IDOR (any authed user triggering a job that
  then runs as an admin identity for the rest of the request). Verified
  against HEAD: `executeJob` now captures the prior session user and
  restores it in a `finally` block (#1006) regardless of success or
  failure, closing that escalation chain. REQ-002's underlying IDOR
  (missing per-object authorization on `run`/`test`) is a separate,
  still-open finding — unchanged by this delta.
- **LOW (unit hazard):** per-job `logRetention` is in seconds; service
  internals are in milliseconds — the `* 1000` happens at every save.
  Consistent here but easy to misuse downstream.
- **LOW (NC API gap):** `getJobListId` is best-effort `ORDER BY id DESC
  LIMIT 1` because NC's `IJobList` does not surface a way to read the
  id of a just-added job. Documented for visibility.
