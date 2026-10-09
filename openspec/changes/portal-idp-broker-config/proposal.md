---
kind: code
depends_on: []
---

# Proposal: portal-idp-broker-config

## Summary

Give the DigiD, eHerkenning and eIDAS broker the half it still misses: a live
adapter and the place an administrator configures it. Integriq gets a
pluggable identity provider adapter with two kinds, a SAML 2.0 Service
Provider and an OIDC Relying Party, configured once per organisation under
*Beheer > Authenticatie*. Keys and certificates stay in the Keepiq vault.
Every login below eIDAS substantial is refused with a message the resident or
employee can act on.

Rows covered in `openspec/parity/capabilities.json`: `id-digid`,
`id-eherkenning`, `id-portal-idp`.

## Why

The broker runtime is built and dormant. The envelope, the one-time code, the
exchange, the trust table, the pseudonym, the browser start and the callback
all exist (archived changes `2026-09-29-idp-broker-envelope-runtime` and
`2026-09-29-identity-broker-browser-login`). The container binds
`GovernmentIdpAdapterInterface` to `LogGovernmentIdpAdapter` only, and that
adapter refuses every call. So `GET /apps/integriq/api/idp/digid/start` answers
with a refusal and no resident has ever logged in.

The live adapter waited on five open decisions in the archived
`portal-idp-broker` change (proposal, Open Questions; design, D1 to D7).
Ruben answered them on 8 October 2026 (decision 94):

| Decision | Answer |
| --- | --- |
| D1 broker vendor | Vendor neutral. A pluggable adapter with two kinds, SAML 2.0 SP and OIDC RP. The vendor is configuration; no vendor is named in code. Polymorphic pseudonymisation is optional per adapter, with the salted HMAC as fallback. |
| D2 certificate custody | Keys and SP certificates live in the Keepiq vault. Integriq reads them through its secret seam and fails closed when Keepiq or the secret is missing. Rotation is Keepiq's job. |
| D3 SP metadata tenancy | Per organisation. One SP registration per organisation, served at a metadata URL of its own. |
| D4 acceptance floor | Substantial is the floor. DigiD Basis and Midden are refused with a clear message; eHerkenning EH3 and up and eIDAS substantial and high are accepted. |
| D5 Berichtenbox linkage | Stays deferred. Out of scope here. |

With those answered this change is no longer a stub.

## What changes

- **One adapter contract, two kinds.** `SamlServiceProviderAdapter` and
  `OidcRelyingPartyAdapter` implement the existing
  `GovernmentIdpAdapterInterface`. `IdpAdapterRegistry` binds a provider to a
  live adapter only when the organisation's registration is complete and
  enabled, and to the Log adapter otherwise.
- **An `idpRegistration` schema in OpenRegister.** One object per organisation
  and provider: the adapter kind, the identity provider's metadata or
  discovery URL, our EntityID or client id, the attribute or claim mapping for
  BSN, KvK, RSIN, branch, pseudonym and eIDAS identifier, the assurance level
  mapping, the pseudonymisation mode, and credential references. No secret is
  ever a property of this object.
- **Secrets through the existing seam, into Keepiq.** Every key, certificate
  and salt is an OpenRegister credential whose bytes live in Keepiq, read with
  `CredentialBrokerService::resolveInjectable()` at the moment of use, the
  same seam `IdpConsumerSecretResolver` and `BrokeredCallService` already use.
  No Keepiq, no secret, no login.
- **A metadata URL per organisation.**
  `GET /apps/integriq/api/idp/metadata/{organisation}/{provider}` serves the
  SAML SP metadata for that organisation's registration, or for an OIDC
  registration the redirect URI and client id the broker needs.
- **The assurance floor.** A start that asks for less than substantial is
  refused. The authentication request asks the identity provider for at least
  substantial. An assertion that comes back lower is refused on integriq's own
  page, which says which level was used, which level is needed and how to get
  it.
- **The *Beheer > Authenticatie* screen.** A section in integriq's admin
  settings that lists every registration with its readiness, and lets an
  administrator add, edit, test, enable and disable one, copy its metadata URL
  and see which credential is missing.
- **Tests against a stub identity provider.** A SAML stub and an OIDC stub,
  both in the test tree, drive both adapter kinds end to end without a broker
  contract.

## Out of scope

- **Berichtenbox identity linkage (D5).** Deferred by decision 94. The
  `berichtenbox-client` and `berichtenbox-digital-post-adapter` changes do not
  read the envelope and this change gives them nothing to read.
- **Single logout.** Still vendor dependent; the main spec keeps it as a
  SHOULD.
- **Key rotation.** Keepiq owns it (D2). Integriq reads the credential at the
  moment of use, so a rotated value is picked up without a change here.
- **The envelope signing key and the consumer exchange secrets.** Neither is
  an identity provider credential. The consumer secrets already sit behind the
  same broker reference; the envelope signing key is a follow-up named in
  `design.md`.
- **The `id-eidas` capability row.** It is recorded as decided-no, while
  decision 94 accepts eIDAS substantial and high through the same adapters.
  The adapters carry eIDAS because the trust table already does; whether the
  row reopens is Ruben's call, not this change's.
- **Anything in portaliq.** Portaliq keeps its dev login until a live
  registration exists, as the main spec already says.

## Impact

- **Specs**: new capability `idp-broker-configuration`; a modified
  requirement in `digid-eherkenning-auth-adapter` (REQ-IDP-002, so the floor
  refusal can say what went wrong).
- **Code**: `lib/Auth/Idp/Adapter/*`, `lib/Auth/Idp/IdpAdapterRegistry.php`,
  `lib/Auth/Idp/IdpRegistrationService.php`, `lib/Auth/Idp/IdpSecretSeam.php`,
  `lib/Auth/Idp/TrustLevelMapper.php`, `lib/Auth/Idp/IdpLoginService.php`,
  `lib/Controller/IdpMetadataController.php`, `appinfo/routes.php`,
  `lib/Settings/register.d/` (the `idpRegistration` schema),
  `src/views/admin/IdpRegistrationSettings.vue`,
  `src/modals/IdpRegistrationModal.vue`, `l10n/`, `tests/`.
- **Dependencies**: one SAML library, chosen in task 1 with a
  `composer audit` pass. OIDC needs none beyond what integriq already ships for
  JWT.
- **Backwards compatible**: the feature flag stays `0` by default, and a
  provider without a complete registration binds to the Log adapter exactly as
  today. The superseded app config keys `idp_broker_entity_ids`,
  `idp_broker_salts` and `idp_broker_trust_aliases` are no longer read; no
  instance has a live broker, so nothing depends on them.
