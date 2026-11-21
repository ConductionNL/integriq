# Tasks: idp-broker-yivi-route

Kind: code. Size M. Row: portaliq `sig-yivi`. Depends on `portal-idp-broker-config`; build after it. D5 of `design.md` waits for Ruben's answer in the proposal's "Needs a decision".

## Task 1: Registration properties and the save rule
- **spec_ref**: `specs/idp-broker-yivi/spec.md#requirement-yivi-is-a-provider-on-the-broker-reached-as-an-oidc-registration-req-yivi-001`
- **files**: the `idpRegistration` fragment in `lib/Settings/register.d/`, `lib/Auth/Idp/IdpRegistrationService.php`, `tests/Unit/Auth/Idp/IdpRegistrationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN it imports THEN `provider` accepts `yivi` and the D2 properties exist with titles, with no `PARTIAL IMPORT` line
  - GIVEN a `yivi` registration with kind `saml-sp` WHEN it is saved THEN the save is refused
- [x] Implement
  - `40-idp-registration.json` (idpRegistration 1.1.0): `provider` gains `yivi`, and the D2 properties `yiviAttributes` (items `{ id, claim, label, carriesBsn }`, id and claim required), `yiviTrust` (`low`, `substantial`, default `low`) and `yiviBsnToConsumer` (default `false`) are added, each with a title.
  - The save rule is `IdpRegistrationSaveCheck`, applied by `IdpRegistrationGuardListener` on OpenRegister's create and update path: a provider only reached over OIDC (Yivi) is refused with 400 `idp_registration_needs_oidc` unless its kind is `oidc-rp`, and the message says Yivi needs an OIDC bridge (en and nl in `l10n/*.json`). The rule sits there and not in `IdpRegistrationService::save()`, the file this task named, because the Authentication settings screen writes registrations through OpenRegister's object API and nothing calls that method.
  - `IdpAdapterRegistry::PROVIDERS` does not list `yivi` yet: the start and the callback are tasks 2 and 3.
- [ ] Test
  - PHPUnit done: `IdpRegistrationFragmentTest::testTheYiviPropertiesAndTheirDefaults` (and the property list of D2), and `IdpRegistrationGuardListenerTest` (Yivi over SAML refused on create and update; Yivi over OIDC, another provider over SAML, another schema and an unresolvable schema pass). Open: the first criterion's "no `PARTIAL IMPORT` line", which is read from the import log on a live instance, so the box stays unticked.

## Task 2: The start with attributes
- **spec_ref**: `specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002`
- **files**: `lib/Auth/Idp/IdpLoginService.php`, `lib/Auth/Idp/Adapter/OidcRelyingPartyAdapter.php`, `tests/Unit/Auth/Idp/YiviStartTest.php`
- **acceptance_criteria**:
  - GIVEN an allowed list WHEN the start runs THEN the signed state holds the list and the authorization request carries one essential claim per attribute
  - GIVEN an empty list or an unknown id WHEN the start runs THEN integriq's error page shows and there is no redirect
- [x] Implement
  - `AttributeDisclosureRequest` checks the start's `attributes` (comma-separated ids, trimmed, repeats dropped, at most 32) against the registration's `yiviAttributes`; an entry without an id or a claim name allows nothing. `IdpLoginService::start()` builds it for provider `yivi` only, from the organisation's enabled Yivi registration, before the adapter is reached; any refusal throws, so the controller shows integriq's error page. The state store gained the field `attributes`, and the adapter context gains `claims` (the claim names only). `OidcRelyingPartyAdapter::beginAuthentication()` turns those into `claims={"id_token":{"<claim>":{"essential":true}}}`. `yivi` is now in `IdpAdapterRegistry::PROVIDERS` and the start and callback routes, and `TrustLevelMapper::PROVIDER_YIVI` exists without a mapping row, so the callback stays fail-closed until tasks 3 and 4.
  - Not in this task: the start and callback still resolve the container-bound adapter (`forProvider`), not the registration's own (`forRegistration`); that switch is the end-to-end wiring of `portal-idp-broker-config` task 12.
- [x] Test
  - `tests/Unit/Auth/Idp/YiviStartTest.php`: the real login service and the real OIDC adapter over the stub issuer send the bridge exactly the two claims and record both ids in the signed state; an id outside the list, an empty list, an entry without a claim, no or a disabled registration, an unusable allowlist and 33 ids are refused with nothing begun and nothing stored; DigiD ignores `attributes`. `IdpBrokerControllerTest::testAYiviStartPassesTheAttributesOn`.

## Task 3: The callback and the envelope
- **spec_ref**: `specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003`
- **files**: `lib/Auth/Idp/Adapter/OidcRelyingPartyAdapter.php`, the envelope builder under `lib/Auth/Idp/`, `tests/Unit/Auth/Idp/YiviEnvelopeTest.php`, the OIDC stub issuer in the test tree, `tests/postman/idp-yivi.json`
- **acceptance_criteria**:
  - GIVEN the stub returns extra claims WHEN the envelope is built THEN only the requested attributes are in it
  - GIVEN a BSN attribute and `yiviBsnToConsumer` false WHEN the envelope is built THEN the value is the pseudonym
  - GIVEN a missing essential claim WHEN the callback runs THEN no code is issued
- [x] Implement
  - `OidcRelyingPartyAdapter` answers, for a Yivi registration, `disclosed`: only the claims the registration's `yiviAttributes` names (`IdpRegistration::attributeAllowlist()`), so an extra claim is never read. `AttributeEnvelopeFactory::disclosed()` reads exactly the ids in the state through their claim names and throws `IdpDisclosureIncompleteException` when one is missing or blank; `IdpLoginService::finish()` runs it before the trust, so the refusal is reached today. The callback turns it into integriq's own page (`IdpBrokerController::disclosureIncompletePage()`, "You did not share all the requested details", nl "Je hebt niet alle gevraagde gegevens gedeeld" in the informal register `nl` uses) with one way back carrying `error=attributes_not_disclosed`.
  - The envelope (`AttributeEnvelopeFactory::envelope()`): `provider` `yivi`, `subType` `attributes`, `sub` an HMAC under the registration's salt over the organisation and the disclosed ids and values, and `attributes` with only those values; a `carriesBsn` attribute becomes the DigiD pseudonym unless `yiviBsnToConsumer` is true. `SubjectEnvelope` carries `attributes` only for subtype `attributes`, and they survive signing and verifying.
  - The trust: the envelope takes the trust the mapper gives. `TrustLevelMapper` has no Yivi row until task 4 (D5), so on a live instance a Yivi callback still ends with `error=login_failed` and no code.
- [x] Test
  - `tests/Unit/Auth/Idp/YiviEnvelopeTest.php`: a round trip through the real login service and OIDC adapter over the stub issuer (with a mapper standing in for the D5 answer) gives an envelope with name and e-mail and none of the BSN or phone claims the stub also returned, verified after minting; a missing, blank or non-string e-mail claim throws the refusal with the way back and issues no code; BSN pseudonymised or passed on; the subject stable per disclosure and per organisation; an empty state or a withdrawn attribute refused; a DigiD envelope never carries attributes; with the real mapper the callback stays fail-closed. `IdpBrokerControllerFloorTest::testAYiviLoginThatSharedLessIsExplained` for the page.
  - Not written: `tests/postman/idp-yivi.json`. A Newman round trip needs the stub issuer reachable from an instance and cannot end in a code until task 4 maps the Yivi trust.

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
