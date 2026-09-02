---
kind: spec-only
depends_on: []
---

# Proposal: hitl-approval-rule-action (superseded — re-scoped 2026-09-02)

This directory double-counted a change that had already shipped. The
`approval` rule action was implemented and archived on 2026-07-15
(`archive/2026-07-15-hitl-approval-rule-action`, PR #181, 31/42 tasks checked
with per-task evidence), yet this live copy was left standing at 0/42. The
machinery exists at HEAD: `ApprovalService`, `ApprovalsController`,
`ApprovalTimeoutSweepJob`, the `approval_request` schema in
`lib/Settings/register.d/hitl-approval-rule-action.json`, the suspend/resume
path in `EndpointService`, the Pending Approvals UI, and the ADR-023
`approval.approve`/`approval.reject` matrix entries.

The One-engine direction also moves this change's ownership boundary. Expiry
and outcome semantics (`expiresAt`, `onTimeout`, `onReject`) now belong to
OpenRegister's shared task service (openregister change
`task-expiry-and-outcomes`), and Integriq's adoption of it is a separate,
in-flight change: `hitl-on-shared-tasks` (its first PR, #1812, is merged;
its remaining tasks continue there — do not duplicate them here).

13 `@spec` tags in `lib/` and `tests/` point at this directory's `design.md`
and `specs/approval-workflow/spec.md`, so those two files stay exactly where
they are as anchor targets. The other artifacts (context brief, contract,
discovery, migration, test plan) are removed; they survive verbatim in the
archived twin and in git history.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `approval` rule action, `approval_request` schema, `ApprovalService`, suspend/resume in `EndpointService`, `ApprovalTimeoutSweepJob`, Pending Approvals UI, ADR-023 matrix entries, Synchronization `requiresApproval` gate (tasks 1-12, 15-16) | **Already shipped and archived**: `archive/2026-07-15-hitl-approval-rule-action` (PR #181), code at HEAD |
| Expiry, `onTimeout`, `onReject` enforcement | **Moving onto OR's task service**: openregister `task-expiry-and-outcomes` owns the vocabulary and the shared sweep; Integriq adopts it through `openspec/changes/hitl-on-shared-tasks` (in flight, first PR #1812 merged) |
| Residual verification: suspend-approve-resume integration test, Newman for `/api/approvals*`, Playwright for the Approvals pages and the rule editor's approval form, feature docs, screenshot, l10n catalog entries | `openspec/changes/approvals-verification-pack` — **first shippable slice**, fully authored |
| Future approval-shaped features (delegation, escalation, multi-step sign-off chains) | Target OR primitives (`TaskService`, `TaskSequenceService`); no new app-local approval machinery. A disposition, not a change |

## Sequencing

`approvals-verification-pack` is independent and ready to hand to an agent
today. `hitl-on-shared-tasks` continues on its own track. Nothing remains to
implement from this change directly.

## Archival

This directory is retired in place (not moved or renamed): its `design.md`
and `specs/approval-workflow/spec.md` are live `@spec` anchor targets, and a
rename would both break those tags and detonate every diff-scoped gate.
Archive it via the normal flow only after those tags are repointed at the
main specs.
