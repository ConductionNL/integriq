# Tasks: idp-broker-yivi-route

Kind: code. Size M. Row: portaliq `sig-yivi`. Depends on `portal-idp-broker-config`; build after it. D5 of `design.md` waits for Ruben's answer in the proposal's "Needs a decision".

## Task 1: Registration properties and the save rule
- **spec_ref**: `specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001`
- **files**: the `idpRegistration` fragment in `lib/Settings/register.d/`, `lib/Auth/Idp/IdpRegistrationService.php`, `tests/Unit/Auth/Idp/IdpRegistrationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN it imports THEN `provider` accepts `yivi` and the D2 properties exist with titles, with no `PARTIAL IMPORT` line
  - GIVEN a `yivi` registration with kind `saml-sp` WHEN it is saved THEN the save is refused
- [ ] Implement
- [ ] Test

## Task 2: The start with attributes
- **spec_ref**: `specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002`
- **files**: `lib/Auth/Idp/IdpLoginService.php`, `lib/Auth/Idp/Adapter/OidcRelyingPartyAdapter.php`, `tests/Unit/Auth/Idp/YiviStartTest.php`
- **acceptance_criteria**:
  - GIVEN an allowed list WHEN the start runs THEN the signed state holds the list and the authorization request carries one essential claim per attribute
  - GIVEN an empty list or an unknown id WHEN the start runs THEN integriq's error page shows and there is no redirect
- [ ] Implement
- [ ] Test

## Task 3: The callback and the envelope
- **spec_ref**: `specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003`
- **files**: `lib/Auth/Idp/Adapter/OidcRelyingPartyAdapter.php`, the envelope builder under `lib/Auth/Idp/`, `tests/Unit/Auth/Idp/YiviEnvelopeTest.php`, the OIDC stub issuer in the test tree, `tests/postman/idp-yivi.json`
- **acceptance_criteria**:
  - GIVEN the stub returns extra claims WHEN the envelope is built THEN only the requested attributes are in it
  - GIVEN a BSN attribute and `yiviBsnToConsumer` false WHEN the envelope is built THEN the value is the pseudonym
  - GIVEN a missing essential claim WHEN the callback runs THEN no code is issued
- [ ] Implement
- [ ] Test

## Task 4: The trust per provider
- **spec_ref**: `specs/idp-broker-yivi/spec.md#requirement-the-assurance-floor-applies-to-the-government-providers-a-yivi-login-carries-its-registrations-trust-req-yivi-004`
- **files**: `lib/Auth/Idp/TrustLevelMapper.php`, `tests/Unit/Auth/Idp/TrustLevelMapperTest.php`
- **acceptance_criteria**:
  - GIVEN Ruben's answer to the proposal's decision WHEN the mapper is built THEN it follows that answer, and a DigiD Basis login is refused either way
- [ ] Implement
- [ ] Test

## Task 5: The screen
- **spec_ref**: `specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001`
- **files**: `src/views/admin/IdpRegistrationSettings.vue`, `src/modals/IdpRegistrationModal.vue`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN provider `yivi` WHEN the modal opens THEN only OIDC fields and the attribute list show, and kind is fixed to `oidc-rp`
- [ ] Implement
- [ ] Test
