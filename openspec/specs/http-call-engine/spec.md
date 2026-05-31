# http-call-engine Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-http-call-engine. Update Purpose after archive.
## Requirements
### Requirement: Outbound HTTP call orchestration with CallLog persistence (REQ-001)

`CallService::call(ObjectEntity $source, string $endpoint, string $method, array $config,
bool $asynchronous, bool $createCertificates, bool $overruleAuth, bool $read, bool
$runningSupportRequest)` MUST validate that `$source->getObject().isEnabled === true`
and `$source->getObject().location` is non-empty before dispatching; on either guard
failure it MUST persist a synthetic `call_log` (status 409) and return that entity
without an outbound HTTP call. When the source's rate-limit window is exhausted
(`rateLimitRemaining <= 0`), the method MUST persist a synthetic 429 `call_log` and
return. The method MUST flatten dotted config keys via `applyConfigDot`, render every
templated config value via `renderConfiguration` (Twig over `{{ ... }}`), invoke
`getCertificate` to materialise any inline cert/ssl_key/verify blobs to temp files,
strip every config key whose name (case-insensitively) contains `authentication`
before dispatch, and resolve the effective HTTP method via `decideMethod` from
configured per-CRUD-operation overrides (`createMethod` / `updateMethod` /
`destroyMethod` / `listMethod` / `readMethod`). For non-async, non-SOAP sources the
method MUST dispatch via the internal Guzzle client with `http_errors = false`; on
`BadResponseException` it MUST capture the response; on `ConnectException` it MUST
synthesise a 503 response. For SOAP sources (`sourceData.type === 'soap'`) the
method MUST delegate to `SOAPService::callSoapSource`. After dispatch the method MUST
remove any materialised certificate temp files, build a `call_log` object with
request + response payload (the response body is omitted unless `statusCode >= 400 &&
< 600` AND `logBody === true`, in which case it is kept), persist via the OR
`call_log` schema with `expires` computed from the maximum of source-level retention
and global retention, and return the persisted `ObjectEntity`. When `config.preRequest`
or `config.postRequest` is set AND `runningSupportRequest === false`, the method MUST
recursively call itself with `runningSupportRequest = true` before/after the main call
respectively, so support-call payloads do not themselves trigger nested support calls.

#### Scenario: a disabled source short-circuits with a synthetic 409 CallLog

- **GIVEN** a Source ObjectEntity with `isEnabled = false`
- **WHEN** `call($source, $endpoint, $method, $config)` runs
- **THEN** the Guzzle client SHALL NOT be invoked
- **AND** a `call_log` SHALL be persisted with `statusCode = 409`, `statusMessage =
  "This source is not enabled"`, and the source UUID

#### Scenario: a rate-limited source short-circuits with a synthetic 429 CallLog

- **GIVEN** a Source with `rateLimitRemaining <= 0` and `rateLimitReset` in the future
- **WHEN** `call(...)` runs
- **THEN** a `call_log` SHALL be persisted with `statusCode = 429` and the source UUID
- **AND** the Guzzle client SHALL NOT be invoked

#### Scenario: a successful REST call persists a CallLog without the response body by default

- **GIVEN** a healthy Source AND a 200 OK upstream response
- **WHEN** `call(...)` runs with `config.logBody = false` (or unset)
- **THEN** a `call_log` SHALL be persisted whose `response.body` field is removed
  before persistence
- **AND** the in-memory entity returned to the caller SHALL still carry the full
  `response.body` for downstream processing

#### Scenario: a 4xx/5xx with logBody=true persists the response body

- **GIVEN** a Source with `config.logBody = true` AND an upstream 500 response
- **WHEN** `call(...)` runs
- **THEN** the persisted `call_log.response.body` SHALL contain the response body
  (UTF-8 verbatim, or base64-encoded if the body fails UTF-8 validation)

#### Scenario: preRequest support call does not trigger its own support call

- **GIVEN** `config.preRequest = {endpoint: 'x', config: {...}}` AND the main call
  context has `runningSupportRequest = false`
- **WHEN** `call(...)` runs
- **THEN** the inner `call(...)` invocation SHALL pass `runningSupportRequest = true`
- **AND** the inner invocation SHALL NOT recurse into its own preRequest/postRequest

#### Notes

- `decideMethod` translates HTTP verbs to per-CRUD-operation overrides (POST →
  `createMethod`, GET → `listMethod` or `readMethod` depending on `$read`, etc.).
  Unknown verbs fall through to `default` and return `$default` unchanged. The
  override unset on lines 421-422 happens AFTER `decideMethod` already returned, so
  the actual dispatched method is not affected by the unset — the unset only prevents
  the verbs from showing up in the persisted `request.method` field.
- `renderValue` recursively walks arrays and renders strings that contain both `{{`
  and `}}`. Non-string non-array leaf values are passed through untouched (observed:
  the inner `array_map` guards on `is_string === false && is_array === false` to
  return the value verbatim).
- The authentication-secrets stripping at line 562-568 uses
  `str_contains(strtolower($key), 'authentication')`. Any config key whose name
  contains the substring "authentication" anywhere is removed before dispatch.
  Operators relying on this for secrets-hiding need to know it is a substring match,
  not an exact field match. Observed.

### Requirement: Certificate materialisation and cleanup (REQ-002)

`CallService::getCertificate(array &$config)` MUST detect inline certificate/SSL-key
payloads in the caller's `$config` array and materialise them to temp files on disk so
the Guzzle client can pass file paths to `curl`. The method MUST recognise three
config keys — `cert`, `ssl_key`, and `verify` — each of which may be either a string
(single PEM blob) or a `[blob, passphrase]` tuple. For each materialised file the
method MUST mutate `$config` in place, replacing the inline blob with the file path
produced by `writeFile`. `removeFiles(array $config)` MUST be invoked after every
dispatch (success path, `BadResponseException`, and `ConnectException`) and MUST
`unlink` every file path it finds at the same three config keys. `writeFile`
MUST generate a unique filename of shape `<baseFileName>-<microtime><pid>` and MUST
substitute escaped newlines (`\\n`) with literal newlines before writing the blob,
so PEM content shipped as a single-line string still parses.

#### Scenario: an inline cert blob is materialised to a uniquely-named file

- **GIVEN** `$config = ['cert' => '-----BEGIN CERTIFICATE-----\n…']`
- **WHEN** `getCertificate($config)` runs
- **THEN** `$config['cert']` SHALL be mutated to a string of shape
  `certificate-<microtime><pid>`
- **AND** the named file SHALL exist on disk with the PEM content

#### Scenario: removeFiles deletes every materialised path

- **GIVEN** a `$config` with `cert`, `ssl_key`, and `verify` all pointing at
  previously-materialised file paths
- **WHEN** `removeFiles($config)` runs
- **THEN** all three files SHALL be `unlink`-ed
- **AND** the method SHALL return without exception even if a file is already missing
  (NOTE: `unlink` warning suppression is at the PHP level, not in this method — see
  Notes)

#### Notes

- `writeFile` uses `microtime().getmypid()` for the suffix. Under high concurrency
  two requests on the same FPM worker that hit `writeFile` in the same microsecond
  would collide. Observed; no locking; flagged for follow-up.
- `unlink` on a missing file emits a PHP warning. The current code does not suppress
  it; operator-visible warnings may appear in php-fpm error logs on partial-cleanup
  paths. Observed; flagged.
- `getCertificate` writes private SSL keys to `/var/tmp` (the current working
  directory of the writeFile resolution; see `BASE_FILENAME_LOCATION = "%s-%s"`).
  The location should be hardened to a chmod-0700 per-worker dir. Observed; flagged.

### Requirement: Source rate-limit tracking across dispatches (REQ-003)

`CallService::sourceRateLimit(ObjectEntity $source, array $sourceData, array $headers)`
MUST inspect the upstream response headers for `X-RateLimit-Reset`, `X-RateLimit-Limit`,
and `X-RateLimit-Remaining`; when any of those headers are present the method MUST
mirror their values onto the source's `rateLimitReset` / `rateLimitLimit` /
`rateLimitRemaining` fields. When `X-RateLimit-Reset` is absent AND the source has
no in-progress reset window AND a `rateLimitWindow` is configured, the method MUST
compute `rateLimitReset = time() + rateLimitWindow` and persist it. When
`X-RateLimit-Remaining` is absent AND a `rateLimitLimit` is configured, the method
MUST decrement the cached remaining counter by 1 (initialising from `rateLimitLimit`
on the first call). On any change the method MUST persist the source via OR
`saveObject(register='openconnector', schema='source', uuid=source.uuid)`. Finally,
when `rateLimitLimit OR rateLimitWindow` is set, the method MUST inject five
synthesised `X-RateLimit-*` headers into the response header array so downstream
consumers see a consistent rate-limit shape even when upstream did not emit one. Also,
when `CallService::call()` enters with `rateLimitReset <= time()` AND a non-null
`rateLimitRemaining`, the method MUST clear both fields and persist the source before
dispatch (rate-limit window has rolled over).

#### Scenario: upstream X-RateLimit-Reset is mirrored to the source

- **GIVEN** an upstream response with header `X-RateLimit-Reset: 1700000000`
- **WHEN** `sourceRateLimit(...)` runs
- **THEN** the source's `rateLimitReset` field SHALL be set to `1700000000`
- **AND** OR `saveObject` SHALL be called for the source

#### Scenario: rate-limit window rolls over before dispatch

- **GIVEN** a source with `rateLimitReset = (time() - 60)` (60 seconds in the past)
  AND `rateLimitRemaining = 0`
- **WHEN** `call(...)` enters
- **THEN** both fields SHALL be cleared on the source AND persisted
- **AND** the dispatch SHALL proceed (no 429 short-circuit)

#### Scenario: synthetic headers are injected when only a window is configured

- **GIVEN** a source with `rateLimitWindow = 3600` AND `rateLimitLimit = null`
- **WHEN** `sourceRateLimit(...)` returns
- **THEN** the response header array SHALL contain `X-RateLimit-Window: 3600` AND
  `X-RateLimit-Used: 1`

#### Notes

- The synthesised header values are PHP-side strings cast from int via `(string)`.
  If `$rateLimitReset` is `null`, the header value becomes the empty string `""` — an
  HTTP-spec-debatable but observably-present behaviour. Flagged.
- The decrement path (REQ-003 fourth sentence) writes the new `rateLimitRemaining`
  to the source on every call when the upstream does not send the header. This is a
  hot-path persistence; every outbound call to a configured-limit source incurs an
  OR write. Observed; flagged for follow-up if it becomes a bottleneck.

### Requirement: SOAP source invocation via WSDL (REQ-004)

`SOAPService::callSoapSource(ObjectEntity $source, string $soapAction, array $config)`
MUST extract the request body from `$config['body']` (JSON-decoded) or
`$config['json']`, set the libxml external-entity loader to a permissive callback that
returns the requested system URI verbatim, set up a SOAP engine via `setupEngine`,
invoke `$engine->request($soapAction, $body)`, then reset the libxml external-entity
loader to a null-returning callback. The method MUST decode any inline
`edcLk01.object.inhoud` field as base64 before the request (legacy CMIS document
transport). On a SOAP result with a `QueryExecute2Result.any` child, the method MUST
hand off to `parseDynamicXsd` for diffgram XSD parsing; on a result with a `FileBytes`
field that fails `json_encode`, the method MUST base64-encode `FileBytes` for safe
JSON serialisation. The method MUST always return a Guzzle `Response` of status 200
with a JSON-encoded body. `setupEngine` MUST require a `wsdl` config field
(throwing if absent), build a Psr18-backed WSDL loader provider (permanent vs.
temporary based on `config.permanentWsdl`), and assemble an `ExtSoapDriver`-backed
`SimpleEngine` with `cache_wsdl = WSDL_CACHE_NONE` and `trace = true`. `getSoapVersion`
MUST accept `'1.1'`, `'1_1'`, `'soap1.1'`, `'soap1_1'`, `'soap_1_1'`, `'SOAP_1_1'`
(returning the `SOAP_1_1` constant) and the 1.2 equivalents (returning `SOAP_1_2`);
an integer in `(0,3)` is returned as-is; any other integer throws
`BadRequestHttpException`; any other value falls through to `SOAP_1_2`.

#### Scenario: a SOAP source dispatch returns a 200 Response with JSON body

- **GIVEN** a healthy SOAP Source with a valid WSDL configured AND `soapAction = 'doThing'`
- **WHEN** `callSoapSource(...)` runs
- **THEN** the SOAP engine SHALL be invoked with `('doThing', $decodedBody)`
- **AND** the response SHALL be a Guzzle `Response` with status 200 AND a
  JSON-encoded body

#### Scenario: setupEngine without a WSDL throws

- **GIVEN** a Source whose configuration lacks a `wsdl` field
- **WHEN** `setupEngine(...)` runs
- **THEN** a `Symfony\Component\Config\Definition\Exception\Exception` SHALL be
  thrown with `message = "No wsdl provided"`

#### Scenario: getSoapVersion accepts string and integer variants

- **GIVEN** the input `'soap_1_1'`
- **WHEN** `getSoapVersion(...)` runs
- **THEN** the method SHALL return `SOAP_1_1`
- **GIVEN** the input `4`
- **WHEN** `getSoapVersion(...)` runs
- **THEN** `BadRequestHttpException` SHALL be thrown

#### Notes

- The permissive `libxml_set_external_entity_loader` callback on lines 250-253 is an
  XXE-risk surface: any external entity referenced from the SOAP response payload
  will be fetched/loaded by `simplexml_load_string` later. The reset on lines 296-300
  closes the window AFTER the request, so concurrent requests on the same FPM worker
  can race. Observed-but-suspicious; flagged with high severity.
- The hard-coded handling of `edcLk01.object.inhoud` (CMIS-StUF), `QueryExecute2Result`
  (legacy WCF service), and `FileBytes` (likely DMS export) reveals that
  `SOAPService` was built against a small number of named integrations and is not
  truly generic. New SOAP sources requiring other binary-field handling will need
  this code edited. Observed; flagged.

### Requirement: Diffgram-wrapped dynamic-XSD payload parsing (REQ-005)

`SOAPService::parseDynamicXsd(string $xmlString)` MUST wrap the payload in a synthetic
`<any>…</any>` element after substituting `NewDataSet` → `DocumentElement` in the raw
input, load the result via `DOMDocument::loadXML`, attempt schema validation
(clearing errors on failure rather than aborting), then `simplexml_load_string` the
payload with the `diffgr` namespace registered. The method MUST navigate to
`//DocumentElement` via XPath, return `null` when no `DocumentElement` is present,
and otherwise return the `QueryExecResult` child of the `DocumentElement`.

#### Scenario: a diffgram payload with QueryExecResult returns the parsed child

- **GIVEN** an `$xmlString` containing a `<NewDataSet>` envelope and a
  `<QueryExecResult>` child
- **WHEN** `parseDynamicXsd($xmlString)` runs
- **THEN** `NewDataSet` SHALL be substring-replaced with `DocumentElement`
- **AND** the method SHALL return the `QueryExecResult` `SimpleXMLElement`

#### Scenario: a payload without a DocumentElement returns null

- **GIVEN** an `$xmlString` that does not contain a `DocumentElement` XPath match
- **WHEN** `parseDynamicXsd($xmlString)` runs
- **THEN** the method SHALL return `null`

#### Notes

- Per the in-line `@TODO`, the substring `NewDataSet → DocumentElement` is fragile —
  any `NewDataSet` string appearing inside payload text would also be rewritten.
  Observed; flagged.
- `libxml_use_internal_errors(true)` is set inside this method but never restored to
  its previous state on exit. Side-effect leaks to the rest of the request. Observed;
  flagged.

