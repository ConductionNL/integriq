# Tasks: access-consumer-credentials

Kind: code. Size M. Rows `integriq:acc-multi-auth`, `acc-mtls-in`, `acc-secret-rotation`, `acc-secret-reveal-once`.

## Implementation tasks

### Task 1: The credentials list and the migration of the single key
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001`
- **files**: `lib/Settings/integriq_register.json`, `lib/Settings/register.d/99-consumer-secrets-writeonly.json`, `lib/Repair/HashConsumerApiKeys.php`, `lib/Service/AuthorizationService.php`
- **acceptance_criteria**:
  - GIVEN a consumer with a plaintext apiKey WHEN the repair step runs THEN it has one hashed credential and no plaintext key, and its calls still pass
- [x] Implement
- [x] Test (PHPUnit on the repair step including a failure halfway; Newman call with the old key after repair)
  > 2026-11-25, live (sandbox Nextcloud 34.0.6 + MariaDB, OpenRegister main 292aa09d with openregister#11279 applied): `HashConsumerApiKeysTest` 6/6 (failure on the first and on the second save keep the plaintext and the consumer working). Newman `tests/postman/integriq-consumer-key-repair.postman_collection.json`: folder 1 makes a consumer with a plaintext key and an endpoint whose apikey rule has no keys of its own, the key passes (5/5); `occ maintenance:repair` reports "hashed 1 consumer API key(s), 0 failed"; folder 2 + teardown 15/15: the old key still passes, the credentials route lists one `migrated` entry with the key's prefix and no key or hash, the consumer read carries neither, a wrong key is 401. Without openregister#11279 the create answer of folder 1 carries the plaintext key in `@self.relations["authorizationConfiguration.apiKey"]` (1 failed assertion).

### Task 2: Generate, reveal once, revoke
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002`
- **files**: `lib/Controller/ConsumerCredentialsController.php`, `appinfo/routes.php`, the consumer detail page, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on a consumer WHEN they generate a key THEN it is shown once with a copy button, and reloading the page shows only its prefix
- [x] Implement
  > 2026-11-21, the screen: `src/components/ConsumerCredentialsPanel.vue`, a ConsumerDetail body widget over `CredentialKeysPanel` (shared with the developer portal), reads and writes `/api/consumers/{id}/credentials`. Generating shows the key once, with a copy button. Reloading shows only its label and prefix, because no route answers a key again. Revoking removes one credential and leaves the others. Vitest: `tests/vitest/credentialKeysPanel.spec.js`.
- [x] Test (Playwright for generate, copy and revoke; PHPUnit that no route returns a key); written, runs in nightly
  > 2026-11-25, run live: 2/2 green after three fixes. The panel's own `credential-keys` test id was overwritten by the `data-testid` the detail page's body section passes down (a wrapper element now takes it; vitest case added); the spec's API calls went with the session cookie only (412, now with `OCS-APIRequest`); the label field's test id sits on the input itself, so `.locator('input')` found nothing.
  > 2026-11-23, written, not run (no live instance): `tests/e2e/consumer-credentials.spec.ts` generates a key on a seeded consumer, copies it, checks the credentials list never carries it and a reload shows only label and prefix, then puts a second key next to the first and revokes the old one. PHPUnit that no route returns a key is `ConsumerCredentialsControllerTest::testAGeneratedKeyIsInTheCreateResponseAndNeverInTheList`.

### Task 3: Two keys at once and last used
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003`
- **files**: `lib/Service/AuthorizationService.php`, the consumer detail page
- **acceptance_criteria**:
  - GIVEN a consumer with two keys WHEN the partner switches to the new key THEN both pass until the old is revoked, and the list shows when each was last used
- [x] Implement
  > 2026-11-21, the screen: the consumer's keys are listed side by side with when each was last used, its expiry and whether it expired, and a new key can carry an expiry, so a partner switches before the old key is revoked (`CredentialKeysPanel`, vitest in `tests/vitest/credentialKeysPanel.spec.js`).
- [x] Test (PHPUnit on lookup and throttled lastUsedAt writes)

### Task 4: Client certificate credentials and the mtls type
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004`
- **files**: `lib/Service/Auth/ClientCertificateReader.php`, `lib/Service/AuthorizationService.php`, `lib/Service/EndpointService.php`, the consumer detail page
- **acceptance_criteria**:
  - GIVEN a pinned certificate WHEN a call arrives with it verified by the web server THEN it passes as that consumer
  - GIVEN the certificate header WHEN it arrives from an address that is not a trusted proxy THEN it is ignored
- [x] Implement
  - Backend done: `ClientCertificateReader` (server `SSL_CLIENT_VERIFY`/`SSL_CLIENT_CERT`, else the
    `mtls_certificate_header` header only from a `trusted_proxies` address; OIN from the subject
    serialNumber), `clientCertificate` credentials pinned by fingerprint or by issuing CA plus subject
    pattern (`ConsumerCredentials::certificateEntry()` / `matchingCertificate()`), the bridge's
    `authorizeClientCertificate()` resolving the consumer, the `mtls` rule type (also inside `anyOf`,
    with an optional `consumers` list), and `POST /api/consumers/{id}/credentials` with
    `type=clientCertificate`. The consumer detail page part is left with the other screens.
  - 2026-11-21, the screen: `CredentialKeysPanel` in its administrator mode lists the pinned certificates (fingerprint, subject pattern, OIN), pins a PEM certificate or an issuing CA with a subject pattern, shows a refusal as the server words it, and revokes one. Vitest in `tests/vitest/credentialKeysPanel.spec.js`.
- [x] Test (PHPUnit with fixture certificates including a PKIoverheid subject; a documented Apache and nginx config walked once)
  - PHPUnit done with certificates generated at test time (CA, PKIoverheid-style leaf with an OIN,
    a same-subject leaf from another CA): `ClientCertificateReaderTest`,
    `ClientCertificateCredentialsTest`, `EndpointAuthenticationMtlsTest`, two controller cases.
    The Apache and nginx config is documented in `docs/features/endpoints.md`; walking it once is live.
  - 2026-11-25, both configs walked live, copied from `docs/features/endpoints.md` with only the CA path
    changed: nginx 1.24 terminating TLS in front of Nextcloud (its address in `trusted_proxies`), and
    Apache 2.4.58 terminating TLS itself with php-fpm. Test PKI: a "PKIoverheid" CA, a leaf with
    `serialNumber=00000001234567890000`, a renewed leaf with the same subject, the same subject from another CA.
    The walk found a defect: no certificate was ever found, under either server. `ClientCertificateReader`
    read `SSL_CLIENT_VERIFY`, `SSL_CLIENT_CERT` and `REMOTE_ADDR` through `IRequest::getEnv()`, which reads
    `$_ENV`; the web server puts them in `$_SERVER`. The unit test mocked `getEnv`, so it passed. Now read
    from `$request->server`; the test sets `server` and fails if `getEnv` is called. After the fix, per server:
    leaf pinned by fingerprint 200 (OIN read, lastUsedAt set); renewed leaf pinned by CA + subject pattern 200;
    same subject from another CA refused by the web server (nginx 400, Apache handshake abort); no
    certificate 401; a client-sent `X-SSL-Client-Cert` through nginx 401 (`proxy_set_header` replaces it);
    the same header sent straight to Nextcloud from an address outside `trusted_proxies` 401. Control:
    the old reader under Apache answered 401 for the pinned leaf.

### Task 5: anyOf on an authentication rule
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005`
- **files**: `lib/Service/EndpointService.php`, the endpoint rule editor
- **acceptance_criteria**:
  - GIVEN an endpoint with anyOf apikey and jwt WHEN a caller presents either THEN it passes, and with neither the 401 lists both reasons
- [x] Implement
  - Backend: `EndpointService::authenticateAnyOf()` tries the methods in order, applies the rule's
    `requiredScopes` to every method without its own, and answers 401 with `details.anyOf`, one
    reason per method keyed `<position>:<type>`. PHPUnit: `EndpointAuthenticationAnyOfTest`.
  - Rule editor: `AuthenticationForm.vue` has the switch "Accept any one of several methods". It
    turns the current method into the first of an `anyOf` list (and back into the first method),
    edits each method with the same form nested (no switch and no scope lists of its own, since a
    nested `anyOf` is refused), adds and removes methods (the last one stays), and keeps the scope
    lists on the rule. The shape changes are in `actionForms/authMethods.js`. Vitest:
    `tests/vitest/authenticationAnyOf.spec.js`.
- [x] Test (PHPUnit; Newman with each method and with none); written, runs in nightly
  - PHPUnit done (`EndpointAuthenticationAnyOfTest`). Newman written, not run (no live instance):
    `tests/postman/integriq-auth-any-of.postman_collection.json` creates a rule with `anyOf` [an API
    key, basic auth for the admin user] behind an endpoint, calls it with each method alone and with
    neither (401 with the reasons `0:apikey` and `1:basic`), and deletes both again. The box stays
    unticked until a Newman run reads green.
  - 2026-11-25, run live: 7 requests, 9/9 assertions, exit 0.

## Verification
- [x] `openspec validate access-consumer-credentials --type change --strict` passes (openspec 2.2.0, 25 Nov 2026)
- [x] PHPUnit and Newman run, exit codes read
  > 2026-11-25: PHPUnit `--filter 'ConsumerCredentials|ClientCertificate|EndpointAuthenticationMtls|EndpointAuthenticationAnyOf'` 44/44 and `HashConsumerApiKeysTest` 6/6, exit 0; Newman any-of 9/9 and key-repair 5/5 + 15/15, exit 0; Playwright `consumer-credentials.spec.ts` 2/2.
- [x] A read of every consumer over the OpenRegister object API shows no key and no hash
  > 2026-11-25, RK.8 of the key-repair collection: the list read carries no `authorizationConfiguration.apiKey`, no `clientSecretHash`, no `credentials` and no `keyHash` anywhere. A consumer that still holds a plaintext key (before the repair) shows it in `@self.relations` on OpenRegister main until openregister#11279 lands.
