# Capability: beta-alignment

## ADDED Requirements

### Requirement: Public-facing surfaces SHALL only claim verified, shipped capabilities (REQ-BA-001)

OpenConnector's `appinfo/info.xml` description, the `conduction.nl/apps/openconnector`
product page (EN + NL), and the `openconnector.conduction.nl` docs MUST only
describe connector protocols, adapters, and compliance claims that are
demonstrably implemented in `lib/` at the time of writing. A protocol or
adapter name MUST NOT appear on a public surface unless it is traceable to a
concrete class/service and, where one exists, a retrofit
`openspec/specs/*` entry.

#### Scenario: Reviewer checks a protocol claim against code

- **GIVEN** a claim on the product page (e.g. "REST and SOAP sources")
- **WHEN** the reviewer greps `lib/` for the corresponding client/service code
- **THEN** a concrete implementation MUST exist (e.g. `CallService`, `SOAPService`)

#### Scenario: An unimplemented spec'd capability MUST NOT be marketed

- **GIVEN** an `openspec/specs/*` entry that describes a capability in
  aspirational ("MUST be implemented") rather than retrofit ("already exists")
  language, with no corresponding `lib/Service/Adapter/*` class or registered
  `IntegrationProvider`
- **THEN** that capability MUST NOT appear on the product page, docs, or
  `info.xml` description until it is actually implemented

### Requirement: `info.xml`, product page, and docs SHALL use one shared feature vocabulary and version (REQ-BA-002)

The `<summary>`/`<description>` in `appinfo/info.xml`, the product page's
FeatureList/Showcase copy, and the docs `intro.md` MUST name the same
capabilities using the same terms, and the product page's `version` prop MUST
match `info.xml`'s `<version>` (the source of truth).

#### Scenario: Version drift is corrected

- **GIVEN** `info.xml` version `0.2.16`
- **WHEN** the product page hero is rendered
- **THEN** its `version` prop MUST read `v0.2.16`, not a stale prior value

#### Scenario: `<summary>` carries real Dutch, not a machine-copy of English

- **GIVEN** the beta-release checklist (ADR-007)
- **WHEN** `info.xml` is reviewed
- **THEN** it MUST declare both `<summary lang="en">` and `<summary lang="nl">`
  with distinct, natural Dutch text — not an English string duplicated under
  a `nl` tag
