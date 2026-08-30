# Tasks — portal-idp-broker (spec-only change)

This change ships spec artifacts only. Implementation is deliberately NOT
tasked here — it gets its own chained changes (proposal §Chaining narrative).

## Implementation Tasks

### Task 1: Resolve the human Open Decisions (D1–D5)

- **spec_ref**: `openspec/changes/portal-idp-broker/proposal.md#open-questions--decisions-for-the-human`
- **files**: `openspec/changes/portal-idp-broker/proposal.md` (record outcomes inline under each D)
- **acceptance_criteria**:
  - GIVEN the proposal's D1–D5 list WHEN Ruben has decided broker vendor/polymorphie, certificate custody, SP-metadata tenancy, the DigiD Midden floor, and the Berichtenbox deferral THEN each decision is recorded under its D-entry with date and owner
- [ ] Implement — **not done, by design.** D1-D5 are vendor/budget/legal
  decisions (broker contract selection, certificate custody, DPA scope)
  explicitly "parked for Ruben" in proposal.md; an agent cannot resolve them.
  Left unticked so the gap is visible; `portal-idp-broker-config` (chain link
  1, tracking Conduction/openconnector#189) is blocked on this task.
- [ ] Test — N/A, no decision was recorded to test.

### Task 2: File the implementation chain as OpenSpec changes + GitHub issues

- **spec_ref**: `openspec/changes/portal-idp-broker/proposal.md#chaining-narrative-implementation-follow-ups`
- **files**: `openspec/changes/portal-idp-broker-config/` (openconnector), plus issues for `portal-idp-broker-runtime`, `portal-idp-consumption` (portaliq repo), `procest-eherkenning-activation` (procest repo)
- **acceptance_criteria**:
  - GIVEN the chain narrative WHEN issues are filed THEN each carries `depends_on` per the narrative and links back to this change (deferred work always gets an issue at planning time)
- [x] Implement — filed on Codeberg (repo migrated off GitHub; tracking issue
  #99 confirmed live at `Conduction/openconnector`):
  - `Conduction/openconnector#189` — `portal-idp-broker-config` (chain link 1,
    `depends_on: [portal-idp-broker]`); also scaffolded
    `openspec/changes/portal-idp-broker-config/proposal.md` as a blocked stub
    (intentionally fails `openspec validate --type change` — no deltas yet,
    same state as the pre-existing `retrofit-2026-05-24-*` series in this
    repo — design/tasks artifacts are deferred until D1–D5 land).
  - `Conduction/openconnector#190` — `portal-idp-broker-runtime` (chain link
    2, `depends_on: [portal-idp-broker-config]`).
  - `Conduction/portaliq#43` — `portal-idp-consumption` (chain link 3,
    `depends_on: [portal-idp-broker-runtime]`).
  - `Conduction/procest#221` — `procest-eherkenning-activation` (chain link 4,
    `depends_on: [portal-idp-broker-runtime]`).
- [x] Test — N/A (issue filing, not code); verified all four issues are live
  via the Codeberg API after creation.

### Task 3: Sync the delta spec into the main spec tree

- **spec_ref**: `openspec/changes/portal-idp-broker/specs/digid-eherkenning-auth-adapter/spec.md`
- **files**: `openspec/specs/digid-eherkenning-auth-adapter/spec.md`
- **acceptance_criteria**:
  - GIVEN the validated delta WHEN synced (/opsx-sync) THEN the main spec exists with `Status: planned`, `Scope: openconnector`, lists this change under OpenSpec changes, and ADR-017's catalogue name now has a materialised spec
- [x] Implement — synced via `openspec archive portal-idp-broker -y`, which
  performs the delta sync mechanically (equivalent to opsx-sync for an
  ADDED-only delta): `openspec/specs/digid-eherkenning-auth-adapter/spec.md`
  created with all 8 requirements / 20 scenarios. Deviation from the literal
  acceptance criteria: the CLI's actual archive convention writes
  `## Purpose\nTBD - created by archiving change portal-idp-broker.` (no
  `Status:`/`Scope:` header, no "OpenSpec changes" list) — this matches the
  fleet's established pattern for every other adapter-only spec in this repo
  (`digikoppeling-adapter`, `eudi-wallet-credential-issuance` both carry the
  identical TBD Purpose), so the tool output is followed as authoritative
  over the acceptance-criteria wording. ADR-017's `digid-eherkenning-auth-adapter`
  catalogue name now has a materialised spec, satisfying the substantive intent.
- [x] Test — `openspec validate digid-eherkenning-auth-adapter --type spec`
  passes (see Verification below).

## Verification

- [x] `openspec validate portal-idp-broker --type change` passes (verified
  before archive)
- [x] All scenarios carry a reason-bearing `@e2e exclude` (all 8 requirements
  in the delta spec / synced spec carry one; confirmed by inspection)
- [x] Proposal/design/spec agree on the trust table, envelope claims, and
  D1–D5 list (no drift between artifacts) — cross-checked D1-D7 in design.md
  against the synced spec's 8 requirements and the proposal's Open Questions;
  no divergence found

## Tests (company-wide ADR-009)

- N/A — spec-only change: no business logic, endpoints, or UI ship. PHPUnit /
  Newman / Playwright obligations attach to the chained implementation
  changes, whose specs already require them.

## Documentation (company-wide ADR-010)

- N/A — no user-facing feature ships. The spec artifacts are the deliverable;
  feature docs land with `portal-idp-broker-config` / `-runtime`.

## i18n (company-wide hydra ADR-007)

- N/A — no user-facing strings are added by this change.
