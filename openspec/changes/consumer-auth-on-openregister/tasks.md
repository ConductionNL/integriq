# Tasks: consumer-auth-on-openregister

Kind: code. Size M. Hydra gate 23 rule 6 on `lib/Service/AuthorizationService.php`; decisions 56, 62, 64 (Q5); Q4 open.

## Implementation tasks

### Task 1: Pin today's outcomes through the real callers
- **spec_ref**: `openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006`
- **files**: `tests/Unit/Service/InboundCredentialCallersTest.php`
- **acceptance_criteria**:
  - GIVEN real HS256, RS256 and PS256 tokens and consumers validated against the real `consumer` schema WHEN they run through `EndpointService::processAuthenticationRule()` and `LtiLaunchService::validateTiming()` on the old class THEN all 15 tests pass (the control)
  - Switched to the bridge before the callers are retyped, the same tests fail
- [x] Implement
- [x] Test

### Task 2: Consumer source and bridge, callers retyped
- **spec_ref**: `openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006`
- **files**: `lib/Service/Consumer/IntegriqConsumerSource.php`, `lib/Service/Consumer/OpenRegisterCredentialBridge.php`, the six callers, `tests/Helpers/OpenRegisterCredentials.php`, `tests/bootstrap.php`, `tests/stubs/OCA/OpenRegister/**`, `phpstan.neon`, `psalm.xml`
- **acceptance_criteria**:
  - GIVEN the callers on the bridge with OpenRegister's real AuthorizationService WHEN Task 1's tests run THEN all pass, and every test that mocked or built the old class passes against the bridge
- [x] Implement
- [x] Test

### Task 3: Lifetime cap after OpenRegister
- **spec_ref**: `openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-keeps-its-token-lifetime-cap-req-007`
- **files**: `lib/Service/Consumer/OpenRegisterCredentialBridge.php`
- **acceptance_criteria**:
  - GIVEN a token with `exp = iat + 7200` WHEN it is presented THEN 401 with the cap message, no acting user and no resolved consumer; `iat + 3600` passes
- [x] Implement
- [x] Test

### Task 4: Refuse on an older OpenRegister
- **spec_ref**: `openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-an-openregister-without-the-public-checks-is-refused-req-008`
- **files**: `lib/Service/Consumer/OpenRegisterCredentialBridge.php`, `tests/Unit/Service/Consumer/OpenRegisterCredentialBridgeOldOpenRegisterTest.php`, `tests/Unit/Service/Consumer/Fixtures/old-openregister-probe.php`
- **acceptance_criteria**:
  - GIVEN an OpenRegister with the checks protected and no ConsumerSource WHEN any check runs THEN it is refused with "Inbound authentication is unavailable"; with the guard removed the probe shows the fatal errors and an admitted payload check (control)
- [x] Implement
- [x] Test

### Task 5: Remove the local service
- **spec_ref**: `openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006`
- **files**: `lib/Service/AuthorizationService.php` (deleted)
- **acceptance_criteria**:
  - `git grep 'Integriq\\Service\\AuthorizationService' lib tests appinfo` finds no code reference
  - Hydra gate 23 reports 0 matches
- [x] Implement
- [x] Test

### Task 6: Live check on the dev instance
- **spec_ref**: `openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006`
- **acceptance_criteria**:
  - On an instance with openregister development (#4361) and this branch: an endpoint with a `jwt` rule admits an HS256 token for a seeded consumer and refuses the same token's second use and a two-hour token; an `apikey` rule admits a consumer key
- [ ] Implement
- [ ] Test

## Verification
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints: no endpoint changes shape; the live recipe is Task 6
- [ ] Vitest: N/A, no frontend surface
- [ ] Playwright: N/A, the scenarios carry `@e2e exclude` with the reason
