# Proposal: automation-endpoint-flow-trigger

## Summary

An outside system calling an integriq endpoint can already start a flow: an endpoint rule of type `flow` runs `FlowRunnerService::run()`. But the rule editor does not offer that type, so only a rule seeded from an imported configuration can carry it, and no administrator can set one up. This change offers the flow action in the rule editor, with a picker of the instance's flows.

## Why

Matrix row `integriq:auto-webhook-trigger`, "Start a flow when an outside system calls a webhook", rated `partial`, state `building`. Decided `build` (29 Sep 2026): three competitors rate it `yes`.

Competitor cells from the matrix:

- n8n `yes`: "packages/nodes-base/nodes/Webhook/Webhook.node.ts:135 path and :97 httpMethod register an inbound URL that starts the workflow".
- mulesoft `yes`: "the HTTP Listener source starts a flow on each incoming request".
- frank `yes` (see the matrix row).

## What integriq already has

- `EndpointService::processFlowRule()` (around :2805) reads `configuration.flow`, finds the flow, and runs it with the request as input.
- `src/views/Rule/ruleDraft.js` keeps `flow` out of `ACTION_TYPES` on purpose: "`flow` also [has a] match arm but no authoring UI, so [it is] deliberately not offered".
- The subscription modal already has a flow picker (`SubscriptionActionFields.vue`, `nc-events-start-or-flows`), which reads `/apps/openregister/api/objects/integriq/flow`.

## What this change builds

`flow` joins `ACTION_TYPES`, and the rule editor shows a labelled flow picker for it that writes `configuration.flow`. Saving a flow rule without a flow is refused in the editor, as the runtime would refuse it.
