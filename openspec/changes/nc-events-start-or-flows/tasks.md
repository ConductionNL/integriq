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
- [ ] Implement
- [ ] Test

## 2. Dispatch

### Task 2: `flow` arm in EventService's action dispatch
- **spec_ref**: `openspec/changes/nc-events-start-or-flows/specs/nextcloud-event-triggers/spec.md`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN a matched subscription with `kind: flow` THEN OpenRegister's flow-run entrypoint is called with the CloudEvent envelope as input (duck-typed resolution, ADR-022)
  - GIVEN the entrypoint throws THEN the existing delivery failure/retry path records it, mirroring the webhook kind
- [ ] Implement
- [ ] Test

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
