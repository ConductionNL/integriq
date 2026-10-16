# Tasks: bounded-jsonl-sync

- [x] Reproduce: the old path against the real Austria `full.jsonl.gz` under a 2 GB limit dies at about 90,000 records in `json_decode`.
- [x] Stream a JSONL download to a temporary file through the call engine's `sink` and read it line by line (`readJsonLines`).
- [x] One batch per run (`sourceConfig.pageSize`, default 5,000), resumed through `currentPage`; only a whole file read in one run is complete.
- [x] Carry the next batch through `synchronize()` so the run's final save does not overwrite it.
- [x] `SynchronizationAction` reports `resumeSoon`; `JobService` schedules that job on the next tick.
- [x] Ship the ten external gathering connector Jobs disabled.
- [x] Tests: streaming to a removed temp file, batch and resume, a whole-file run is complete, a failed download keeps its batch, a 24 MB file read under a 2 MB budget with a control, a batch run deletes nothing and persists the next batch, `resumeSoon` scheduling, the fragment test asserts disabled jobs.
- [ ] Live pass: a fresh install runs cron with no integriq job pulling an external feed; enabling the Austria job imports it in batches without a fatal.
