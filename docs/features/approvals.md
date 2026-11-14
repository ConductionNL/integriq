# Approvals (human in the loop)

## Overview

Some integration traffic should not go out until a person has looked at it:
publishing a decision, writing a batch of changes into a register, sending a
message to an outside party. Integriq can pause such a run, ask a group of
people to approve it, and continue exactly where it stopped once someone does.

There are two places a run can wait for approval:

- an **endpoint** whose rule chain contains an `approval` rule, and
- a **synchronization** whose source configuration sets `requiresApproval`.

Both create an *approval request*. Approvers find their requests on the
**Approvals** page, approve or reject them with a comment, and the decision
is recorded on the request.

## The `approval` rule action

An `approval` rule is a rule like any other on an endpoint, with type
`approval`. When the endpoint is called and the rule's conditions pass, the
rule chain stops at that rule:

1. Integriq stores an approval request with the state of the call so far. The
   request headers that carry credentials (`Authorization`,
   `Proxy-Authorization`, `Cookie`, `X-Api-Key`) are stored redacted.
2. The caller gets **`202 Accepted`** with the request's id and a URL to poll:

   ```json
   {
     "status": "pending_approval",
     "approvalRequestId": "…",
     "statusUrl": "https://…/apps/integriq/api/approvals/…"
   }
   ```

3. The members of the approver group are notified.

Rules ordered before the approval rule have already run; the rules after it,
and the call to the endpoint's target, have not. When the request is
approved, the chain continues with the first rule ordered **after** the
approval rule, and then calls the target as an unpaused call would. The
approver's request answers with the target's own answer.

An `approval` rule only works with timing `before`: it gates a write before
it happens. An approval rule in the `after` phase fails the run.

### Rule configuration

In the rule editor, choose **approval** as the action type. The form asks for:

| Field | Meaning | Default |
|-------|---------|---------|
| Approver group | The Nextcloud group whose members may decide | none |
| Time to live (seconds) | How long the request can wait | 86400 (one day) |
| On reject | What a rejection does | Return an error to the caller |
| On timeout | What an expired request does | Return an error to the caller |

The outcomes are: return an error to the caller (`error`), skip, resolving
without writing (`skip`), or dead-letter for later review (`dead_letter`).

Stored on the rule, this is:

```json
{
  "type": "approval",
  "timing": "before",
  "order": 10,
  "configuration": {
    "approval": {
      "approverGroup": "publication-approvers",
      "ttlSeconds": 86400,
      "onReject": "dead_letter",
      "onTimeout": "error"
    }
  }
}
```

A request that is not decided within its time to live expires: a background
job marks it `expired`, or `dead_letter` when the rule's **On timeout** says
so.

## The synchronization approval gate

A synchronization whose source configuration sets `requiresApproval: true`
does not write on its own. When a (non-test) run finds work to do, it:

- computes the change set: what an approval would create, change and remove;
- stores an approval request carrying that change set, and pauses with the
  log message `pending_approval`;
- writes nothing; every object found counts as skipped in the run's log.

The approver sees the change set on the request ("What an approve would
write") and approves the run, which then writes exactly once. If the source
has changed by the time the run comes back, so that the change set is no
longer what was approved, the old request is marked superseded and a new
one is made (`approval_superseded`): an approval only ever covers the
changes the approver saw.

The approver group and outcomes come from `sourceConfig.approval`, with the
same keys as the rule configuration above (`approverGroup`, `ttlSeconds`,
`onReject`, `onTimeout`).

## The Approvals page

**Approvals** in the navigation lists the requests you may act on: those for
a group you are in, and those you started yourself. Administrators see all of
them. The list opens on **pending** requests and can be filtered on
`pending`, `approved`, `rejected`, `expired` and `dead_letter`.

Opening a request shows who started it, the approver group, when it was
created and when it expires, what a rejection and a timeout will do, and
what the paused call or run would write. Approvers can:

- **Approve**, optionally with a comment. The run continues straight away.
- **Reject**, with a comment, which is required. The rule's **On reject**
  outcome applies; with `dead_letter` the request is kept as `dead_letter`.

Once decided, the page shows the decision, who made it, the comment, and the
result of resuming the run.

## Who may decide

Deciding takes two things: the approval permission in Integriq's action
matrix, and membership of *this* request's approver group (administrators
always qualify). Everyone else is refused with `403`, also when they can
otherwise see the request because they started it.

A request is decided once. Approving or rejecting a request that is no longer
pending, or has expired, is refused with `409`.

## API

| Method | Path | Answer |
|--------|------|--------|
| `GET` | `/api/approvals?status=pending` | The requests you may act on |
| `GET` | `/api/approvals/{id}` | One request, with its decision and change set |
| `POST` | `/api/approvals/{id}/approve` | Body `{"comment": "…"}` (optional); answers with the resumed run's result |
| `POST` | `/api/approvals/{id}/reject` | Body `{"comment": "…"}` (required) |

Refusals: `401` without a session, `403` for someone who may not decide,
`404` for an unknown id, `409` for a request that is no longer pending, and
`400` for a rejection without a comment.
