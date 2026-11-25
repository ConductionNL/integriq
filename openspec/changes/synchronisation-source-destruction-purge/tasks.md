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
- [x] Test (integration test against OpenRegister checking the Nextcloud folder is removed)
  - Done: `tests/Unit/Service/SynchronizationServicePurgeTest.php` through the real `deleteInvalidObjects()`, `updateTarget()` and `synchronize()`: the vanished record is deleted with `permanent: true`, a full run on a complete empty page reports two purged, an incomplete fetch, incremental mode and the ratio guard purge nothing.
  - Live 25 Nov 2026 (sandbox: Nextcloud 34.0.6, MariaDB, Redis, OpenRegister main, integriq development): an object with an attached file (folder node 125, file 126 under `Open Registers/<register>/<uuid>/`) purged by a signed notice: the folder and file are gone from disk and from the file cache (`occ info:file 125` / `126`: not found), the object answers 404 and is not in the deleted view. A full run against a local feed of 12 records, then 11, reports `purged: 1` (deletion ratio 0.083), the vanished record's object is 404 and the 11 others stay; the contract log has `purged` with trigger `fullRun`.

### Task 2: The destruction notice paths
- **spec_ref**: openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
- **files**: `lib/Service/SourceDestructionService.php`, `lib/Service/NotificatiesSubscriberService.php`, `lib/Controller/SynchronizationsController.php` (`destroyed()`), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a ZGW `destroy` notification for a synchronized document WHEN it arrives THEN only that object is purged
  - GIVEN `onSourceDestroyed` absent WHEN a notice arrives THEN the disappearance policy is applied to that one object
  - GIVEN a bad signature on the destroyed route WHEN it arrives THEN it is refused before the body is read
- [x] Implement
  - `SourceDestructionService` takes both ways in. `handleZgwDestroyed()` finds the synchronizations of the abonnement's source and tries the resource URL, then its last path segment, as `originId`; `handleDestroyed()` takes one synchronization and the record's id. Both hand the record to `SynchronizationService::applySourceDestruction()`, which resolves the one contract of that synchronization (both fields checked on the row), purges when `sourceConfig.onSourceDestroyed` is `purge` (trigger `destructionNotice`, the notice's reference on the contract log), and otherwise applies the disappearance policy to that object alone (`delete`, `markEnded`, `keepAndFlag`, `purge`). No full run, and a record without a contract touches nothing (`no_contract`).
  - `NotificatiesSubscriberService::handleInboundNotification()` hands an `actie: destroy` to it after the CloudEvent and the ZGW pull, never throwing.
  - The signed route is `POST /api/synchronizations/{id}/destroyed` on its own `SourceDestroyedController` (`sourceDestroyed#destroyed`; on `SynchronizationsController` the constructor would pass phpmd's parameter limit). `#[PublicPage]`, verified against the source's `configuration.webhookSignature` over the raw body before the body is read; an unknown synchronization, a source without a secret and a bad signature all answer the same 401. The body carries `originId` (the route's `{id}` is the synchronization) and an optional `reference`.
- [x] Test (PHPUnit on the service and controller; `tests/e2e/source-destruction-purge.spec.ts`)
  - Done: `tests/Unit/Service/SourceDestructionServiceTest.php` through the real `SourceDestructionService` and `SynchronizationService` (only that object is purged, URL and uuid keyed contracts, the policy without `onSourceDestroyed`, keepAndFlag, no contract, another source's contract, unknown synchronization); `tests/Unit/Controller/SourceDestroyedControllerTest.php` with the real `WebhookSignatureService` (signed, forged, unsigned, unknown synchronization, missing `originId`); three tests in `NotificatiesSubscriberServiceTest` assert the hand-off from the caller.
  - Live 25 Nov 2026 (sandbox: Nextcloud 34.0.6, MariaDB, Redis, OpenRegister main, integriq development): `tests/e2e/source-destruction-purge.spec.ts` 4 passed, two runs. The live run found three app bugs, fixed here: (1) on the public route there is no user, so the contract search found nothing (`404 no_contract`); the notice's contract lookup is now a system read and the ZGW path lists the source's synchronizations unscoped, like the synchronization itself already was; (2) OpenRegister refuses a sessionless permanent delete, so a notice without a user now acts as the synchronization's owner (`403 no_owner` when there is none); (3) `writePurgeLog()` built the entry but never stored it, and its `expires` was not a string. PHPUnit adds the owner identity, the no-owner refusal, the signed-in caller, the unscoped lookups and the 403. The spec seeded its register with the schema's uuid (OpenRegister lists schemas by numeric id) and opened the editor from page 1 of a paged index; both fixed.
  - Was written before the live run: `tests/e2e/source-destruction-purge.spec.ts` posts signed and unsigned notices from a context with an empty storage state: unsigned, wrongly signed and unknown synchronization answer 401; an unknown record 404; a signed notice purges only that record's object (a second contracted object stays), keeps the contract with `targetId` null and `targetLastAction` purge, and a repeat finds nothing to remove; the contract log names the record, the object, the trigger, the reference and the time.

### Task 3: The purge record and the refusal
- **spec_ref**: openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-every-purge-is-recorded-and-a-refused-purge-stays-visible-req-sdp-003
- **files**: `lib/Service/SynchronizationService.php`, the contract log writer
- **acceptance_criteria**:
  - GIVEN a purge WHEN it completes THEN a contract log entry names the source record, the object id and the trigger, and the run counts it as purged
  - GIVEN a `ReferentialIntegrityException` WHEN the purge runs THEN the object is untouched and the refusal is in the run failures
- [x] Implement
  - `purgeTarget()` writes a contract log entry per purge (`targetResult: purged`, `source.originId`, `source.trigger` `fullRun` or `destructionNotice`, `source.reference`, `target.id`) and persists the contract with `targetLastAction: purge`. A refusal from OpenRegister leaves the object and the contract as they were, never falls back to a soft delete, is written to the contract log as `purge_refused` and is listed in `objects.purgeRefusals` with OpenRegister's reason.
- [x] Test (integration test with a restricting relation)
  - Done: the same test throws OpenRegister's real `ReferentialIntegrityException` (copied with its `DeletionAnalysis` into `tests/stubs/`) and checks the refusal is listed with its reason, nothing is deleted and the contract does not claim a purge.
  - Live 25 Nov 2026 (sandbox: Nextcloud 34.0.6, MariaDB, Redis, OpenRegister main, integriq development): a schema with a `RESTRICT` relation (`$ref` to the target schema, `onDelete: RESTRICT`) and one object pointing at a synchronized object; a signed notice answers `purge_refused` with OpenRegister's reason ("1 dependent object(s) block deletion"), the object stays (200), the contract keeps its `targetId` and claims no purge, and the contract log holds `purge_refused` with that reason.

### Task 4: The edit form and demo data
- **spec_ref**: openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-synchronization-can-purge-a-vanished-record-and-its-files-req-sdp-001
- **files**: `src/modals/v2/SynchronizationEditorModal.vue`, `lib/Settings/integriq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the synchronization editor WHEN an administrator picks `purge` THEN the form states that purged files cannot be restored
  - GIVEN demo data WHEN the demo synchronization runs THEN the run shows a purged count
- [x] Implement
  - `ownershipOptions.js` offers `purge` as a fourth disappearance policy and a new choice for `onSourceDestroyed` (the policy, or purge at once; the default leaves the key out). `SynchronizationEditorModal.vue` shows that picker under the policy and a warning card ("Purged files cannot be restored.") whenever either key is `purge`. Five strings in `l10n/en.json` and `l10n/nl.json`.
  - Demo data: the mock register has no publication synchronization (the design assumed one), so `disappearancePolicy: purge` and `onSourceDestroyed: purge` are declared on the full-mode demo synchronization `synchronization-voorbeeld-name-3-3`. `tests/Unit/Settings/SourceDestructionDemoTest.php` pins it and validates it against the real register schema.
- [ ] Test (`tests/e2e/source-destruction-purge.spec.ts`)
  - Done: `tests/vitest/syncOwnershipEditor.spec.js` mounts the real modal: the purge option, the `onSourceDestroyed` picker writing and removing its key, and the warning shown only when something purges.
  - Playwright written, not yet run: the editor test in `tests/e2e/source-destruction-purge.spec.ts` shows the warning on a synchronization that purges on destruction and as soon as purge is picked on one that does not. Owed: a live demo run that shows the purged count, and the full-run scenario with an attached PDF, which needs a source that serves records.
  - Live 25 Nov 2026: the editor test passes (the warning on a synchronization that purges on destruction, and as soon as purge is picked on one that does not). Still open, so the box stays unticked: the demo synchronization `synchronization-voorbeeld-name-3-3` is placeholder data (`sourceId` "Voorbeeld Sourceid 3", `targetId` "Voorbeeld Targetid 3"), so a demo run cannot reach a source and cannot show a purged count. The purged count itself was shown live by a full run against a real feed (task 1). Closing this needs demo data with a source that serves records.

## Verification

- `openspec validate synchronisation-source-destruction-purge --type change --strict`
- On a local instance with OpenCatalogi: synchronize one publication with an
  attachment, send a `destroy` notification for it, and confirm the object
  and its folder are gone and the contract log names it.
- `composer check:strict` and `npm run lint` once before push.
