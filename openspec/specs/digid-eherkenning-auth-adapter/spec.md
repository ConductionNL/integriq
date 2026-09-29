# digid-eherkenning-auth-adapter Specification

## Purpose
Integriq is the one app that talks to DigiD, eHerkenning and eIDAS. A consuming app sends the browser to integriq to start a login, gets it back with a one-time code, and trades that code for a short-lived signed subject envelope. The app never sees the assertion or a BSN.

## Requirements

### Requirement: Broker boundary — integriq owns the government IdP conversation

Integriq SHALL host the SAML Service Provider and/or OIDC Relying Party
for the DigiD, eHerkenning, and eIDAS-inbound conversations (typically via a
commercial broker), and SHALL be the only component in the fleet that
processes raw IdP assertions. Consuming apps SHALL receive only the verified
subject envelope defined in this spec: `sub` (KvK or RSIN for eHerkenning, a
BSN-pseudonym for DigiD, the eIDAS PersonIdentifier for eIDAS), `subType`,
`provider`, `audience`, `organisation`, and `trust`. Consuming apps MUST NOT
receive, parse, or store raw SAML assertions, OIDC id_tokens, or any IdP
attribute not projected into the envelope.

@e2e exclude Spec-first: no SP/RP endpoints, broker contract, or certificates exist yet; implementation arrives in the chained changes (proposal §Chaining narrative).

#### Scenario: eHerkenning login yields a KvK-based envelope

- GIVEN a live eHerkenning broker configuration for organisation `gemeente-x`
- WHEN a user completes eHerkenning authentication at level EH3 for a portal initiated by portaliq
- THEN integriq verifies the assertion and issues a subject envelope with `sub` = the KvK number, `subType` = `kvk`, `provider` = `eherkenning`, `organisation` = `gemeente-x`, and `trust` = `substantial`
- AND portaliq receives only the envelope, never the SAML assertion

#### Scenario: DigiD login never exposes the BSN downstream

- GIVEN a live DigiD connection whose assertion carries a BSN-derived identity
- WHEN integriq processes the callback and issues the subject envelope
- THEN the envelope's `sub` is a pseudonym with `subType` = `bsn-pseudonym`
- AND the raw BSN appears in no envelope claim, no log line, no OpenRegister object, and no error message

### Requirement: BSN pseudonymisation at the broker edge

For DigiD authentications, integriq SHALL convert the citizen identity to
a pseudonym at the broker edge before any other processing. When polymorphic
pseudonymisation (eToegang polymorphe pseudoniemen) is contracted with the
broker, integriq SHALL use the decrypted per-service-provider pseudonym
and the BSN SHALL never materialise in the stack. When polymorphie is not
contracted, integriq SHALL compute `HMAC-SHA256(salt_organisation, bsn)`
in memory during callback processing only, using a per-organisation salt from
the encrypted credential store (ADR-016), and SHALL discard the BSN before the
callback request completes. The same subject SHALL map to the same pseudonym
within one organisation across logins, and to different pseudonyms across
different organisations.

@e2e exclude Spec-first: pseudonymisation requires the D1 broker contract (polymorphie) or the D2-decided salt store; neither exists yet.

#### Scenario: Polymorphic pseudonym used when contracted

- GIVEN a broker contract that includes polymorphic pseudonymisation
- WHEN a DigiD assertion is processed
- THEN the envelope `sub` is the per-service-provider pseudonym delivered by the polymorphie decryption
- AND no BSN is ever present in integriq's memory beyond the broker library boundary

#### Scenario: Salted-HMAC fallback pseudonym when polymorphie is not contracted

- GIVEN a broker contract without polymorphic pseudonymisation
- WHEN a DigiD assertion containing a BSN is processed for organisation `gemeente-x`
- THEN the envelope `sub` is `HMAC-SHA256(salt_gemeente-x, bsn)` computed in memory during that callback only
- AND a later login by the same citizen for `gemeente-x` yields the same `sub`, while a login for `gemeente-y` yields a different `sub`

### Requirement: Trust levels map to the eIDAS-aligned vocabulary

Integriq SHALL map provider assurance levels to the fleet trust
vocabulary `low | substantial | high` (portaliq's `TRUST_ORDER`) exactly as
follows: DigiD Basis → `low`, DigiD Midden → `low`, DigiD Substantieel →
`substantial`, DigiD Hoog → `high`, eHerkenning EH2/EH2+ → `low`, eHerkenning
EH3 → `substantial`, eHerkenning EH4 → `high`, eIDAS inbound low/substantial/
high → same value. An unknown or absent assurance level SHALL cause the
authentication to be rejected — no envelope is issued (fail-closed at the
broker; consumers additionally normalise unknown trust to `low` on their
side).

@e2e exclude Spec-first: no live IdP chain exists to assert levels against; the table is validated in the runtime chain link.

#### Scenario: EH3 maps to substantial

- GIVEN a verified eHerkenning assertion at betrouwbaarheidsniveau EH3
- WHEN the subject envelope is minted
- THEN the envelope `trust` claim is `substantial`

#### Scenario: DigiD Hoog maps to high

- GIVEN a verified DigiD assertion at niveau Hoog
- WHEN the subject envelope is minted
- THEN the envelope `trust` claim is `high`

#### Scenario: Unknown assurance level is rejected fail-closed

- GIVEN a verified assertion whose assurance level is absent or not in the mapping table
- WHEN integriq processes the callback
- THEN no subject envelope is issued and the login fails with an auditable error
- AND the failure reason does not leak assertion contents

### Requirement: One-time signed subject envelope handoff

Integriq SHALL hand the subject envelope to the consuming app via a
one-time opaque code (at least 256 bits of entropy, single-use, TTL of at most
60 seconds) returned through the browser redirect, redeemed server-to-server
at an exchange endpoint authenticated with a per-consumer shared secret
(constant-time comparison). The envelope SHALL be a signed JWT that mirrors
portaliq's A6 assertion shape: claims `sub`, `subType`, `provider`,
`audience`, `organisation`, `trust`, `use` = `idp-envelope`, `jti`, `iat`,
`exp` (at most `iat` + 60s), `iss` = `openconnector-idp-broker`. Envelope
`jti` values SHALL be single-use. Consumers SHALL mint their own sessions
(portaliq: `PortalSessionService::issueSession()` + `portalAccount` upsert) —
an envelope SHALL never function as a session and a session SHALL never be
accepted by the exchange endpoint (`use`-claim token-confusion guard, mirrors
contract v2 A6).

@e2e exclude Spec-first: the exchange endpoint and consumer-secret provisioning do not exist yet (chain links 1–2).

#### Scenario: Envelope code is redeemed exactly once

- GIVEN a one-time code issued after a successful authentication
- WHEN the consumer redeems it a first time
- THEN the signed subject envelope is returned
- AND a second redemption of the same code is rejected and audit-logged

#### Scenario: Expired envelope artefacts are rejected

- GIVEN a one-time code or envelope older than its TTL (60 seconds)
- WHEN redemption or verification is attempted
- THEN the artefact is rejected and no session can be derived from it

#### Scenario: Envelope cannot be replayed as a portal session

- GIVEN a leaked subject envelope JWT
- WHEN it is presented to portaliq as a bearer session token
- THEN `PortalSessionService::resolveFromBearer()` rejects it because it carries a non-empty `use` claim

### Requirement: Replay, audience confusion, and IdP-initiated flows are rejected

Integriq SHALL reject: (a) any SAML Response or OIDC callback whose
`InResponseTo`/`state` does not match an outstanding authentication request it
initiated — IdP-initiated (unsolicited) flows are not supported by design;
(b) any assertion whose `AudienceRestriction`/`aud` does not match the
configured SP/RP EntityID; (c) any assertion outside its
`NotBefore`/`NotOnOrAfter` window; (d) any assertion replayed after first
use. The envelope SHALL be bound to the `organisation` and consumer from the
signed initiation `state`, so an envelope minted for one tenant cannot be
redeemed in another tenant's context.

@e2e exclude Spec-first: negative-path tests require the live SP/RP runtime from chain link 2.

#### Scenario: Unsolicited IdP-initiated response is refused

- GIVEN a syntactically valid, correctly signed SAML Response that integriq never requested
- WHEN it is POSTed to the assertion consumer endpoint
- THEN it is rejected because no outstanding request matches its `InResponseTo`
- AND no envelope is issued

#### Scenario: Wrong-audience assertion is refused

- GIVEN a valid assertion issued for a different Service Provider EntityID
- WHEN integriq verifies it
- THEN verification fails on the audience restriction and no envelope is issued

#### Scenario: Cross-tenant envelope redemption is refused

- GIVEN an envelope minted for organisation `gemeente-x`
- WHEN a consumer attempts to establish a session in organisation `gemeente-y` with it
- THEN the consumer rejects the envelope because its `organisation` claim does not match

### Requirement: Dormant seam — adapters ship config-flag-gated and inert

The broker SHALL follow the dormant-seam pattern proven by procest
(`EHerkenningSamlAdapterInterface` + `LogEHerkenningSamlAdapter`): a per-provider
adapter interface with a default Log implementation that logs the call and
throws "broker not configured" — never silently authenticating. In integriq
that seam is `GovernmentIdpAdapterInterface` and `LogGovernmentIdpAdapter`,
and every provider binds to the Log implementation until a broker is
configured.

Activation SHALL require all of: (1) a broker entry configured in *Beheer >
Authenticatie*, (2) SP private key + certificate loaded via the encrypted
store (ADR-016), (3) the feature flag flipped from `0` to `1`, and (4) the DI
binding swapped to the live adapter. Consumers SHALL keep their existing
edges (portaliq's debug-gated dev-login) until the flip; the broker path
SHALL contain no fail-open resolver shapes (no catch-Throwable-return-null
around auth services).

The Log adapter SHALL NOT log the callback payload. It is the one place in the
stack where a raw assertion exists, and a log line is the easiest place to
leak one from.

#### Scenario: Feature flag off means the broker refuses

- GIVEN the broker feature flag is `0` or certificates are absent
- WHEN any broker endpoint or adapter is invoked
- THEN the Log-adapter logs the attempt and throws "broker not configured"
- AND no authentication, envelope, or session results

#### Scenario: Portaliq dev-login keeps working until the flip

- GIVEN portaliq with debug-gated dev-login enabled and no live broker
- WHEN a developer uses the dev-login edge
- THEN portal sessions are minted exactly as today, unaffected by the dormant broker seam

#### Scenario: The refusal names no assertion contents

- GIVEN a callback carrying a raw assertion
- WHEN the dormant adapter refuses it
- THEN the logged context carries the provider, the call and the tenant, and no part of the payload

### Requirement: Configuration placement follows ADR-017 (Rules 1, 3, 7)

Broker configuration SHALL follow ADR-017: tenant-wide settings (broker
entry, IdP metadata, certificates, feature flags, per-consumer exchange
secrets, trust-mapping overrides) SHALL live in *Beheer > Authenticatie*.
The adapter SHALL be discoverable as the
`digid-eherkenning-auth-adapter` catalogue entry in *Adapters* (domain tag
`Overheid-NL`). There SHALL be no new top-level menu, no per-adapter settings
page, and no inline provider-create form on connections — connections
reference configured providers via the Auth-tab picker only. This is the
Adapters + Beheer split ADR-017 Rule 7 explicitly sanctions for this adapter.

@e2e exclude Spec-first: the config surface is chain link 1 (`portal-idp-broker-config`); no UI exists to test.

#### Scenario: No new IA surface is introduced

- GIVEN the broker config and catalogue entry are implemented
- WHEN an operator looks for DigiD/eHerkenning configuration
- THEN broker config is found under *Beheer > Authenticatie* and discovery under *Adapters*
- AND the top-level menu count remains five (ADR-017)

### Requirement: Session lifetime and logout considerations

Consumer sessions derived from an envelope SHALL respect: envelope artefact
TTL ≤ 60s, consumer session TTL per the consumer's own policy (portaliq: 2h,
revocable via the `portalSession` jti record), and the envelope `trust` frozen
into the session — a level step-up SHALL require a new authentication flow,
never an in-place trust mutation. User-initiated logout SHALL always end the
local consumer session; where the broker contract supports it, integriq
SHOULD propagate SP-initiated single logout, and IdP-initiated SLO received by
integriq SHOULD propagate a revocation signal to consumers.

@e2e exclude Spec-first: SLO depth is vendor-dependent (Open Decision D1); only local logout exists today on the portaliq side.

#### Scenario: Trust never mutates in place

- GIVEN an active portal session minted from a `substantial` envelope
- WHEN the subject needs a `high` action mid-session
- THEN the subject is sent through a new authentication flow at the higher level
- AND the existing session's trust claim is never rewritten

#### Scenario: Local logout always works regardless of SLO support

- GIVEN an active portal session and a broker contract without SLO support
- WHEN the user logs out of the portal
- THEN the consumer session is ended (and its jti revocable record marked revoked) even though no broker-side logout occurs

### Requirement: A login starts at integriq with a signed, single-use state (REQ-IDP-001)

Integriq MUST offer a browser start address per provider that takes the organisation, the consumer, the requested trust, a return address and a relay state. It MUST refuse an unknown or disabled consumer, a return address not registered for that consumer, a disabled broker and an unconfigured adapter, showing its own error page and redirecting nowhere. Otherwise it MUST store a signed state valid once for at most five minutes and MUST send the browser to the identity provider through the adapter.

#### Scenario: a resident starts a DigiD login from the portal
- GIVEN portaliq registered with the return address `https://portal.example.nl/portal/api/session/broker/callback`
- WHEN the browser arrives at `/api/idp/digid/start` for organisation `gemeente-x`, trust `substantial`, with that address and a relay state
- THEN a state is stored and the browser is sent to the identity provider
- @e2e exclude cross-app browser redirect; covered by Newman with the scripted adapter

#### Scenario: a return address that is not registered
- GIVEN portaliq registered with one return address
- WHEN the start is called with another address
- THEN integriq's error page shows and the browser is not redirected
- @e2e exclude cross-app browser redirect; covered by Newman

### Requirement: The callback returns the browser with a one-time code (REQ-IDP-002)

The callback MUST read the assertion through the adapter, MUST consume the state it answers, MUST run the assertion and replay guards, and MUST mint the envelope with the state's consumer as audience and the state's organisation. It MUST then issue a one-time code and redirect to the state's return address with the code and the relay state. Every failure MUST redirect to that return address with one generic error and the relay state, and MUST log the real reason. An assertion that answers no stored state MUST be refused.

#### Scenario: the resident comes back signed in
- GIVEN a stored state for portaliq and a DigiD assertion answering it
- WHEN the callback runs
- THEN the browser is redirected to portaliq's return address with a code and the relay state, and redeeming the code at the exchange yields one envelope whose audience is `portaliq` and whose subject is a pseudonym
- @e2e exclude cross-app browser redirect; covered by a Newman round trip with the scripted adapter

#### Scenario: an identity provider starts a login on its own
- GIVEN an assertion that answers no stored state
- WHEN it reaches the callback
- THEN it is refused and no code is issued
- @e2e exclude backend guard; covered by PHPUnit

### Requirement: A consuming app is registered with its return addresses (REQ-IDP-003)

Each consuming app MUST be registered with a secret held by broker reference, a list of allowed return addresses and an enabled flag. A consumer registered in the older form, with only a secret, MUST still be able to redeem a code and MUST NOT be able to start a login. Integriq MUST ship a disabled `portaliq` entry and an `occ` command to set a consumer's addresses and secret reference.

#### Scenario: an administrator enables portaliq
- GIVEN the seeded disabled `portaliq` consumer
- WHEN the administrator runs the consumer command with the portal's return address and a secret reference
- THEN portaliq can start a login and redeem codes
- @e2e exclude occ command; covered by PHPUnit

### Requirement: An eHerkenning envelope carries the branch the login was restricted to (REQ-IDP-004)

When an eHerkenning assertion restricts the login to a branch, the envelope MUST carry that branch number as the claim `branch`. An envelope from any other provider, or from an assertion without a branch, MUST NOT carry the claim.

#### Scenario: an employee signs in for one branch
- GIVEN an eHerkenning assertion for KvK 12345678 restricted to branch 000012345678
- WHEN integriq mints the envelope
- THEN the envelope carries `sub` 12345678, `subType` kvk and `branch` 000012345678
- @e2e exclude backend envelope; covered by PHPUnit

### Requirement: Single-use artefacts refuse rather than degrade

The one-time code and the envelope `jti` SHALL be guarded by a cache that is
both shared between requests and atomic. Integriq SHALL treat the absence of
such a cache as a reason to refuse, never as a reason to skip the check: with
no shared cache no code SHALL be issued, no code SHALL be redeemed, and no
envelope SHALL be accepted.

Nextcloud's `ICacheFactory::createDistributed()` answers with an `ArrayCache`
on an instance that has no memcache configured, and an ArrayCache lives for one
request. A single-use guard on top of one accepts every replay that arrives in
a different request, which is every replay that matters. The guard would be
present, green and guarding nothing, and that is indistinguishable from a guard
that works.

Redemption SHALL be atomic. `IMemcache::cad()` removes the entry in the same
operation that reads it, so two requests racing on one code cannot both receive
the envelope. A read followed by a separate delete SHALL NOT be used.

#### Scenario: No shared cache means no code is issued

- **GIVEN** an instance whose configured cache is not a shared atomic one
- **WHEN** a code issue is attempted
- **THEN** it SHALL be refused with a reason naming the missing cache
- **AND** no code SHALL be returned

#### Scenario: A code redeems exactly once

- **GIVEN** a code holding an envelope
- **WHEN** it is redeemed twice
- **THEN** the first redemption SHALL return the envelope
- **AND** the second SHALL be refused as unknown, expired or already redeemed

#### Scenario: A jti is burnt on first verification

- **GIVEN** an envelope that has been verified once
- **WHEN** the same envelope is verified again
- **THEN** it SHALL be refused

### Requirement: An unverifiable assurance level is configured, never guessed

The trust table SHALL carry only assurance-level spellings that are published
and unambiguous. Any other spelling a tenant's identity provider sends SHALL
reach the table through tenant configuration
(`idp_broker_trust_aliases`), and SHALL NOT be guessed in code.

A guessed `AuthnContextClassRef` does not fail loudly. It either falls through
to "unknown", which is refused and looks like a configuration problem, or it
matches the wrong row and maps a high assurance level onto a low trust claim,
which looks like nothing at all.

#### Scenario: A configured alias maps an unknown spelling

- **GIVEN** a tenant alias mapping its identity provider's level spelling onto `hoog`
- **WHEN** an assertion carries that spelling
- **THEN** the envelope's `trust` claim SHALL be `high`

#### Scenario: An unaliased unknown spelling is refused

- **GIVEN** no alias for a level spelling the table does not carry
- **WHEN** an assertion carries it
- **THEN** no envelope SHALL be issued
- **AND** the reason SHALL name the provider and the level, and nothing else off the assertion
