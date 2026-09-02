---
kind: code
depends_on: []
---

# Proposal: approvals-verification-pack

## Summary

Close the verification debt the archived `hitl-approval-rule-action` change
left open with honest unticked boxes: the shipped approval surface
(`ApprovalService`, `ApprovalsController`, the Pending Approvals pages, the
rule editor's `approval` form) has unit coverage but no end-to-end proof, no
Newman coverage, no feature documentation and no compiled l10n catalog
entries. This change adds exactly that verification and nothing else: no
approval behavior changes here.

## Motivation

The archived twin (`archive/2026-07-15-hitl-approval-rule-action`) checked
31/42 boxes and left the rest open with reasons ("no live instance in this
environment", "not run in this finalization pass"). That debt has sat
invisible inside a superseded 0/42 umbrella ever since. It matters now
because `hitl-on-shared-tasks` is rewiring approval expiry and outcomes onto
OpenRegister's task service: a regression net around the existing
suspend-approve-resume behavior is the difference between that cutover being
verifiable and being hopeful.

## Affected Projects

- [x] Project: `integriq` — tests (PHPUnit integration, Newman, Playwright),
  docs, l10n. No `lib/` behavior changes.

## Scope

### In Scope

1. PHPUnit integration test: suspend → approve → resume through a real
   endpoint rule chain (real `EndpointService` + `ApprovalService` wiring,
   faked HTTP/OR edges only), per the archived change's open ADR-009 box.
2. Newman coverage for `/api/approvals*`: list, detail, approve, reject, and
   the 403/404/409 error paths, added to the existing Postman collection.
3. Playwright specs for the Pending Approvals list + detail pages (approve
   with comment, reject with comment) and the rule editor's `approval` action
   form, traced to the scenarios in
   `openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md`
   per `hydra-gate-e2e-coverage`.
4. Feature documentation in `docs/`: the `approval` rule action type, the
   Synchronization `requiresApproval` gate, the Pending Approvals UI; one
   screenshot in `docs/images/`.
5. l10n: `en_US` source strings verified extractable and `nl_NL` catalog
   entries for the Approvals UI and the `ApprovalForm.vue` fields.

### Out of Scope

- Any change to approval behavior, schemas or routes.
- Expiry/onTimeout/onReject semantics: `hitl-on-shared-tasks` owns their
  move onto OR's task service. If that change alters resume mechanics before
  this one runs, the tests here assert the behavior at HEAD when written.
- New approval features (delegation, escalation): OR `TaskService` /
  `TaskSequenceService` territory, per the superseded umbrella's disposition.

## Approach

Test-only PR(s). The integration test lives in `tests/Integration/`, the
Newman additions in `tests/postman/`, the Playwright specs in
`tests/e2e/spec-coverage/`. Docs follow the existing `docs/` layout.

## Impact

- `tests/Integration/ApprovalRuleChainTest.php` — new.
- `tests/postman/*.postman_collection.json` — approval scenarios added.
- `tests/e2e/spec-coverage/approval-workflow.spec.ts` — new.
- `docs/` + `docs/images/` — new page + screenshot.
- `l10n/` — catalog entries.

## Rollback Strategy

Tests and docs only; revert the PR. No schema, route or service is touched.
