# app-distribution-metadata — Delta: licence tag and OpenRegister-requirement honesty

## Purpose

Establishes that OpenConnector's human-facing distribution metadata — the
Nextcloud App Store licence tag (`appinfo/info.xml`), the README, and the
OpenSpec `config.yaml` design rules — MUST NOT contradict the app's declared,
machine-enforced dependency on OpenRegister, and that a missing OpenRegister
install is surfaced as a clear admin/health signal rather than bare HTTP 500s.
The machine-readable dependency itself is owned by `openconnector-app-manifest`
(`manifest.dependencies` MUST contain `openregister`); this capability keeps the
prose and the App Store metadata consistent with it.

## ADDED Requirements

### Requirement: info.xml licence tag matches the project licence (REQ-ADM-001)

The `<licence>` element in `appinfo/info.xml` MUST identify the project's
licence as EUPL-1.2, using the App Store token `eupl`. It MUST NOT declare
`agpl` or any other licence for the OpenConnector app itself, because `LICENSE`
(European Union Public Licence v. 1.2), `publiccode.yml` (`license: EUPL-1.2`),
and the README badge are the canonical licence artifacts and all read EUPL-1.2.

@e2e exclude static manifest metadata — asserted by a manifest/lint check, no browser UI

#### Scenario: the App Store licence token is EUPL

- **GIVEN** `appinfo/info.xml`
- **WHEN** the `<licence>` element is inspected
- **THEN** its value SHALL be `eupl`
- **AND** no repository artifact SHALL declare AGPL as the licence of the
  OpenConnector app itself
- @e2e exclude static manifest metadata — covered by a lint/CI check

### Requirement: Human-facing metadata declares OpenRegister as required (REQ-ADM-002)

The README and the OpenSpec `config.yaml` MUST describe OpenRegister as a
**required runtime dependency** of OpenConnector. They MUST NOT state or imply
that OpenConnector is a standalone application, that it runs without
OpenRegister, or that OpenRegister is optional. This is consistent with the
observable contract at HEAD: OpenConnector persists every entity as an
OpenRegister object (no `lib/Db/` mappers exist), its controllers inject
`OCA\OpenRegister\Service\ObjectService` as a required non-nullable constructor
dependency, `src/manifest.json` declares `"dependencies": ["openregister"]`,
and the `mapping-and-search` spec states persistence requires OpenRegister.

@e2e exclude documentation/config prose consistency — asserted by a doc-lint check, no browser UI

#### Scenario: README does not claim standalone operation

- **GIVEN** `README.md`
- **WHEN** its dependency statements are inspected
- **THEN** none SHALL claim OpenConnector is standalone or that OpenRegister is
  optional
- **AND** at least one SHALL state OpenRegister is a required runtime dependency
- @e2e exclude documentation prose — covered by a doc-lint check

#### Scenario: config.yaml design rule matches the code contract

- **GIVEN** `openspec/config.yaml`
- **WHEN** its design rules are inspected
- **THEN** no rule SHALL assert OpenConnector operates independently of
  OpenRegister or has no hard dependency on it
- @e2e exclude config prose — covered by a doc-lint check

#### Scenario: no phantom lib/Db directory in the documented structure

- **GIVEN** the README Directory Structure block
- **WHEN** it is inspected
- **THEN** it SHALL NOT list a `lib/Db/` directory (which does not exist at HEAD)
- @e2e exclude documentation prose — covered by a doc-lint check

### Requirement: Missing OpenRegister surfaces a clear signal, not a 500 (REQ-ADM-003)

When the `openregister` app is not installed or not enabled, OpenConnector MUST
surface a clear, actionable signal that OpenRegister is required, rather than
letting controller dependency-injection fail with bare HTTP 500 responses. The
detection MUST use `IAppManager::isEnabledForUser('openregister')` (or the
equivalent app-enablement check) and MUST NOT reference any `OCA\OpenRegister\*`
class, so it is safe to run while OpenRegister is absent. Two surfaces are
required: (a) an admin-visible notice at app boot naming OpenRegister as a
required dependency, and (b) the `/api/health` endpoint reporting the missing
dependency as an unhealthy/degraded reason (HTTP 503, per ADR-006).

@e2e exclude backend boot guard + health endpoint — covered by PHPUnit, no browser UI

#### Scenario: OpenRegister disabled raises an admin notice

- **GIVEN** the `openregister` app is disabled
- **WHEN** OpenConnector boots
- **THEN** an admin-visible notice SHALL state that OpenConnector requires the
  OpenRegister app
- **AND** the boot guard SHALL NOT reference any OpenRegister class
- @e2e exclude backend boot guard — covered by PHPUnit

#### Scenario: health reports the missing dependency instead of 500

- **GIVEN** the `openregister` app is disabled
- **WHEN** `/api/health` is requested
- **THEN** the response SHALL be HTTP 503 naming the missing OpenRegister
  dependency as the unhealthy reason
- @e2e exclude backend health endpoint — covered by PHPUnit

#### Scenario: OpenRegister enabled leaves behaviour unchanged

- **GIVEN** the `openregister` app is enabled
- **WHEN** OpenConnector boots and `/api/health` is requested
- **THEN** no missing-dependency notice SHALL be raised
- **AND** the health check SHALL not report OpenRegister as missing
- @e2e exclude backend boot guard — covered by PHPUnit
