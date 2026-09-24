# Tasks: harden-scim-consumer-authorization

## Implementation Tasks

### Task 1: `authorize()` returns the resolved consumer instead of discarding it
- **spec_ref**: `openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-a-scim-call-is-answered-as-a-named-consumer-req-ds-007`
- **files**: `lib/Controller/ScimController.php`, `tests/Unit/Controller/ScimControllerTest.php`
- **acceptance_criteria**:
  - GIVEN a SCIM request presenting a registered consumer's API key WHEN `authorize()` runs THEN it returns that consumer, obtained from `AuthorizationService::getResolvedConsumer()`, and the nine route methods receive it
  - GIVEN a credential matching no consumer WHEN `authorize()` runs THEN it returns the existing undifferentiated 401 and the response body is byte-identical to the missing-credential body
  - GIVEN any outcome WHEN the call is logged THEN an accepted call names the resolved consumer and a refusal is logged as it is today
  - Do NOT change `AuthorizationService`; `getResolvedConsumer()` (`:922`) and the `$resolvedConsumer` assignment (`:840`) already exist and are consumed as-is
  - Follow the in-repo pattern at `NotificatiesSubscriberController:334`
- [x] Implement
- [x] Test

### Task 2: Refuse every SCIM membership write targeting the `admin` group
- **spec_ref**: `openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008`
- **files**: `lib/Directory/ScimProvisioningService.php`, `tests/Unit/Directory/ScimProvisioningServiceTest.php`
- **acceptance_criteria**:
  - GIVEN any caller WHEN `setGroupMembers()` is called with `$groupId === 'admin'` THEN it refuses before `IGroupManager::get()` is reached and before any member is added or removed
  - GIVEN an `admin` group with existing members WHEN a reconciling write names `admin` with an empty member list THEN every existing member remains — the refusal must precede the removal loop, not merely skip the add loop
  - The refusal raises `DirectorySyncRefusalException` naming the group, which `ScimController` maps to a SCIM-shaped 403; `false` continues to mean "group not found" and maps to 404, so the two outcomes stay distinguishable
  - `upsertUser()` needs NO refusal — verified during design that it writes displayName, email and active only, never membership (see the proposal's Risk 2 table)
  - The refusal is NOT configurable by appconfig, connection configuration or any caller-supplied value
  - The refusal is logged naming the consumer and the group; the response to the caller does not name the group or explain the rule
- [x] Implement
- [x] Test

### Task 3: Confine membership writes to the declared managed-group union
- **spec_ref**: `openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-only-the-groups-a-connection-declares-it-manages-req-ds-009`
- **files**: `lib/Directory/ScimProvisioningService.php`, `tests/Unit/Directory/ScimProvisioningServiceTest.php`
- **acceptance_criteria**:
  - GIVEN directory connections from `DirectorySource::findConnections()` WHEN a membership write arrives THEN the writable set is the union of `GroupMappingResolver::managedGroups()` across those connections, resolved once per write and never once per member
  - GIVEN a group outside that set WHEN a write names it THEN it is refused before any membership changes and the refusal names the group so an operator can extend the mapping
  - GIVEN no directory connection is configured WHEN any membership write arrives THEN it is refused and no group is created or modified — fail closed on an empty union
  - GIVEN a group inside the set WHEN a write names it THEN membership reconciles exactly as before this change
  - The `admin` refusal from Task 2 applies on top of this allow-list, so a mistakenly managed `admin` is still refused
- [x] Implement
- [x] Test

### Task 4: Amend REQ-DS-003 so the requirement covers authorisation
- **spec_ref**: `openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003`
- **files**: `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md`
- **acceptance_criteria**:
  - GIVEN the amended REQ-DS-003 WHEN it is read THEN it states that holding a valid credential is not by itself permission to write a group, and references REQ-DS-008 and REQ-DS-009
  - The two existing scenarios are preserved verbatim; the new scenario is added rather than replacing them
  - The `@spec` docblock references in `ScimController.php` and `ScimProvisioningService.php` still resolve after the edit
  - This task exists because the original requirement asked only for authentication — the implementation satisfied its spec, so patching code without amending the spec would leave the gap re-openable
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [ ] Manual testing against acceptance criteria — for the contributor, on a live instance; an agent's test run is not a substitute
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints — no endpoint is added or changed in shape; the two new 403s need a live instance and a real consumer key, and every spec scenario names PHPUnit as its cover
- [ ] Vitest tests for new/changed frontend logic — N/A, no frontend surface
- [ ] Playwright e2e for new/changed user journeys — N/A, a SCIM client cannot be staged in a browser; the spec scenarios carry `@e2e exclude` with that reason

## Notes

Regression tests that matter more than the happy path, and the reason each exists:

- A write naming `admin` with an **empty** member list must leave administrators in
  place. Asserting only the add path would pass against a refusal placed after the
  removal loop, which is the mistake this code invites.
- A **valid** consumer key writing an unmanaged group must be refused. A test using
  an invalid key proves nothing here — it would pass before this change.
- An instance with **no** directory connection must refuse every write. Without this
  the empty-union case can silently fail open.

Out of scope, and deliberately not tested here: per-consumer isolation. Any valid
consumer key still reaches SCIM after this change. That needs a permission property
on the `consumer` schema and is a separate change — see the proposal's Out of Scope.
