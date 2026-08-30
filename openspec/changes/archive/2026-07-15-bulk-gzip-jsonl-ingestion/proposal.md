---
kind: code
depends_on: []
---

# openconnector — gzip decompression + JSONL bulk-file ingestion (oc#97)

## Why

Spectr's drafted connectors for three national procurement registries —
`slovenia_ocds.json`, `romania_seap.json`, and the OCP-mirror path for
`croatia_eojn.json` — all sit on the Open Contracting Partnership /
DIGIWHIST bulk-export platform (`data.open-contracting.org`, backed by
`fastly.data.open-contracting.org`). Every download format that platform
offers is `.jsonl.gz` (gzipped line-delimited JSON), `.csv.tar.gz`, or
`.xlsx` — confirmed live 2026-07-06 against publication 93 (Slovenia): a HEAD
request against the resolved download target returns `Content-Type:
application/gzip`, a genuine binary gzip file, not a JSON response with a
transport `Content-Encoding: gzip` header that Guzzle would already unwrap
transparently.

`SynchronizationService::fetchSinglePageData()` only ever attempts two things
against a fetched response body: `json_decode()`, then (if that yields
empty) `simplexml_load_string()` as an XML fallback. Neither can do anything
with gzip-compressed binary bytes or line-delimited JSON (a JSONL body is
not valid whole-document JSON) — the engine silently returns zero objects
for every page, forever, with no error surfaced. All three spectr connectors
ship `isEnabled: false` today specifically because of this gap.

## What Changes

- **Gzip detection + decompression**: `fetchSinglePageData()` gunzips the
  response body (PHP's `gzdecode()`, ext-zlib, already available) when any
  one of three signals is present: an explicit `Source.configuration.
  decompress: "gzip"` hint, a `.gz`-suffixed endpoint (path or a `name=`-style
  query value carrying the filename — the exact shape of the OCP registry's
  `/download?name=full.jsonl.gz`), or an `application/gzip` response
  Content-Type header. `CallService` already base64-encodes any response
  body that fails UTF-8 validation (gzip binary always will) and records
  that in the CallLog's `encoding` field; the fix base64-decodes back to raw
  bytes first when that flag is set.
- **JSONL parsing**: a new `Synchronization.sourceConfig.format: "jsonl"`
  option reads the (decompressed, or already-plain) body as line-delimited
  JSON — one record per non-empty line — instead of whole-document
  JSON/XML. Wired into the exact point `resultsPosition` already extracts
  arrays from (`getAllObjectsFromArray()`), so `resultsPosition: "_root"`
  pairs with it exactly like every other source shape.
- **`.tar.gz` is explicitly NOT supported this batch.** Gzip decompression
  alone unpacks a `.tar.gz` to a tar byte stream, not parseable JSON — a
  genuine tar-extraction step is a separate, heavier lift. The engine now
  detects `.tar.gz`/`.tar` endpoints and short-circuits with a logged
  warning instead of the pre-existing silent-empty failure mode. Croatia's
  OCP-mirror path (which offers `.csv.tar.gz`) stays blocked; its non-mirror
  `croatia_eojn.json` path is unaffected by this change either way.
- **Memory**: bulk files can run into the tens of megabytes once
  decompressed. The JSONL parser tokenises line-by-line (`strtok`) rather
  than `explode()`-ing a second full copy, and skips malformed lines instead
  of aborting the whole page. The response body itself is still held fully
  in memory by the time it reaches this code (Guzzle/CallService already
  buffer the whole HTTP response) — true streaming (fetch → decompress →
  parse without ever holding the full decompressed string) would need a
  lower-level change to how `CallService` reads the response body, which is
  out of scope here and noted as a follow-up.

## Impact

- Affected specs: `synchronization-engine` (REQ-002 area — additive; existing
  REQ-002 scenarios and prose are unchanged).
- Affected code: `lib/Service/SynchronizationService.php`
  (`fetchSinglePageData()` + three new private helpers: `isGzipPayload()`,
  `isTarGzEndpoint()`, `parseJsonLines()`; `endpointSuggestsSuffix()` shared
  with the tar-gz check), `lib/Settings/openconnector_register.json`
  (`sourceConfig`/`configuration` field descriptions documented, no schema
  shape change — both are already free-form objects), unit tests.
- Not affected: JSON/XML/query-param sources (no `format`/`decompress`
  signal present) take the exact pre-existing code path — see the
  regression tests in `SynchronizationServiceTest` proving byte-identical
  behaviour.
- Unblocks (follow-up, not this change): flipping `spectr/connectors/
  slovenia_ocds.json`, `romania_seap.json` (and the OCP-mirror path noted in
  `croatia_eojn.json`) from `isEnabled: false` draft to live.
