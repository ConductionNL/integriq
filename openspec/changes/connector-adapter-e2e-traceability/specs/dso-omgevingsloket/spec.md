# dso-omgevingsloket — Traceability Delta

**Spec refs**: `dso-omgevingsloket`, ADR-020 (gate scope). Does not touch REQ-DSO-050,
owned by `openspec/changes/dso-stam-pkioverheid-signature-verification/`.

## ADDED Requirements

### Requirement: Scenario-Level Test Traceability (excluding REQ-DSO-050)

Every `#### Scenario:` in this capability MUST carry either an `@e2e` reference to a
browser test, or a reason-bearing `@e2e exclude <reason>` line — except the REQ-DSO-050
(PKIoverheid Certificate Authentication) scenarios, which are tracked separately.

#### Scenario: Backend-only scenario carries an exclude reason

- GIVEN a scenario describes STAM koppelvlak HTTP/XML wire behavior with no Vue UI
  surface (confirmed: no `src/**/*dso*` or `*omgevingsloket*` Vue files exist)
- WHEN the scenario is reviewed for e2e traceability
- THEN it MUST carry `@e2e exclude backend DSO/Omgevingsloket STAM integration — covered by PHPUnit, not browser UI`

#### Scenario: REQ-DSO-050 scenarios are exempt from this change

- GIVEN a scenario belongs to REQ-DSO-050 (PKIoverheid Certificate Authentication)
- WHEN this change's annotation pass runs
- THEN those scenarios are left unannotated here and are owned by
  `dso-stam-pkioverheid-signature-verification` instead
