# ibabs-notubiz-connector — Traceability Delta

**Spec refs**: `ibabs-notubiz-connector`, ADR-020 (gate scope)

## ADDED Requirements

### Requirement: Scenario-Level Test Traceability

Every `#### Scenario:` in this capability MUST carry either an `@e2e` reference to a
browser test, or a reason-bearing `@e2e exclude <reason>` line.

#### Scenario: Backend-only scenario carries an exclude reason

- GIVEN a scenario describes iBabs REST / NotuBiz API wire behavior with no Vue UI
  surface (confirmed: no `src/**/*ibabs*` or `*notubiz*` Vue files exist)
- WHEN the scenario is reviewed for e2e traceability
- THEN it MUST carry `@e2e exclude backend iBabs/NotuBiz RIS integration — covered by PHPUnit, not browser UI`
