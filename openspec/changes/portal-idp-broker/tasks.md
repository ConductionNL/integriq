# Tasks — portal-idp-broker (spec-only change)

This change ships spec artifacts only. Implementation is deliberately NOT
tasked here — it gets its own chained changes (proposal §Chaining narrative).

## Implementation Tasks

### Task 1: Resolve the human Open Decisions (D1–D5)

- **spec_ref**: `openspec/changes/portal-idp-broker/proposal.md#open-questions--decisions-for-the-human`
- **files**: `openspec/changes/portal-idp-broker/proposal.md` (record outcomes inline under each D)
- **acceptance_criteria**:
  - GIVEN the proposal's D1–D5 list WHEN Ruben has decided broker vendor/polymorphie, certificate custody, SP-metadata tenancy, the DigiD Midden floor, and the Berichtenbox deferral THEN each decision is recorded under its D-entry with date and owner
- [ ] Implement
- [ ] Test

### Task 2: File the implementation chain as OpenSpec changes + GitHub issues

- **spec_ref**: `openspec/changes/portal-idp-broker/proposal.md#chaining-narrative-implementation-follow-ups`
- **files**: `openspec/changes/portal-idp-broker-config/` (openconnector), plus issues for `portal-idp-broker-runtime`, `portal-idp-consumption` (portaliq repo), `procest-eherkenning-activation` (procest repo)
- **acceptance_criteria**:
  - GIVEN the chain narrative WHEN issues are filed THEN each carries `depends_on` per the narrative and links back to this change (deferred work always gets an issue at planning time)
- [ ] Implement
- [ ] Test

### Task 3: Sync the delta spec into the main spec tree

- **spec_ref**: `openspec/changes/portal-idp-broker/specs/digid-eherkenning-auth-adapter/spec.md`
- **files**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md`
- **acceptance_criteria**:
  - GIVEN the validated delta WHEN synced (/opsx-sync) THEN the main spec exists with `Status: planned`, `Scope: openconnector`, lists this change under OpenSpec changes, and ADR-017's catalogue name now has a materialised spec
- [ ] Implement
- [ ] Test

## Verification

- [ ] `openspec validate portal-idp-broker --type change` passes
- [ ] All scenarios carry a reason-bearing `@e2e exclude` (gate-19; nothing implementable yet)
- [ ] Proposal/design/spec agree on the trust table, envelope claims, and D1–D5 list (no drift between artifacts)

## Tests (company-wide ADR-009)

- N/A — spec-only change: no business logic, endpoints, or UI ship. PHPUnit /
  Newman / Playwright obligations attach to the chained implementation
  changes, whose specs already require them.

## Documentation (company-wide ADR-010)

- N/A — no user-facing feature ships. The spec artifacts are the deliverable;
  feature docs land with `portal-idp-broker-config` / `-runtime`.

## i18n (company-wide hydra ADR-007)

- N/A — no user-facing strings are added by this change.
