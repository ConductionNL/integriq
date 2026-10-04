# Tasks: synchronisation-source-destruction-purge

Kind: code. Size S. Row `opencatalogi:lc-source-destroyed`.

### Task 1: The purge policy and the permanent delete
- **spec_ref**: openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-synchronization-can-purge-a-vanished-record-and-its-files-req-sdp-001
- **files**: `lib/Service/Ownership/DisappearancePolicy.php`, `lib/Service/SynchronizationService.php` (policy branch, `updateTargetOpenRegister()` purge action)
- **acceptance_criteria**:
  - GIVEN policy `purge` and a vanished record on a complete run WHEN the run finishes THEN `deleteObject()` is called with `permanent: true` and the object's files are gone
  - GIVEN policy `purge` in incremental mode or on an incomplete fetch WHEN the run finishes THEN nothing is purged
- [x] Implement
  - `DisappearancePolicy::PURGE` is accepted. In `deleteInvalidObjects()` a vanished record under `purge` goes to `purgeTarget()`, behind the same incremental, completeness and ratio guards as a delete. `updateTargetOpenRegister()` has a `purge` action that calls OpenRegister's `deleteObject(permanent: true)` inside the source-owned delete guard, and sets `targetId: null`, `targetLastAction: purge`. The run result carries `objects.purged` and `objects.purgeRefusals`.
- [ ] Test (integration test against OpenRegister checking the Nextcloud folder is removed)
  - Done: `tests/Unit/Service/SynchronizationServicePurgeTest.php` through the real `deleteInvalidObjects()`, `updateTarget()` and `synchronize()`: the vanished record is deleted with `permanent: true`, a full run on a complete empty page reports two purged, an incomplete fetch, incremental mode and the ratio guard purge nothing.
  - Owed: the integration test against a live OpenRegister that the object's Nextcloud folder is gone.

### Task 2: The destruction notice paths
- **spec_ref**: openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
- **files**: `lib/Service/SourceDestructionService.php`, `lib/Service/NotificatiesSubscriberService.php`, `lib/Controller/SynchronizationsController.php` (`destroyed()`), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a ZGW `destroy` notification for a synchronized document WHEN it arrives THEN only that object is purged
  - GIVEN `onSourceDestroyed` absent WHEN a notice arrives THEN the disappearance policy is applied to that one object
  - GIVEN a bad signature on the destroyed route WHEN it arrives THEN it is refused before the body is read
- [ ] Implement
- [ ] Test (PHPUnit on the service and controller; `tests/e2e/source-destruction-purge.spec.ts`)

### Task 3: The purge record and the refusal
- **spec_ref**: openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-every-purge-is-recorded-and-a-refused-purge-stays-visible-req-sdp-003
- **files**: `lib/Service/SynchronizationService.php`, the contract log writer
- **acceptance_criteria**:
  - GIVEN a purge WHEN it completes THEN a contract log entry names the source record, the object id and the trigger, and the run counts it as purged
  - GIVEN a `ReferentialIntegrityException` WHEN the purge runs THEN the object is untouched and the refusal is in the run failures
- [x] Implement
  - `purgeTarget()` writes a contract log entry per purge (`targetResult: purged`, `source.originId`, `source.trigger` `fullRun` or `destructionNotice`, `source.reference`, `target.id`) and persists the contract with `targetLastAction: purge`. A refusal from OpenRegister leaves the object and the contract as they were, never falls back to a soft delete, is written to the contract log as `purge_refused` and is listed in `objects.purgeRefusals` with OpenRegister's reason.
- [ ] Test (integration test with a restricting relation)
  - Done: the same test throws OpenRegister's real `ReferentialIntegrityException` (copied with its `DeletionAnalysis` into `tests/stubs/`) and checks the refusal is listed with its reason, nothing is deleted and the contract does not claim a purge.
  - Owed: the integration test with a restricting relation on a live OpenRegister.

### Task 4: The edit form and demo data
- **spec_ref**: openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-synchronization-can-purge-a-vanished-record-and-its-files-req-sdp-001
- **files**: `src/modals/v2/SynchronizationEditorModal.vue`, `lib/Settings/integriq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the synchronization editor WHEN an administrator picks `purge` THEN the form states that purged files cannot be restored
  - GIVEN demo data WHEN the demo synchronization runs THEN the run shows a purged count
- [ ] Implement
- [ ] Test (`tests/e2e/source-destruction-purge.spec.ts`)

## Verification

- `openspec validate synchronisation-source-destruction-purge --type change --strict`
- On a local instance with OpenCatalogi: synchronize one publication with an
  attachment, send a `destroy` notification for it, and confirm the object
  and its folder are gone and the contract log names it.
- `composer check:strict` and `npm run lint` once before push.
