# Tasks — or-integration-provider

<!-- Spec-only change: the implementation already ships on `development`
     (lib/Service/Integration/SynchronizationContractProvider.php, registered in
     lib/AppInfo/Application.php::boot()). These tasks formalise the contract and
     pin the shipped code against the spec. HYDRA CAP: max 20 unindented lines; 8 used. -->

## 1. Provider contract (shipped — verify against spec)

- [ ] 1.1 Confirm `SynchronizationContractProvider` extends OR's `AbstractIntegrationProvider` and declares the D3 metadata exactly: id `sync-contract`, label `Synced from` (translated), icon `SyncOutline`, group `workflow`, requiredApp `openconnector`, storageStrategy `query-time` (REQ-OCIP-001)
  - Drift-pin against OpenRegister HEAD's `IntegrationProvider` interface — every method the interface declares is implemented or inherited.

- [ ] 1.2 Confirm the read-only posture: `get`/`create`/`update`/`delete` inherit the `NotImplementedException` default; `requiresPermission()` returns null (RBAC inherited from the target object) (REQ-OCIP-002)

- [ ] 1.3 Confirm `list()` filters on `targetId` ONLY, sets register/schema via `setRegister()/setSchema()` (never via the `filters` array), maps `_limit`/`_page` → `limit`/`offset`, and projects generic-card rows + raw provenance fields (REQ-OCIP-003)
  - Regression-pin the register/schema-context trap from design D4 (slug-in-filters silently matches nothing).

## 2. Availability + resilience (shipped — verify)

- [ ] 2.1 Confirm `isEnabled()`/`health()` gate on `openconnector.storage_migrated === 'true'`, returning `[]` and `status: unavailable` before cutover (REQ-OCIP-004)

- [ ] 2.2 Confirm `Application::boot()` registration is guarded by `class_exists(IntegrationRegistry::class)` + try/catch so openconnector boots when OR is absent or predates the registry (REQ-OCIP-005)

## 3. Verification

- [ ] 3.1 Unit test: provider metadata getters return the D3 values; the four mutation verbs throw `NotImplementedException`; `list()` with a `targetId` returns projected rows and with no matches returns `[]` (REQ-OCIP-001, REQ-OCIP-002, REQ-OCIP-003)
  - Run in the `nextcloud:34` container: `docker run --rm -v $PWD:/app -w /app <nc-image> php vendor/bin/phpunit`.

- [ ] 3.2 Soft-fail test: with `IntegrationRegistry` absent, `boot()` is a no-op and does not throw; with `storage_migrated=false`, `list()` returns `[]` and `health()` reports unavailable (REQ-OCIP-004, REQ-OCIP-005)

Acceptance criteria:
- Every OR object synced by openconnector surfaces a read-only "Synced from" leaf in its sidebar, across the fleet, with no per-leaf-app coupling.
- The leaf is query-time (no new table/route/Vue), inherits target-object RBAC, and is invisible until storage migration completes.
- openconnector boots cleanly against an OpenRegister that lacks the pluggable registry.
