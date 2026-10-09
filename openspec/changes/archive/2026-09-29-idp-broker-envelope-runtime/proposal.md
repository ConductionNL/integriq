---
kind: code
---

# Proposal: idp-broker-envelope-runtime

## Summary

The half of the DigiD and eHerkenning broker that does not need a vendor: the
trust table, the pseudonym, the guard, the envelope, the one-time code and the
exchange endpoint. All of it testable today, all of it inert until a live
broker is configured.

## Motivation

Parity ledger row 12.8, "citizen authentication (DigiD, eHerkenning, eIDAS)".
Zaaksysteem has it, nobody else in the comparison does, and integriq is where
the fleet decided it belongs: one component processes raw assertions and every
consuming app receives only a verified subject.

`openspec/specs/digid-eherkenning-auth-adapter/spec.md` is the spec, and it is
spec-first: every requirement in it carries `@e2e exclude Spec-first: ...
implementation arrives in the chained changes`. Nothing had arrived.

**🔴 The ledger's description of the code is wrong for this app, and the
correction matters.** The 2026-09-19 gap scan says integriq has
`lib/Service/Auth/SimulatorDigidSamlAdapter.php` and
`SimulatorEHerkenningSamlAdapter.php`. Neither file exists in this repository,
and `lib/` contains no DigiD or eHerkenning code at all: the only hits for
either word are the two OpenRegister register seeds. The simulator adapters the
scan saw are procest's, which the spec itself names as the precedent
(`EHerkenningSamlAdapterInterface` + `LogEHerkenningSamlAdapter`). So the
starting point for this row was not "simulator adapters exist", it was nothing.

## What changes

Seven pieces, each of which can be wrong in a way a test can see.

- **`TrustLevelMapper`.** The assurance-to-trust table, fail-closed. An
  unmapped level ends the login instead of becoming `low`.
- **`SubjectPseudonymService`.** The polymorphic pseudonym when the broker
  contract has one, and a salted HMAC computed in memory when it does not.
- **`SubjectEnvelope` and `SubjectEnvelopeService`.** The claim set and its
  signing and verification: `use: idp-envelope`, `iss`, `jti`, and an `exp`
  that may not sit further than 60 seconds from its own `iat`.
- **`EnvelopeReplayGuard`.** Makes a `jti` or an assertion id usable once.
- **`EnvelopeCodeStore`.** A 256-bit opaque code that redeems atomically.
- **`AssertionGuard`.** The four refusals: unsolicited, wrong audience,
  outside the validity window, already used.
- **`EnvelopeExchangeService` and `IdpBrokerController`.** The
  server-to-server exchange, authenticated with a per-consumer shared secret
  compared in constant time, answering one undifferentiated 401 to every
  refusal.

Plus `GovernmentIdpAdapterInterface` and `LogGovernmentIdpAdapter`: the dormant
seam the spec pins, which logs the attempt and throws "broker not configured".

## The decision worth arguing about

**Single use is refused rather than approximated.** `EnvelopeReplayGuard` and
`EnvelopeCodeStore` both refuse to work without a shared cache that implements
`IMemcache`, and they say so rather than falling back.

Nextcloud's `createDistributed()` hands back an `ArrayCache` on an instance
with no memcache configured. An ArrayCache lives for one request. A replay
guard on top of one accepts every replay that arrives in a different request,
which is every replay: the guard would be there, green, tested, and guarding
nothing.

`integriq`'s own `AuthorizationService::validatePayload()` has exactly that
shape today for its JWT `jti` check. That is inherited and not touched here,
but it is why this change does not copy it.

## Out of scope, and why

- **The SAML Service Provider and the OIDC Relying Party.** Signature
  verification, metadata exchange, certificates and the broker contract are
  vendor work, and Open Decision D1 (which broker, and whether polymorphie is
  contracted) is not made. The adapter interface is the seam they arrive
  behind, and until they do every provider binds to the Log adapter.
- **The DigiD `AuthnContextClassRef` URNs.** The eHerkenning
  (`urn:etoegang:core:assurance-class:loaN`) and eIDAS
  (`http://eidas.europa.eu/LoA/...`) URNs are in the table because both are
  published and unambiguous. DigiD's could not be verified against Logius
  here, and a guessed URN does not fail loudly: it either falls through to
  "unknown", or it matches the wrong row and maps Hoog onto low. An operator
  supplies them once, from the tenant's own metadata, through
  `idp_broker_trust_aliases`.
- **The Beheer > Authenticatie configuration screen.** The settings are read
  through `IdpBrokerConfig` and can be set with `occ config:app:set`. The
  screen is ADR-017 Rule 7 work and belongs with the vendor choice.
- **Single logout.** Vendor-dependent, per the spec's own open decision.
- **Anything in portaliq.** Its dev-login edge keeps working exactly as it
  does; this change adds a seam beside it and takes nothing away.

## Impact

- **Affected specs**: `digid-eherkenning-auth-adapter` (delta: two added
  requirements, one modified).
- **Affected code**: `lib/Auth/Idp/*`, `lib/Controller/IdpBrokerController.php`,
  `lib/Exception/IdpAssertionException.php`, `appinfo/routes.php`,
  `lib/AppInfo/Application.php`.
- **Backwards compatible**: everything is new, and the feature flag starts at
  `0`. With the flag off the exchange endpoint answers 401 to everything and
  no adapter authenticates anybody.
- **Consumers**: none change. A consumer that wants the envelope opts in by
  being given a shared secret.
