# Test Plan: rostering-adapter-targets-planninq

## Test Cases

### TC-1: Four presets load; an unknown one is refused
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-one-mapping-preset-per-rostering-source-req-001`
- **type**: regression
- **preconditions**: the seed file
- **steps**: load the registry; ask for each id and for `roster-unknown`
- **expected result**: four presets with the four required fields; an exception for the unknown id
- **test command**: `vendor/bin/phpunit --filter RosterMappingPresetRegistryTest`

### TC-2: Mapper transforms and maps
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002`
- **type**: regression
- **preconditions**: the Zermelo and Untis presets, group and teacher maps
- **steps**: map a Zermelo appointment and an Untis period
- **expected result**: ISO 8601 times, first-element text, codes and ids side by side, cancelled status
- **test command**: `vendor/bin/phpunit --filter RosterMappingPresetRegistryTest`

### TC-3: Every mock source maps to planninq sessions
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002`
- **type**: regression
- **preconditions**: the mock client and the fixture
- **steps**: import lessons for each source
- **expected result**: required planninq fields present; no `startTime`/`endTime`
- **test command**: `vendor/bin/phpunit --filter RosterImportSourceAdapterTest`

### TC-4: Target configuration merges stored and delivered maps
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-a-per-source-target-configuration-links-school-codes-to-fleet-ids-req-003`
- **type**: regression
- **preconditions**: app config returning stored maps, one unreadable
- **steps**: resolve the configuration with and without overrides
- **expected result**: delivery entries win; an unreadable map is empty
- **test command**: `vendor/bin/phpunit --filter RosterTargetConfigurationTest`

### TC-5: Planninq target dispatches and fails closed
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-delivery-goes-to-planninq-through-planninqs-typed-event-req-004`
- **type**: api
- **preconditions**: a verbatim copy of planninq's event class; a dispatcher double that answers or stays silent
- **steps**: deliver with a listener, without one, and with the class name pointing nowhere
- **expected result**: planninq's result; `planninq-absent` twice
- **test command**: `vendor/bin/phpunit --filter PlanninqTimetableTargetTest`

### TC-6: Learniq's event is always answered
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005`
- **type**: api
- **preconditions**: the real delivery service over the mock client and a planninq double
- **steps**: handle events for a known source, an unknown source, and with planninq absent or refusing
- **expected result**: `delivered` with counts; `failed` with the matching `errorCode`
- **test command**: `vendor/bin/phpunit --filter RosterDeliveryServiceTest`

### TC-7: The client binding resolves
- **spec_ref**: `openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-adapter-can-be-constructed-on-an-instance-req-006`
- **type**: regression
- **preconditions**: `Application` source
- **steps**: assert the registration names the mock
- **expected result**: `RosterImportClient` is bound to `RosterImportClientMock`
- **test command**: `vendor/bin/phpunit --filter RosterDeliveryServiceTest`

## Coverage Summary
REQ-001 TC-1; REQ-002 TC-2, TC-3; REQ-003 TC-4; REQ-004 TC-5; REQ-005 TC-6; REQ-006 TC-7.

## Out of Scope
A live run on an instance with planninq and learniq installed: the lane must not deploy to the shared instance. The planninq side of the event is covered by planninq #685's listener tests on the same class.
