# Tasks: bounded-jsonl-sync

- [x] Reproduce: the old path against the real Austria `full.jsonl.gz` under a 2 GB limit dies at about 90,000 records in `json_decode`.
- [x] Stream a JSONL download to a temporary file through the call engine's `sink` and read it line by line (`readJsonLines`).
- [x] One batch per run (`sourceConfig.pageSize`, default 5,000), resumed through `currentPage`; only a whole file read in one run is complete.
- [x] Carry the next batch through `synchronize()` so the run's final save does not overwrite it.
- [x] `SynchronizationAction` reports `resumeSoon`; `JobService` schedules that job on the next tick.
- [x] Ship the ten external gathering connector Jobs disabled.
- [x] Tests: streaming to a removed temp file, batch and resume, a whole-file run is complete, a failed download keeps its batch, a 24 MB file read under a 2 MB budget with a control, a batch run deletes nothing and persists the next batch, `resumeSoon` scheduling, the fragment test asserts disabled jobs.
- [x] Review follow-ups (integriq#2643): a failed batch keeps the job's interval, a JSONL synchronization refuses a tar endpoint, a batch run does not warn about the disappearance pass, a cycle restarts when the file changes between batches, the ten job objects carry version 1.0.1.
- [x] Live pass: a fresh install runs cron with no integriq job pulling an external feed; enabling the Austria job imports it in batches without a fatal.
  - Run 25 Nov 2026 on NC 34.0.6 + MariaDB (OpenRegister main 58479c0d0, integriq development with #3958). Fresh install: the 20 external gathering and discovery jobs (TenderNed, AusTender, Austria OpenTender, BOAMP, the API discovery jobs and the rest) ship disabled; only the eight small endoflife.date jobs are enabled, as the proposal keeps them.
  - Austria: the job enabled, its target register `spectr/tender` imported from the spectr repository, and integriq's `JobTask` run through the cron path (`occ background-job:execute`) under `memory_limit=512M`, three times. Each run read one batch of 5,000 records from the 70 MB `full.jsonl.gz` in about 40 s with no fatal (5,000, 10,000, 15,000 tenders stored), and after each run the job's `nextRun` was the next tick (`resumeSoon`), not a week later.
  - A manual `occ integriq:job:run` of the same job is refused with "Anonymous ... update ... Synchronization": since #3958 only the cron loop runs a job without a user as the system, which is the decided behaviour.
