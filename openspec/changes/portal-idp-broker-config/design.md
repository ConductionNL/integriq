# Design: portal-idp-broker-config

## Context

What exists on `development`:

- `lib/Auth/Idp/`: `TrustLevelMapper`, `SubjectPseudonymService`,
  `SubjectEnvelope(Service)`, `EnvelopeReplayGuard`, `EnvelopeCodeStore`,
  `AssertionGuard`, `EnvelopeExchangeService`, `IdpLoginService`,
  `IdpLoginStateStore`, `IdpAdapterRegistry`, `IdpConsumer`,
  `IdpConsumerSecretResolver`, `IdpBrokerConfig`,
  `GovernmentIdpAdapterInterface` and `LogGovernmentIdpAdapter`.
- Routes `idpBroker#start`, `#callback` (GET and POST) and `#exchange`.
- `IdpBrokerConfig` reads six app config keys: the flag, the envelope signing
  key, the consumers, the salts, the trust aliases and the entity ids. The last
  three are single-tenant JSON maps keyed by provider or organisation.
- `lib/AppInfo/Application.php` binds every provider to
  `LogGovernmentIdpAdapter`, which logs and throws.

Decision 94 (Ruben, 8 October 2026) answers D1 to D5 of the archived
`portal-idp-broker` change. This design turns those answers into the adapter,
the registration, the secret seam, the metadata endpoint, the floor and the
screen.

## Goals and non-goals

**Goals**

- A live adapter for each of the two protocols a broker or identity provider
  can speak, with no vendor name in code.
- One registration per organisation and provider, multi-tenant from the start.
- Every secret read from Keepiq at the moment of use, and no login when that
  read fails.
- Substantial as a floor nobody can configure away.
- A screen an administrator can set all of this up from, without `occ`.

**Non-goals**

- Berichtenbox identity linkage (D5, deferred).
- Single logout.
- Rotation (Keepiq's).
- Moving the envelope signing key into Keepiq. It is integriq's own key, not
  an identity provider credential, and moving it changes the exchange contract
  for every consumer. Named as a follow-up below.

## Decisions

### D1. Two adapter kinds behind the existing interface

`GovernmentIdpAdapterInterface` stays the seam. Two implementations arrive:

- `Adapter\SamlServiceProviderAdapter`: builds a signed `AuthnRequest` (HTTP
  Redirect or POST binding, from the identity provider's metadata), reads the
  `Response` on the assertion consumer service, verifies the signature against
  the identity provider's certificate from its metadata, decrypts an encrypted
  assertion with our decryption key, and normalises it into the shape
  `AssertionGuard` reads.
- `Adapter\OidcRelyingPartyAdapter`: authorization code flow with PKCE,
  `state` and `nonce`, client authentication by `private_key_jwt` or
  `client_secret_basic`, ID token verified against the issuer's JWKS, `acr`
  read as the assurance level.

The registration names the kind. Nothing in code says Signicat, OneWelcome,
Logius or any other party: the vendor is the URL and the mapping an
administrator enters. A broker that speaks both protocols is configured as
whichever the contract uses.

`IdpAdapterRegistry::forProvider()` gains the organisation:
`forRegistration(organisation, provider)`. It returns a live adapter only when
the registration exists, is enabled, is complete (D6) and the flag is `1`.
Every other case returns the Log adapter, so the existing refusal path stays
the only path when anything is missing.

**Alternatives considered.** One adapter per vendor: rejected by D1, and it
would put contract details in code that change when a municipality changes
broker. One adapter per provider (DigiD, eHerkenning, eIDAS): rejected, the
protocol differs per broker contract, not per provider; DigiD can arrive over
SAML from one broker and over OIDC from another.

**SAML library.** The archived design left this open. It is an engineering
choice, made in task 1: a maintained library with XML signature wrapping
defences and no open advisories in `composer audit`. Hand-rolled XML signature
verification is not an option.

### D2. Per-organisation registration as an OpenRegister object

Schema `idpRegistration` in a `lib/Settings/register.d/` fragment of the
integriq register. Integriq's design rule and ADR-022 put entities in
OpenRegister; app config cannot hold one object per organisation without
becoming a hand-rolled table.

| Property | Type | Notes |
| --- | --- | --- |
| `organisation` | uuid | OpenRegister organisation the logins are for. Unique together with `provider`. |
| `provider` | enum `digid`, `eherkenning`, `eidas` | |
| `kind` | enum `saml-sp`, `oidc-rp` | |
| `enabled` | boolean | Default `false`. |
| `label` | string | Shown on the screen. |
| `idpMetadataUrl` | uri | SAML only. The identity provider's or broker's metadata. |
| `issuer` | uri | OIDC only. Discovery is read from `{issuer}/.well-known/openid-configuration`. |
| `spEntityId` | string | SAML only. Defaults to the metadata URL (D3); editable because some schemes assign an EntityID. |
| `clientId` | string | OIDC only. |
| `clientAuth` | enum `private_key_jwt`, `client_secret_basic` | OIDC only. |
| `subjectMapping` | object | Attribute (SAML) or claim (OIDC) name per subject field: `bsn`, `pseudonym`, `kvk`, `rsin`, `branch`, `eidasPersonIdentifier`. |
| `assuranceMapping` | object | Spelling the identity provider sends, mapped to a provider level the trust table knows (`substantieel`, `hoog`, `eh3`, `eh4`, `substantial`, `high`, and the refused lower ones). Replaces `idp_broker_trust_aliases`. |
| `pseudonymisation` | enum `polymorphic`, `hmac` | Default `hmac`. |
| `credentials` | object | Credential references only, see D4. |

The schema is admin-only through OpenRegister RBAC: no read or write for
anyone outside the admin group. It holds no secret, so a leak of the object is
a leak of configuration, not of a key.

**Alternatives considered.** Keep the app config maps and key them by
organisation: rejected, three parallel maps that must agree per organisation
are the drift ADR-017 Rule 3 warns about, and there is no list to render.

### D3. A metadata URL per organisation

`GET /apps/integriq/api/idp/metadata/{organisation}/{provider}`, public,
no CSRF, brute-force throttled.

- For a `saml-sp` registration: SP metadata XML
  (`application/samlmetadata+xml`) with the EntityID, the signing and
  encryption certificates (public parts, read through the seam), the
  assertion consumer service `/apps/integriq/api/idp/{provider}/callback` for
  both bindings, and the requested attributes.
- For an `oidc-rp` registration: a JSON document with the client id, the
  redirect URI and the JWKS URI of our `private_key_jwt` key, which is what a
  broker asks for when registering a client.
- An unknown organisation, an unknown provider, a missing registration and a
  disabled one all answer the same 404. The endpoint does not tell a caller
  which organisations exist.
- A registration whose certificate cannot be read answers 503 and logs the
  credential reference, never a byte of the certificate.

The default EntityID is this URL itself, which is the common convention and
makes each organisation's SP unique without the administrator choosing a name.
The assertion consumer service stays shared: the stored state already names
the organisation, so the callback finds the registration from the state, and
`AssertionGuard` checks the audience against that registration's EntityID.

### D4. Secrets: OpenRegister credential references, bytes in Keepiq

Each registration names its secrets as OpenRegister credential ids:

| Reference | Used for | Required when |
| --- | --- | --- |
| `signing` | SP private key and certificate; signs the `AuthnRequest`, or the `private_key_jwt` client assertion. | Always for `saml-sp`; for `oidc-rp` with `private_key_jwt`. |
| `decryption` | Decrypts an encrypted SAML assertion. | `saml-sp` when the identity provider encrypts. |
| `clientSecret` | OIDC client secret. | `oidc-rp` with `client_secret_basic`. |
| `pseudonymKeys` | Decrypts a polymorphic pseudonym to our per-service pseudonym. | `pseudonymisation` is `polymorphic`. |
| `salt` | The per-organisation HMAC salt. | `pseudonymisation` is `hmac`. |

`IdpSecretSeam` reads them with
`CredentialBrokerService::resolveInjectable(credentialId, 'integriq', null, organisation)`,
the call `BrokeredCallService` and `IdpConsumerSecretResolver` already make.
OpenRegister's `CredentialStoreResolver` puts the bytes in Keepiq whenever
Keepiq is eligible (OpenRegister ADR-004, `DoriathCredentialStore`).

Fail closed, in this order, before any secret is read:

1. Keepiq is not installed or not enabled (`IAppManager`, app id `keepiq`):
   refuse. OpenRegister would otherwise fall back to its Nextcloud credential
   store, and decision 94 puts these keys in Keepiq, not anywhere a fallback
   lands.
2. The OpenRegister broker class or `resolveInjectable()` is absent: refuse.
3. The reference is empty, unknown, not allowed for integriq, or resolves to
   an empty value: refuse.

A refusal is never "skip the check". The secret is held in memory for the
request that uses it and never written to app config, an object, a log line
or an error message. Rotation happens in Keepiq; because integriq reads at use
time, the next login uses the new key.

Keepiq's machine API (`/api/v1/app/secrets/by-name/{name}`, keepiq spec
`secret-store-api`) is the other way an app reaches Keepiq. It is not used
here: integriq already holds every other secret through the OpenRegister
broker, and a second path would mean a second registration in Keepiq and a
second private key for integriq to keep.

**Follow-up, not blocking.** OpenRegister's broker does not say which store
holds a given credential, so check 1 proves Keepiq is present rather than that
this credential sits in it. A store accessor on the broker would make the
check exact; it belongs in an OpenRegister change.

### D5. Pseudonymisation per registration

`SubjectPseudonymService` already has both modes. The registration picks one:

- `polymorphic`: the broker delivers an encrypted polymorphic pseudonym; the
  adapter decrypts it with `pseudonymKeys` to our per-service pseudonym. The
  BSN never reaches integriq.
- `hmac`: the assertion carries the BSN; the callback computes the salted HMAC
  in memory with `salt` and the organisation, and the BSN is discarded.

A `polymorphic` registration whose assertion carries a plain BSN is refused
rather than falling back to HMAC, so a broker misconfiguration cannot quietly
move BSNs into integriq.

### D6. Readiness, one function for the screen and the runtime

`IdpRegistrationService::readiness(registration)` answers one status and the
reasons behind it:

- `ready`: enabled, complete, Keepiq present, every required reference
  resolves, the flag is `1`.
- `flag-off`: everything else is ready but the broker flag is `0`.
- `incomplete`: a required property for its kind is empty (named).
- `secret-missing`: a required reference does not resolve (named by role,
  never by value).
- `keepiq-missing`: Keepiq is not installed or not enabled.
- `disabled`: `enabled` is `false`.

The registry binds a live adapter only on `ready`, and the screen shows the
same answer. One function means the screen cannot say ready while the login
refuses.

### D7. Substantial is the floor

`TrustLevelMapper` keeps its table, including the rows that map DigiD Basis
and Midden, eHerkenning EH2 and EH2+ and eIDAS low onto `low`. Mapping them is
what lets integriq say *why* a login is refused. A new constant
`TrustLevelMapper::FLOOR = 'substantial'` is applied in three places:

1. **Start.** A start asking for `low` is refused on integriq's error page.
   A start may ask for `high`.
2. **Request.** The SAML `RequestedAuthnContext` uses
   `Comparison="minimum"` with the registration's spelling for substantial;
   the OIDC request sends `acr_values` with the substantial and high
   spellings. A compliant identity provider never offers a lower means.
3. **Callback.** An assertion whose mapped trust is below the floor is
   refused, whatever the start asked for.

The floor is a constant, not a setting. A tenant that wants Basis or Midden
needs a new decision, not a checkbox.

The callback refusal is the one failure that is not generic (modified
REQ-IDP-002). Integriq renders its own page, in Dutch and English, saying
which level was used, that this service needs at least substantial, and how to
get it: for DigiD, the DigiD app with an identity check or a means at level
Substantieel; for eHerkenning, a means at EH3 or higher. The page has one
button back to the consumer's return address with `error=assurance_below_floor`
and the relay state, so the consumer can resume. The message lives in integriq
once, instead of in every consumer. Naming the level the user just used leaks
nothing the user did not choose.

### D8. The *Beheer > Authenticatie* screen

ADR-017 Rule 3 puts identity provider configuration in *Beheer >
Authenticatie*, and Rule 7 sanctions the split with the *Adapters* catalogue
entry. In today's app *Beheer* is the admin settings page
(`src/views/admin/AdminSettings.vue`, `CnAdminSettingsShell`), where the DSO
PKI, Berichtenbox and webhook sections already live. A new section
`IdpRegistrationSettings.vue` goes there, titled "Authenticatie".

- A table of registrations: organisation, provider, kind, status (D6, with
  the reasons as text), and the metadata URL with a copy action.
- An empty state that says what a registration is and offers "Add
  registration".
- "Add registration" and the row's "Edit" open `IdpRegistrationModal.vue`
  (in `src/modals/`, per the modal isolation gate). The modal shows the
  fields of the chosen kind only. Credential references are picked with an
  `NcSelect` over the OpenRegister credentials integriq may use, each with an
  `inputLabel`; the modal never shows or accepts a secret value.
- Row actions: "Test", "Enable" or "Disable", "Download metadata", "Delete"
  with a confirmation dialog in `src/dialogs/` naming the organisation whose
  logins stop.
- "Test" fetches the identity provider's metadata or discovery document and
  resolves every required reference, then reports per check what passed and
  what failed. It never displays a secret and never starts a login.
- A note card at the top when the broker flag is `0` or Keepiq is missing,
  because then no registration can be ready.

Integriq is not one of the apps on the design canvas, so no board covers this screen; it is designed here. The
settings section is admin-only by Nextcloud's settings framework and never a
route in the app's router (admin-router gate).

## Threat notes

- **Signature wrapping and XML attacks.** Only the library's verified
  assertion is read; no attribute is read from an unverified node. External
  entities are disabled.
- **Audience confusion across tenants.** The audience is checked against the
  registration of the state's organisation, so an assertion for organisation A
  cannot complete a login started for organisation B.
- **Metadata enumeration.** One 404 for every absent case.
- **Secret exposure.** No secret on the object, the screen, the metadata
  response, a log line or an error. Only public certificate parts leave the
  seam, and only into metadata.
- **Fail open.** Every missing piece binds the Log adapter, which throws.
  No `catch (Throwable) { return null; }` around the seam, the registry or the
  readiness check.

## Risks and trade-offs

- [Keepiq becomes a hard dependency of citizen login] → intended by D2; the
  screen says so in the status, so nobody finds out from a failed login.
- [A broker sends an assurance spelling nobody mapped] → refused as unknown,
  and the "Test" check lists the levels the identity provider's metadata
  advertises that the mapping does not cover.
- [Per-organisation SPs mean one broker contract per organisation] → that is
  how DigiD and eHerkenning contracts work for municipalities; a shared SP
  would make every tenant's logins depend on one contract.

## Migration plan

No instance has a live broker; the flag is `0` everywhere. The repair step
logs one warning per superseded key (`idp_broker_entity_ids`,
`idp_broker_salts`, `idp_broker_trust_aliases`) that still holds a value, and
the code stops reading them. The salts map held plaintext salts; the warning
tells the administrator to put each salt in Keepiq and reference it from a
registration, then delete the key.
