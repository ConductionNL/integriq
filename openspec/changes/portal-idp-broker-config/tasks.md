# Tasks: portal-idp-broker-config

Kind: code. Size L. Rows `id-digid`, `id-eherkenning`, `id-portal-idp`.
Decision 94 (Ruben, 8 October 2026) answers D1 to D5; `design.md` carries the
answers as D1 to D8. Everything stays inert until a registration is ready and
the flag is `1`.

## Implementation tasks

### Task 1: Choose the SAML library
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001`
- **files**: `composer.json`, `composer.lock`, `openspec/changes/portal-idp-broker-config/design.md`
- **acceptance_criteria**:
  - GIVEN the candidate libraries WHEN one is chosen THEN it is maintained, defends against XML signature wrapping, disables external entities, and `composer audit` reports no advisory for it
  - GIVEN the choice WHEN it is made THEN `design.md` D1 records the library, the version and why, in one paragraph
- [ ] Implement
- [ ] Test

### Task 2: The `idpRegistration` schema
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002`
- **files**: `lib/Settings/register.d/40-idp-registration.json` (pick the next free prefix), `lib/Auth/Idp/IdpRegistration.php`, `lib/Auth/Idp/IdpRegistrationService.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register imports THEN the schema carries the properties in `design.md` D2, every property has a `title`, and `credentials` holds credential ids only
  - GIVEN a second registration for the same organisation and provider WHEN it is saved THEN the save is refused with a message naming the existing one
  - GIVEN a user outside the admin group WHEN they list `idpRegistration` objects THEN nothing is returned
  - GIVEN the re-import WHEN the log is read THEN there is no `PARTIAL IMPORT` line for this schema
- [ ] Implement
- [ ] Test

### Task 3: The secret seam
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-identity-provider-secrets-are-read-from-keepiq-and-fail-closed-req-idpc-004`
- **files**: `lib/Auth/Idp/IdpSecretSeam.php`, `tests/Unit/Auth/Idp/IdpSecretSeamTest.php`
- **acceptance_criteria**:
  - GIVEN Keepiq not installed or disabled WHEN any reference is read THEN the seam refuses before calling the broker
  - GIVEN no broker or no `resolveInjectable()` WHEN a reference is read THEN the seam refuses
  - GIVEN an empty, unknown or empty-resolving reference WHEN it is read THEN the seam refuses and logs the registration and the role, never a value
  - GIVEN two reads in two requests and a value changed in between WHEN the second read runs THEN it returns the new value (no cache across requests)
  - GIVEN the class WHEN grepped THEN there is no `catch (Throwable)` that returns null or an empty "skip"
- [ ] Implement
- [ ] Test

### Task 4: Readiness
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-readiness-is-one-answer-for-the-screen-and-the-runtime-req-idpc-007`
- **files**: `lib/Auth/Idp/IdpRegistrationService.php`, `lib/Auth/Idp/IdpAdapterRegistry.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN each of the six statuses WHEN readiness is computed THEN it returns that status with reasons naming properties and roles only
  - GIVEN any status but `ready` WHEN `forRegistration()` is called THEN the Log adapter is returned
  - GIVEN `ready` WHEN `forRegistration()` is called THEN the adapter of the registration's kind is returned
- [ ] Implement
- [ ] Test

### Task 5: The SAML Service Provider adapter
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001`
- **files**: `lib/Auth/Idp/Adapter/SamlServiceProviderAdapter.php`, `tests/Unit/Auth/Idp/Adapter/SamlServiceProviderAdapterTest.php`, `tests/Fixtures/Idp/SamlStubIdentityProvider.php`
- **acceptance_criteria**:
  - GIVEN the stub identity provider (keys generated in the test, never committed) WHEN a login runs THEN the request is signed, the response verifies, an encrypted assertion decrypts, and the normalised assertion matches what `AssertionGuard` reads
  - GIVEN a response with a bad signature, a wrapped assertion, or an external entity WHEN it is read THEN it is refused and no attribute is read
  - GIVEN the registration's `subjectMapping` WHEN attributes arrive THEN `bsn`, `pseudonym`, `kvk`, `rsin`, `branch` and `eidasPersonIdentifier` are read from the mapped names only
- [ ] Implement
- [ ] Test

### Task 6: The OIDC Relying Party adapter
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001`
- **files**: `lib/Auth/Idp/Adapter/OidcRelyingPartyAdapter.php`, `tests/Unit/Auth/Idp/Adapter/OidcRelyingPartyAdapterTest.php`, `tests/Fixtures/Idp/OidcStubIssuer.php`
- **acceptance_criteria**:
  - GIVEN the stub issuer WHEN a login runs THEN the request carries PKCE, `state`, `nonce` and `acr_values` for substantial and high, and the ID token verifies against the stub JWKS
  - GIVEN `private_key_jwt` WHEN the token request is made THEN the client assertion is signed with the `signing` credential; GIVEN `client_secret_basic` THEN the `clientSecret` credential is used
  - GIVEN a wrong `nonce`, a wrong `aud`, an expired token or a bad signature WHEN the callback runs THEN it is refused
- [ ] Implement
- [ ] Test

### Task 7: Per-registration audience and pseudonymisation
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-pseudonymisation-mode-is-chosen-per-registration-req-idpc-005`
- **files**: `lib/Auth/Idp/IdpLoginService.php`, `lib/Auth/Idp/AssertionGuard.php`, `lib/Auth/Idp/SubjectPseudonymService.php`, `lib/Auth/Idp/IdpBrokerConfig.php`
- **acceptance_criteria**:
  - GIVEN a login for organisation A WHEN the assertion names organisation B's EntityID THEN it is refused on the audience check
  - GIVEN `polymorphic` and an assertion with a plain BSN WHEN the callback runs THEN it is refused and the BSN is in no log line
  - GIVEN `hmac` WHEN the same BSN logs in to two organisations THEN the pseudonyms differ, and stay stable per organisation
  - GIVEN `IdpBrokerConfig` WHEN read THEN it no longer reads `idp_broker_entity_ids`, `idp_broker_salts` or `idp_broker_trust_aliases`; the assurance aliases come from the registration's `assuranceMapping`
- [ ] Implement
- [ ] Test

### Task 8: The assurance floor and its refusal page
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006`
- **files**: `lib/Auth/Idp/TrustLevelMapper.php`, `lib/Auth/Idp/IdpLoginService.php`, `lib/Controller/IdpBrokerController.php`, `templates/idp/refused.php`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN `TrustLevelMapper::FLOOR` WHEN read THEN it is `substantial` and no setting changes it
  - GIVEN a start asking for `low` WHEN it runs THEN integriq's error page shows and nothing redirects
  - GIVEN assertions at DigiD Basis, DigiD Midden, EH2, EH2+ and eIDAS low WHEN the callback runs THEN each is refused with no code; GIVEN DigiD Substantieel and Hoog, EH3, EH4, eIDAS substantial and high THEN each is accepted when it satisfies the requested trust
  - GIVEN a refusal below the floor WHEN the page renders THEN it names the level used, the level needed and how to get it for that provider, in Dutch and English, and its one action goes to the return address with `error=assurance_below_floor` and the relay state
  - GIVEN the page copy WHEN written THEN it follows the `writing` skill (no em-dashes, sentence case) and is reviewed in REVIEW mode before merge
- [ ] Implement
- [ ] Test

### Task 9: The metadata endpoint
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003`
- **files**: `lib/Controller/IdpMetadataController.php`, `appinfo/routes.php`, `tests/Unit/Controller/IdpMetadataControllerTest.php`
- **acceptance_criteria**:
  - GIVEN the route WHEN declared THEN it carries `#[PublicPage]`, `#[NoCSRFRequired]` and brute-force throttling, and the route-auth and route-reachability gates pass
  - GIVEN a `saml-sp` registration WHEN fetched THEN the XML names the EntityID, the certificates (public parts), the callback for both bindings and the requested attributes, and validates against the SAML metadata XSD
  - GIVEN an `oidc-rp` registration WHEN fetched THEN the JSON carries client id, redirect URI and JWKS URI and no secret
  - GIVEN an unknown organisation, unknown provider, missing or disabled registration WHEN fetched THEN each answers the same 404
  - GIVEN an unreadable certificate WHEN fetched THEN 503, and neither the body nor the log carries credential bytes
- [ ] Implement
- [ ] Test

### Task 10: The Beheer > Authenticatie section
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008`
- **files**: `src/views/admin/IdpRegistrationSettings.vue`, `src/views/admin/AdminSettings.vue`, `src/modals/IdpRegistrationModal.vue`, `src/dialogs/IdpRegistrationDeleteDialog.vue`, `lib/Controller/IdpRegistrationController.php` (test action only; CRUD goes through the OpenRegister object store), `appinfo/routes.php`, `l10n/`
- **acceptance_criteria**:
  - GIVEN the admin settings page WHEN it renders THEN an "Authenticatie" section lists registrations with organisation, provider, kind, status with reasons and metadata URL with a copy action, or an empty state with "Add registration"
  - GIVEN the modal WHEN a kind is chosen THEN only that kind's fields show; credential references are `NcSelect` pickers with an `inputLabel`; no field accepts or shows a secret
  - GIVEN "Test" WHEN run THEN each check (metadata or discovery, each required reference) reports passed or failed by name, nothing secret is shown, no login starts; the test endpoint is admin-only (`#[AuthorizedAdminSetting]`)
  - GIVEN "Delete" WHEN chosen THEN a dialog names the organisation whose logins stop
  - GIVEN the flag at `0` or Keepiq missing WHEN the section renders THEN a note card says so above the table
  - GIVEN the router WHEN read THEN the section is not a route (admin-router gate), and the modal-isolation and nc-input-labels gates pass
- [ ] Implement
- [ ] Test

### Task 11: Repair step for the superseded keys
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002`
- **files**: `lib/Repair/WarnSupersededIdpBrokerKeys.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN any of the three superseded keys holding a value WHEN the repair step runs THEN it logs one warning per key, naming the key and what replaces it, and never the value
  - GIVEN none set WHEN it runs THEN it logs nothing
- [ ] Implement
- [ ] Test

### Task 12: End to end, through the stubs
- **spec_ref**: `openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006`
- **files**: `tests/e2e/idp-registration-settings.spec.ts`, `tests/e2e/idp-assurance-floor.spec.ts`, `tests/postman/idp-broker.postman_collection.json`
- **acceptance_criteria**:
  - GIVEN the settings e2e WHEN it runs THEN it covers adding a SAML registration, "Test" reporting a missing credential by role, and the Keepiq-missing note, each tagged with its scenario
  - GIVEN the floor e2e WHEN the stub answers at DigiD Midden THEN the refusal page shows the message and its action carries `error=assurance_below_floor`
  - GIVEN the Newman collection WHEN run THEN it covers a SAML and an OIDC round trip through the stubs, metadata 200, 404 and 503, and RBAC on `idpRegistration`
- [ ] Implement
- [ ] Test

### Task 13: Documentation and the catalogue entry
- **spec_ref**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-configuration-placement-follows-adr-017-rules-1-3-7`
- **files**: the broker documentation page, the *Adapters* catalogue entry `digid-eherkenning-auth-adapter`
- **acceptance_criteria**:
  - GIVEN the documentation page WHEN read THEN it walks an administrator through one registration per kind, says keys live in Keepiq, states the floor, and names Berichtenbox linkage as out of scope
  - GIVEN the catalogue entry WHEN opened THEN it links to *Beheer > Authenticatie* and creates no configuration of its own
- [ ] Implement
- [ ] Test

## Not done, and named as not done

- Berichtenbox identity linkage (decision 94, D5).
- Single logout.
- The envelope signing key in Keepiq (design non-goal).
- An OpenRegister accessor that says which store holds a credential, so the
  Keepiq check is exact (design D4 follow-up, an OpenRegister change).
