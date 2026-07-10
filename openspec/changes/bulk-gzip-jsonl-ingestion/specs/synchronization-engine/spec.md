# synchronization-engine — Delta: bulk gzip/JSONL source ingestion

## Purpose

Extends `SynchronizationService::fetchSinglePageData()` (REQ-002's source
fetching) so a source whose payload is gzip-compressed and/or
line-delimited JSON (JSONL) — the shape national procurement bulk-export
registries (OCP/DIGIWHIST) serve — can be decompressed and parsed instead of
silently yielding zero objects forever. Composes with the existing
`resultsPosition` extraction; does not change pagination, mapping, or any
JSON/XML/query-param source's behaviour.

@e2e exclude backend source-fetching internals — covered by PHPUnit, not browser UI

## ADDED Requirements

### Requirement: Bulk gzip/JSONL source ingestion (REQ-006)

`SynchronizationService::fetchSinglePageData()` MUST detect a
gzip-compressed response body via, in order: an explicit
`Source.configuration.decompress === "gzip"` hint; the fetched endpoint
ending in `.gz` (checked against the endpoint path or any query-string
value, to cover `/download?name=full.jsonl.gz`-shaped registry endpoints);
or an `application/gzip` response Content-Type header (case-insensitive
substring match). When any signal is present, the method MUST first
base64-decode the body when the call-log response's `encoding` field is
`"base64"` (the marker `CallService::buildResponseData()` already records
for any response body that fails UTF-8 validation, which gzip-compressed
binary always does), then gunzip it via `gzdecode()` before any parse
attempt. When `Synchronization.sourceConfig.format === "jsonl"`, the method
MUST parse the (decompressed, or already-plain) body as line-delimited
JSON — each non-empty, non-whitespace line independently `json_decode`d,
malformed or non-array lines skipped rather than aborting the page — instead
of the whole-document JSON/XML parse attempts, and MUST feed the resulting
records array through the existing `getAllObjectsFromArray()` /
`resultsPosition` extraction unchanged. An endpoint identified as a
`.tar.gz`/`.tar` archive (by the same endpoint-suffix check) MUST short-circuit
with a logged warning and an empty result instead of attempting to parse the
undecoded tar byte stream. A source presenting none of these signals MUST
take the exact pre-existing JSON-then-XML-fallback code path, unchanged.

#### Scenario: gzip-compressed JSONL bulk file is decompressed and parsed line-by-line

- **GIVEN** a source whose fetched response has `encoding: "base64"` (a
  non-UTF8 body) and a `.gz`-suffixed endpoint (or an `application/gzip`
  Content-Type, or `configuration.decompress: "gzip"`)
- **AND** `sourceConfig.format` is `"jsonl"`
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the body is base64-decoded, gunzipped, and each non-empty line
  parsed as one JSON record
- **AND** a blank line between two records does not drop the record that follows it

#### Scenario: JSONL parsing works without gzip

- **GIVEN** a source with `sourceConfig.format: "jsonl"` and a plain
  (UTF-8, uncompressed) line-delimited JSON body
- **WHEN** `fetchSinglePageData()` runs
- **THEN** each line is parsed as one record, identically to the gzip case
  minus the decompression step

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

#### Scenario: a source with none of the new signals is unaffected

- **GIVEN** a source with an ordinary JSON (or XML) body, no `.gz`/`.tar`
  suffix, no `application/gzip` Content-Type, no `configuration.decompress`,
  and no `sourceConfig.format`
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the result is byte-identical to the pre-existing
  json_decode-then-simplexml-fallback behaviour
- @e2e exclude backend regression — covered by PHPUnit

**Notes:**

- `.tar.gz`/`.csv.tar.gz` archives are explicitly NOT unpacked by this
  requirement — gzip decompression alone yields a tar byte stream, not
  parseable JSON. A genuine tar-extraction capability is deferred; an
  ETL-style loader remains the documented workaround for `.tar.gz`-only
  sources (e.g. the OCP-mirror path for `croatia_eojn`) until/unless a
  follow-up change adds one.
- The JSONL parser tokenises line-by-line (`strtok`) rather than
  `explode()`-ing a second full in-memory copy of the body, but the body
  itself is still one fully-buffered string by the time this code runs
  (Guzzle/`CallService` already materialise the whole HTTP response) — this
  is a partial memory mitigation, not true streaming. A lower-level change
  to how `CallService` hands off the response body would be needed for
  genuine streaming decompression/parsing; out of scope here.
- Methods added: `isGzipPayload()`, `isTarGzEndpoint()`,
  `endpointSuggestsSuffix()`, `parseJsonLines()` (all private, alongside the
  existing `fetchSinglePageData()`).
