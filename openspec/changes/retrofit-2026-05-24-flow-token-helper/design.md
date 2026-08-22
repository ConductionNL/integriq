# Design — Retrofit flow-token-helper

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`lib/Service/Helper/FlowToken.php` is a mutable value-bag passed
through the integriq rule pipeline. It carries four PAIRED
slots — `request`, `response`, `syncInput`, `syncOutput` — each with
an `original` snapshot (the value as it entered the pipeline) and an
`amended` snapshot (what subsequent rules may have rewritten). The
ingest path normalises `IRequest` and `Response` objects into arrays;
the amended setters are dumb pass-throughs.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `parseContent::simplexml_load_string` | parses inbound request bodies as XML when `Content-Type` is `application/xml` / `text/xml` (or empty + content sniffs as XML) with NO `LIBXML_NONET` / `LIBXML_DTDLOAD` defence. PHP's default libxml resolves DTD entities — an attacker-controlled XML body can: (a) exfiltrate local files via `<!ENTITY xxe SYSTEM "file:///etc/passwd">` (XXE), (b) trigger out-of-band DNS / HTTP via external entities (SSRF), (c) billion-laughs DoS. This is the entry point for every XML-shaped request that hits the rule pipeline. | **HIGH — CWE-611 / OWASP A05:2021 XXE** |
| `looksLikeXml::simplexml_load_string` | same library call with same defaults, used as a probe. Even though the result is just a `bool`, parsing an attacker-controlled XML payload still resolves external entities → file read / SSRF. | **HIGH — CWE-611 via probe** |
| `getRawContent::file_get_contents('php://input')` | reads the raw request body unconditionally. Combined with the XXE above, the input is then parsed without size limits — large bodies OOM the worker. | medium — soft DoS |
| `getHeaders::HTTP_X_FORWARDED` filter | by default proxy headers (`X-Forwarded-*`, `X-Real-IP`, `X-Original-URI`) are FILTERED OUT, but the constructor call (line 257) passes `proxyHeaders: true`. Net: the headers passed to the rule pipeline include client-controllable proxy headers that downstream rules might treat as trustworthy (e.g. trusting `X-Forwarded-For` for ACL decisions). | medium — proxy-header trust drift |
| `parseContent::multipart` | `request_parse_body()` returns `[$post, $files]`; the code `file_get_contents($file['tmp_name'])` reads every uploaded file into memory and merges it into `$post`. No file-size guard, no MIME check, no path-traversal sanitiser on the filename (`$file['tmp_name']` is server-controlled but `$post` keys come from the client). | medium — soft DoS / file-name confusion |
| `parseContent::json_decode` fallback | uses `assoc=true` so JSON objects become arrays, then returns silently on `!== null`. Note that `json_decode('null', true)` returns `null`, but `json_decode('false', true)` returns `false` — the `!== null` guard means an XML payload that happens to be valid JSON `false` falls through to the XML branch, which then tries to parse `'false'` as XML. Edge case but observable. | low — edge-case priority confusion |
| `__construct::array_merge` | `array_merge($request->getParams(), $this->parseContent($request))` lets parsed body keys OVERRIDE query-string params with the same name. Documented behaviour but a footgun for downstream rule writers who expect either source to win. | low — precedence surprise |
| `setRequestOriginal::IRequest$path` | the `$path` argument is ONLY used when `requestOriginal instanceof IRequest`. If the array form is passed, the `$path` argument is silently dropped. | informational |
| `setSyncOutputOriginal` constructor coupling | The constructor (`__construct`) seeds `amended` from `original` for all four pairs — so a caller that only sets `original` later via the setter ALSO needs to re-seed `amended` or the slot stays at its previous amended value. The setters do NOT auto-seed amended. | low — coupling surprise |

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `__construct` (request ingest part) + `setRequestOriginal` + `getRequestOriginal` + private helpers `getHeaders`, `getRawContent`, `looksLikeXml`, `parseContent` |
| REQ-002 | `setResponseOriginal` + `getResponseOriginal` |
| REQ-003 | `setSyncInputOriginal` + `getSyncInputOriginal` + `setSyncOutputOriginal` + `getSyncOutputOriginal` |
| REQ-004 | `setRequestAmended` + `getRequestAmended` + `setResponseAmended` + `getResponseAmended` + `setSyncInputAmended` + `getSyncInputAmended` + `setSyncOutputAmended` + `getSyncOutputAmended` (all 8 amended setters/getters — same pass-through shape) |
| REQ-005 | `__serialize` |

REQ-001 deliberately folds the private parsing helpers because they
are only reachable from `setRequestOriginal` and their contracts
collapse into the request-shape semantics.

## What the spec deliberately does NOT cover

- The rule pipeline that consumes / mutates the FlowToken — cluster
  `rule-pipeline`, deferred.
- The synchronization engine that produces `syncInput` / `syncOutput`
  — cluster `synchronization-engine`, deferred.
- The constructor's signature (DI-flavoured but the class is not
  injected — it is `new`'d at the entry point of the rule pipeline).

## Validation

After archive, `openspec validate flow-token-helper --strict` MUST pass.
