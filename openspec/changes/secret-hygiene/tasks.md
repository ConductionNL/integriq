# Tasks: secret-hygiene

## Implementation Tasks

### Task 1: Create the shared SensitiveFieldRegistry
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **files**: `lib/Service/Security/SensitiveFieldRegistry.php`, `tests/Unit/Service/Security/SensitiveFieldRegistryTest.php`
- **acceptance_criteria**:
  - GIVEN the name `Authorization` WHEN `isSensitiveName('Authorization')` is called THEN it SHALL return `true`
  - GIVEN the name `client_secret` WHEN `isSensitiveName('client_secret')` is called THEN it SHALL return `true`
  - GIVEN the name `Accept` WHEN `isSensitiveName('Accept')` is called THEN it SHALL return `false`
  - GIVEN a nested array `{"action":{"headers":{"Authorization":"Bearer x"}}}` WHEN `redactArray(...)` is called THEN the nested `Authorization` value SHALL be `***REDACTED***` and all other keys SHALL be unchanged
  - GIVEN the exact-match names lifted from `SourceHandler` (`authorizationHeader`, `auth`, `authenticationConfig`, `authorizationPassthroughMethod`, `jwt`, `jwtId`, `secret`, `username`, `password`, `apikey`) WHEN each is checked via `isSensitiveName()` THEN each SHALL return `true` even though some (e.g. `username`) do not match the regex pattern alone
- [ ] Implement (redaction matrix unit tests — this task IS the redaction matrix required by the security-change-has-tests gate)
- [ ] Test

### Task 2: Refactor CallService::isSecretKeyName() to delegate to the shared registry
- **spec_ref**: `openspec/changes/secret-hygiene/specs/http-call-engine/spec.md#requirement-req-006--calllog-requestresponse-redaction-before-persistence`
- **files**: `lib/Service/CallService.php`, `tests/Unit/Service/CallServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the refactored `CallService` WHEN `CallServiceTest::testBrokeredCallLogRedactsSecretsLikeGuzzlePath` runs THEN it SHALL still pass unmodified (behaviour-preserving refactor)
  - GIVEN a Source configured with `configuration.headers.Authorization = "Bearer live-secret-token-123"` WHEN a call is dispatched through the non-brokered Guzzle path and its CallLog persisted THEN `call_log.request.headers.Authorization` SHALL be `***REDACTED***` AND the literal token SHALL NOT appear anywhere in the persisted CallLog AND the real outbound request SHALL still have carried the unredacted header (new explicit Guzzle-path sibling test to `testBrokeredCallLogRedactsSecretsLikeGuzzlePath`)
- [ ] Implement
- [ ] Test

### Task 3: Refactor SourceHandler's nested `configuration` redaction onto the shared registry
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **files**: `lib/Service/ConfigurationHandlers/SourceHandler.php`, `tests/Unit/Service/ConfigurationHandlers/SourceHandlerTest.php`
- **acceptance_criteria**:
  - GIVEN a Source with `apikey`, `secret`, and `configuration.headers.Authorization` set WHEN exported THEN `apikey`/`secret` are absent (unchanged top-level `unset()` behaviour) AND `configuration.headers.Authorization` is `***REDACTED***` (new nested-registry behaviour, replacing the old ad hoc `str_contains` substring check)
  - GIVEN a Source with `configuration.headers.Accept = "application/json"` WHEN exported THEN that header is retained unmodified
- [ ] Implement
- [ ] Test

### Task 4: Add configuration redaction to EndpointHandler and MappingHandler
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **files**: `lib/Service/ConfigurationHandlers/EndpointHandler.php`, `lib/Service/ConfigurationHandlers/MappingHandler.php`, `tests/Unit/Service/ConfigurationHandlers/EndpointHandlerTest.php`, `tests/Unit/Service/ConfigurationHandlers/MappingHandlerTest.php`
- **acceptance_criteria**:
  - GIVEN an Endpoint with `configuration.headers.X-Api-Key = "live_endpoint_key_123"` WHEN exported via `EndpointHandler::export()` THEN `configuration.headers.X-Api-Key` SHALL be `***REDACTED***` AND the key itself SHALL still be present
  - GIVEN a Mapping with a `configuration.client_secret` value WHEN exported via `MappingHandler::export()` THEN that value SHALL be `***REDACTED***`
- [ ] Implement
- [ ] Test

### Task 5: Add configuration redaction to RuleHandler (nested-configuration walk)
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **files**: `lib/Service/ConfigurationHandlers/RuleHandler.php`, `tests/Unit/Service/ConfigurationHandlers/RuleHandlerTest.php`
- **acceptance_criteria**:
  - GIVEN a Rule with `configuration = {"action": {"headers": {"Authorization": "Bearer live_rule_token"}}}` WHEN exported via `RuleHandler::export()` THEN `configuration.action.headers.Authorization` SHALL be `***REDACTED***` regardless of nesting depth
  - GIVEN the redaction runs alongside `RuleHandler`'s existing `convertIdsToSlugs()` nested-key rewriting WHEN both operate on the same export THEN slug translation of legitimate id-reference keys (e.g. `sourceId`) SHALL be unaffected by the new redaction pass (they run as independent passes, not overlapping matches)
- [ ] Implement
- [ ] Test

### Task 6: Add configuration redaction to JobHandler and SynchronizationHandler
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **files**: `lib/Service/ConfigurationHandlers/JobHandler.php`, `lib/Service/ConfigurationHandlers/SynchronizationHandler.php`, `tests/Unit/Service/ConfigurationHandlers/JobHandlerTest.php`, `tests/Unit/Service/ConfigurationHandlers/SynchronizationHandlerTest.php`
- **acceptance_criteria**:
  - GIVEN a Job with a `configuration.password` value (distinct from its `arguments` field, which is NOT walked per design Open Questions) WHEN exported via `JobHandler::export()` THEN `configuration.password` SHALL be `***REDACTED***`
  - GIVEN a Synchronization with a `configuration.token` value WHEN exported via `SynchronizationHandler::export()` THEN that value SHALL be `***REDACTED***`
- [ ] Implement
- [ ] Test

### Task 7: Add the cross-entity-type export-leak regression test
- **spec_ref**: `openspec/changes/secret-hygiene/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations`
- **files**: `tests/Unit/Service/ConfigurationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a configuration set containing one Source, Endpoint, Mapping, Rule, Job, and Synchronization, each seeded with a differently-named secret-shaped `configuration` value (`password`, `token`, `client_secret`, `apikey`, `Authorization` header, `Cookie` header respectively) WHEN `ConfigurationService::exportConfiguration()` is called THEN the JSON-serialised export SHALL NOT contain any of the six seeded plaintext secret values as a substring
- [ ] Implement (this IS the export-leak regression test required by the security-change-has-tests gate)
- [ ] Test

### Task 8: Add temp-file permission regression tests for CallService cert/key materialisation
- **spec_ref**: `openspec/changes/secret-hygiene/specs/http-call-engine/spec.md#requirement-certificate-materialisation-and-cleanup-req-002`
- **files**: `tests/Unit/Service/CallServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `getCertificate($config)` materialises a `cert` blob to disk WHEN the resulting file's permission mode is inspected (`fileperms() & 0777`) THEN it SHALL equal `0600`
  - GIVEN the same for `ssl_key` and `verify` WHEN inspected THEN each SHALL also be `0600`
  - GIVEN `removeFiles()` runs on a config where one of the three paths was already deleted WHEN it executes THEN no PHP warning/error SHALL be triggered (assert via PHPUnit's error handler or `set_error_handler` capture) and no exception SHALL propagate
- [ ] Implement (this IS the temp-file permission test required by the security-change-has-tests gate, CallService half)
- [ ] Test

### Task 9: Add temp-file permission and cleanup-on-failure regression tests for AuthenticationService::getRSJWK
- **spec_ref**: `openspec/changes/secret-hygiene/specs/authentication-twig/spec.md#requirement-jwt-token-minting-with-hsrsps-algorithms-req-002`
- **files**: `tests/Unit/Service/AuthenticationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `fetchJWTToken()` is called with `algorithm = 'RS256'` and a valid base64-encoded RSA private key WHEN the temp file backing `getRSJWK()` is inspected during key materialisation (via a decorated/spy temp directory, or by asserting no file matching the legacy `/var/tmp/privatekey-*` pattern exists after the call) THEN its permission mode SHALL be `0600` and its filename SHALL NOT match the legacy predictable `privatekey-<microtime><pid>` shape
  - GIVEN `configuration.secret` is not valid RSA key material WHEN `getRSJWK()` throws from `JWKFactory::createFromKeyFile` THEN no temp file created during that call SHALL remain on disk afterward (assert via a directory listing diff of `sys_get_temp_dir()` filtered to the `oc_privatekey_*` prefix, before and after the call)
- [ ] Implement (this IS the temp-file permission test required by the security-change-has-tests gate, AuthenticationService half)
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — Tasks 1-9 above; run `composer test` (or `vendor/bin/phpunit`) and confirm 100% pass, including the pre-existing suites (`CallServiceTest`, `AuthenticationServiceTest`, `ConfigurationServiceTest`) to catch any regression from the `isSecretKeyName()` extraction.
- [ ] Newman/Postman tests for new/changed API endpoints — N/A: this change introduces no new/modified API endpoints. `configuration-export-import` and `http-call-engine`/`authentication-twig` are pure backend services (see each spec's `@e2e exclude` marker); the existing export/import Newman coverage, if any, is unaffected by this change's scope.
- [ ] Browser tests (Playwright MCP) for UI changes — N/A: no UI surface exists for CallLog persistence, configuration export/import, or JWT minting (all three affected specs carry `@e2e exclude`, no dedicated UI panel).
- [ ] All tests pass (`composer test`, `newman run`) — `newman run` N/A per above; `composer test` MUST pass with the new tests included.

## Security regression tests (hydra gate: security-change-has-tests)

This is a security-relevant change (credential redaction + temp-key-file hygiene).
Per the hydra `security-change-has-tests` gate, the following explicit regression
tests are REQUIRED and map directly to Tasks 1, 2, 7, 8, 9 above:
- [ ] Redaction matrix unit tests (Task 1) — every sensitive field-name pattern from
  #1013/#964 exercised against `SensitiveFieldRegistry::isSensitiveName()`.
- [ ] Export-leak regression test (Task 7) — exports one instance of every one of
  the six entity types, each seeded with a secret, asserts zero leakage.
- [ ] CallLog non-brokered-path redaction test (Task 2) — proves a CallLog written
  after an authenticated Guzzle-path call contains no plaintext secret.
- [ ] Temp-file permission tests (Tasks 8 and 9) — `CallService` cert/key files and
  `AuthenticationService` private-key files are asserted `0600` and are asserted
  removed even on the exception/failure path.

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — N/A: no operator-facing behaviour
  change. Source export redaction already existed and is unchanged from an
  operator's perspective; the five newly-redacting entity types silently close a
  leak with no new UI, flag, or workflow to document. The behaviour itself is
  fully documented in the spec deltas under
  `openspec/changes/secret-hygiene/specs/`.
- [ ] Screenshot captured and committed to `docs/images/` — N/A: no UI change.

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A: no
  new user-facing strings (no new UI, no new error messages returned to end users;
  `***REDACTED***` is a persisted-data placeholder, not a UI string).
