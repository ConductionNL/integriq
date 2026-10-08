---
kind: code
depends_on: [portal-idp-broker-config]
---

# Proposal: idp-broker-yivi-route

## Summary

Add Yivi as a fourth provider on integriq's sign-in broker, on the adapter model `portal-idp-broker-config` sets up. A Yivi registration is an `oidc-rp` registration per organisation, pointed at a Yivi OIDC bridge. A consuming app asks for a list of Yivi attributes when it starts the login; the resident discloses exactly those in the Yivi app; the envelope carries only the disclosed attributes. No Yivi server runs in integriq.

Rows covered: portaliq `sig-yivi` (decision 104). This is integriq's half of portaliq's `resident-identity-in-forms` (merged in portaliq #1387).

## Why

Portaliq's `resident-identity-in-forms` says: "Yivi reaches portaliq through the OIDC broker (integriq), like DigiD and eHerkenning do (`portal-broker-envelope-login`). Portaliq adds the route kind and the attribute mapping, not a Yivi server." Its design: "a broker route kind `yivi` in the organisation's login routes (REQ-BEL-001). The envelope carries only the disclosed attributes. The session's level of assurance is what the broker states, and a form whose `minTrust` is higher does not open." A form binding lists `yiviAttributes`, each with the field it prefills.

Open Formulieren 4.0.1 has Yivi login over OIDC with attribute prefill (`src/openforms/authentication/contrib/yivi_oidc/plugin.py:66`, `src/openforms/prefill/contrib/yivi/plugin.py:30`).

What integriq has, once `portal-idp-broker-config` lands: two adapter kinds behind `GovernmentIdpAdapterInterface`, one `idpRegistration` per organisation and provider (`digid`, `eherkenning`, `eidas`), secrets through Keepiq, the assurance floor at substantial, and *Beheer > Authenticatie*. Yivi has no provider value, no way to ask for attributes, and no envelope shape for a login without a BSN or KvK number.

## What changes

- **Provider `yivi`.** `idpRegistration.provider` gains `yivi`, allowed only with kind `oidc-rp`. The registration's `issuer` is the organisation's Yivi OIDC bridge.
- **An attribute allowlist per registration.** `yiviAttributes`: the Yivi attribute ids (`pbdf.gemeente.personalData.fullname`, `pbdf.sidn-pbdf.email.email`, `pbdf.gemeente.address.zipcode` and so on) this organisation may ask for, each with a claim name and whether it carries a BSN.
- **Attributes asked at the start.** The browser start address takes `attributes`, a list of attribute ids. Integriq refuses an id outside the allowlist, and asks the bridge for exactly that list as an OIDC `claims` request.
- **An envelope with attributes.** A Yivi envelope carries `provider: yivi`, `subType: attributes`, `sub` as a pseudonym of the disclosure, and `attributes` with only the disclosed values. When a BSN attribute was disclosed it is pseudonymised like a DigiD BSN unless the registration says the consumer may receive it.
- **A trust per registration.** Yivi has no assurance level of its own. The registration states the trust its attributes carry (`yiviTrust`, default `low`).
- **The screen.** *Beheer > Authenticatie* offers Yivi in the provider list and shows the attribute allowlist.

## Needs a decision

Decision 94 made substantial the floor for every login, and `portal-idp-broker-config` makes it "a constant, not a setting" (REQ-IDPC-006). A Yivi disclosure of self-issued or municipality-issued attributes has no eIDAS level, and refusing it below substantial refuses every Yivi login. This change applies the floor to `digid`, `eherkenning` and `eidas` only, and lets a Yivi login through at its registration's `yiviTrust`, so portaliq's `minTrust` per form decides what a Yivi resident may open. Ruben confirms this reading or keeps the floor for Yivi too, in which case Yivi ships disabled.

## Out of scope

- Running a Yivi server or an OIDC bridge.
- Issuing Yivi credentials (the EUDI wallet issuance is `eudi-wallet-credential-issuance`).
- Mapping attributes onto form fields: portaliq's `yiviAttributes`.

## Impact

- Specs: new capability `idp-broker-yivi`.
- Changed: `lib/Settings/register.d/` (the `idpRegistration` schema: `provider` enum, `yiviAttributes`, `yiviTrust`), `lib/Auth/Idp/Adapter/OidcRelyingPartyAdapter.php` (the `claims` request and attribute read), `lib/Auth/Idp/IdpLoginService.php` (the start parameter), `lib/Auth/Idp/TrustLevelMapper.php` (floor per provider), the envelope builder, `src/views/admin/IdpRegistrationSettings.vue`, `src/modals/IdpRegistrationModal.vue`, `l10n/`.

## Cross-project dependencies

- portaliq `resident-identity-in-forms` adds the route kind `yivi`, passes the form's attribute ids on the start, and maps the envelope's `attributes` onto fields.
