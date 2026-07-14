# Test Plan: secret-hygiene

## Test Cases

### TC-1: Sensitive-name pattern matches all known secret field/header names
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **type**: security
- **persona**: N/A (backend unit test)
- **preconditions**: `SensitiveFieldRegistry` instantiated
- **steps**: call `isSensitiveName()` with each of: `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `client_secret`, `apikey`, `password`, `passwd`, `access_token`, `bearer`, `signature`, `assertion`, `private_key`, `username`, `authorizationHeader`, `jwt`, `jwtId`, and a non-secret control (`Accept`, `page`, `title`)
- **expected result**: every secret-shaped name returns `true`; the control names return `false`
- **test command**: `vendor/bin/phpunit --filter SensitiveFieldRegistryTest`

### TC-2: redactArray masks nested secret values without disturbing structure
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **type**: security
- **persona**: N/A
- **preconditions**: a fixture array with 3+ levels of nesting containing exactly one secret-shaped key at the deepest level
- **steps**: call `redactArray($fixture)`
- **expected result**: the deep secret value becomes `***REDACTED***`; every sibling key/value at every level is unchanged; array key order is preserved
- **test command**: `vendor/bin/phpunit --filter SensitiveFieldRegistryTest`

### TC-3: SourceHandler export redacts nested configuration via the shared registry
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **type**: security
- **persona**: N/A
- **preconditions**: a Source ObjectEntity with `apikey`, `secret`, and `configuration.headers.Authorization` set to live values
- **steps**: call `SourceHandler::export($source, $mappings)`
- **expected result**: `apikey`/`secret` absent from the returned array (top-level unset, unchanged behaviour); `configuration.headers.Authorization` equals `***REDACTED***` (new nested-registry behaviour); `configuration.headers.Accept` (a control non-secret header) unchanged
- **test command**: `vendor/bin/phpunit --filter SourceHandlerTest`

### TC-4: EndpointHandler export redacts an inline auth-override header
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **type**: security
- **persona**: N/A
- **preconditions**: an Endpoint ObjectEntity with `configuration.headers.X-Api-Key = "live_endpoint_key_123"`
- **steps**: call `EndpointHandler::export($endpoint, $mappings)`
- **expected result**: `configuration.headers.X-Api-Key` equals `***REDACTED***`; the key itself remains present
- **test command**: `vendor/bin/phpunit --filter EndpointHandlerTest`

### TC-5: MappingHandler export redacts a client_secret configuration value
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **type**: security
- **persona**: N/A
- **preconditions**: a Mapping ObjectEntity with `configuration.client_secret` set to a live value
- **steps**: call `MappingHandler::export($mapping, $mappings, $mappingIds)`
- **expected result**: `configuration.client_secret` equals `***REDACTED***`
- **test command**: `vendor/bin/phpunit --filter MappingHandlerTest`

### TC-6: RuleHandler export redacts a deeply-nested Authorization value without disturbing slug translation
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **type**: security
- **persona**: N/A
- **preconditions**: a Rule ObjectEntity with `configuration = {"action": {"headers": {"Authorization": "Bearer live_rule_token"}}, "sourceId": <a real source id present in the slug map>}`
- **steps**: call `RuleHandler::export($rule, $mappings, $mappingIds)`
- **expected result**: `configuration.action.headers.Authorization` equals `***REDACTED***`; `configuration.sourceId` is correctly translated to its slug (existing `convertIdsToSlugs()` behaviour unaffected by the new redaction pass)
- **test command**: `vendor/bin/phpunit --filter RuleHandlerTest`

### TC-7: JobHandler and SynchronizationHandler exports redact configuration secrets
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **type**: security
- **persona**: N/A
- **preconditions**: a Job ObjectEntity with `configuration.password` set, and a Synchronization ObjectEntity with `configuration.token` set
- **steps**: call `JobHandler::export(...)` and `SynchronizationHandler::export(...)` respectively
- **expected result**: `configuration.password` and `configuration.token` both equal `***REDACTED***` in their respective exports
- **test command**: `vendor/bin/phpunit --filter "JobHandlerTest|SynchronizationHandlerTest"`

### TC-8: Cross-entity export-leak regression — zero secret-shaped values survive export of all six types
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **type**: security
- **persona**: N/A
- **preconditions**: one Source, Endpoint, Mapping, Rule, Job, and Synchronization, all tagged with the same configuration id, each seeded with a distinct secret-shaped value under a differently-named field (`password`, `token`, `client_secret`, `apikey`, `Authorization` header, `Cookie` header)
- **steps**: call `ConfigurationService::exportConfiguration($configId)`; JSON-encode the result
- **expected result**: none of the six seeded plaintext values appear anywhere in the JSON-encoded export as a substring
- **test command**: `vendor/bin/phpunit --filter ConfigurationServiceTest`

### TC-9: CallLog persisted after a Guzzle-path authenticated call contains no plaintext Authorization header
- **spec_ref**: `openspec/changes/secret-hygiene/specs/http-call-engine/spec.md#requirement-req-006--calllog-requestresponse-redaction-before-persistence`
- **type**: security
- **persona**: N/A
- **preconditions**: a healthy, non-brokered Source configured with `configuration.headers.Authorization = "Bearer live-secret-token-123"`; mocked Guzzle client returning 200
- **steps**: call `CallService::call($source, ...)`
- **expected result**: the persisted `call_log.request.headers.Authorization` equals `***REDACTED***`; the literal string `live-secret-token-123` does not appear anywhere in the persisted CallLog object (assert via JSON-encoding the persisted entity and asserting the substring is absent); the mock Guzzle client's captured request config still carried the real, unredacted header (proves the live call was unaffected)
- **test command**: `vendor/bin/phpunit --filter CallServiceTest`

### TC-10: Response body echoing a submitted secret is scrubbed before persistence
- **spec_ref**: `openspec/changes/secret-hygiene/specs/http-call-engine/spec.md#requirement-req-006--calllog-requestresponse-redaction-before-persistence`
- **type**: security
- **persona**: N/A
- **preconditions**: a Source call configured with `form_params.client_secret = "super-secret-value"`; mocked upstream 500 response whose body echoes `super-secret-value`; `config.logBody = true`
- **steps**: call `CallService::call($source, ...)`
- **expected result**: `call_log.response.body` does not contain the substring `super-secret-value`; occurrences are replaced with `***REDACTED***`
- **test command**: `vendor/bin/phpunit --filter CallServiceTest`

### TC-11: Brokered-dispatch CallLog redaction parity (existing coverage, re-verified)
- **spec_ref**: `openspec/changes/secret-hygiene/specs/http-call-engine/spec.md#requirement-req-006--calllog-requestresponse-redaction-before-persistence`
- **type**: regression
- **persona**: N/A
- **preconditions**: existing `testBrokeredCallLogRedactsSecretsLikeGuzzlePath` fixture
- **steps**: run the existing test unmodified against the refactored `CallService` (post `isSecretKeyName()` extraction to `SensitiveFieldRegistry`)
- **expected result**: test still passes with zero behavioural drift
- **test command**: `vendor/bin/phpunit --filter testBrokeredCallLogRedactsSecretsLikeGuzzlePath`

### TC-12: Certificate/key/verify temp files are created with 0600 permissions
- **spec_ref**: `openspec/changes/secret-hygiene/specs/http-call-engine/spec.md#requirement-certificate-materialisation-and-cleanup-req-002`
- **type**: security
- **persona**: N/A
- **preconditions**: `$config = ['cert' => <PEM fixture>, 'ssl_key' => <key fixture>, 'verify' => <CA fixture>]`
- **steps**: call `CallService::getCertificate($config)`; inspect `fileperms()` of each materialised path
- **expected result**: `fileperms($path) & 0777 === 0600` for all three files
- **test command**: `vendor/bin/phpunit --filter CallServiceTest`

### TC-13: removeFiles() cleans up without warnings on partial-cleanup paths
- **spec_ref**: `openspec/changes/secret-hygiene/specs/http-call-engine/spec.md#requirement-certificate-materialisation-and-cleanup-req-002`
- **type**: regression
- **persona**: N/A
- **preconditions**: a config with `cert`/`ssl_key`/`verify` paths, one manually `unlink`-ed before calling `removeFiles()`
- **steps**: call `CallService::removeFiles($config)` with PHP's error handler capturing warnings
- **expected result**: the two remaining files are removed; no PHP warning is captured; no exception is thrown
- **test command**: `vendor/bin/phpunit --filter CallServiceTest`

### TC-14: AuthenticationService::getRSJWK writes an unpredictable, 0600-permissioned temp file
- **spec_ref**: `openspec/changes/secret-hygiene/specs/authentication-twig/spec.md#requirement-jwt-token-minting-with-hsrsps-algorithms-req-002`
- **type**: security
- **persona**: N/A
- **preconditions**: `configuration = {payload: '{"sub":"x"}', secret: <valid base64 RSA private key>, algorithm: 'RS256'}`
- **steps**: call `fetchJWTToken($configuration)`; during/immediately after the call, inspect `sys_get_temp_dir()` for files matching the `oc_privatekey_*` prefix and their permission bits (a decorated temp-dir helper or a `register_shutdown_function`/spy pattern is acceptable to capture the file before it is unlinked)
- **expected result**: the temp file's permission mode is `0600`; the filename does NOT match the legacy `privatekey-<microtime><pid>` shape; the file no longer exists once `fetchJWTToken()` returns
- **test command**: `vendor/bin/phpunit --filter AuthenticationServiceTest`

### TC-15: getRSJWK removes its temp file even when key parsing fails
- **spec_ref**: `openspec/changes/secret-hygiene/specs/authentication-twig/spec.md#requirement-jwt-token-minting-with-hsrsps-algorithms-req-002`
- **type**: security
- **persona**: N/A
- **preconditions**: `configuration.secret` is a base64 string that does not decode to a valid RSA private key
- **steps**: call `fetchJWTToken($configuration)`, catching the expected exception from `JWKFactory::createFromKeyFile`; diff the `oc_privatekey_*`-prefixed contents of `sys_get_temp_dir()` before and after the call
- **expected result**: the exception propagates to the caller; no `oc_privatekey_*` file remains in the temp directory after the call
- **test command**: `vendor/bin/phpunit --filter AuthenticationServiceTest`

## Coverage Summary

| Requirement | Covered by | Status |
|---|---|---|
| `configuration-export-import#REQ-005` (MODIFIED — six-entity-type redaction via shared registry) | TC-1, TC-2, TC-3, TC-4, TC-5, TC-6, TC-7, TC-8 | Covered |
| `http-call-engine#REQ-006` (ADDED — CallLog redaction before persistence) | TC-9, TC-10, TC-11 | Covered |
| `http-call-engine#REQ-002` (MODIFIED — cert/key temp-file materialisation and cleanup) | TC-12, TC-13 | Covered |
| `authentication-twig#REQ-002` (MODIFIED — JWT private-key temp-file hygiene) | TC-14, TC-15 | Covered |

All four spec-delta requirements introduced or modified by this change have at
least one mapped, security-typed test case. TC-11 and TC-13 are explicitly
regression-typed since they re-verify already-shipped behaviour (commits
`8b6d6a27` and `b0a5ef8a`) rather than new behaviour.

## Out of Scope

- No `functional` (browser), `persona`, `accessibility`, or `performance` test
  cases: all three affected specs (`http-call-engine`, `configuration-export-import`,
  `authentication-twig`) are pure backend services carrying `@e2e exclude` — there
  is no UI surface for CallLog persistence, configuration export/import, or JWT
  minting to drive through a browser.
- No `api`/Newman test cases: this change modifies no REST endpoints; export/import
  already goes through `ConfigurationService`, exercised here at the unit level.
- `JobHandler::arguments` redaction is explicitly not tested here (or implemented)
  — see the proposal's Open Questions and design's Risks/Trade-offs: no concrete
  evidence was found that `arguments` structurally carries raw secret values, as
  distinct from entity id/slug references already governed by REQ-004's id↔slug
  maps. Revisit if a future example surfaces.
- `getHSJWK`'s `addslashes` bug and `generateJWT`'s exception-message-as-token bug
  (both documented in the `authentication-twig` spec delta's Notes) are functional
  correctness issues, not secret-exposure issues, and are explicitly out of scope
  for this security change.
