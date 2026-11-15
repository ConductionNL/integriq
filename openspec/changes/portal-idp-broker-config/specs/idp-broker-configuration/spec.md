# idp-broker-configuration Specification (delta)

## ADDED Requirements

### Requirement: An identity provider adapter is one of two vendor-neutral kinds (REQ-IDPC-001)

Integriq MUST offer exactly two live adapter kinds behind
`GovernmentIdpAdapterInterface`: a SAML 2.0 Service Provider (`saml-sp`) and an
OIDC Relying Party (`oidc-rp`). The kind MUST be chosen per registration. No
class, constant, route, schema property or label MUST name a broker vendor;
the vendor MUST be expressed only through the URLs, identifiers and mappings an
administrator configures.

The `saml-sp` adapter MUST sign its authentication request with the
registration's signing credential, MUST verify the response signature against
the certificate in the identity provider's metadata, MUST decrypt an encrypted
assertion with the registration's decryption credential, and MUST read
attributes only from the verified assertion. The `oidc-rp` adapter MUST use the
authorization code flow with PKCE, `state` and `nonce`, MUST verify the ID
token against the issuer's JWKS, and MUST read the assurance level from the
`acr` claim. Both MUST return the normalised assertion shape `AssertionGuard`
reads.

#### Scenario: A SAML registration logs a resident in through the stub identity provider

- **GIVEN** a ready `saml-sp` registration for DigiD for organisation `gemeente-x`, pointing at the SAML stub identity provider
- **WHEN** a login is started and the stub answers with a signed assertion at level Substantieel
- **THEN** the callback SHALL redirect to the consumer with a one-time code
- **AND** the redeemed envelope SHALL carry `provider` `digid`, `trust` `substantial` and a pseudonym as `sub`
- @e2e exclude cross-app browser redirect against a stub identity provider; covered by PHPUnit and a Newman round trip

#### Scenario: An OIDC registration logs an employee in through the stub identity provider

- **GIVEN** a ready `oidc-rp` registration for eHerkenning, pointing at the OIDC stub issuer
- **WHEN** a login is started and the stub returns an ID token with `acr` mapped to EH3 and a KvK number
- **THEN** the redeemed envelope SHALL carry `subType` `kvk` and `trust` `substantial`
- @e2e exclude cross-app browser redirect against a stub identity provider; covered by PHPUnit and a Newman round trip

#### Scenario: A forged or unsigned response is refused

- **GIVEN** a ready `saml-sp` registration
- **WHEN** the callback receives a response whose signature does not verify, or an assertion with an extra unsigned node carrying another subject
- **THEN** no code SHALL be issued and no attribute of the unverified content SHALL be read
- @e2e exclude backend signature verification; covered by PHPUnit against the SAML stub

#### Scenario: No vendor name ships in code

- **GIVEN** the integriq source tree
- **WHEN** `lib/`, `src/`, `appinfo/` and `lib/Settings/` are searched for broker vendor names
- **THEN** there SHALL be no match
- @e2e exclude static source check; covered by a PHPUnit source guard

### Requirement: Each organisation has its own registration per provider (REQ-IDPC-002)

Identity provider configuration MUST be stored as `idpRegistration` objects in
the integriq register, one per organisation and provider. A second
registration for the same organisation and provider MUST be refused. The
object MUST carry the kind, the enabled flag, the identity provider's metadata
URL (SAML) or issuer (OIDC), our EntityID (SAML) or client id (OIDC), the
subject attribute or claim mapping for `bsn`, `pseudonym`, `kvk`, `rsin`,
`branch` and `eidasPersonIdentifier`, the assurance level mapping, the
pseudonymisation mode and credential references. It MUST NOT carry a secret
value in any property. Only administrators MUST be able to read or write it.

A login MUST use the registration of the organisation named in the stored
state, and `AssertionGuard` MUST check the audience against that
registration's EntityID or client id. The app config keys
`idp_broker_entity_ids`, `idp_broker_salts` and `idp_broker_trust_aliases`
MUST no longer be read.

#### Scenario: Two organisations log in through their own registrations

- **GIVEN** ready DigiD registrations for `gemeente-x` and `gemeente-y`, each with its own EntityID
- **WHEN** a login started for `gemeente-x` returns an assertion addressed to the EntityID of `gemeente-y`
- **THEN** the assertion SHALL be refused on the audience check and no code SHALL be issued
- @e2e exclude backend audience check; covered by PHPUnit

#### Scenario: A duplicate registration is refused

- **GIVEN** a DigiD registration for `gemeente-x`
- **WHEN** an administrator saves a second DigiD registration for `gemeente-x`
- **THEN** the save SHALL be refused with a message naming the existing registration
- @e2e exclude uniqueness enforced on save; covered by PHPUnit, the screen's handling by the settings e2e

#### Scenario: A non-administrator cannot read a registration

- **GIVEN** a registration and a signed-in user outside the admin group
- **WHEN** that user requests the `idpRegistration` objects through the OpenRegister API
- **THEN** no registration SHALL be returned
- @e2e exclude RBAC through the OpenRegister API; covered by Newman

### Requirement: Every SP is published at its own metadata URL (REQ-IDPC-003)

Integriq MUST serve `GET /apps/integriq/api/idp/metadata/{organisation}/{provider}`
as a public, throttled endpoint. For a `saml-sp` registration it MUST answer
SP metadata XML with the EntityID, the public signing and encryption
certificates, the assertion consumer service for both bindings and the
requested attributes. For an `oidc-rp` registration it MUST answer a JSON
document with the client id, the redirect URI and the JWKS URI. The default
SAML EntityID MUST be this URL. An unknown organisation, an unknown provider,
a missing registration and a disabled registration MUST all answer the same
404. A registration whose certificate cannot be read MUST answer 503 and MUST
NOT include any part of the credential in the response or the log.

#### Scenario: A broker fetches an organisation's SAML metadata

- **GIVEN** an enabled `saml-sp` DigiD registration for `gemeente-x` with a readable signing certificate
- **WHEN** `GET /apps/integriq/api/idp/metadata/gemeente-x-uuid/digid` is requested without authentication
- **THEN** the response SHALL be `application/samlmetadata+xml` naming the registration's EntityID and the callback `/apps/integriq/api/idp/digid/callback`
- **AND** it SHALL contain the certificate and no private key material
- @e2e exclude machine endpoint; covered by PHPUnit and Newman

#### Scenario: The endpoint does not reveal which organisations exist

- **GIVEN** organisation `gemeente-x` with a disabled registration and an organisation id that does not exist
- **WHEN** metadata is requested for both
- **THEN** both SHALL answer 404 with the same body
- @e2e exclude machine endpoint; covered by Newman

### Requirement: Identity provider secrets are read from Keepiq and fail closed (REQ-IDPC-004)

Every key, certificate and salt a registration needs MUST be referenced as an
OpenRegister credential and read through
`CredentialBrokerService::resolveInjectable()` at the moment of use, with
integriq as the app and the registration's organisation as the acting
organisation. Integriq MUST refuse before reading when Keepiq (app id
`keepiq`) is not installed or not enabled, when the broker or its
`resolveInjectable()` method is absent, and when a required reference is
empty, unknown, not allowed for integriq or resolves to an empty value. A
refusal MUST bind the Log adapter and MUST never be treated as a skipped
check. A secret value MUST NOT be written to app config, an OpenRegister
object, a log line, a response or an error message. Integriq MUST NOT cache a
secret beyond the request that uses it, so a value rotated in Keepiq is used
from the next login on.

#### Scenario: Without Keepiq nobody logs in

- **GIVEN** a registration that is complete and enabled, on an instance where Keepiq is disabled
- **WHEN** a login is started
- **THEN** integriq SHALL refuse on its own error page and SHALL NOT redirect to the identity provider
- **AND** the registration's status SHALL be `keepiq-missing`
- @e2e exclude backend seam; covered by PHPUnit with Keepiq absent

#### Scenario: A missing secret refuses the login and names its role

- **GIVEN** a `saml-sp` registration whose `signing` reference resolves to nothing
- **WHEN** a login is started
- **THEN** the login SHALL be refused and the log SHALL name the registration and the role `signing`, and no value
- @e2e exclude backend seam; covered by PHPUnit

#### Scenario: A rotated key is used on the next login

- **GIVEN** a ready registration whose signing credential is rotated in Keepiq
- **WHEN** the next login is started
- **THEN** the authentication request SHALL be signed with the new key
- @e2e exclude backend seam; covered by PHPUnit with a broker double returning a new value

### Requirement: Pseudonymisation mode is chosen per registration (REQ-IDPC-005)

A registration MUST choose `polymorphic` or `hmac` pseudonymisation, `hmac`
by default. With `polymorphic` the adapter MUST decrypt the polymorphic
pseudonym with the `pseudonymKeys` credential and MUST refuse an assertion
that carries a plain BSN instead. With `hmac` the callback MUST compute the
pseudonym in memory from the BSN, the organisation and the `salt` credential,
and MUST discard the BSN. The envelope MUST carry `subType` `bsn-pseudonym` in
both modes.

#### Scenario: A polymorphic registration refuses a plain BSN

- **GIVEN** a DigiD registration with `pseudonymisation` `polymorphic`
- **WHEN** the assertion carries a BSN attribute and no polymorphic pseudonym
- **THEN** no code SHALL be issued and the BSN SHALL NOT appear in any log line
- @e2e exclude backend pseudonymisation; covered by PHPUnit

#### Scenario: An HMAC registration yields a stable pseudonym per organisation

- **GIVEN** DigiD registrations with `hmac` for `gemeente-x` and `gemeente-y`, each with its own salt in Keepiq
- **WHEN** the same resident logs in to both twice
- **THEN** each organisation SHALL see the same pseudonym on both logins
- **AND** the two organisations SHALL see different pseudonyms
- @e2e exclude backend pseudonymisation; covered by PHPUnit

### Requirement: Substantial is the assurance floor (REQ-IDPC-006)

Integriq MUST refuse every login whose trust is below `substantial`. A start
that asks for `low` MUST be refused on integriq's error page. The SAML
authentication request MUST ask for at least the registration's substantial
level with `Comparison="minimum"`, and the OIDC request MUST send `acr_values`
for substantial and high only. An assertion whose mapped trust is `low` MUST
be refused at the callback whatever the start asked for. DigiD Basis and
Midden, eHerkenning EH2 and EH2+, and eIDAS low MUST be refused. DigiD
Substantieel and Hoog, eHerkenning EH3 and EH4, and eIDAS substantial and high
MUST be accepted when they satisfy the trust the start asked for. The floor
MUST NOT be configurable.

The callback refusal MUST show integriq's own page, in Dutch and English,
naming the level used, stating that the service needs at least substantial,
and saying how to obtain it for that provider. The page MUST offer one action
back to the consumer's return address with `error=assurance_below_floor` and
the relay state, and MUST NOT issue a code.

#### Scenario: DigiD Midden is refused with a clear message

- **GIVEN** a ready DigiD registration and a login started for trust `substantial`
- **WHEN** the identity provider answers with an assertion at level Midden
- **THEN** no code SHALL be issued
- **AND** the page SHALL say that the resident logged in at DigiD Midden, that this service needs at least Substantieel, and that the DigiD app with an identity check provides it
- **AND** its one action SHALL lead to the return address with `error=assurance_below_floor` and the relay state

#### Scenario: eHerkenning EH3 is accepted

- **GIVEN** a ready eHerkenning registration and a login started for trust `substantial`
- **WHEN** the assertion carries EH3
- **THEN** the envelope SHALL carry `trust` `substantial`
- @e2e exclude backend trust mapping; covered by PHPUnit

#### Scenario: eHerkenning EH2+ is refused

- **GIVEN** a ready eHerkenning registration
- **WHEN** the assertion carries EH2+
- **THEN** no code SHALL be issued and the page SHALL say that this service needs EH3 or higher
- @e2e exclude backend trust floor against the stub identity provider; covered by PHPUnit

#### Scenario: A start asking for low is refused

- **GIVEN** a registered consumer
- **WHEN** the start is called with trust `low`
- **THEN** integriq's error page SHALL show and the browser SHALL NOT be redirected to the identity provider
- @e2e exclude backend start validation; covered by PHPUnit

#### Scenario: The request asks the identity provider for substantial at least

- **GIVEN** a ready `saml-sp` registration whose assurance mapping names the provider's substantial spelling
- **WHEN** the authentication request is built
- **THEN** it SHALL carry `RequestedAuthnContext` with `Comparison="minimum"` and that spelling
- @e2e exclude backend request building; covered by PHPUnit

### Requirement: Readiness is one answer for the screen and the runtime (REQ-IDPC-007)

Integriq MUST compute one readiness status per registration: `ready`,
`flag-off`, `incomplete`, `secret-missing`, `keepiq-missing` or `disabled`,
with the reasons as text that names properties and credential roles and never
values. The adapter registry MUST bind a live adapter only for `ready`, and
the settings screen MUST show the same status the registry acts on.

#### Scenario: An incomplete registration is not bound

- **GIVEN** an enabled `oidc-rp` registration without a client id
- **WHEN** a login is started for its organisation
- **THEN** the Log adapter SHALL refuse it
- **AND** the screen SHALL show `incomplete` naming the client id
- @e2e exclude backend binding; the screen side is covered by the settings e2e

### Requirement: Beheer > Authenticatie configures the registrations (REQ-IDPC-008)

Integriq's admin settings MUST carry an "Authenticatie" section that lists
every registration with organisation, provider, kind, readiness status with
its reasons, and metadata URL with a copy action. When there is none it MUST
show an empty state explaining what a registration is, with an "Add
registration" action. Adding and editing MUST happen in a modal that shows
only the fields of the chosen kind and picks credential references from the
OpenRegister credentials integriq may use, never accepting or showing a
secret value. Each row MUST offer "Test", "Enable" or "Disable", "Download
metadata" and "Delete"; "Delete" MUST ask for confirmation naming the
organisation whose logins stop. "Test" MUST fetch the identity provider's
metadata or discovery document and resolve every required reference, and
MUST report per check what passed and what failed without showing a secret or
starting a login. When the broker flag is `0` or Keepiq is missing the section
MUST say so above the table. The section MUST be reachable only by
administrators and MUST NOT be a route in the app's router.

#### Scenario: An administrator adds a SAML registration

- **GIVEN** an administrator on the admin settings page with no registrations
- **WHEN** they choose "Add registration", pick organisation `gemeente-x`, provider DigiD, kind SAML, enter the metadata URL and pick the signing and salt credentials, and save
- **THEN** the table SHALL show the registration with its metadata URL and a status
- **AND** no field in the modal SHALL have shown a secret value

#### Scenario: Test reports a missing credential by role

- **GIVEN** a registration whose salt credential is deleted from Keepiq
- **WHEN** the administrator runs "Test"
- **THEN** the result SHALL list the metadata check as passed and the `salt` credential as failed
- **AND** the status SHALL read `secret-missing`

#### Scenario: Without Keepiq the screen says why nothing can be ready

- **GIVEN** Keepiq is disabled
- **WHEN** the administrator opens the section
- **THEN** a note SHALL say that identity provider keys live in Keepiq and that no registration can log anyone in until it is enabled

#### Scenario: A non-administrator cannot reach the section

- **GIVEN** a signed-in user outside the admin group
- **WHEN** they open integriq's admin settings URL
- **THEN** Nextcloud SHALL deny the page and no registration SHALL be shown
- @e2e exclude Nextcloud's settings framework enforces admin access; covered by the admin-router and route-auth gates
