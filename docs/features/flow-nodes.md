# Flow nodes

## Overview

OpenRegister runs the fleet's one flow engine. Integriq does not run its own graphs. It contributes step types, so a flow can do what Integriq is good at: call an API, run a synchronization, apply a mapping, ask a person, emit an event.

You build the flow in OpenRegister's flow editor. The Integriq steps appear in the palette when both apps are enabled.

| Node | What the step does |
|------|--------------------|
| `openconnector.source-call` | Make one governed API call per item through a configured Source |
| `openconnector.synchronization-run` | Run a configured Synchronization and hand each synchronised object onward |
| `openconnector.source-paginate` | Fetch one page of objects from a Source |
| `openconnector.apply-mapping` | Apply a configured Mapping to every item |
| `openconnector.contract` / `contract-commit` / `contract-sweep` | The decomposed synchronization's contract steps |
| `openconnector.fetch-file` | Fetch a file referenced by an item |
| `openconnector.approval-request` | Pause the run until someone approves or rejects |
| `openconnector.event-emit` | Emit a CloudEvent for every item |

## Call an API from a flow

Add a `source-call` step. Pick a Source, give it a path and a method:

```json
{
  "id": "step-apply-label",
  "type": "openconnector.source-call",
  "config": {
    "source": "demo-forge-api",
    "endpoint": "/issues/{{issue.number}}/labels",
    "method": "POST",
    "body": { "labels": ["{{triage.proposedLabel}}"] },
    "output": "labelResult"
  }
}
```

The step runs once per item. `{{dotted.path}}` placeholders resolve from each item's record, and the response lands under the key you name in `output`. The call goes through `CallService`, so the Source's enablement, host guard, rate limits and call logging all apply unchanged.

## Why there is no raw-URL node

You cannot type a URL into a flow step. The step names a Source, and the endpoint is a path inside that Source's location. An absolute URL, a `//host` path or a `../` escape is rejected before any request goes out.

This is the whole security model, not a missing convenience. A Source is where an administrator decides which hosts may be called, how often, and with which credential. A URL field in a flow document would hand that decision to every flow author and turn the editor into a request forger. If a host is worth calling, give it a Source first.

Credentials follow the same line. A step has no token field. Authentication comes from the Source's `credentialRef`, resolved by the credential broker at call time. No secret ever sits in a flow document.

## Why an unattributed run fails closed

Every call runs as the flow run's owner, read from the run context. When no owner resolves, the step refuses and raises. There is no fallback to an admin, to the Source's creator, or to nobody.

An anonymous authenticated outbound call is the failure we refuse to ship. A loud error names the gap; a silent fallback hides it behind someone else's identity.

## Ask a person: the approval step

`openconnector.approval-request` parks the run and creates a pending approval request. The approvers see it on the Pending approvals page and in their shared task list, like every other Integriq approval.

```json
{
  "id": "approve-publish",
  "type": "openconnector.approval-request",
  "config": {
    "question": "Publish this dataset?",
    "approverGroup": "data-stewards",
    "ttlSeconds": 86400
  }
}
```

- **Approved.** The run resumes. The decision, the approver and the comment land on every item under `approval`, so a later step can route on them.
- **Rejected.** By default the run continues and your reject edge reads `approval.decision`. Set `failOnReject: true` when a no should fail the run.
- **Expired.** The run fails. An approval nobody answered never counts as answered.

An answer wakes the run immediately. If that wake-up is ever lost, the step re-checks the approval request itself on its next heartbeat, so a decision is never stranded.

## Emit an event

`openconnector.event-emit` sends one CloudEvent per item through the existing event pipeline. Name a `type` and a `source`, and subscriptions pick it up exactly as they would for any other Integriq event.

## Migrate old step-list flows

Flows built in Integriq's earlier step-list editor still exist as ordered `steps[]`. One command translates them onto the engine's graph shape:

```bash
occ integriq:flow:steps-to-graph            # dry run: reports what would happen
occ integriq:flow:steps-to-graph --apply    # writes nodes/edges onto each flow
```

The migration is additive and repeatable. `steps` stays on the object, a flow that already carries `nodes` is skipped, and a flow the translator cannot express faithfully is refused with the reasons listed. The same pass also runs automatically on upgrade.

Changed your mind? Roll it back:

```bash
occ integriq:flow:steps-to-graph --rollback --apply
```

## Next steps

Create a [Source](sources.md) for the API you want to call, then open OpenRegister's flow editor and add a `source-call` step against it.
