---
kind: spec-only
depends_on: []
---

# Proposal: tables-bridge (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The
Nextcloud Tables bridge was implemented and archived on 2026-07-15
(`archive/2026-07-15-tables-bridge`, 30/35 tasks checked with per-task
evidence), yet this live copy was resurrected at 0/35: the
openconnector→integriq rename applied to the prose, the evidence notes
stripped, every box reset. The machinery exists at HEAD:
`lib/Service/Tables/TablesSyncAdapter.php`, `TablesOcsClient.php`,
`TablesClientInterface.php`, the sync-editor table picker and column-mapping
helper (`src/views/Synchronization/TablesColumnMapping.vue` and friends),
and the test tree (`tests/Unit/Service/Tables/`,
`tests/Integration/Tables/TablesBridgeIntegrationTest.php`,
`tests/vitest/tablesBridge.spec.js` — the integration test moved from the
path the original tasks named, which is a move, not a gap).

10 live `@spec` tags in `tests/` point at this directory's
`specs/tables-bridge/spec.md`, `specs/sync-editor-ui/spec.md`, this
proposal's Risk 3 heading, and three task headings, so those anchor files
and headings stay exactly where they are (see the kept anchors below). The
other artifacts (context brief, contract, design, discovery, migration,
test plan, the synchronization-engine spec delta) are removed; they survive
verbatim in the archived twin and in git history.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `TablesSyncAdapter` with column cache and coercion, OCS client, discovery endpoints, sync-editor table picker + column-mapping helper, unit/integration/vitest coverage | **Already shipped and archived**: `archive/2026-07-15-tables-bridge` (30/35 boxes checked), code at HEAD |
| Residual verification: a walkthrough against a live Tables app, Newman for the discovery endpoints, a screenshot in `docs/images/` | Open, and honestly unticked in the archived twin (no Tables app in that build environment). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Kept anchors

The heading below is a live `@spec` anchor target
(`tests/Integration/Tables/TablesBridgeIntegrationTest.php`); it keeps its
original text so the tag resolves.

### Risk 3: CI image may not have the Tables app installed

Resolved as designed: the integration test degrades to the stubbed-client
path when the Tables app is absent (see the archived twin's Task 12
evidence).

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification belongs to a verification-pack-style follow-up.

## Archival

This directory is retired in place (not moved or renamed): its two spec
files, this proposal and `tasks.md` are live `@spec` anchor targets, and a
rename would both break those tags and detonate every diff-scoped gate.
Archive it via the normal flow only after those tags are repointed at the
main specs.
