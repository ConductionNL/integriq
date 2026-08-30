# Design — gzip decompression + JSONL bulk-file ingestion

## Context

Verified against HEAD (`origin/development`, `44755371`):

- `SynchronizationService::getAllObjectsFromApi()` (`lib/Service/
  SynchronizationService.php`) resolves the source, builds a `$config`
  array (headers/query/body), and calls `fetchAllPages()` →
  `fetchAllPagesOptimized()` (or `fetchSinglePage()` when
  `usesPagination === false`) → `fetchSinglePageData()` once per page.
- `fetchSinglePageData()` (pre-change) does exactly two things to the
  response body: `json_decode($body, true)`, then — if that yields empty —
  `simplexml_load_string()` + `xmlToArray()` as an XML fallback (added
  2026-06-20 per `estonia_rhr.json`'s own writeup). Neither attempt can
  handle gzip-compressed binary bytes or line-delimited JSON.
- `CallService::buildResponseData()` (`lib/Service/CallService.php:975`)
  already does the relevant encoding detection: `$body =
  $response->getBody()->getContents(); $isUtf8 =
  (mb_check_encoding($body, 'UTF-8') !== false);` — when `$isUtf8` is
  `false` (which real gzip binary always is), the body is base64-encoded and
  `'encoding' => 'base64'` is recorded alongside it in the persisted
  `response` sub-array. `fetchSinglePageData()` reads
  `$response['body']`/`$response['encoding']` from that same structure via
  `callLogResponse()`.
- Confirmed live 2026-07-06 (see `slovenia_ocds.json`'s `$comment`): a HEAD
  request against the resolved OCP download target
  (`https://fastly.data.open-contracting.org/downloads/slovenia_digiwhist/
  3907/2024.jsonl.gz`) returns `Content-Type: application/gzip`,
  `Content-Length: 2,758,327` — a genuine `.gz` file payload, not a
  transport-compression header Guzzle would already unwrap.
- `getAllObjectsFromArray()` extracts the records array via
  `Synchronization.sourceConfig.resultsPosition`: `_root`/`_object` return
  the decoded body as-is; any other value is a dot-path lookup
  (`Adbar\Dot`); absent falls back to common keys (`items`/`result`/
  `results`).
- `Source.configuration` and `Synchronization.sourceConfig` are both
  free-form `type: object` in `lib/Settings/openconnector_register.json` —
  no schema shape change is needed to add recognised sub-keys, only
  documentation (mirrors how `configuration.authentication.credentialRef`
  is already documented inline, from `source-broker-credentials`).

## Decisions

- **Detection order for gzip** (first match wins, no further guessing):
  1. `Source.configuration.decompress === "gzip"` (explicit, source-level —
     survives even if the endpoint/response signals are ambiguous or the
     admin wants to force it).
  2. Endpoint suffix `.gz` — checked against both the path and any
     query-string VALUE (handles `/download?name=full.jsonl.gz`, where the
     meaningful filename lives in a query value, not the path itself).
  3. Response `Content-Type` header containing `gzip` (case-insensitive,
     substring match — covers `application/gzip`,
     `application/x-gzip`, etc.).
- **`.tar.gz` is detected and REFUSED, not silently mis-parsed.** Gunzipping
  a `.tar.gz` produces a tar byte stream; feeding that to `json_decode`/
  `simplexml_load_string` would fail exactly like the pre-existing
  (undetected) bug did — the fix would look complete while `.tar.gz` sources
  still silently returned zero objects. Instead `isTarGzEndpoint()`
  short-circuits with a `$this->logger->warning()` call naming the endpoint,
  so the gap is visible in logs rather than invisible.
- **JSONL is a `sourceConfig.format` value, not a `resultsPosition` value.**
  `resultsPosition` answers "where in the decoded body are the records";
  `format` answers "how do I decode the body at all" — these are
  orthogonal questions (a JSONL body's records ARE the whole body, so
  `format: "jsonl"` is documented to pair with `resultsPosition: "_root"`,
  but the two config keys stay conceptually separate rather than
  overloading `resultsPosition` with a `_jsonl` sentinel that would need
  its own branch in `getAllObjectsFromArray()` too).
- **Line parsing skips malformed lines rather than aborting the page.** One
  corrupt record in a multi-thousand-line bulk file should not lose every
  other record on that page. `json_decode()` per line; non-array decodes
  (parse failure, or a bare scalar/null line) are dropped.
- **Memory**: `strtok($body, "\n")` iterates line-by-line without
  `explode()`-ing a second full array of lines into memory up front. This
  is a partial mitigation only — `$body` itself is still one fully-buffered
  string by the time this code runs (Guzzle's `getContents()` in
  `CallService::buildResponseData()` already materialises the whole
  response). True streaming (decompress-while-reading, parse-while-reading)
  would require `CallService` to hand `fetchSinglePageData()` a stream
  resource instead of a materialised string — a larger change to the HTTP
  call engine's response handling, explicitly out of scope here and called
  out as a known ceiling rather than silently assumed away.
- **No change to `getAllObjectsFromArray()`, `getNextPageInfo()`, or the
  pagination loop.** A JSONL bulk source in practice always ships
  `usesPagination: false` (the whole file is fetched in one shot; see
  `slovenia_ocds.json`), so this change composes with the existing
  pagination machinery without needing to touch it.

## Backward compatibility

Every new code path is gated behind an explicit, opt-in signal:
`configuration.decompress: "gzip"`, a `.gz`/`.tar.gz`-suffixed endpoint, an
`application/gzip` Content-Type, or `sourceConfig.format: "jsonl"`. A source
with none of these — i.e. every existing JSON, XML, or query-param source in
the fleet today — takes the IDENTICAL code path it did before this change:
`$response['encoding']` is `'UTF-8'` for any ordinary text response (the
new base64-decode branch only fires when `encoding === 'base64'`),
`isGzipPayload()`/`isTarGzEndpoint()` both return `false`, and
`sourceConfig['format']` is absent — falling straight through to the
pre-existing `json_decode` → XML-fallback → `getAllObjectsFromArray()`
sequence, byte-for-byte. Proven by dedicated regression tests (plain JSON,
plain XML) alongside the new gzip/JSONL tests, all in the same PHPUnit run.
