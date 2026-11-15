# idp-broker-yivi Specification (delta)

## ADDED Requirements

### Requirement: Yivi is a provider on the broker, reached as an OIDC registration (REQ-YIVI-001)

Integriq MUST accept `yivi` as a provider on an `idpRegistration`, and MUST refuse to save a `yivi` registration whose kind is not `oidc-rp`. A `yivi` registration MUST list the Yivi attributes the organisation may ask for (`yiviAttributes`) and MUST state the trust its logins carry (`yiviTrust`, default `low`). *Beheer > Authenticatie* MUST offer Yivi as a provider and MUST show and edit the attribute list.

#### Scenario: an administrator adds Yivi for the municipality
- **WHEN** an administrator adds a registration for provider `yivi`, kind `oidc-rp`, with the bridge's issuer and two attributes
- **THEN** the registration is saved with `yiviTrust` `low`, and the screen lists it with its readiness
- @e2e exclude admin settings screen covered by the e2e of `portal-idp-broker-config`; the save rule is covered by PHPUnit

#### Scenario: Yivi over SAML is refused
- **WHEN** an administrator saves a `yivi` registration with kind `saml-sp`
- **THEN** the save is refused with a message that Yivi needs an OIDC bridge
- @e2e exclude backend validation; covered by PHPUnit

### Requirement: A login asks for exactly the attributes the consumer names (REQ-YIVI-002)

The browser start address for `yivi` MUST take a list of attribute ids. Integriq MUST refuse a start whose list is empty or names an attribute outside the registration's `yiviAttributes`, on its own error page and redirecting nowhere. Otherwise it MUST record the list in the signed state and MUST ask the bridge for each attribute as an essential claim.

#### Scenario: a form asks for name and e-mail
- **WHEN** portaliq starts a Yivi login with the attributes for full name and e-mail
- **THEN** the browser goes to the bridge with a `claims` request for exactly those two
- @e2e exclude cross-app browser redirect against a stub OIDC issuer; covered by PHPUnit and a Newman round trip

#### Scenario: an attribute the organisation did not allow
- **WHEN** a start names an attribute outside the registration's list
- **THEN** integriq shows its error page and does not redirect
- @e2e exclude backend refusal; covered by PHPUnit

### Requirement: A Yivi envelope carries only the disclosed attributes (REQ-YIVI-003)

After a verified callback, integriq MUST issue an envelope with `provider` `yivi`, `subType` `attributes`, a `sub` derived from the disclosure with the registration's salt, `trust` from `yiviTrust`, and `attributes` holding only the attributes recorded in the signed state. It MUST refuse the login when a requested attribute is missing from the ID token. A disclosed BSN attribute MUST be replaced by its pseudonym unless the registration allows the consumer to receive it.

#### Scenario: the resident shares what the form asked
- **WHEN** the stub issuer returns name and e-mail claims for a login that asked for both
- **THEN** the redeemed envelope carries both attributes, `trust` `low` and no other claim
- @e2e exclude cross-app browser redirect against a stub OIDC issuer; covered by PHPUnit and a Newman round trip

#### Scenario: the resident shares less than asked
- **WHEN** the ID token lacks the e-mail claim that was asked for
- **THEN** integriq refuses on its own page with "U heeft niet alle gevraagde gegevens gedeeld" and issues no code
- @e2e exclude backend refusal; covered by PHPUnit

### Requirement: The assurance floor applies to the government providers, a Yivi login carries its registration's trust (REQ-YIVI-004)

Integriq MUST keep the substantial floor of REQ-IDPC-006 for `digid`, `eherkenning` and `eidas`. For `yivi` it MUST set the envelope's trust from the registration's `yiviTrust`, and the consuming app's own minimum trust MUST decide what the resident may open.

#### Scenario: a Yivi login reaches a low-trust form
- **WHEN** a Yivi login with `yiviTrust` `low` completes
- **THEN** the envelope carries `trust` `low`, and a DigiD login at Basis for the same organisation is still refused
- @e2e exclude backend trust mapping; covered by PHPUnit
