# bounded-jsonl-sync

## Why

On a fresh Nextcloud 32 install (Zuiddrecht rehearsal R3), `cron.php` died with exit 255 on two of
five runs: `Allowed memory size of 2147483648 bytes exhausted` in
`SynchronizationService::parseJsonLines()`. A fresh install enabled 18 jobs that pull external
data, and one of them reads Austria's OpenTender export (`full.jsonl.gz`, 70 MB compressed,
451 MB and 181,741 records decompressed). The JSONL path held the whole body in memory three
times over (the call log body, base64; the gunzipped string; every decoded record in one array).
Replaying that path against the real file under a 2 GB limit dies at about 90,000 records, in the
same `json_decode` line. A fatal cannot be caught, so it took the whole cron process down, and
every job after it in that pass with it.

## What changes

- A `sourceConfig.format: "jsonl"` synchronization streams its download to a temporary file (the
  call engine's existing `sink`), and reads it back one line at a time with `gzopen`/`gzgets`,
  which reads gzip and plain files alike. For a streamed response neither the compressed nor the
  decompressed body is held whole, and the call log records no body for it; a body an in-process
  source returns on the call log is written to the temporary file first.
- One run reads one batch: `sourceConfig.pageSize` records, default 5,000. The next batch is
  kept in the synchronization's `currentPage`, and the run's result carries
  `resume: {page, reason}`. The batch after the last one is batch 1 again.
- Only a run that read the whole file from its first line is a complete fetch. Every other batch
  is incomplete (`batch_limit_reached`), so the deletion pass never treats one batch as the
  whole source.
- A synchronization job whose run left batches asks to run again on the next cron tick
  (`resumeSoon`), instead of after its interval.
- The ten external data-gathering connectors (TenderNed, AusTender, BOAMP, Austria OpenTender,
  Germany Bund, Diavgeia, Latvia IUB, Avropa, DPG registry, Nextcloud App Store) ship their Job
  disabled. Sources, mappings and synchronizations still ship, so an admin opts in by enabling
  the Job. Their spec already required a connector that needs streaming of very large files to
  ship disabled.

## Not changed

- The eight endoflife.date jobs stay enabled: `endoflife-date-source` requires them to ship
  `isEnabled: true`. They are small JSON calls, one per product per day.
- Whole-document formats (JSON, XML, YAML, markdown, HTML) still parse the whole page. They are
  bounded per page by pagination, `maxPages` and the existing memory ceiling; a streaming
  parser for them is a separate change.
- A failing job already cannot stop the others: `JobService` catches `\Throwable` per job and
  around each action. Memory exhaustion is a fatal that no handler sees, which is why the fix is
  the bounded reader.
- The ten job objects carry version 1.0.1, so an upgrade disables a job an earlier version
  imported enabled. An administrator's own edits to those jobs are overwritten with it.

## Impact

No route change. `currentPage` already exists on `synchronization`; `currentPageVersion` is added
next to it (synchronization schema 1.2.1) to tie a cycle to one version of the file. A large JSONL
source now takes several cron ticks per full pass and downloads the file once per batch.
