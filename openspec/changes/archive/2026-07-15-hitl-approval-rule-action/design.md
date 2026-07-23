# Design: hitl-approval-rule-action

## Architecture Overview

```
Endpoint request                                    Approver's own request
──────────────────                                   ───────────────────────
doHandleRequest()
  processRules(timing:'before')
    ... earlier rules run, mutate FlowToken ...
    hits `approval` rule (order N)
      ApprovalService::suspend()
        - snapshot = flowToken->__serialize()
        - persist `approval_request` OR object:
            status=pending, endpointId, ruleId, resumeOrder=N,
            timing='before', snapshot, requester, approverGroup,
            onReject, onTimeout, expiresAt
        - ApprovalService::notifyApprovers()  (imperative, see Decision 4)
      returns JSONResponse(202, {id, statusUrl})
  <-- short-circuits at the EXISTING `instanceof JSONResponse` check
      in doHandleRequest() (line 354) -->
  caller receives 202 + Location/statusUrl              ApprovalsController::approve($id)
                                                            - requireAction('approval.approve')
  GET  /api/approvals/{id}                                 - object-level: caller in approverGroup?
    -> ApprovalsController::show()                          - ApprovalService::resume($id)
       returns current status                                  - rehydrate FlowToken from snapshot
                                                                 - processRules(timing:'before',
                                                                     startAfterOrder: resumeOrder)
                                                                 - continue doHandleRequest()'s normal
                                                                     path: schema dispatch + 'after' rules
                                                            - response: the resumed pipeline's final
                                                                result (200/201/4xx), NOT another 202

ApprovalTimeoutSweepJob (TimedJob, cron)
  - scans approval_request where status=pending AND expiresAt < now
  - applies onTimeout: error | skip | dead_letter
```

The suspension point reuses `EndpointService::doHandleRequest()`'s existing
short-circuit contract (`processRules()` may already return a `JSONResponse`
or `DataDownloadResponse` to abort the pipeline — see `rule-pipeline` spec
REQ-RULE-001). No new Response type or execution model is introduced.

## API Design

### `GET /api/approvals`
**Auth**: NC session, `#[NoAdminRequired]`, `requireAction('approval.approve')` OR
membership check deferred to per-row filtering (list only rows the caller's
groups can act on, plus their own submitted requests).

**Response (200):**
```json
{
  "results": [
    {
      "id": "uuid",
      "status": "pending",
      "endpointName": "WOO Publish",
      "ruleName": "Require WOO approval",
      "requester": "j.jansen",
      "approverGroup": "woo-approvers",
      "createdAt": "2026-07-14T09:00:00+00:00",
      "expiresAt": "2026-07-15T09:00:00+00:00"
    }
  ]
}
```

### `GET /api/approvals/{id}`
**Auth**: NC session, `#[NoAdminRequired]`; object-level approver-group or
requester check inside the controller (see Decision 5).

**Response (200):** full `approval_request` object including snapshot
summary (method/path/body preview — not the raw FlowToken internals) and
audit fields (`approver`, `approvedAt`/`rejectedAt`, `comment`).

**Errors:**
| Code | Condition |
|------|-----------|
| 403  | caller is neither in `approverGroup` nor an admin, and did not create the request |
| 404  | no such `approval_request` |

### `POST /api/approvals/{id}/approve`
**Auth**: NC session, `#[NoAdminRequired]`, `#[NoCSRFRequired]` is NOT set
(this is a state-changing UI action, CSRF protection stays on);
`requireAction('approval.approve')` + object-level `approverGroup`
membership check (Decision 5).

**Request:**
```json
{ "comment": "optional note" }
```
**Response (200):** the resumed pipeline's final result — same shape the
original suspended endpoint would have returned on success (e.g. the
created/updated object, or the target's response), plus an
`_approval: { id, status: "approved", resumedAt }` envelope key.

**Errors:**
| Code | Condition |
|------|-----------|
| 403  | not authorized (matrix or group) |
| 404  | no such `approval_request` |
| 409  | request is not `pending` (already approved/rejected/expired/dead_letter) |
| 500  | resumed rule chain itself failed — mirrors `rule-pipeline`'s existing 500 contract, `approval_request.status` is set to `error` |

### `POST /api/approvals/{id}/reject`
**Auth**: same as approve; `requireAction('approval.reject')`.

**Request:**
```json
{ "comment": "required" }
```
**Response (200):** `{ id, status: "rejected", comment, rejectedAt }`.

**Errors:** same table as approve, plus `400` when `comment` is empty
(reject always requires an audit comment; approve's comment is optional).

## Database Changes

New OpenRegister schema `approval_request`, declared via a `register.d`
fragment (`lib/Settings/register.d/hitl-approval-rule-action.json`, ADR-037
pattern — does not touch `openconnector_register.json` directly):

| Field | Type | Notes |
|---|---|---|
| `uuid` | string | canonical UUID (OR-assigned) |
| `status` | string enum | `pending`, `approved`, `rejected`, `expired`, `dead_letter`, `error` |
| `endpointId` | string (uuid, `$ref` endpoint) | the suspended endpoint |
| `ruleId` | string (uuid, `$ref` rule) | the `approval` rule that suspended the run |
| `timing` | string | always `before` for v1 (see Decision 1) |
| `resumeOrder` | integer | the `order` of the approval rule; resume continues with rules whose `order` is strictly greater, same `timing` |
| `snapshot` | object | `FlowToken::__serialize()` output — the 8-key request/response/syncInput/syncOutput array |
| `synchronizationId` | string (uuid, `$ref` synchronization), nullable | set instead of `endpointId`/`ruleId` for the Synchronization batch gate (Decision 6) |
| `requesterUserId` | string | NC user id who triggered the original request (or `null` for unauthenticated/API-key callers) |
| `approverGroup` | string | NC group id resolved from the rule's/synchronization's configuration at suspension time |
| `onReject` | string enum | `error` \| `skip` \| `dead_letter` |
| `onTimeout` | string enum | `error` \| `skip` \| `dead_letter` |
| `expiresAt` | string (date-time) | `createdAt` + configured TTL (default 24h) |
| `approverUserId` | string, nullable | set on approve/reject |
| `comment` | string, nullable | required on reject |
| `approvedAt` / `rejectedAt` | string (date-time), nullable | |
| `resumeResult` | string enum, nullable | `success` \| `error` — outcome of the resumed chain, for audit |

OpenRegister's existing audit trail (already used by every other schema in
this app) covers who/when for every field mutation — no separate audit table.

## Nextcloud Integration

- Controllers: `ApprovalsController` (`Controller -> Service -> Mapper`
  per ADR-008) — index/show/approve/reject, delegates all logic to
  `ApprovalService`.
- Services: `ApprovalService` (suspend/notify/resume/reject/sweep helpers,
  used by both `EndpointService` and `SynchronizationService`),
  `ActionAuthService` (existing, reused for the coarse gate).
- Mappers/Entities: none new — `approval_request` is an OpenRegister object,
  accessed via the existing `ObjectService`/OR mapper adapter, exactly like
  every other schema in this app (per `openconnector-direct-or-usage`: no
  app-local reimplementation of OR persistence).
- Events/Hooks: `ApprovalTimeoutSweepJob extends TimedJob` (NC
  `OCP\BackgroundJob\TimedJob`), registered in `lib/AppInfo/Application.php`
  alongside the existing five cron jobs; runs every 5 minutes (matches
  `EventRetryJob`'s cadence) and sweeps expired `pending` requests.
- Notification: `OCP\Notification\IManager` (imperative, new to this app —
  see Decision 4) for the actionable approver notification;
  `x-openregister-notifications` (declarative, ADR-031) on `approval_request`
  for ops-visibility.

## Security Considerations

- **Two-layer authorization (Decision 5).** `requireAction('approval.approve'
  | 'approval.reject')` is the coarse, ADR-023 app-wide gate (seeded
  `["admin"]`, broadened via the existing Action Authorization settings UI).
  It alone is insufficient: it doesn't know which specific `approval_request`
  a caller is trying to act on. `ApprovalsController::approve()`/`reject()`
  additionally check `IGroupManager::isInGroup($user, $approvalRequest->approverGroup)`
  (or admin) before calling `ApprovalService::resume()`/`reject()`. A user
  in the app-wide `approval.approve` matrix group who is NOT in the
  specific request's `approverGroup` is rejected with 403 — this is the
  "unauthorized user cannot approve" scenario required by the brief.
- **Snapshot contains request data, not credentials.** `FlowToken`'s
  request slot includes headers (per `flow-token-helper` REQ-001, including
  `Authorization` if present pre-redaction). `ApprovalService::suspend()`
  MUST strip the `Authorization` header (and any configured secret headers)
  from the persisted snapshot before saving — the resumed chain re-derives
  auth state from the *original* successful `authentication` rule result,
  not by replaying credentials. Flagged as a hard requirement, not a nice-to-have:
  storing bearer tokens/API keys in a long-lived, admin-and-approver-readable
  OR object would be a credential-exposure regression.
- **CSRF.** `approve`/`reject` are state-changing POSTs from the OpenConnector
  SPA — standard NC CSRF protection applies (no `#[NoCSRFRequired]`), unlike
  the webhook-style endpoints elsewhere in this app.
- **Expiry enforcement.** `approve()`/`reject()` MUST re-check `expiresAt`
  server-side even though the sweep job runs every 5 minutes — a request
  that expired in the last few minutes must not be approvable in the gap
  before the next sweep (race close via a status check at read time, not
  reliance on the job's cadence).
- **IDOR precedent.** `synchronization-engine`'s retrofit spec already
  flags `SynchronizationsController`/`SynchronizationContractsController` as
  missing per-object ownership checks (IDOR, OWASP A01:2021). This change
  does NOT inherit that gap: `ApprovalsController` gates every method on
  the two-layer check above from day one.

## NL Design System

Pending Approvals list + detail page use standard NC components
(`NcAppContent`, `NcListItem`, `NcButton`, `NcTextArea` for the reject
comment, `NcEmptyContent` for the zero-pending state) and CSS variables only
— no hardcoded colors, consistent with the rest of the OpenConnector SPA
(Rules/Synchronizations index pages already follow this convention, per
`rule-pipeline`/`synchronization-engine` REQ-UI-001 scenarios). WCAG AA:
the approve/reject actions are reachable via standard NC button semantics,
not icon-only controls.

## File Structure

```
lib/
  Controller/
    ApprovalsController.php
  Service/
    ApprovalService.php
  Cron/
    ApprovalTimeoutSweepJob.php
  Settings/
    register.d/
      hitl-approval-rule-action.json
  actions.seed.json                 (add approval.approve / approval.reject)
src/
  views/
    Approvals/
      ApprovalsIndex.vue
      ApprovalDetail.vue
    Rule/
      actionForms/
        ApprovalForm.vue
      RuleActionConfig.vue          (add `approval` to ACTION_TYPES/ACTION_FORM_MAP)
appinfo/
  routes.php                        (add /api/approvals routes)
```

## Seed Data

### Schema: `approval_request`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `approval-woo-publish-pending` | `approval-berichtenbox-approved` | `approval-woo-publish-rejected` |
| status | `pending` | `approved` | `rejected` |
| endpointId | (seeded "WOO Publish" endpoint) | (seeded "Berichtenbox Outbound" endpoint) | (seeded "WOO Publish" endpoint) |
| approverGroup | `woo-approvers` | `berichtenbox-approvers` | `woo-approvers` |
| requesterUserId | `admin` | `admin` | `admin` |
| onReject | `error` | `error` | `error` |
| onTimeout | `dead_letter` | `dead_letter` | `dead_letter` |
| comment | — | `"Looks correct, approved"` | `"Missing legal basis field, please add and resubmit"` |
| expiresAt | now + 24h | now - 1h (already resolved) | now - 2h (already resolved) |

**Related items per object:** none (audit trail is OR-native, no separate
files/notes/tasks needed for a realistic demo of the state machine).

## Trade-offs

- **Synchronous resume in the approver's request vs. background job.**
  Chosen: synchronous (Decision 3). Alternative — queue resume via
  `IJobList::add()` — was rejected for the common case because NC cron
  cadence (as low as 1/min in production, but unbounded in dev/staging
  without a live cron) would make "I approved it" feel broken to the
  approver, and the brief explicitly rules out long-running processes, not
  bounded synchronous work. Background execution is kept for timeout
  sweeping only, where no human is waiting.
- **Whole-batch dead-letter on `approval_request` vs. reusing
  `dead-letter-replay`'s `event_message` machinery.** Chosen: a self-contained
  `dead_letter` status on `approval_request` (Decision 7). Reusing
  `event_message` would conflate two different domains (CloudEvent delivery
  retries vs. rule-pipeline suspension) and would require synthesizing a
  fake CloudEvent for every suspended rule chain — rejected as unnecessary
  coupling. The list/detail/audit UX pattern is intentionally imitated,
  not the underlying schema.
- **Declarative-only notifications were considered and rejected as
  infeasible**, not merely undesirable — see Decision 4 and discovery.md;
  the dialect has no dynamic-group-from-field recipient kind and no
  interactive-action support, confirmed against all 9 existing usages.

## Decisions

### Decision 1: `approval` rule type is `timing: before` only
**Why:** `doHandleRequest()` calls `processRules(timing:'before')` strictly
before the schema/target dispatch (`handleSchemaRequest()`), and
`processRules(timing:'after')` runs strictly after a write already
happened. Gating BEFORE the write is the only point where "suspend and
maybe never write" is safe without a compensating rollback. `override`
(existing) is the precedent for a timing-restricted rule type — the same
mechanism (a runtime check inside the rule handler, throwing/erroring if
misconfigured) applies to `approval`.
**Alternative considered:** support `after`-timing approval with a
compensating delete/rollback on reject. Rejected for v1 — adds a rollback
matrix per target type (schema write, external source call, file write)
that isn't justified by the brief's scope; revisit if a real use case needs
post-write approval.

### Decision 2: Suspension reuses the existing `JSONResponse` short-circuit; no new Response type
**Why:** `doHandleRequest()` already treats any `JSONResponse`/
`DataDownloadResponse` returned from `processRules()` as a terminal result
(`rule-pipeline` REQ-RULE-001). Returning `JSONResponse(202, ['status' =>
'pending_approval', 'approvalRequestId' => ..., 'statusUrl' => ...])` from
the new `processApprovalRule()` case needs zero changes to
`doHandleRequest()`'s control flow — it is indistinguishable, mechanically,
from an `error` rule's short-circuit.
**Alternative considered:** a new `SuspendedResponse` Response subclass to
make "this was a suspension, not an error" explicit at the type level.
Rejected — the 202 status code plus response body shape already
disambiguates it from the 4xx/5xx error rules use, and a new Response type
would need explicit handling added to `doHandleRequest()`'s and callers'
`instanceof` checks, increasing the blast radius for no behavioral gain.

### Decision 3: Approve/reject resume synchronously in the approver's own HTTP request; only timeout sweeping is background/cron
**Why:** The approver's `POST /api/approvals/{id}/approve` call is already
a fresh PHP process, satisfying "suspension must survive PHP process
boundaries" without inventing new infrastructure. It also gives the
approver an immediate, synchronous answer (success or the resumed chain's
own error) rather than a "submitted, check back later" experience for what
is usually a fast operation. Timeout sweeping has no human waiting on a
response, so a `TimedJob` (matching `EventRetryJob`'s existing idiom) is the
right fit there.
**Alternative considered:** always resume via a queued background job
(uniform mental model for "resume"). Rejected per the trade-off above —
NC cron latency would make every approval feel slow/unreliable, and the
brief explicitly frames the process-boundary constraint as "no long-running
processes," not "no synchronous work."

### Decision 4: Approver notification is dispatched imperatively via `OCP\Notification\IManager`; ops-visibility notification stays declarative (ADR-031)
**Why:** confirmed by inspecting every existing `x-openregister-notifications`
block in `lib/Settings/openconnector_register.json` (9 occurrences) — the
dialect supports exactly two recipient kinds, `field` (a single userId
property on the created object) and `groups` (a **static**, schema-level
list of NC group names), and carries no action/button configuration at all
(only `trigger`/`enabled`/`channels`/`recipients`/`subject`). This feature
needs (a) a recipient group chosen per-rule at configuration time — not
expressible via a static schema-level `groups` list, and there is no
`field`-kind that resolves to a *group* — and (b) interactive approve/reject
deep-link actions on the notification itself, which the dialect has no
syntax for at all. `hydra-gate-notification-dialect` explicitly WARNS
(non-blocking) rather than hard-fails on imperative dispatch in a leaf app
— this is that documented, accepted exception, scoped to exactly one method
(`ApprovalService::notifyApprovers()`). The `approval_request` schema still
declares a compliant, declarative `x-openregister-notifications` `created`
rule targeting the static `openconnector-ops` group for passive
ops-visibility, keeping the app's dominant notification pattern intact
everywhere it can apply.
**Alternative considered:** encode the approver group as a schema-level
static `groups` list (one entry per possible approver group, always
notified). Rejected — this would spam every configured approver group on
every approval request regardless of which rule/synchronization it
belongs to, defeating the purpose of per-rule approver routing.

### Decision 5: Two-layer authorization — ADR-023 action matrix (coarse) + per-object `approverGroup` membership (fine)
**Why:** `ActionAuthService::requireAction()` checks one flat,
app-wide action-name → allowed-groups mapping (confirmed by reading the
full class) — it has no concept of "the approver group for *this specific*
`approval_request`," which is chosen per-rule/per-synchronization at
configuration time, not app-wide. `ActionAuthService`'s own docblock already
draws the line between action RBAC (this service) and data/object RBAC
("OpenRegister's job"); checking `IGroupManager::isInGroup()` against the
`approval_request` object's own `approverGroup` field is the natural
data-RBAC layer for this feature, sitting alongside (not replacing) the
coarse `requireAction()` gate. This directly satisfies the brief's required
scenario: a user who passes the app-wide `approval.approve` matrix check
but is not in the specific request's approver group MUST still be rejected.
**Alternative considered:** model each possible approver group as its own
ADR-023 action name (e.g. `approval.approve.woo-approvers`). Rejected —
action names would have to be dynamically minted per admin-configured
group, breaking the matrix's fixed, seedable, admin-editable shape that
every other action in `actions.seed.json` relies on.

### Decision 6: Synchronization gate gates the whole batch via a single `approval_request`, not one per object
**Why:** the brief requires batch-level, not per-object, gating.
`synchronization-engine`'s REQ-001/REQ-004 boundary (orchestration creates
the `synchronization_log`, then `updateTarget()` writes each transformed
object) is the natural insertion point: after fetch+map, before the
`updateTarget()` loop begins, check `synchronizationConfig.requiresApproval`;
if set and no matching approved `approval_request` exists for this run,
create ONE `approval_request` (with `synchronizationId` set instead of
`endpointId`/`ruleId`) and return/log a `pending_approval` outcome instead
of writing. On approval, `ApprovalService::resume()` re-invokes
`synchronize()` with `force: true` and the approved `approval_request`'s id
as a bypass token, so the gate check passes and the write phase proceeds.
**Alternative considered:** snapshot the entire fetched+mapped batch payload
into the `approval_request` (mirroring the endpoint-rule snapshot approach)
and resume by replaying the snapshot instead of re-running `synchronize()`.
Rejected — batches can be large (up to the 50-page fetch cap), and
re-fetching on resume is both cheaper to store and gives the approver a
guarantee the write reflects re-validated (if unlikely to have changed)
data rather than a payload that could be stale by the time it's approved;
staleness risk is bounded by `expiresAt` either way (see proposal.md Risk 3).

### Decision 7: Timeout/reject `dead_letter` outcome lives on `approval_request` itself, not a new `event_message` row
**Why:** `dead-letter-replay`'s existing capability is scoped to CloudEvent
delivery retries (`event_message` schema, `pending/delivered/failed/
abandoned/discarded` states) — a different domain. Synthesizing a fake
`event_message` for a suspended rule-pipeline run would require inventing
CloudEvent fields (`type`, `sink`, delivery `attempts[]`) that don't apply
here. Setting `approval_request.status = 'dead_letter'` keeps the concept
self-contained and reuses the same list/detail/audit UX pattern
(list-with-filters, inspect, replay-shaped-but-not-replayed-in-v1) that
`dead-letter-replay` already established as this app's idiom for "this
needs a human to look at it later," without coupling the two domains.
**Alternative considered:** none of the above — see proposal.md Open
Questions for the deferred replay question.

## Migration Plan

Purely additive: new schema (register fragment), new controller/service/cron
files, new frontend views, new routes, two new `actions.seed.json` entries.
No existing table/schema is modified. See `migration.md` for the step-by-step
deployment sequence and rollback (mirrors proposal.md's Rollback Strategy).

## Open Questions

Carried from proposal.md:
- Dead-lettered `approval_request` replay — deferred.
- Whether `approval.approve`/`approval.reject` ever need a richer matrix
  than the two-layer model here — deferred, flag if it surfaces in real
  deployments.
