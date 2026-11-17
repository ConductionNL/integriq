# synchronization-engine (delta)

## MODIFIED Requirements

### Requirement: Bulk gzip/JSONL source ingestion (REQ-006)

`SynchronizationService::fetchSinglePageData()` MUST detect a
gzip-compressed response body via, in order: an explicit
`Source.configuration.decompress === "gzip"` hint; the fetched endpoint
ending in `.gz` (checked against the endpoint path or any query-string
value, to cover `/download?name=full.jsonl.gz`-shaped registry endpoints);
or an `application/gzip` response Content-Type header (case-insensitive
substring match). When any signal is present, the method MUST first
base64-decode the body when the call-log response's `encoding` field is
`"base64"`, then gunzip it via `gzdecode()` before any parse attempt.

When `Synchronization.sourceConfig.format === "jsonl"`, the engine MUST NOT
hold a streamed response body in memory (a body an in-process source returns on
the call log is written to the temporary file first). It MUST stream the response to a
temporary file through the call engine's `sink`, read that file back one
line at a time (gzip-compressed or plain), decode each non-empty line
independently, and skip malformed or non-array lines rather than abort. One
run MUST read at most one batch of `sourceConfig.pageSize` records (default
5,000): batch N holds records `(N - 1) * pageSize` up to `N * pageSize`. The
next batch MUST be persisted in the synchronization's `currentPage`, and
returns to 1 after the last batch. Only a run that read the whole file from
its first record is a complete fetch; every other batch MUST be reported
incomplete with `failureReason: "batch_limit_reached"`, so the deletion
pass never runs on part of the source (REQ-009). A JSONL source larger than
one batch therefore never runs the disappearance pass: a record removed
upstream is not removed here. A `batch_limit_reached` run is expected, so it
MUST NOT log the fetch-incomplete warning or dispatch the deletion-guarded
event; its deletion guard reads `batch_run`. A failed download MUST be
incomplete and keep its batch, and MUST NOT report `resume`, so its job waits
for its own interval. Each download's version (the ETag, else Last-Modified
with Content-Length) MUST be kept in the synchronization's
`currentPageVersion`; when a batch after the first finds another version, the
file changed under the cursor, and the run MUST log it and start the cycle
again at batch 1 of the new file. A source that sends neither header cannot
show a change, and its cursor is used as is. The temporary file MUST be removed when the
batch has been read. The records MUST pass through the existing
`getAllObjectsFromArray()` / `resultsPosition` extraction unchanged.

An endpoint identified as a `.tar.gz`/`.tar` archive (by the same
endpoint-suffix check) MUST short-circuit with a logged warning and an
empty result, for a `format: "jsonl"` synchronization too, before anything
is downloaded; that batch is incomplete and keeps its cursor. A source presenting none of these signals MUST take the
exact pre-existing JSON-then-XML-fallback code path, unchanged.

@e2e exclude backend bulk gzip/JSONL ingestion internals, covered by PHPUnit, not browser UI

#### Scenario: gzip-compressed JSONL bulk file is decompressed and parsed line-by-line

- **GIVEN** a source whose endpoint is `.gz`-suffixed (or answers
  `application/gzip`, or has `configuration.decompress: "gzip"`)
- **AND** `sourceConfig.format` is `"jsonl"`
- **WHEN** the synchronization runs
- **THEN** the gzip file is read decompressed, one line at a time, each
  non-empty line parsed as one JSON record
- **AND** a blank line between two records does not drop the record that follows it

#### Scenario: JSONL parsing works without gzip

- **GIVEN** a source with `sourceConfig.format: "jsonl"` and a plain
  (uncompressed) line-delimited JSON body
- **WHEN** the synchronization runs
- **THEN** each line is parsed as one record, identically to the gzip case
- **AND** a body a source answered in-process, on the call log rather than
  through the sink, is read the same way

#### Scenario: a large gzip JSONL file is read with bounded memory

- **GIVEN** a synchronization with `sourceConfig.format: "jsonl"` and a gzip
  body that decompresses to far more than PHP's memory limit allows
- **WHEN** the synchronization runs
- **THEN** the body is streamed to a temporary file and read one line at a time
- **AND** memory use does not grow with the size of the file
- **AND** the temporary file is gone after the run

#### Scenario: one run reads one batch and the next run resumes

- **GIVEN** a JSONL file of five records and `sourceConfig.pageSize: 2`
- **WHEN** three runs follow each other
- **THEN** they read records 1-2, 3-4 and 5
- **AND** `currentPage` is 2, then 3, then 1
- **AND** a blank or malformed line does not shift a batch boundary

#### Scenario: a batch of a larger file deletes nothing

- **GIVEN** a JSONL file larger than one batch and existing contracts for
  records outside the batch
- **WHEN** a run reads one batch
- **THEN** the fetch is incomplete with `failureReason: "batch_limit_reached"`
- **AND** the deletion pass does not run, its guard reads `batch_run`, and no
  fetch-incomplete warning is logged
- **AND** the run result carries `resume: {page, reason}`

#### Scenario: a file larger than one batch never runs the disappearance pass

- **GIVEN** a JSONL file larger than one batch, and a contract for a record
  that is no longer in the file
- **WHEN** every batch of a cycle has run
- **THEN** no run deleted that record's target

#### Scenario: the file changes between two batches

- **GIVEN** batch 1 of a cycle read a file with ETag `"a"`
- **WHEN** the run for batch 2 downloads the file and it answers ETag `"b"`
- **THEN** the run reads batch 1 of the new file, logs the restart, and the
  next batch is 2

#### Scenario: a failed download does not come back on the next tick

- **GIVEN** a synchronization at batch 4 whose download answers 503
- **WHEN** the run ends
- **THEN** `currentPage` stays 4 and the result carries no `resume`

#### Scenario: a file that fits one batch is a complete fetch

- **GIVEN** a JSONL file no larger than one batch
- **WHEN** a run reads it from its first record
- **THEN** the fetch is complete and `currentPage` stays 1

#### Scenario: gzip decompression works independently of JSONL, for an ordinary JSON body

- **GIVEN** a source with no `format` override, a gzip-compressed response
  body, and a `resultsPosition` pointing at a nested array
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the body is gunzipped and then parsed as ordinary whole-document
  JSON through the pre-existing `resultsPosition` extraction

#### Scenario: a `.tar.gz` endpoint is refused with a logged warning, not silently mis-parsed

- **GIVEN** a source whose endpoint identifies a `.tar.gz`/`.tar` archive
- **WHEN** `fetchSinglePageData()` runs
- **THEN** a warning is logged naming the endpoint
- **AND** the method returns an empty `objects`/`result` pair without
  attempting to gunzip-then-parse the tar byte stream

#### Scenario: a JSONL synchronization refuses a `.tar.gz` endpoint too

- **GIVEN** a synchronization with `sourceConfig.format: "jsonl"` whose
  endpoint identifies a `.tar.gz` archive
- **WHEN** the synchronization runs
- **THEN** a warning is logged once, nothing is downloaded, no objects are
  returned, and the fetch is incomplete with the batch kept

#### Scenario: a source with none of the new signals is unaffected

- **GIVEN** a source with an ordinary JSON (or XML) body, no `.gz`/`.tar`
  suffix, no `application/gzip` Content-Type, no `configuration.decompress`,
  and no `sourceConfig.format`
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the result is byte-identical to the pre-existing
  json_decode-then-simplexml-fallback behaviour
- @e2e exclude backend regression, covered by PHPUnit
