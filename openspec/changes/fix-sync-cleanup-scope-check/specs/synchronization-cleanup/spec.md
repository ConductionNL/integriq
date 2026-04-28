## ADDED Requirements

### Requirement: Cleanup deletes objects that disappeared from the source

After an extern-to-intern synchronization processes the full source list, the cleanup pass SHALL delete every OpenRegister object whose synchronization contract was not matched against an item in this run's source list, provided the object exists in the synchronization's current `register/schema` scope.

#### Scenario: Object missing from source is deleted on next sync

- **WHEN** a synchronization has previously created a contract for an object with a populated `target_id` pointing to an OpenRegister object in the synchronization's current `register/schema`
- **AND** a new sync run completes and the object's `originId` is not present in the source list
- **THEN** the cleanup pass SHALL invoke `ObjectService::deleteObject` for that `target_id`
- **AND** the synchronization contract's `target_id` SHALL be set to `null`
- **AND** the contract's `targetLastAction` SHALL be set to `'delete'`
- **AND** the sync log's `result.objects.deleted` counter SHALL be incremented

#### Scenario: Object still in source is preserved

- **WHEN** a sync run completes and the object's `originId` is present in the source list
- **THEN** the cleanup pass SHALL NOT delete the corresponding object
- **AND** the contract's `target_id` SHALL remain unchanged

### Requirement: Cleanup respects the synchronization's register/schema scope

The cleanup pass SHALL only delete objects that exist in the synchronization's current `register/schema` scope. Contracts whose `target_id` resolves to an object in a different scope, or to no object at all, SHALL be left untouched.

#### Scenario: Contract targeting an object in a different schema is not deleted

- **WHEN** the cleanup pass evaluates a candidate contract whose `target_id` is a UUID
- **AND** `ObjectService::find($targetId, register: $registerId, schema: $schemaId)` returns `null` because the object lives in a different `register/schema`
- **THEN** the cleanup pass SHALL NOT call `ObjectService::deleteObject` for that `target_id`
- **AND** the contract SHALL remain unchanged
- **AND** no entry SHALL be added to `result.objects.deleted` for that contract

#### Scenario: Contract targeting a non-existent UUID is not deleted

- **WHEN** the cleanup pass evaluates a candidate contract whose `target_id` does not match any object in any `register/schema`
- **THEN** the cleanup pass SHALL NOT call `ObjectService::deleteObject` for that `target_id`
- **AND** the contract SHALL remain unchanged

### Requirement: Cleanup uses the public ObjectService API only

The synchronization cleanup pass SHALL NOT issue direct SQL queries against OpenRegister's storage tables (`openregister_objects` or any per-register/schema "magic table" such as `oc_or_{registerId}_{schemaId}`). All object existence checks and deletions SHALL go through `ObjectService`.

#### Scenario: No direct queries against openregister_objects

- **WHEN** the cleanup pass runs
- **THEN** the only OpenRegister interactions SHALL be calls to `ObjectService::find` (for scope-checked existence) and the existing `updateTarget('delete')` path (which uses `ObjectService::deleteObject`)
- **AND** the contract mapper's candidate query SHALL filter only by `synchronization_id`, with no JOIN to OpenRegister tables

### Requirement: Cleanup logs partial failures instead of swallowing them

When `findOnTarget` for a candidate contract throws `DoesNotExistException` during the cleanup loop, the cleanup pass SHALL emit a warning log entry with the synchronization id, target id, and exception message, and SHALL continue processing remaining candidates.

#### Scenario: Contract not found on second lookup is logged

- **WHEN** the cleanup pass identifies a candidate `target_id` for deletion
- **AND** the subsequent `findOnTarget` lookup throws `DoesNotExistException` (e.g. concurrent deletion by another process)
- **THEN** the cleanup pass SHALL log a warning that includes the synchronization id, the candidate target id, and the exception message
- **AND** the cleanup pass SHALL continue to the next candidate without aborting
