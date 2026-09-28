---
kind: code
depends_on: [idp-broker-envelope-runtime]
---

# Proposal: identity-broker-browser-login

## Summary

A resident who signs in to the portal with DigiD through integriq cannot get in: integriq can exchange a one-time code for a signed subject envelope, but nothing starts a login and nothing sends the browser back with a code. The exchange therefore always fails. This change adds the two browser steps: a start address a consuming app sends the browser to, and a callback that checks the assertion, mints the envelope and returns the browser to the app with a one-time code. It registers each consuming app with its allowed return addresses, and adds the eHerkenning branch to the envelope when the assertion restricts a login to one.

## Why

The owner-moves pass of 2026-09-28 handed these halves to integriq from two portaliq changes merged on portaliq `development`. Both are `build` by the decision rule.

- **Start, callback and consumer entry.** portaliq `signin-integriq-broker-login`, Sibling halves: "ConductionNL/integriq owes a browser-facing start endpoint that calls `GovernmentIdpAdapterInterface::beginAuthentication()` with `{organisation, consumer, trust, relayState}`, and a callback that sends the browser back to the consumer's return address with the one-time code and the relay state. Neither exists on integriq development: its routes carry only `idpBroker#exchange`. ... and a `portaliq` entry in `idp_broker_consumers`."
- **Branch.** portaliq `signin-eherkenning-branch`: "the envelope has to carry the branch the eHerkenning assertion restricted the login to. That is integriq's to add to its `digid-eherkenning-auth-adapter` envelope."

The vendor SAML Service Provider or OIDC Relying Party behind `GovernmentIdpAdapterInterface` is not in this change: it waits on Open Decision D1 (broker vendor and contract), parked for Ruben in `openspec/changes/archive/2026-07-15-portal-idp-broker/proposal.md`. The start and callback work against the interface, so the vendor binding drops in without touching them.

Rows in the portaliq matrix:

- `sig-digid`: Open Inwoner Platform, NL Portal, xxllnc PIP and MijnOverheid rate yes (Open Inwoner `src/open_inwoner/accounts/backends.py:163` DigiDOIDCBackend).
- `sig-eherkenning`: Open Inwoner Platform, NL Portal and xxllnc PIP rate yes.
- `cmp-sig-eidas`: Open Inwoner Platform, xxllnc PIP and MijnOverheid rate yes.
- `dem-cl-eherkenning-branch`: Open Inwoner Platform, NL Portal and xxllnc PIP rate yes; origin https://github.com/maykinmedia/open-inwoner/blob/v2.4.3/CHANGELOG.rst#L1155.

integriq's own rows `id-digid`, `id-eherkenning` and `id-envelope` are rated no; the note on `id-envelope` says a consuming app calling the exchange today would always get a 401.

## What integriq already has

- The envelope runtime from `idp-broker-envelope-runtime` (all tasks ticked): `SubjectEnvelopeService::mint()` and `verify()`, `EnvelopeExchangeService::issueCode()` (`lib/Auth/Idp/EnvelopeExchangeService.php:67`, no caller outside tests) and `redeemCode()` (:96), `AssertionGuard`, `EnvelopeReplayGuard`, `SubjectPseudonymService`, `TrustLevelMapper`.
- `GovernmentIdpAdapterInterface` with `beginAuthentication()` (:60, no caller) and `readAssertion()` (:75), bound to `LogGovernmentIdpAdapter`, which logs and throws.
- `POST /api/idp/envelope/exchange` (`appinfo/routes.php:372`).
- `IdpBrokerConfig` (`lib/Auth/Idp/IdpBrokerConfig.php:54-82`): enabled flag, signing key, consumers as an id to secret map, salts, trust aliases. No return addresses.
- The envelope claims `sub`, `subType`, `provider`, `audience`, `organisation`, `trust`, `use`, `iss` (`SubjectEnvelope::toClaims()`), no branch.

## What this change builds

1. `GET /api/idp/{provider}/start`: checks the consumer and its return address, stores a signed single-use initiation state, and sends the browser to the IdP through `beginAuthentication()`.
2. `GET|POST /api/idp/{provider}/callback`: reads the assertion, runs the guards, mints the envelope bound to the state's organisation and consumer, issues the one-time code, and redirects to the consumer's registered return address with the code and relay state.
3. Consumer registration: per consumer a secret by broker reference and an allow-list of return addresses, with an `occ` command and a seeded disabled `portaliq` entry.
4. An optional `branch` claim (the eHerkenning vestigingsnummer) in the normalised assertion and the envelope.

## Out of scope

- The vendor SP or RP and its certificates (Open Decision D1).
- The Beheer screen for broker configuration (`portal-idp-broker-config`, blocked on the same decisions).
- Single logout and silent sign-in.

## Impact

- Changed: `lib/Controller/IdpBrokerController.php`, `appinfo/routes.php`, `lib/Auth/Idp/IdpBrokerConfig.php`, `SubjectEnvelope.php`, `GovernmentIdpAdapterInterface.php` (normalised shape), `LogGovernmentIdpAdapter.php`, `docs/administrators/citizen-authentication.md`.
- New: an initiation state store beside `EnvelopeCodeStore`, a consumer `occ` command.

## Cross-project dependencies

- portaliq `signin-integriq-broker-login` sends the browser to the start address and redeems the code; portaliq `signin-eherkenning-branch` reads `branch`.

## Risks

- An open redirect. The callback redirects only to a return address registered for the consumer in the stored state, compared exactly; anything else ends on integriq's own error page.
