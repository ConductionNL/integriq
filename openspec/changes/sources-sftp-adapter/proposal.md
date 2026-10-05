---
kind: code
depends_on: []
---

# Proposal: sources-sftp-adapter

## Summary

Many partners still exchange files over SFTP: a nightly export dropped in a folder, a batch of documents picked up in the morning. Integriq has no way to fetch or deliver them. This change ships an SFTP and FTPS adapter: an administrator connects a partner's server with a pinned host key, and a synchronization or flow lists, fetches, delivers, moves and deletes files, with each fetched file landing in Nextcloud Files or handed on as an object.

## Why

Row `integriq:src-sftp` (rated no, built none), sources area (the core area), decided `build` in the OpenSpec pass of 2026-09-27: three competitors rate yes.

- MuleSoft https://docs.mulesoft.com/sftp-connector/latest/index.md "Anypoint Connector for SFTP (SFTP Connector) manages secure file transfers over the Secure File Transfer Protocol".
- n8n 2.40.7 `packages/nodes-base/nodes/Ftp/Ftp.node.ts:166` "'protocol' parameter chooses ftp or sftp with list, download, upload, rename, delete operations".
- Frank!Framework v10.2.0 `filesystem/src/main/java/org/frankframework/senders/SftpFileSystemSender.java:23` and `FtpFileSystemSender.java:23` "read, write, move and delete".

The matrix evidence: `grep -rliE "\bsftp\b|\bftp\b"` over `lib/` and `src/` returns nothing.

## What integriq already has

- The data infrastructure connector category, `openspec/specs/data-infra-connectors` REQ-DIC-001 to REQ-DIC-006, which names SFTP and FTPS among the file stores it covers and allows a schema posture of `none` "for an SFTP file-list adapter" (REQ-DIC-004). `S3Adapter` is the reference adapter.
- File handling in synchronizations: `parallel-file-fetch` and `stream-file-content` stream file content to disk instead of memory.
- `IRootFolder` is already used to store fetched files in Nextcloud Files (`lib/Service/IBabsConnectorService.php:71`).

REQ-DIC-007 asks for one adapter per change; the database adapter is `sources-database-adapter`.

## What this change builds

1. An SFTP adapter (password or key) and an FTPS adapter (explicit TLS), with capabilities `read`, `write` and `bulk-import`, meeting REQ-DIC-001 to REQ-DIC-006.
2. A mandatory host key pin for SFTP and a certificate check for FTPS. A changed key stops the connection and says so.
3. Operations: list a folder with a name pattern, fetch, deliver, move and delete.
4. A pickup synchronization: fetch every new file matching a pattern, store it in a Nextcloud folder or hand it to a mapping, then move it to an archive folder on the remote or delete it.

## Out of scope

- Plain FTP without TLS. It sends the password in the clear.
- Serving files to partners from integriq (integriq as an SFTP server).

## Amendment 2026-10-05: a watched folder is a source (Woo row 1.7, decision D4)

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

What this amendment adds, on top of tasks 1 to 4 (none of which is built):

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
