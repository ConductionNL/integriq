# Tasks: identity-broker-browser-login

Kind: code. Size M. Halves for portaliq `signin-integriq-broker-login` and `signin-eherkenning-branch` (rows portaliq `sig-digid`, `sig-eherkenning`, `cmp-sig-eidas`, `dem-cl-eherkenning-branch`).

## Implementation tasks

### Task 1: Consumers with return addresses
- **spec_ref**: `openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003`
- **files**: `lib/Auth/Idp/IdpBrokerConfig.php`, `lib/Command/IdpConsumerCommand.php`, `lib/Repair/` (seed the disabled `portaliq` entry)
- **acceptance_criteria**:
  - GIVEN the old id-to-secret form WHEN the config is read THEN the consumer can redeem and cannot start
  - GIVEN `occ integriq:idp:consumer portaliq --return-url=https://portal.example.nl/portal/api/session/broker/callback` WHEN run THEN the consumer is enabled with that address
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 2: Start
- **spec_ref**: `openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001`
- **files**: `lib/Controller/IdpBrokerController.php`, `appinfo/routes.php`, a state store beside `lib/Auth/Idp/EnvelopeCodeStore.php`
- **acceptance_criteria**:
  - GIVEN a registered consumer and address WHEN the start is called THEN a state is stored and the browser goes to the adapter's redirect
  - GIVEN an address not on the list WHEN the start is called THEN integriq's error page shows and no redirect happens
- [ ] Implement
- [ ] Test (PHPUnit; Newman for the redirect and the refusal)

### Task 3: Callback
- **spec_ref**: `openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002`
- **files**: `lib/Controller/IdpBrokerController.php`, `lib/Auth/Idp/LogGovernmentIdpAdapter.php` (scripted test mode)
- **acceptance_criteria**:
  - GIVEN a scripted DigiD assertion answering a stored state WHEN the callback runs THEN the browser is redirected to the return address with a code, and redeeming that code at the exchange gives an envelope whose audience is the consumer
  - GIVEN an assertion without a state WHEN the callback runs THEN it is refused
- [ ] Implement
- [ ] Test (PHPUnit; a Newman round trip start, callback, exchange with the scripted adapter)

### Task 4: Branch claim and docs
- **spec_ref**: `openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-an-eherkenning-envelope-carries-the-branch-the-login-was-restricted-to-req-idp-004`
- **files**: `lib/Auth/Idp/SubjectEnvelope.php`, `GovernmentIdpAdapterInterface.php`, `docs/administrators/citizen-authentication.md`
- **acceptance_criteria**:
  - GIVEN a scripted eHerkenning assertion with a vestigingsnummer WHEN the envelope is minted THEN it carries `branch`; GIVEN a DigiD assertion THEN it does not
- [ ] Implement
- [ ] Test (PHPUnit; the docs claim table lists `branch`)

## Verification
- [ ] `openspec validate identity-broker-browser-login --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit and Newman exit codes read
- [ ] The portaliq lane runs its broker login against this branch with the scripted adapter
