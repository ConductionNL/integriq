# Tasks: rostering-adapter-targets-planninq

## Implementation Tasks

### Task 1: Roster mapping presets, registry and mapper (V1)
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-one-mapping-preset-per-rostering-source-req-001`
- **files**: `lib/roster-mapping-presets.seed.json`, `lib/Sources/Roster/RosterMappingPresetRegistry.php`, `lib/Sources/Roster/RosterSessionMapper.php`, tests
- **acceptance_criteria**:
  - GIVEN the seed WHEN loaded THEN four presets exist, each naming the required planninq fields
  - GIVEN a vendor record WHEN mapped THEN it is a planninq session with codes and ids apart
- [x] Implement
- [x] Test

### Task 2: Target configuration and vendor-shaped mock (V1)
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-a-per-source-target-configuration-links-school-codes-to-fleet-ids-req-003`
- **files**: `lib/Sources/Roster/RosterTargetConfiguration.php`, `lib/Adapters/Roster/RosterImportClient.php`, `lib/Adapters/Roster/RosterImportClientMock.php`, `lib/Sources/Roster/RosterImportSourceAdapter.php`, `tests/fixtures/roster/fixture-roster-batch.json`, tests
- **acceptance_criteria**:
  - GIVEN stored and delivered maps WHEN resolved THEN the delivery wins
  - GIVEN each source WHEN imported THEN planninq sessions come out
- [x] Implement
- [x] Test

### Task 3: Planninq target and delivery service (V1)
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-delivery-goes-to-planninq-through-planninqs-typed-event-req-004`
- **files**: `lib/Sources/Roster/PlanninqTimetableTarget.php`, `lib/Sources/Roster/RosterDeliveryService.php`, `lib/Sources/Roster/RosterDeliveryException.php`, `tests/stubs/planninq/TimetableUpsertRequestedEvent.php`, tests
- **acceptance_criteria**:
  - GIVEN planninq answers WHEN delivering THEN its result is returned
  - GIVEN planninq is absent WHEN delivering THEN it fails closed with `planninq-absent`
- [x] Implement
- [x] Test

### Task 4: RosterImportRequestedEvent, listener and DI binding (V1)
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005`
- **files**: `lib/Event/RosterImportRequestedEvent.php`, `lib/EventListener/RosterImportRequestedListener.php`, `lib/AppInfo/Application.php`, tests
- **acceptance_criteria**:
  - GIVEN learniq's event WHEN handled THEN it is always answered with `delivered` or `failed` plus a code
  - GIVEN the container WHEN `RosterImportClient` is resolved THEN the mock is returned
- [x] Implement
- [x] Test

### Task 5: Docs (V1)
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-a-per-source-target-configuration-links-school-codes-to-fleet-ids-req-003`
- **files**: `docs/features/rostering-to-planninq.md`
- **acceptance_criteria**:
  - GIVEN an operator WHEN they read the page THEN they can set the group and teacher maps and know what a dormant delivery sends
- [x] Implement

## Verification
- [x] All tasks checked off
- [x] `openspec validate rostering-adapter-targets-planninq` passes

## Quality checklist

- New services covered by PHPUnit unit tests.
- No REST endpoints, so no Newman collection.
- No UI, so no Playwright test and no new interface strings.
