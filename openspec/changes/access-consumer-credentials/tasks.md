# Tasks: access-consumer-credentials

Kind: code. Size M. Rows `integriq:acc-multi-auth`, `acc-mtls-in`, `acc-secret-rotation`, `acc-secret-reveal-once`.

## Implementation tasks

### Task 1: The credentials list and the migration of the single key
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-consumer-holds-several-credentials-and-no-plaintext-key-req-cred-001`
- **files**: `lib/Settings/integriq_register.json`, `lib/Settings/register.d/99-consumer-secrets-writeonly.json`, `lib/Repair/HashConsumerApiKeys.php`, `lib/Service/AuthorizationService.php`
- **acceptance_criteria**:
  - GIVEN a consumer with a plaintext apiKey WHEN the repair step runs THEN it has one hashed credential and no plaintext key, and its calls still pass
- [ ] Implement
- [ ] Test (PHPUnit on the repair step including a failure halfway; Newman call with the old key after repair)

### Task 2: Generate, reveal once, revoke
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-new-key-is-shown-exactly-once-req-cred-002`
- **files**: `lib/Controller/ConsumerCredentialsController.php`, `appinfo/routes.php`, the consumer detail page, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on a consumer WHEN they generate a key THEN it is shown once with a copy button, and reloading the page shows only its prefix
- [ ] Implement
- [ ] Test (Playwright for generate, copy and revoke; PHPUnit that no route returns a key)

### Task 3: Two keys at once and last used
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/consumer-management/spec.md#requirement-a-key-can-be-replaced-without-downtime-req-cred-003`
- **files**: `lib/Service/AuthorizationService.php`, the consumer detail page
- **acceptance_criteria**:
  - GIVEN a consumer with two keys WHEN the partner switches to the new key THEN both pass until the old is revoked, and the list shows when each was last used
- [ ] Implement
- [ ] Test (PHPUnit on lookup and throttled lastUsedAt writes)

### Task 4: Client certificate credentials and the mtls type
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004`
- **files**: `lib/Service/Auth/ClientCertificateReader.php`, `lib/Service/AuthorizationService.php`, `lib/Service/EndpointService.php`, the consumer detail page
- **acceptance_criteria**:
  - GIVEN a pinned certificate WHEN a call arrives with it verified by the web server THEN it passes as that consumer
  - GIVEN the certificate header WHEN it arrives from an address that is not a trusted proxy THEN it is ignored
- [ ] Implement
- [ ] Test (PHPUnit with fixture certificates including a PKIoverheid subject; a documented Apache and nginx config walked once)

### Task 5: anyOf on an authentication rule
- **spec_ref**: `openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-accepts-any-one-of-several-login-methods-req-cred-005`
- **files**: `lib/Service/EndpointService.php`, the endpoint rule editor
- **acceptance_criteria**:
  - GIVEN an endpoint with anyOf apikey and jwt WHEN a caller presents either THEN it passes, and with neither the 401 lists both reasons
- [ ] Implement
- [ ] Test (PHPUnit; Newman with each method and with none)

## Verification
- [ ] `openspec validate access-consumer-credentials --type change --strict` passes
- [ ] PHPUnit and Newman run, exit codes read
- [ ] A read of every consumer over the OpenRegister object API shows no key and no hash
