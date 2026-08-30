# Migration: openconnector-frontend-vue-rewrite

## N/A — No Database or Schema Changes

Chain D2 is a **frontend-only** rewrite. It introduces no changes to:

- Nextcloud database tables (no `lib/Migration/` classes needed)
- OpenRegister schemas (schema declarations were locked in chain A)
- PHP backend services (the service layer was stabilised in chain C)
- REST API endpoints (no endpoint additions, removals, or signature changes)

No Nextcloud migration class is required for this change.

---

## "Migration" in the frontend sense

The term "migration" for D2 refers to the incremental resource-page transition from
the legacy per-resource UI triad (ADR-010) to the `CnIndexPage` + `createCrudStore`
pattern. This is a code migration, not a data migration. Each resource is migrated
independently so that the `development` branch always has a buildable, testable frontend.

The ordered migration path is:

| Step | Resource | Thijn PR | Store migration | Modals deleted |
|------|----------|----------|-----------------|----------------|
| 1 | Dashboard | #718 (already merged on feature/nextcloud-vue) | n/a (no CRUD store) | n/a |
| 2 | Sources | #719 | `source.ts` → `createCrudStore` | `src/modals/Source/` |
| 3 | Endpoints | #720 | `endpoints.ts` → `createCrudStore` | `src/modals/Endpoint/` |
| 4 | Consumers | #721 | `consumer.ts` → `createCrudStore` | `src/modals/Consumer/` |
| 5 | Mappings | #743 | `mapping.ts` → `createCrudStore` | `src/modals/Mapping/` |
| 6 | Cloud Events | #744 | `event.ts` → `createCrudStore` | `src/modals/Event/` |
| 7 | Synchronizations | #768 | `synchronization.ts` → `createCrudStore` | `src/modals/Synchronization/` |
| 8 | Sync Contracts | #769 | `contract.ts` → `createCrudStore` | `src/modals/SynchronizationContract/` |
| 9 | Rules | #809 | `rule.ts` → `createCrudStore` | `src/modals/Rule/` |
| 10 | Import | #810 | `importExport.js` (not CRUD — keep as-is) | n/a (custom page) |

After step 10, the `Modals.vue` aggregator can be deleted.

---

## Rollback

Each step above is a separate merge commit on `development`. If a step introduces a
regression, revert the specific merge commit without affecting the preceding steps.
The manifest (`src/manifest.json`, owned by D1) is not touched by D2; it remains in
place even if D2 is fully reverted.
