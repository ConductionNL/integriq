# Tasks: sources-per-user-oauth

Kind: code. Size M. Row `buildiq:int-per-user-oauth`.

## Implementation tasks

### Task 1: Per-user references and resolution
- **spec_ref**: `openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-source-can-call-with-the-signed-in-users-own-account-req-puoa-001`
- **files**: `lib/Service/BrokeredCallService.php`, `lib/Settings/integriq_register.json` (source credentialRef shape)
- **acceptance_criteria**:
  - GIVEN two users with their own token sets WHEN each calls the same endpoint THEN each call is made with that user's credential
  - GIVEN a sessionless job on a per-user source WHEN it runs THEN it is refused with a message naming the reason
- [ ] Implement
- [ ] Test (PHPUnit with a fake broker; no fallback to a shared credential)

### Task 2: The connect prompt
- **spec_ref**: `openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002`
- **files**: `lib/Service/EndpointService.php`, `lib/Http/ProblemResponse.php`
- **acceptance_criteria**:
  - GIVEN a user without a token set WHEN they call a per-user endpoint THEN the answer is 401 with type connect-account-required and a connectUrl at the broker
- [ ] Implement
- [ ] Test (PHPUnit; Newman)

### Task 3: Source page option and the personal overview
- **spec_ref**: `openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-sees-and-disconnects-their-own-accounts-req-puoa-003`
- **files**: the source detail page, `lib/Settings/PersonalSection.php`, `lib/Settings/PersonalConnectedAccounts.php`, `src/views/PersonalConnectedAccounts.vue`, `appinfo/info.xml`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a user with one connected account WHEN they disconnect it in personal settings THEN their next call gets the connect prompt again
- [ ] Implement
- [ ] Test (Playwright end to end against the broker's connect flow with a mock provider)

## Verification
- [ ] `openspec validate sources-per-user-oauth --type change --strict` passes
- [ ] No token value appears in any integriq object, log or response, asserted in a test
- [ ] PHPUnit, Newman and Playwright run, exit codes read
