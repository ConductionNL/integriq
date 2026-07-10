# Tasks — gzip decompression + JSONL bulk-file ingestion (oc#97)

> Verified the exact fetch/parse flow at HEAD before touching it (see
> design.md `Context`). Implementation is additive inside
> `fetchSinglePageData()` — no change to pagination, mapping, or the
> query-param/JSON/XML code paths.

- [x] Add `isGzipPayload()`: detects `Source.configuration.decompress ===
  "gzip"`, a `.gz`-suffixed endpoint (path or `name=`-style query value), or
  an `application/gzip` response Content-Type header (first match wins)
- [x] Add `isTarGzEndpoint()` + shared `endpointSuggestsSuffix()` helper:
  detects `.tar.gz`/`.tar` endpoints so they short-circuit with a logged
  warning instead of the pre-existing silent-empty failure
- [x] Wire gzip decompression into `fetchSinglePageData()`: base64-decode
  first when `$response['encoding'] === 'base64'` (CallService's existing
  non-UTF8 encoding marker), then `gzdecode()` when `isGzipPayload()` is true
- [x] Add `parseJsonLines()`: line-by-line (`strtok`) JSONL parsing, skips
  blank/malformed lines, returns the decoded records array
- [x] Wire `sourceConfig.format === "jsonl"` into `fetchSinglePageData()`,
  feeding `parseJsonLines()`'s output through the existing
  `getAllObjectsFromArray()` (so `resultsPosition` behaves identically to
  every other source shape)
- [x] Document the new `configuration.decompress` / `sourceConfig.format` /
  `sourceConfig.paginationIn` (cross-referenced, see post-body-pagination)
  keys inline in `lib/Settings/openconnector_register.json`'s field
  descriptions — no schema shape change (both fields are already free-form
  objects)
- [x] Unit tests (`SynchronizationServiceTest`): gzip+JSONL end-to-end
  (with a blank line, proving line-skip doesn't lose subsequent records),
  plain JSONL without gzip, gzip-via-Content-Type-header without a `.gz`
  endpoint, gzip-via-`configuration.decompress` hint, `.tar.gz`
  short-circuit (asserts the logged warning + empty result)
- [x] Regression tests: a plain JSON source with none of the new config
  keys, and a plain XML source (existing simplexml fallback) — both proven
  byte-identical to pre-change behaviour in the same PHPUnit run
- [x] `composer phpcs` + `composer phpstan` clean on the touched file
- [x] Full existing PHPUnit suite green (417/417) — no regressions from this
  change or from the co-located post-body-pagination change

Acceptance criteria (plain bullets — verified by /opsx-verify):

- A source with `sourceConfig.format: "jsonl"` and a gzip-compressed,
  base64-logged response body (the real OCP/DIGIWHIST registry shape) is
  decompressed and each JSONL line becomes one record
- A source with `sourceConfig.format: "jsonl"` and a plain (uncompressed)
  body parses identically, without requiring the gzip signal
- Gzip decompression fires independently of `format` — an ordinary JSON body
  compressed with gzip decompresses and then parses through the existing
  JSON/`resultsPosition` path unchanged
- A `.tar.gz`/`.tar` endpoint logs a warning and returns zero objects instead
  of silently failing
- A source with none of the new signals (`decompress`, `.gz`/`.tar`
  endpoint, `application/gzip` Content-Type, `format: "jsonl"`) is provably
  unaffected — same JSON and XML regression tests pass before and after
