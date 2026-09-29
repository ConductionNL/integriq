# digid-eherkenning-auth-adapter Specification (delta)

## ADDED Requirements

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

## MODIFIED Requirements

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
