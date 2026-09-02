# nextcloud-event-triggers Specification (delta)

## ADDED Requirements

### Requirement: A matched subscription can start an OpenRegister flow

`event_subscription.action.kind` MUST accept `flow`, with `action.flowId`
naming an OpenRegister flow. On match, Integriq MUST start that flow through
OpenRegister's flow-run entrypoint with the CloudEvent envelope as the run
input, and MUST record a start failure through the existing delivery
failure/retry path.

@e2e exclude backend dispatch into OpenRegister's flow engine — covered by
PHPUnit on the dispatch arm plus the flow engine's own run coverage; the only
browser surface is the picker below.

#### Scenario: A file event starts a flow
- GIVEN a subscription for `com.nextcloud.files.node.created` with `action: {kind: "flow", flowId: F}`
- WHEN a matching CloudEvent is processed
- THEN flow F is started with the event envelope as input

#### Scenario: A failed start dead-letters like any delivery
- GIVEN the flow-run entrypoint throws
- WHEN the subscription fires
- THEN a delivery failure is recorded and retried per the subscription's retry policy

### Requirement: The subscription modal offers the flow action kind

The action-type picker MUST offer `flow` alongside synchronization, job and
webhook, with an OpenRegister flow picker for `flowId`.

@e2e exclude picker lands with the implementation change; the Playwright spec
extends `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts` authored in
`nextcloud-event-hub-verification`, which MUST land first.

#### Scenario: Choosing flow persists the target
- GIVEN the subscription modal
- WHEN "Flow" is chosen and a flow is picked
- THEN the saved subscription carries `action: {kind: "flow", flowId}`
