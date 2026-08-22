# http-call-engine Specification Delta

## ADDED Requirements

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

## MODIFIED Requirements

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
  temp directory, not a dedicated Integriq-owned subdirectory. The
  unpredictable-name + `0600`-permission combination closes the specific
  world-readability risk that made #1012 CRITICAL; a dedicated
  `chmod(0700)` subdirectory remains a deferred hardening option (see
  `secret-hygiene` proposal Open Questions), not implemented by this delta.
