# digid-eherkenning-auth-adapter

Government IdP broker for the Conduction fleet: openconnector owns the
SAML/OIDC conversation with the DigiD / eHerkenning / eIDAS chain and hands
consuming apps (portaliq first, procest's leverancier portal later) a
verified, one-time, signed subject envelope. Capability name matches the
catalogue entry reserved by openconnector ADR-017. Spec-first: this change
ships no implementation — every scenario below is annotated `@e2e exclude`
because no broker contract, certificates, endpoints, or adapters exist yet
(see `openspec/changes/portal-idp-broker/proposal.md`, Open Decisions D1–D5
and the Chaining narrative).

## ADDED Requirements

### Requirement: Broker boundary — openconnector owns the government IdP conversation

Openconnector SHALL host the SAML Service Provider and/or OIDC Relying Party
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
- THEN openconnector verifies the assertion and issues a subject envelope with `sub` = the KvK number, `subType` = `kvk`, `provider` = `eherkenning`, `organisation` = `gemeente-x`, and `trust` = `substantial`
- AND portaliq receives only the envelope, never the SAML assertion

#### Scenario: DigiD login never exposes the BSN downstream

- GIVEN a live DigiD connection whose assertion carries a BSN-derived identity
- WHEN openconnector processes the callback and issues the subject envelope
- THEN the envelope's `sub` is a pseudonym with `subType` = `bsn-pseudonym`
- AND the raw BSN appears in no envelope claim, no log line, no OpenRegister object, and no error message

### Requirement: BSN pseudonymisation at the broker edge

For DigiD authentications, openconnector SHALL convert the citizen identity to
a pseudonym at the broker edge before any other processing. When polymorphic
pseudonymisation (eToegang polymorphe pseudoniemen) is contracted with the
broker, openconnector SHALL use the decrypted per-service-provider pseudonym
and the BSN SHALL never materialise in the stack. When polymorphie is not
contracted, openconnector SHALL compute `HMAC-SHA256(salt_organisation, bsn)`
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
- AND no BSN is ever present in openconnector's memory beyond the broker library boundary

#### Scenario: Salted-HMAC fallback pseudonym when polymorphie is not contracted

- GIVEN a broker contract without polymorphic pseudonymisation
- WHEN a DigiD assertion containing a BSN is processed for organisation `gemeente-x`
- THEN the envelope `sub` is `HMAC-SHA256(salt_gemeente-x, bsn)` computed in memory during that callback only
- AND a later login by the same citizen for `gemeente-x` yields the same `sub`, while a login for `gemeente-y` yields a different `sub`

### Requirement: Trust levels map to the eIDAS-aligned vocabulary

Openconnector SHALL map provider assurance levels to the fleet trust
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
- WHEN openconnector processes the callback
- THEN no subject envelope is issued and the login fails with an auditable error
- AND the failure reason does not leak assertion contents

### Requirement: One-time signed subject envelope handoff

Openconnector SHALL hand the subject envelope to the consuming app via a
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

Openconnector SHALL reject: (a) any SAML Response or OIDC callback whose
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

- GIVEN a syntactically valid, correctly signed SAML Response that openconnector never requested
- WHEN it is POSTed to the assertion consumer endpoint
- THEN it is rejected because no outstanding request matches its `InResponseTo`
- AND no envelope is issued

#### Scenario: Wrong-audience assertion is refused

- GIVEN a valid assertion issued for a different Service Provider EntityID
- WHEN openconnector verifies it
- THEN verification fails on the audience restriction and no envelope is issued

#### Scenario: Cross-tenant envelope redemption is refused

- GIVEN an envelope minted for organisation `gemeente-x`
- WHEN a consumer attempts to establish a session in organisation `gemeente-y` with it
- THEN the consumer rejects the envelope because its `organisation` claim does not match

### Requirement: Dormant seam — adapters ship config-flag-gated and inert

The broker SHALL follow the dormant-seam pattern proven by procest
(`EHerkenningSamlAdapterInterface` + `LogEHerkenningSamlAdapter`): per-provider
adapter interfaces with a default Log implementation that logs the call and
throws "broker not configured" — never silently authenticating. Activation
SHALL require all of: (1) a broker entry configured in *Beheer >
Authenticatie*, (2) SP private key + certificate loaded via the encrypted
store (ADR-016), (3) the feature flag flipped from `0` to `1`, and (4) the DI
binding swapped to the live adapter. Consumers SHALL keep their existing
edges (portaliq's debug-gated dev-login) until the flip; the broker path
SHALL contain no fail-open resolver shapes (no catch-Throwable-return-null
around auth services).

@e2e exclude Spec-first: the adapters themselves are future work; this requirement pins the procest-precedent pattern they must follow.

#### Scenario: Feature flag off means the broker refuses

- GIVEN the broker feature flag is `0` or certificates are absent
- WHEN any broker endpoint or adapter is invoked
- THEN the Log-adapter logs the attempt and throws "broker not configured"
- AND no authentication, envelope, or session results

#### Scenario: Portaliq dev-login keeps working until the flip

- GIVEN portaliq with debug-gated dev-login enabled and no live broker
- WHEN a developer uses the dev-login edge
- THEN portal sessions are minted exactly as today, unaffected by the dormant broker seam

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
local consumer session; where the broker contract supports it, openconnector
SHOULD propagate SP-initiated single logout, and IdP-initiated SLO received by
openconnector SHOULD propagate a revocation signal to consumers.

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
