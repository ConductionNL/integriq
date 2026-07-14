# Contract: hitl-approval-rule-action

## Consumers

None. Per proposal.md's "Cross-Project Dependencies" section, this change
is self-contained within OpenConnector. No other `apps-extra` project calls
`/api/approvals*`, reads `approval_request` objects directly, or depends on
the `approval` rule action type. The only cross-app dependency is
OpenRegister object persistence/audit, which is already a required runtime
dependency of every OpenConnector schema (not a new contract introduced by
this change).

This document is therefore not applicable in its full form — see
design.md's API Design section for the concrete endpoint definitions
(`GET /api/approvals`, `GET /api/approvals/{id}`,
`POST /api/approvals/{id}/approve`, `POST /api/approvals/{id}/reject}`),
which remain OpenConnector-internal (consumed only by its own SPA).

## Endpoints

See `design.md` → API Design for the full request/response/error shapes.
Not duplicated here since there are no external consumers to align with.

## Error Codes

See `design.md` → API Design's per-endpoint error tables (403, 404, 409,
500) and `openspec/specs/approval-workflow/spec.md` REQ-003/REQ-004/REQ-006
for the scenarios each code corresponds to.

## Versioning

Internal-only REST surface, versioned implicitly with the OpenConnector app
release (no separate API version). No external consumer commitment is made.

## Breaking Change Policy

N/A — no external consumers. Changes to `/api/approvals*` only need to stay
in sync with the OpenConnector frontend SPA, tracked in the same change/PR.

## SLA

N/A — internal admin/approver-facing UI, not a service-level surface. The
only latency consideration is design.md Risk 2 (resumed rule chain runs
inside the approver's own request), which is a UX trade-off documented
there, not an SLA commitment.
