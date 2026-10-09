---
kind: code
depends_on: [integriq-flow-nodes]
---

# Proposal: nc-events-start-or-flows

## Summary

Let a matched event subscription start an OpenRegister flow run. Integriq's
event hub already normalizes Nextcloud core events (files, calendar, Tables,
Forms) into CloudEvents and lets a subscription drive a synchronization, a
job or a signed webhook. The one engine those events cannot reach is the one
the fleet standardized on: an OpenRegister flow. This change adds `flow` as a
fourth subscription action kind (`event_subscription.action = {kind: "flow",
flowId}`): on match, Integriq asks OpenRegister's `FlowRunService` to run the
named flow with the CloudEvent envelope as input. Nothing else: no scheduler,
no app-local orchestration, no new trigger machinery.

## Motivation

One-engine consolidation is retiring the surfaces the current action kinds
point at. `flow-native-synchronization` turns synchronizations into drawn OR
flows and marks Jobs/Rules as legacy; once that lands, "a file changed, so
run this synchronization" is really "a file changed, so run this flow" with
an extra hop through a deprecated surface. Wiring subscriptions to flows
directly means NC events reach the engine that owns automation, and the
legacy kinds can retire without stranding event-driven setups. The
alternative (an OR-side trigger listening for Integriq's CloudEvents) would
put NC-event knowledge into OR; the fleet boundary is the reverse — Integriq
owns the NC event surface and contributes to OR's engine.

## Affected Projects

- [x] Project: `integriq` — `event_subscription` schema (`action.kind` enum +
  `flowId`), `EventService` dispatch arm, subscription modal picker option,
  tests.

## Scope

### In Scope

1. `event_subscription.action.kind` gains `flow`; `action.flowId` names an
   OpenRegister flow. Additive schema change to the `event_subscription`
   fragment.
2. `EventService`'s action dispatch gains a `flow` arm that resolves
   OpenRegister's flow-run entrypoint (duck-typed, ADR-022 style, same as the
   other cross-app calls) and starts the flow with the CloudEvent envelope as
   the run input. Failure records a delivery failure through the existing
   retry/dead-letter path, exactly like the webhook kind.
3. The subscription modal's action-type picker gains "Flow" with an OR flow
   picker.
4. Self-service gating is unchanged: the per-family ADR-023 actions govern
   who may subscribe; the `flow` kind adds no new grant.

### Out of Scope

- Any OR-side change. If `FlowRunService` needs a formal "start with external
  input" seam, that is an openregister change this one then consumes.
- Retiring the `synchronization`/`job` kinds (follows
  `flow-native-synchronization`'s deprecation track, later change).
- Timer- or schedule-shaped triggering: OR's `TriggerScheduleNode` (with
  explicit `runAs`) owns that; an event subscription is never a scheduler.

## Approach

One additive enum value, one dispatch arm, one picker option. The dispatch
arm mirrors the existing `synchronization`/`job` arms' shape and error
handling. Sequenced after `integriq-flow-nodes` so a triggered flow has
call/sync nodes worth running.

## Impact

- `lib/Settings/register.d/` — `event_subscription` fragment: `action.kind`
  enum + `flowId`.
- `lib/Service/EventService.php` — one dispatch arm.
- `src/` subscription modal — picker option + flow picker.
- Unit tests for the dispatch arm; Playwright for the picker.

## Rollback Strategy

Additive on every surface. Revert the PR; subscriptions with `kind: flow`
become non-matching rows that the dispatch logs as unknown-kind failures,
and deleting them is a data cleanup, not a migration.

## Open Questions

- Which OR entrypoint is the stable one for "run this flow now with this
  input" — `FlowRunService` directly, or a queued start? Resolve against
  OpenRegister at HEAD when implementation starts; prefer whatever
  `flow-native-synchronization` already binds to.
