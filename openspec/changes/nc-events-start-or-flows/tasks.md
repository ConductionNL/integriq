# Tasks: nc-events-start-or-flows

Gate: `integriq-flow-nodes` must be landed first, so a triggered flow has
call/synchronization nodes worth running. `nextcloud-event-hub-verification`
must land first too: its Playwright spec file is the one task 4 extends.

## 1. Schema

### Task 1: `flow` action kind on `event_subscription`
- **spec_ref**: `openspec/changes/nc-events-start-or-flows/specs/nextcloud-event-triggers/spec.md`
- **files**: `lib/Settings/register.d/` (event_subscription fragment)
- **acceptance_criteria**:
  - GIVEN the merged register THEN `action.kind` accepts `flow` and `action.flowId` (string) exists; existing kinds and required fields are untouched
- [x] Implement
- [x] Test

## 2. Dispatch

### Task 2: `flow` arm in EventService's action dispatch
- **spec_ref**: `openspec/changes/nc-events-start-or-flows/specs/nextcloud-event-triggers/spec.md`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN a matched subscription with `kind: flow` THEN OpenRegister's flow-run entrypoint is called with the CloudEvent envelope as input (duck-typed resolution, ADR-022)
  - GIVEN the entrypoint throws THEN the existing delivery failure/retry path records it, mirroring the webhook kind
- [x] Implement
- [x] Test

## 3. UI

### Task 3: "Flow" in the action-type picker
- **spec_ref**: `openspec/changes/nc-events-start-or-flows/specs/nextcloud-event-triggers/spec.md`
- **files**: subscription modal component under `src/modals/`
- **acceptance_criteria**:
  - GIVEN the modal WHEN "Flow" is chosen THEN an OR flow picker (NcSelect with `inputLabel`) renders and the saved subscription carries the chosen `flowId`
- [ ] Implement
- [ ] Test

### Task 4: Playwright coverage
- **spec_ref**: `openspec/changes/nc-events-start-or-flows/specs/nextcloud-event-triggers/spec.md`
- **files**: `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts`
- **acceptance_criteria**:
  - GIVEN the spec runs THEN choosing "Flow", picking a flow and saving round-trips, traced to the picker scenario
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] All tests pass (`composer test`, Playwright suite)

## What was already here, and what the enum was doing to it

Task 2 was already implemented before this branch. `EventService`'s dispatch
switch has had a `flow` arm calling `FlowRunnerService` for some time, with a
unit test in `EventServiceTest` asserting it runs the flow and reads the
resulting `flow_run` status. `mapping` likewise.

Neither could be reached. `event_subscription.action.kind` still listed four
values, `webhook`, `synchronization`, `job` and `notificaties`, so a
subscription naming `flow` could not be saved, and `action.flowId` did not
exist as a property at all. The dispatch arm was not unused, it was
unreachable, and from the outside those look the same.

This branch opens the enum, adds `flowId` and `mappingId`, and adds the test
that compares the two lists: every kind the switch handles must be savable,
and every kind the register offers must have somewhere to go. Both lists are
read from their own files rather than hard-coded, so a future arm added
without registering it fails here.

Task 3, the picker, stays open, and the gap is wider than this change. The
modal offers three kinds. It has never offered `notificaties` either, and a
flow picker needs a list of flows the modal cannot currently fetch. That is
a UI change of its own rather than a line in this one.

Task 4's Playwright spec waits on `nextcloud-event-hub-verification`, which
is the file it extends and which has not been built.
