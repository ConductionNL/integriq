# Context Brief: hitl-approval-rule-action
Source: Specter deep-research 2026-07-14 (insight #1264). VERIFY every code claim against HEAD before writing artifacts.

## Problem / Opportunity
No human-in-the-loop step exists in OpenConnector flows. n8n ships native approval steps + fallback logic; Windmill has approval/suspend steps (integrated with NC Talk in integration_windmill); Workato has HITL. Municipal integration flows need "a human approves before data leaves/enters" (e.g. publish to WOO portal, outbound message to Berichtenbox).

## Current state (verify at HEAD)
- Rules: ordered pre/post endpoint rules with JsonLogic conditions; 16+ action types incl. synchronization-trigger, webhook_signature (see EndpointService + src/views/Rule/actionForms/).
- Synchronizations trigger via cron/webhook/rule/manual.
- openconnector-notifications change (ADR-031 dialect) exists — check its state; NC notifications available via OCP\Notification.
- flow-token-helper spec: 8-slot request/response/sync snapshots through rule pipeline (the suspension/resume substrate candidate).

## In scope
1. New rule action type `approval`: when hit, the pipeline run SUSPENDS: persist an ApprovalRequest entity (context snapshot, requester, approver group, expiry) as an OR object; notify approver group via NC notifications (approve/reject actions deep-linking into OpenConnector UI).
2. Approve → pipeline resumes from suspension point with original context; Reject or timeout → configurable outcome (error response, skip action, route to dead-letter).
3. Synchronization gate: optional "requires approval" on a synchronization — run pauses before write phase until approved (batch-level, not per-object).
4. Approvals UI: pending approvals list page + approve/reject with comment; full audit (who/when/comment) on the ApprovalRequest object (OR audit trail).
5. Authorization via ADR-023 action matrix (approval.approve, approval.reject grants).
6. Tests: unit for state machine (pending/approved/rejected/expired); integration for suspend→approve→resume through an endpoint rule.
## Out of scope
- Talk-message approvals (notifications only, Talk later), multi-step quorum approvals, decidesk integration.

## Constraints
- Suspension must survive PHP process boundaries (persisted state + resume via background job or next request — design decision for design.md; NO long-running processes).
- Specs: new capability spec + deltas to rule-pipeline, synchronization-engine; notifications must follow ADR-031 x-openregister-notifications dialect (check hydra gate notification-dialect).
