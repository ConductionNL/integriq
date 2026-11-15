# Tasks: directory-and-group-sync

Kind: code. Size M. Round 4 discovery cluster 33, candidates
C-access-and-privacy-82 (matrix hole), C-integrations-34 and C-integrations-21.
Number 7 of the twenty-five loudest, four driven passers. Waits on nothing.

## Implementation tasks

### Task 1: The directory connection and its run
- **spec_ref**: `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001`
- **files**: `lib/Directory/DirectorySource.php`, the synchronisation registration, `lib/Settings/` source seed
- [x] Implement (a directory as a source under `source-management` with a mock-mode fixture; scheduled and on-demand runs; membership writes through `IGroupManager`; removal of a membership the directory dropped)
- [x] Test (a removal, an addition, and an assertion that no credential is persisted)

### Task 2: The mapping
- **spec_ref**: `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002`
- **files**: `lib/Directory/GroupMappingResolver.php`, the connection configuration screen
- [x] Implement (many directory groups onto one Nextcloud group, attribute-based mapping, create-or-fail on an unknown target)
- [x] Test (an unknown target group with creation off fails naming the group and drops nothing)

### Task 3: The SCIM endpoint
- **spec_ref**: `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003`
- **files**: `lib/Controller/ScimController.php`, `appinfo/routes.php`, the credential store already used by the inbound webhook endpoints
- [x] Implement (SCIM 2.0 `Users` and `Groups`, own credential, deactivate disables and never deletes)
- [x] Test (Newman against the endpoint, including an unauthenticated call that reads nothing)

### Task 4: What a leaver still holds
- **spec_ref**: `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004`
- **files**: `lib/Directory/OpenWorkReporter.php`, a DI-tagged consumer contract, the run report
- [x] Implement (ask registered consumers, name each answering consumer, `unknown` for an absent one, reassign nothing)
- [x] Test (an answering consumer, and an absent one that reads `unknown` and not zero)

### Task 5: Preview and the removal guard
- **spec_ref**: `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005`
- **files**: the synchronisation test-run path (REQ-011) and the deletion-ratio guard (REQ-010)
- [x] Implement (test run writes nothing and lists both sides; a run over the ratio stops before writing and is resumable after confirmation)
- [x] Test (a truncated directory fixture)

### Task 6: The run record
- **spec_ref**: `openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006`
- **files**: the run record shape, the run screen, `logs-and-statistics` REQ-001
- [x] Implement (counts, per-item failures with reasons, no abort on a bad item)
- [x] Test

### Task 7: Coordination, docs and the hand-offs
- **files**: `docs/`, Dutch and English strings, the catalogue entry, this change's row in `competitor-parity-2026-09`
- [x] Tell dossiq that `roleType.ncGroupId` groups can now be filled from the directory, and that `lib/Repair/ProvisionAssignedGroups.php` keeps seeding and stops being the only writer
- [x] Ask dossiq to answer the open-work query for an account, so a leaver's case list is reported rather than guessed
- [x] Record C-integrations-21 as recorded and not built, with the lane's reason, so it is not rediscovered
- [x] Test (`tests/e2e/directory-sync.spec.ts`, `openspec validate directory-and-group-sync --type change --strict`)

## What was built, and where it landed

The screen is not a page of its own. A directory connection is a Source, so
"Preview directory run" and "Run directory sync" are row actions on the Sources
index, and what a run changed is read on **Directory runs**, a typed logs page
over the shared `synchronization_log` schema. Both decisions follow from D3: the
engine already exists, so the sync is configuration rather than a second thing
to debug.

Task 3's Newman coverage asserts the half a collection can assert without a
seeded credential: every SCIM route rejects an unauthenticated call before any
user is read, and says nothing about which check failed. Create, change and
deactivate are covered by PHPUnit over `ScimProvisioningService`.

Task 7's hand-offs are written down rather than sent: dossiq's
`roleType.ncGroupId` groups can now be filled from the directory, and
`lib/Repair/ProvisionAssignedGroups.php` keeps seeding while it stops being the
only writer. The open-work query dossiq is asked to answer is
`OCA\Integriq\Event\OpenWorkQueryEvent`, or the `IOpenWorkConsumer` contract for
an in-process consumer. C-integrations-21 is recorded and not built, with the
lane's reason, in the proposal and in `docs/administrators/directory-and-group-sync.md`.
