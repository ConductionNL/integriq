---
kind: code
depends_on: [sources-sftp-adapter]
---

# Proposal: sources-sftp-adapter-watched-folder

## Summary

Watch a folder in Nextcloud Files as a source: pick up each file that lands in it exactly once, map it onto a draft object that is never published, and move or tag it only after a safe write.

- Rows: Woo row 1.7 "Records arrive from a watched folder or a file drop" (not statutory). This part closes it for `target: mapping`; the third part adds the intake hand-over.
- Wave: 1.
- Depends on: `integriq/sources-sftp-adapter` (https://github.com/ConductionNL/integriq/issues/2539), part 1 of this chain. `integriq/sources-sftp-adapter-intake-hand-over` (part 3) depends on this one.
- Decision: D4 (2026-10-05), integriq owns the folder watcher because a folder is a source.

Build rules: openspec/woo-build-rules.md

## Why

This is the second part of `sources-sftp-adapter`. It was written on 2026-10-05 as an
amendment to that change and split on 2026-10-06 into this part and
`sources-sftp-adapter-intake-hand-over`, so each part stays within 20 tasks. This part builds the watched
folder, the once-per-file ingest and `target: mapping` (REQ-SFTP-003 and
REQ-SFTP-004, tasks 5, 6, 7 and 9). The third part builds `target: intake`
and `WatchedFileArrivedEvent` (REQ-SFTP-005, task 8). The text below covers
both, as it was written.

Woo capability row 1.7, "Records arrive from a watched folder or a file
drop". Our column reads `partial`: "openregister
lib/Service/File/FolderManagementHandler.php and Nextcloud Files give a
folder; opencatalogi src/modals/generic/UploadFiles.vue is a manual upload.
Nothing watches a folder and ingests what lands in it". The gap register names
the missing half: "A watched Nextcloud folder as a standing source: a
background job picks up each file that lands in a configured folder once, and
maps it onto a draft publication (or hands it to a named intake), then moves
or marks it." Build plan: amend this change, wave 1, size S.

Ruben's decision **D4** of 2026-10-05: integriq owns the folder watcher,
because a folder is a source. filinq's scan intake consumes it for
separator-sheet splitting (`filinq/scan-intake-with-separator-sheets` task
3.2, still open, which notes "There is no watched-folder job in
`lib/BackgroundJob/` to point"). Nothing in the fleet watches a folder today.

The pickup synchronization of D4 in `design.md` already fetches new files from
a remote folder, once, and archives them after a safe local write. This
amendment adds the same pickup for a folder in Nextcloud Files, and two
targets for what was picked up.

What this part and the third part add, on top of tasks 1 to 4 of `sources-sftp-adapter` (none of which is built):

1. A source type `nextcloud-folder`: a folder in Nextcloud Files, named by its
   owner and its path. A synchronization on it uses the same `sourceConfig`
   as the SFTP pickup (`path`, `pattern`, `after`, `stableSeconds`).
2. Two ways a file is noticed. A listener on `NodeCreatedEvent` and
   `NodeWrittenEvent` queues a one-shot job for a file under a watched
   folder, and does nothing else on the upload's request. A sweep through an
   OpenRegister `ScheduledWorkflow` (REQ-DIC-005) catches files no event
   announced, such as a file put on external storage and found by a scan.
3. Once per file. The synchronization contract keys a picked-up file by its
   Nextcloud file id. A file already in the contract is never ingested again,
   whichever path notices it.
4. Two targets. `target: mapping` maps the file's metadata (and, for CSV,
   JSON or XML, its parsed content) onto an object of the configured
   register and schema, attaches the file to that object, and leaves it a
   draft. `target: intake` hands the file to a named intake app through a
   typed event, which filinq's scan intake will listen to.
5. Afterwards the file is moved to a `processed` folder or tagged with the
   system tag `integriq-ingested`, as the synchronization says. A failed
   ingest moves it to `failed` or tags it `integriq-ingest-failed`, with the
   reason on the synchronization log.

Fail closed:

- A watched-folder run never publishes. For `target: mapping` the mapped
  object is written without a publication date: the keys `published` and
  `publicatiedatum` are removed from the mapping output before the save, and
  each removal is logged on the run. An officer publishes it.
- A file is moved or tagged only after the object or the intake hand-over
  succeeded. A failed write leaves the file where it was.
- An intake hand-over nobody accepts is not a success. The file stays in the
  folder, is not marked, and is reported as `unclaimed` on the run.

App absent:

- opencatalogi absent, or any target schema that does not resolve: the
  synchronization refuses to run with "target schema not found", nothing is
  picked up, and no file moves.
- filinq absent with `target: intake` and `intakeApp: filinq`: no listener
  accepts, so every file is `unclaimed` and stays (above).
- integriq absent: no folder is watched. filinq's scan intake keeps its
  controller upload, as it does today.

Wave 1. Implements decision D4. No dependency on another planned change.
filinq's consumer (scan intake task 3.2) is filinq's own work, not this
change's.
