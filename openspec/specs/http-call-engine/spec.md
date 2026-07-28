---
status: in-progress
---

# http-call-engine Specification

## Purpose
Dispatches outbound HTTP and SOAP calls to configured sources and records each one as a CallLog. It renders templated request configuration, materialises inline certificates to temp files, enforces per-source enablement and rate limits (short-circuiting with synthetic 409/429 logs), translates HTTP verbs to per-CRUD method overrides, supports pre- and post-request support calls, and persists each call with retention-bounded expiry.

@e2e exclude backend outbound HTTP call engine + CallLog persistence (no browser UI) — covered by PHPUnit/Newman

**OpenSpec changes**
- `source-broker-credentials` (active) — Sources gain a `credentialRef` authentication option; brokered sources dispatch in-process through OpenRegister's `CredentialBrokerService` (constrained proxy, secret injected server-side, never held by OpenConnector). While active, the normative brokered-dispatch requirements (REQ-SBC-001..004) live in the change's delta spec and merge here on archive.
## Requirements
### Requirement: Outbound HTTP call orchestration with CallLog persistence (REQ-001)

The outbound HTTP call engine MUST orchestrate dispatch and CallLog persistence as follows.
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
produced by `writeFile`.

`writeFile(string $baseFileName, string $contents)` MUST generate the temp file via
`tempnam(sys_get_temp_dir(), 'oc_'.$baseFileName.'_')`, so the filename is
unpredictable (not derived from `microtime()`/`getmypid()` alone) and collision-safe
under concurrent FPM workers. The method MUST `chmod` the file to `0600` both
immediately after creation and again after `file_put_contents` (closing the race
window between file creation and the permission being asserted), so the key/cert
bytes are never readable to other local users on a shared host. If `tempnam()`
returns `false` (allocation failure), the method MUST fall back to the legacy
`<baseFileName>-<microtime><pid>` naming scheme but MUST still apply the same
`chmod(0600)` guarantee — the fallback narrows only the unpredictability property,
never the permission property. The method MUST substitute escaped newlines (`\\n`)
with literal newlines before writing the blob, so PEM content shipped as a
single-line string still parses.

`removeFile(string $filename)` (private, one file at a time) MUST silently no-op
when the path is empty, non-string, or the file no longer exists — it MUST NOT throw
or emit a warning in that case. `removeFiles(array $config)` (public, all three keys
at once) MUST be invoked after every dispatch outcome: the synchronous success path,
the `BadResponseException` catch, the `ConnectException` catch, AND — for the
asynchronous dispatch path — attached to the returned Guzzle Promise's `then`/
`otherwise` callbacks via a config-path snapshot taken before the caller can mutate
`$config`, so temp files are cleaned up exactly once regardless of dispatch mode or
outcome.

<!-- Previous behavior: writeFile() generated filenames of shape
     `<baseFileName>-<microtime><pid>` written directly to
     `BASE_FILENAME_LOCATION` with default-umask permissions (world-readable
     on many shared-hosting configurations) and no chmod call. removeFiles()
     unlinked files without existence-checking, causing a PHP warning on
     partial-cleanup paths. The asynchronous dispatch branch returned the
     Guzzle Promise immediately and never called removeFiles() at all — temp
     cert/key files leaked on disk for every async call. See #1012. -->

#### Scenario: an inline cert blob is materialised to an unpredictable, 0600-permissioned file

- **GIVEN** `$config = ['cert' => '-----BEGIN CERTIFICATE-----\n…']`
- **WHEN** `getCertificate($config)` runs
- **THEN** `$config['cert']` SHALL be mutated to a `tempnam()`-generated path under
  the system temp directory
- **AND** the named file SHALL exist on disk with the PEM content
- **AND** the file's permission mode SHALL be `0600` (owner read/write only, no group
  or world access)

#### Scenario: removeFiles deletes every materialised path without warnings on partial cleanup

- **GIVEN** a `$config` with `cert`, `ssl_key`, and `verify` all pointing at
  previously-materialised file paths, and one of the three already deleted by an
  earlier cleanup pass
- **WHEN** `removeFiles($config)` runs
- **THEN** the two remaining files SHALL be `unlink`-ed
- **AND** no PHP warning SHALL be emitted for the already-missing file
- **AND** the method SHALL return without exception

#### Scenario: asynchronous dispatch still cleans up temp cert files on both success and rejection

- **GIVEN** an asynchronous call configured with an inline `cert` blob
- **WHEN** the returned Promise settles, whether fulfilled or rejected
- **THEN** the materialised temp cert file SHALL be removed from disk in both cases

#### Notes

- `writeFile` uses `tempnam()` sourced from `sys_get_temp_dir()` — the shared system
  temp directory, not a dedicated OpenConnector-owned subdirectory. The
  unpredictable-name + `0600`-permission combination closes the specific
  world-readability risk that made #1012 CRITICAL; a dedicated
  `chmod(0700)` subdirectory remains a deferred hardening option (see
  `secret-hygiene` proposal Open Questions), not implemented by this delta.

### Requirement: Source rate-limit tracking across dispatches (REQ-003)

The engine MUST track source rate-limit state across dispatches as follows.
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

The engine MUST invoke SOAP sources via WSDL as follows.
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

### Requirement: REQ-006 — CallLog request/response redaction before persistence

The outbound HTTP call engine SHALL redact secret-shaped values from a
`call_log` object BEFORE it is persisted via `saveObject()`, so that no
plaintext credential is ever written to storage, regardless of who later
reads the CallLog (including a direct OpenRegister object read that bypasses
any endpoint-level filtering — see this capability's Notes on the
`authentication`-substring-strip in REQ-001, which only protects the
outbound Guzzle `$config`, not the persisted log). Redaction SHALL use the
same `SensitiveFieldRegistry` shared with `configuration-export-import`'s
export-time redaction (see `configuration-export-import#REQ-005`), so header
names, query/form parameter names, and secret-value detection are governed by
one pattern across the whole application.

Specifically, `CallService::buildResponseData()` SHALL, before returning the
structured `request`/`response` array that `buildAndPersistCallLog()` persists:
- Redact the following request headers by exact name (case-insensitive):
  `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, replacing
  the header value with the placeholder `***REDACTED***`.
- Redact any request header, query parameter, or `form_params` entry whose
  name matches the shared sensitive-name pattern (covers `X-Api-Key`,
  `X-Auth-Token`, `client_secret`, etc.).
- Redact the Guzzle `auth` (basic-auth `[user, pass]`) array entirely.
- Redact secret-shaped query-string parameters from the persisted request
  URL.
- Redact `cert` / `ssl_key` config values (which by this point are filesystem
  paths to already-cleaned-up temp files, not raw key material — but SHALL
  still be replaced with the placeholder rather than the path, which is
  otherwise meaningless once the file is removed).
- Scrub every literal secret value collected from the above locations out of
  the response body before it is persisted (covers upstream APIs that echo
  the request, e.g. an error response embedding the submitted Authorization
  header or token).

Redaction SHALL be irreversible masking (the literal string `***REDACTED***`),
never encryption or another reversible transform. The actual outbound HTTP
call SHALL be dispatched with the real, unredacted secret values — redaction
applies ONLY to the copy of the config that is written into the persisted
CallLog, never to the live request.

This requirement applies identically on the brokered-dispatch path
(`source-broker-credentials`'s `BrokeredCallService`) and the legacy
Guzzle/SOAP path, since both converge on `buildResponseData()` /
`buildAndPersistCallLog()`.

#### Scenario: a CallLog written after an authenticated call contains no plaintext Authorization header

- GIVEN a Source configured with `configuration.headers.Authorization = "Bearer live-secret-token-123"`
- WHEN `CallService::call()` dispatches a request to that source and persists the resulting `call_log`
- THEN the persisted `call_log.request.headers.Authorization` value SHALL be `***REDACTED***`
- AND the string `live-secret-token-123` SHALL NOT appear anywhere in the persisted `call_log` object
- AND the actual outbound HTTP request SHALL have carried the real `Bearer live-secret-token-123` header

#### Scenario: a secret echoed in the response body is scrubbed before persistence

- GIVEN a Source call configured with a `client_secret` form parameter whose value is `super-secret-value`
- AND the upstream error response body echoes the submitted parameters, including `super-secret-value`
- WHEN the call fails and `logBody = true` causes the response body to be persisted
- THEN the persisted `call_log.response.body` SHALL NOT contain the substring `super-secret-value`
- AND occurrences of that value SHALL be replaced with `***REDACTED***`

#### Scenario: a secret-bearing query-string parameter is redacted from the persisted URL

- GIVEN a call dispatched to `https://api.example.test/things?api_key=live_abc123&page=2`
- WHEN the resulting CallLog is persisted
- THEN `call_log.request.url` SHALL be `https://api.example.test/things?api_key=***REDACTED***&page=2`
- AND non-secret query parameters (e.g. `page`) SHALL be retained unmodified

#### Scenario: the brokered-dispatch path redacts identically to the legacy Guzzle path

- GIVEN a `credentialRef` Source dispatched through `BrokeredCallService`
- WHEN the resulting CallLog is persisted
- THEN the same header/query/body redaction rules SHALL apply as for a
  non-brokered Source, with the same `***REDACTED***` placeholder

### Requirement: Configurable retry policy for outbound dispatch (REQ-007)

`CallService::call(...)` MUST resolve an effective `RetryPolicy` for every
dispatch by merging, in order (later layers override earlier ones on a
per-key basis): a built-in default of `{maxAttempts: 1, backoffStrategy:
"fixed", baseDelayMs: 500, maxDelayMs: 30000, jitter: false,
retryableStatusCodes: [429, 502, 503, 504], retryOnTimeout: false}`; the
dispatching Source's `retryPolicy` object field; and, when present, the
caller-supplied `$config['retryPolicy']` override (populated by
`SynchronizationService` from `Synchronization.retryPolicyOverride` when a
call is made in a synchronization context). When the effective
`maxAttempts` is `1` (the default, and the value for every Source that has
not configured a `retryPolicy`), dispatch behavior MUST be identical to the
pre-existing single-attempt behavior — no new delay, no new CallLog shape
change.

When `maxAttempts > 1`, `CallService` MUST retry a dispatch whose outcome is
either an HTTP response whose status code is in `retryableStatusCodes`, or a
transport-level exception when `retryOnTimeout === true`, up to
`maxAttempts` total attempts, sleeping between attempts for a delay computed
from `backoffStrategy`:
- `fixed`: `delayMs = baseDelayMs`
- `exponential`: `delayMs = min(baseDelayMs * 2^(attempt-1), maxDelayMs)`
When `jitter === true`, the computed delay MUST be adjusted by ±10% using a
uniform random offset (mirroring `PdokConnector::sleepBackoff()`). Only the
CallLog for the **final** attempt MUST be persisted; intermediate retried
attempts MUST NOT each produce a separate CallLog row.

#### Scenario: default policy preserves single-attempt behavior

- **GIVEN** a Source with no `retryPolicy` configured AND an upstream that
  returns `503` on every call
- **WHEN** `call(...)` runs
- **THEN** exactly one HTTP request SHALL be dispatched
- **AND** the persisted `call_log.statusCode` SHALL be `503`

#### Scenario: exponential backoff retries a 503 up to maxAttempts

- **GIVEN** a Source with `retryPolicy = {maxAttempts: 3, backoffStrategy:
  "exponential", baseDelayMs: 100, maxDelayMs: 1000, jitter: false,
  retryableStatusCodes: [503]}` AND an upstream returning `503` on the first
  two calls and `200` on the third
- **WHEN** `call(...)` runs
- **THEN** three HTTP requests SHALL be dispatched, with delays of ~100ms
  then ~200ms between them
- **AND** the persisted `call_log.statusCode` SHALL be `200`

#### Scenario: a non-retryable status code returns immediately

- **GIVEN** a Source with `retryPolicy = {maxAttempts: 3, retryableStatusCodes:
  [429, 503]}` AND an upstream returning `404`
- **WHEN** `call(...)` runs
- **THEN** exactly one HTTP request SHALL be dispatched (404 is not in
  `retryableStatusCodes`)
- **AND** the persisted `call_log.statusCode` SHALL be `404`

#### Scenario: synchronization-level override widens the retryable set

- **GIVEN** a Source with `retryPolicy = {maxAttempts: 1}` AND a
  Synchronization with `retryPolicyOverride = {maxAttempts: 2,
  retryableStatusCodes: [500]}`
- **WHEN** the synchronization dispatches a call that returns `500` then `200`
- **THEN** two HTTP requests SHALL be dispatched for that call
- **AND** a direct (non-synchronization) call against the same Source SHALL
  still use `maxAttempts: 1`

### Requirement: Per-source circuit breaker generalized into CallService (REQ-008)

`CallService` MUST maintain a per-Source circuit breaker persisted on the
`source` OR object via `circuitBreakerState` (`closed|open`),
`circuitBreakerFailureCount`, `circuitBreakerOpenedAt`, and
`circuitBreakerLastProbeAt`, using the configurable
`circuitBreakerThreshold` (default 5) and `circuitBreakerCooldownSeconds`
(default 30) fields on the same Source. Before every dispatch, the engine
MUST evaluate the breaker: when `circuitBreakerState === 'open'` and fewer
than `circuitBreakerCooldownSeconds` have elapsed since
`circuitBreakerOpenedAt`, the call MUST be short-circuited with a synthetic
`call_log` (`statusCode: 503`, `statusMessage: "Circuit breaker is open for
this source"`) and no HTTP request MUST be dispatched. When
`circuitBreakerCooldownSeconds` have elapsed, the breaker is treated as
half-open and exactly the next dispatch is allowed through as a probe (not
persisted as a distinct `half-open` state value). A retryable failure (per
REQ-007) MUST increment `circuitBreakerFailureCount`; when the count reaches
`circuitBreakerThreshold`, the engine MUST set `circuitBreakerState = 'open'`
and `circuitBreakerOpenedAt = now()`. Any successful (non-retryable, non-4xx
excluding configured retryable codes) response MUST reset
`circuitBreakerState = 'closed'` and `circuitBreakerFailureCount = 0`.

#### Scenario: five consecutive failures open the breaker

- **GIVEN** a Source with default breaker thresholds and
  `retryPolicy.retryableStatusCodes` including `503`
- **WHEN** five consecutive calls each return `503`
- **THEN** after the fifth failure `circuitBreakerState` SHALL be `open` AND
  `circuitBreakerOpenedAt` SHALL be set

#### Scenario: an open breaker short-circuits without dispatching

- **GIVEN** a Source with `circuitBreakerState = 'open'` and
  `circuitBreakerOpenedAt` 10 seconds ago (cooldown 30s)
- **WHEN** `call(...)` runs
- **THEN** no HTTP request SHALL be dispatched
- **AND** a `call_log` SHALL be persisted with `statusCode = 503` and message
  `"Circuit breaker is open for this source"`

#### Scenario: a successful half-open probe closes the breaker

- **GIVEN** a Source with `circuitBreakerState = 'open'` and
  `circuitBreakerOpenedAt` 35 seconds ago (cooldown 30s)
- **WHEN** `call(...)` runs AND the upstream returns `200`
- **THEN** exactly one HTTP request SHALL be dispatched (the probe)
- **AND** `circuitBreakerState` SHALL be reset to `closed` with
  `circuitBreakerFailureCount = 0`

#### Scenario: a failed half-open probe reopens the breaker immediately

- **GIVEN** a Source with `circuitBreakerState = 'open'` and
  `circuitBreakerOpenedAt` 35 seconds ago (cooldown 30s)
- **WHEN** `call(...)` runs AND the upstream again returns `503`
- **THEN** `circuitBreakerState` SHALL remain/return to `open` AND
  `circuitBreakerOpenedAt` SHALL be reset to the probe's timestamp

#### Notes

- The half-open probe guard (`circuitBreakerLastProbeAt`) is best-effort:
  under concurrent requests during the cooldown window, more than one
  request may treat itself as "the" probe. This is an accepted limitation
  (see design.md Decision 2/Trade-offs), not a distributed-lock guarantee.

### Requirement: Manual circuit breaker trip and reset (REQ-009)

The engine MUST expose admin-only, CSRF-protected endpoints
`POST /api/sources/{id}/circuit-breaker/trip` and
`POST /api/sources/{id}/circuit-breaker/reset` on `SourcesController`. Trip
MUST set `circuitBreakerState = 'open'`, `circuitBreakerOpenedAt = now()`,
and `circuitBreakerFailureCount = circuitBreakerThreshold` regardless of
prior state. Reset MUST set `circuitBreakerState = 'closed'`,
`circuitBreakerFailureCount = 0`, `circuitBreakerOpenedAt = null`. Both
endpoints MUST return `404` for an unknown source id and MUST NOT carry
`@NoAdminRequired` or `@NoCSRFRequired`.

#### Scenario: an admin manually trips the breaker for a misbehaving upstream

- **GIVEN** a healthy Source (`circuitBreakerState = 'closed'`)
- **WHEN** an admin calls `POST .../sources/{id}/circuit-breaker/trip`
- **THEN** the Source's `circuitBreakerState` SHALL become `open`
- **AND** subsequent calls to that Source SHALL short-circuit per REQ-008
  until the cooldown elapses or an admin resets it

#### Scenario: an admin manually resets an open breaker

- **GIVEN** a Source with `circuitBreakerState = 'open'`
- **WHEN** an admin calls `POST .../sources/{id}/circuit-breaker/reset`
- **THEN** the Source's `circuitBreakerState` SHALL become `closed` with
  `circuitBreakerFailureCount = 0`
- **AND** the next call SHALL dispatch normally (no short-circuit)

#### Scenario: a non-admin is rejected

- **GIVEN** an authenticated non-admin user
- **WHEN** they call either circuit-breaker endpoint
- **THEN** the request SHALL be rejected by NC's admin requirement

### Requirement: credentialRef source authentication contract (REQ-SBC-001)

The engine MUST accept a `credentialRef` object under a Source's
`configuration.authentication` in exactly one of two shapes:
`{"credentialId": "<uuid>"}` (primary) or `{"credentialName": "<name>"}`
(convenience). When `credentialRef` is
present, the engine MUST reject the call as a hard config error (synthetic
409 CallLog via `saveEarlyErrorLog()`) if any sibling field exists under
`authentication` besides `credentialRef`, if both `credentialId` and
`credentialName` are set, or if the set value is empty. Embedded secret
fields MUST NOT be merged, rendered, or dispatched for a `credentialRef`
source under any circumstance. A `credentialName` MUST be resolved at call
time against the acting user's OR `brokeredcredential` metadata objects;
exactly one match resolves to its `credentialId`; zero or multiple matches
MUST be a hard config error naming the reference and the match count — never
a guess.

#### Scenario: a clean credentialId ref is accepted

- **GIVEN** a source whose `configuration.authentication` is exactly
  `{"credentialRef": {"credentialId": "00000000-0000-0000-0000-000000000000"}}`
- **WHEN** `CallService::call(...)` runs
- **THEN** the call SHALL proceed to brokered dispatch (REQ-SBC-002)
- **AND** no `authentication` material SHALL appear in the outbound request config
- @e2e exclude backend config validation — covered by PHPUnit

#### Scenario: sibling embedded secret next to credentialRef is a hard config error

- **GIVEN** `configuration.authentication = {"credentialRef": {"credentialId":
  "00000000-0000-0000-0000-000000000000"}, "client_secret": "YOUR_API_KEY_HERE"}`
- **WHEN** `call(...)` runs
- **THEN** a synthetic 409 `call_log` SHALL be persisted with an actionable
  message stating embedded secrets are forbidden alongside `credentialRef`
- **AND** no outbound request SHALL be dispatched (neither brokered nor Guzzle)
- @e2e exclude backend config validation — covered by PHPUnit

#### Scenario: ambiguous credentialName is a hard config error, never a guess

- **GIVEN** `credentialRef = {"credentialName": "doffin-subscription"}` AND the
  acting user owns two `brokeredcredential` objects with that name
- **WHEN** `call(...)` runs
- **THEN** a synthetic 409 `call_log` SHALL be persisted naming the reference
  and the match count (2)
- **AND** no outbound request SHALL be dispatched
- @e2e exclude backend config validation — covered by PHPUnit

### Requirement: Brokered dispatch through CredentialBrokerService (REQ-SBC-002)

`CallService::dispatchRequest()` MUST route a source whose merged
configuration carries `authentication.credentialRef` IN-PROCESS through
`CredentialBrokerService::request(credentialId, appId: 'openconnector',
method, path, headers, body)` instead of the internal Guzzle client, after
guarding availability via `class_exists` on the broker class AND
`IAppManager::isEnabledForUser('openregister')`. The engine MUST derive
`path` as the path + query-string portion of the composed URL (`location` +
`endpoint`, with `config['query']` serialised into the query string) — the
provider catalogue's host-lock is the sole authority for the target host.
The broker's `array{status, headers, body}` return MUST be adapted to a
PSR-7 response so `buildResponseData()`, `buildAndPersistCallLog()`, and
`sourceRateLimit()` operate unchanged; an upstream non-2xx status returned by
the broker is a completed call and MUST flow through as a normal CallLog with
that status. Pagination, rate-limiting, and retry logic MUST remain in
OpenConnector: each page fetched by
`SynchronizationService::fetchSinglePageData()` is one brokered request. In
v1 the engine MUST reject as a 409 config error: `credentialRef` on
`type: soap` sources, `asynchronous=true` dispatch, and `cert`/`ssl_key`
config alongside `credentialRef`.

#### Scenario: a brokered call bypasses Guzzle and persists a normal CallLog

- **GIVEN** a healthy source with a valid `credentialRef` AND the broker
  reachable in-process AND an upstream 200 response
- **WHEN** `call(...)` runs
- **THEN** `CredentialBrokerService::request(...)` SHALL be invoked with
  `appId = 'openconnector'` and the derived path + query
- **AND** the internal Guzzle client SHALL NOT be invoked
- **AND** a `call_log` SHALL be persisted with the same envelope shape as a
  Guzzle-path call (statusCode 200, headers, body, responseTime, retention)
- @e2e exclude backend dispatch plumbing — covered by PHPUnit

#### Scenario: each synchronization page is one brokered request

- **GIVEN** a synchronization against a brokered source whose upstream
  paginates across 3 pages
- **WHEN** the synchronization runs
- **THEN** the broker SHALL be invoked exactly 3 times (one per page)
- **AND** rate-limit headers returned by the broker SHALL feed the engine's
  existing `sourceRateLimit()` tracking
- @e2e exclude backend sync pagination — covered by PHPUnit

#### Scenario: upstream 404 through the broker is a completed call

- **GIVEN** a brokered source AND the provider upstream returns 404
- **WHEN** `call(...)` runs
- **THEN** a `call_log` SHALL be persisted with `statusCode = 404` (not a
  broker refusal, not a config error)
- @e2e exclude backend dispatch plumbing — covered by PHPUnit

### Requirement: Acting user for sessionless brokered calls (REQ-SBC-003)

Interactive brokered calls MUST rely on the broker's session-derived owner
guard. Background executions (cron `JobTask` → synchronizations — no user
session) MUST pass `actingUserId` = the referenced credential's owner via the
broker's optional acting-user parameter for in-process trusted callers
(cross-repo: specced in openregister change `credential-doriath-leaf`). The
acting-user parameter substitutes only the session identity: allowedApps
(which MUST include `openconnector`), provider allowRules, and host-lock
remain enforced by the broker. When the deployed broker does not yet expose
the acting-user parameter, a sessionless brokered call MUST soft-fail as a
409 config error (feature-detected — never a PHP type error).

#### Scenario: a background sync brokered call passes the credential owner

- **GIVEN** a synchronization job running from cron with no user session AND a
  source with a valid `credentialRef`
- **WHEN** the job dispatches a page request
- **THEN** the broker SHALL be invoked with `actingUserId` = the credential's
  owner
- **AND** the broker's allowedApps / allowRules / host-lock guards SHALL still
  apply
- @e2e exclude backend cron execution — covered by PHPUnit

#### Scenario: older broker without acting-user support soft-fails sessionless calls

- **GIVEN** a deployed OpenRegister whose `request()` has no acting-user
  parameter AND a sessionless brokered call
- **WHEN** the job dispatches
- **THEN** a synthetic 409 `call_log` SHALL be persisted with an actionable
  message about the OpenRegister version requirement
- **AND** no fallback dispatch SHALL occur
- @e2e exclude backend cron execution — covered by PHPUnit

### Requirement: Secret hygiene and refusal logging for brokered calls (REQ-SBC-004)

The brokered credential's secret value MUST NEVER appear in source
configuration, synchronization logs, call logs, or error messages — with
brokering the secret never enters the OpenConnector process. Broker refusals
(`CredentialAccessDeniedException`) MUST be persisted as 403 CallLogs whose
statusMessage carries the broker-surfaced guard name (e.g. `allowedApps`,
with the actionable hint that the credential's allowedApps must include
`openconnector`) and MUST NOT carry the request payload. Broker transport
failures (`CredentialUpstreamException`) MUST be persisted as 502 CallLogs.
When the broker classes are absent, the openregister app is disabled, or the
referenced credential no longer exists, the call MUST fail with a clear 409
config-error CallLog; the engine MUST NOT fall back to embedded secrets.

#### Scenario: a broker 403 logs the guard name, not the payload

- **GIVEN** a brokered source whose credential's allowedApps does not include
  `openconnector`
- **WHEN** `call(...)` runs
- **THEN** a `call_log` SHALL be persisted with `statusCode = 403` and a
  statusMessage naming the failing guard and the allowedApps remedy
- **AND** neither the request payload nor any secret material SHALL appear in
  the log or error message
- @e2e exclude backend error mapping — covered by PHPUnit

#### Scenario: broker absent soft-fails with a config error, no fallback

- **GIVEN** a source with a `credentialRef` AND the openregister app disabled
- **WHEN** `call(...)` runs
- **THEN** a synthetic 409 `call_log` SHALL be persisted stating the credential
  broker is unavailable
- **AND** no outbound request SHALL be dispatched with embedded secrets
- @e2e exclude backend error mapping — covered by PHPUnit

#### Scenario: deleted credential soft-fails with a config error

- **GIVEN** a source referencing `credentialId =
  00000000-0000-0000-0000-000000000000` that no longer exists
- **WHEN** `call(...)` runs
- **THEN** a synthetic 409 `call_log` SHALL be persisted naming the missing
  reference
- **AND** no outbound request SHALL be dispatched
- @e2e exclude backend error mapping — covered by PHPUnit

### Requirement: POST body sources and body-based pagination (REQ-010)

`CallService::call()` MUST resolve the effective HTTP method
(`decideMethod()`) and strip the CRUD-override keys (`createMethod`/
`updateMethod`/`destroyMethod`/`listMethod`/`readMethod`) AFTER merging the
source's own `configuration` (`mergeSourceConfiguration()`), so that a
Source-level method override takes effect for a call with no explicit
call-time `method`/`config` override — matching how `SynchronizationService`
invokes `call()` for every list/fetch call. A source's static
`configuration.body` (a JSON string template) MUST be sent as the outbound
request body unchanged. When `Synchronization.sourceConfig.paginationIn` is
`"body"` (default: `"query"`, unchanged from the pre-existing behaviour),
`CallService::normaliseRequestConfig()` MUST substitute the current page
value into the request body at the `paginationQuery` dot-path instead of the
query string: it MUST decode `config['body']` as JSON (starting from an
empty object when the body is absent or not valid JSON, rather than
dropping the page value), set the page value at that path, and re-encode.
Because the source's static body template is re-merged fresh on every call
(never accumulated across pages), this substitution MUST NOT compound
across successive page fetches. A synchronization with no `paginationIn`
MUST continue substituting the page value into the query string exactly as
before.

#### Scenario: a Source-level method override promotes a default-GET call to POST

- **GIVEN** a Source whose `configuration.listMethod` is `"POST"`
- **WHEN** `call(source, endpoint)` runs with no explicit `method`/`config`
  override (the shape `SynchronizationService::callSourceObject()` always
  uses)
- **THEN** the dispatched method SHALL be `"POST"`
- **AND** the persisted `call_log.request.method` SHALL be `"POST"`
- **AND** `call_log.request` SHALL NOT carry a `listMethod` key (stripped
  before persistence)

#### Scenario: the source's static body template is sent as the request body

- **GIVEN** the same POST-method Source, with `configuration.body` set to a
  static JSON string template
- **WHEN** `call(...)` runs
- **THEN** the dispatched request body SHALL equal that JSON string
  byte-for-byte

#### Scenario: a source without a method override keeps dispatching its default method

- **GIVEN** a Source with no `createMethod`/`updateMethod`/`destroyMethod`/
  `listMethod`/`readMethod` in its `configuration`
- **WHEN** `call(source, endpoint)` runs with no explicit `method` override
- **THEN** the dispatched method SHALL be the caller's default (`"GET"` for
  a list/fetch call), unchanged from pre-existing behaviour

#### Scenario: body-based pagination substitutes the page value across successive pages

- **GIVEN** a synchronization with `sourceConfig.paginationIn: "body"` and
  `sourceConfig.paginationQuery: "page"`, and a Source whose
  `configuration.body` is a static JSON template containing `"page": 1`
  among other fields
- **WHEN** three consecutive page fetches run (pages 1, 2, 3)
- **THEN** each dispatched request body SHALL decode to the same template
  with `page` set to that request's page number
- **AND** every other field in the template SHALL be unchanged across all
  three requests

#### Scenario: body-based pagination without a static body template still sets the page key

- **GIVEN** `sourceConfig.paginationIn: "body"` but the Source has no
  `configuration.body`
- **WHEN** a page fetch runs
- **THEN** the dispatched request body SHALL decode to `{"page": N}` (N
  being the current page), rather than silently dropping the pagination
  directive

#### Scenario: query-string pagination is unaffected when paginationIn is omitted

- **GIVEN** a synchronization with no `sourceConfig.paginationIn`
- **WHEN** a page fetch runs
- **THEN** the page value SHALL be substituted into the query string at
  `paginationQuery`, exactly as before this change
- **AND** no `body` key SHALL be introduced by the pagination step
- @e2e exclude backend regression — covered by PHPUnit

**Notes:**

- This closes a latent ordering bug, not just an additive feature:
  `decideMethod()` previously ran BEFORE `mergeSourceConfiguration()` (see
  REQ-001's documented Phase 2 vs Phase 7), so a Source's own method
  override was invisible unless the CALLER separately passed a matching
  `method`/`config` override at call time. `SynchronizationService` never
  did that, so this override effectively never worked end-to-end for a
  synchronization-driven fetch before this change.
- The override-key `unset()` moved together with `decideMethod()`; this
  also fixes a secondary latent leak where a source-only method override
  was never stripped from the config before persistence.
- `paginationIn` lives on `Synchronization.sourceConfig`, alongside the
  pre-existing `paginationQuery`/`maxPages`/`resultsPosition` — a
  per-synchronization pagination-strategy concern, not a Source-level
  setting.
- Applies identically on the brokered-credential dispatch path
  (`source-broker-credentials`), since both changes run inside
  `CallService::call()` before the Guzzle-vs-broker dispatch branch.
- Methods touched: `CallService::call()` (phase reorder only, no signature
  change), new `CallService::applyBodyPagination()`,
  `SynchronizationService::getNextPage()` (adds `paginationIn` to the
  `pagination` sub-array it already builds).

### Requirement: Trace-scoped call correlation via call_log.sessionId (REQ-011)

`CallService::buildAndPersistCallLog()` MUST set the persisted
`call_log.sessionId` field to the active `ExecutionTraceContext`'s
`traceId` (per `execution-trace` REQ-001) when a trace context is present
for the call, and MUST leave `sessionId` unset — exactly as it is today,
byte-for-byte — when no trace context is present. `CallService::call()`
MUST hand the already-redacted `request`/`response` array produced by
`buildResponseData()` (per REQ-006) to the active `ExecutionTraceContext`,
when present, as the `call` step's snapshot, WITHOUT running a second,
independent redaction pass over the same data — the trace layer MUST NOT
re-derive redaction from the pre-redaction config.

@e2e exclude backend dispatch plumbing — covered by PHPUnit

#### Scenario: sessionId is populated for a call inside a traced execution

- **GIVEN** a `CallService::call()` dispatch made from within a traced
  endpoint execution (an active `ExecutionTraceContext` with `traceId =
  'abc-123'`)
- **WHEN** the call completes and the `call_log` is persisted
- **THEN** `call_log.sessionId` equals `'abc-123'`

#### Scenario: sessionId stays unset for an untraced call

- **GIVEN** a `CallService::call()` dispatch with no active
  `ExecutionTraceContext` (e.g. `SourcesController::test()`)
- **WHEN** the call completes and the `call_log` is persisted
- **THEN** `call_log.sessionId` is absent, unchanged from pre-existing
  behaviour

#### Scenario: the trace's call step reuses the persisted call_log's redacted data

- **GIVEN** a traced call to a source configured with a `client_secret`
  form parameter
- **WHEN** the call completes
- **THEN** the `execution_trace`'s `call` step `output` is the same
  redacted `request`/`response` array persisted to `call_log` (per REQ-006)
  — no second redaction implementation runs, and no plaintext secret exists
  in either location

#### Notes

- This requirement changes only the previously-always-absent `sessionId`
  field's value when a trace context exists; it does not alter REQ-001's
  dispatch contract, REQ-002's certificate handling, REQ-006's redaction
  rules, REQ-007's retry policy, or REQ-008/REQ-009's circuit-breaker
  behaviour in any way.
- `sessionId` was already declared on the `call_log` schema
  ("Session token for correlating multi-call traces") but had zero write
  sites before this change — see `design.md` Decision 5 for why this reuses
  the existing field rather than adding a new column.

