---
kind: spec-only
depends_on: []
---

# Proposal: connector-catalog-ui (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The
connector catalog was implemented and archived on 2026-07-14
(`archive/2026-07-14-connector-catalog-ui`, 32/41 tasks checked with
per-task evidence), yet this live copy was resurrected at 0/41: the
openconnector→integriq rename applied to the prose, the evidence notes
stripped, every box reset. The machinery exists at HEAD:
`lib/Controller/CatalogController.php`, `lib/Service/CatalogRegistryService.php`,
`lib/Repair/MaterializeCatalogItems.php`, the `catalog_item` schema in
`lib/Settings/register.d/catalog-item-schema.json`, the catalog routes in
`appinfo/routes.php`, the Catalog UI (`src/components/CatalogItemCard.vue`,
`src/dialogs/CatalogItemDetailDialog.vue`, import/export dialogs), and the
authored e2e specs (`tests/e2e/spec-coverage/connector-catalog.spec.ts`,
`configuration-import-export-ui.spec.ts`).

`appinfo/routes.php` and `lib/Settings/register.d/catalog-item-schema.json`
reference this directory's `contract.md` and `design.md`, so those two files
stay exactly where they are as reference targets. The other artifacts
(context brief, discovery, migration, test plan, spec deltas) are removed;
they survive verbatim in the archived twin and in git history.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `catalog_item` schema, materialisation repair step, registry service, controller + routes, Catalog page, item detail dialog, import preview / export dialogs, ADR-023 `catalog.instantiate` entry, unit + vitest coverage, authored e2e specs | **Already shipped and archived**: `archive/2026-07-14-connector-catalog-ui` (32/41 boxes checked), code at HEAD |
| Residual verification: executing the two authored Playwright specs against a live instance, Newman coverage for the catalog endpoints, a Catalog-page screenshot in `docs/images/` | Open, and honestly unticked in the archived twin (each open box carries its reason: no live instance in that build environment). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification belongs to a verification-pack-style follow-up.

## Archival

This directory is retired in place (not moved or renamed): `contract.md` and
`design.md` are referenced from `appinfo/routes.php` and the register
fragment, and a rename would break those pointers and detonate every
diff-scoped gate. Archive it via the normal flow only after those comments
are repointed.
