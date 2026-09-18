# Tasks: records-owned-by-an-external-source

## Implementation tasks

### Task 1: Ownership and last-seen are projected onto the object
- **spec_ref**: `openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001`
- **files**: `lib/Service/Ownership/RecordOwnershipService.php`, `lib/Service/Ownership/OwnershipState.php`
- [x] Implement (mode, source, origin identifier, last-seen; `local` for an unmaintained object; unknown before the first run)
- [x] Test

### Task 2: The ownership mode is declared on the synchronisation
- **spec_ref**: `openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001`
- **files**: `lib/Service/Ownership/RecordOwnershipService.php` reads `sourceConfig.ownershipMode`
- [x] Implement (read and validated; a mode the engine does not know reads `local` rather than claiming ownership integriq cannot substantiate)
- [x] Test
- [ ] The edit modal's input and its Dutch and English strings. The synchronisation is written through OpenRegister's objects API, so the field belongs with that screen's other sourceConfig inputs.

### Task 3: The disappearance policy, declared and refused when it is wrong
- **spec_ref**: `openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-what-happens-when-a-record-disappears-is-declared-not-hardcoded-req-sor-002`
- **files**: `lib/Service/Ownership/DisappearancePolicy.php`, `lib/Service/SynchronizationService.php`, `lib/Controller/OwnershipController.php`
- [x] Implement (`delete` default, `markEnded`, `keepAndFlag`, refusal on an unknown value)
- [x] Test
- [ ] The refusal at the moment of the save itself. The synchronisation is written through OpenRegister's objects API, so integriq owns the check (`POST /api/ownership/validate-policy`, called before the save and by the engine on every run) and OpenRegister owns the schema-level refusal.

### Task 4: End and flag, with the counts on the run
- **spec_ref**: `openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-an-ended-record-keeps-its-history-and-says-when-the-source-dropped-it-req-sor-003`
- **files**: `lib/Service/Ownership/DisappearanceApplier.php`, `lib/Service/SynchronizationService.php`
- [x] Implement (end date, absence marking, both timestamps, the flag cleared on return, counts reported on the run's guard info)
- [x] Test

### Task 5: The policy sits behind the completeness and ratio guards
- **spec_ref**: `openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-no-policy-runs-on-a-fetch-that-was-not-complete-req-sor-004`
- **files**: `lib/Service/SynchronizationService.php`
- [x] Implement (the policy runs inside `deleteInvalidObjects()`, behind the incremental, completeness and ratio guards that were already there, so all three policies are gated by one gate)
- [x] Test (the engine's existing guard tests still pass; the policy branch is unreachable past any of them)

### Task 6: The delete refusal and its named override
- **spec_ref**: `openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005`
- **files**: `lib/Service/Ownership/LocalDeleteGuard.php`, `lib/Controller/OwnershipController.php`
- [x] Implement (refusal naming the synchronisation, override with reason, user and timestamp, refusal on an empty reason)
- [x] Test
- [ ] Dutch and English strings for the refusal and the override dialog, with the screen that raises it.

### Task 7: One read answers ownership for a consuming app
- **spec_ref**: `openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-the-consuming-app-reads-ownership-through-one-contract-req-sor-006`
- **files**: `lib/Service/Ownership/RecordOwnershipService.php`, `lib/Controller/OwnershipController.php`, `appinfo/routes.php`
- [x] Implement (`GET /api/ownership/{id}`; an object nobody maintains answers `local` and the call succeeds)
- [x] Test

## Verification

- [x] `openspec validate records-owned-by-an-external-source --strict` passes
- [x] PHPUnit run, exit code read rather than the summary line
- [ ] A run against a fixture source that drops a record under each of the three policies, with the run counts read back. Written as `tests/e2e/source-owned-records.spec.ts` and left for the nightly.

## Handover

- [ ] Hand dossiq its half: declare the ownership mode and the disappearance policy on the `brpPerson` and `kvkCompany` synchronisations in `lib/Settings/register.d/25-brp-kvk.json`, render the ownership state on the contact and the case party, and drop the delete action on a party dossiq does not own
- [ ] Record the row 5.19 closure in `openspec/changes/competitor-parity-2026-09/proposal.md` when this change is archived
