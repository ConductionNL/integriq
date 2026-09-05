---
kind: spec-only
depends_on: []
---

# Proposal: sync-safety-guardrails (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The
synchronization safety guardrails were implemented and archived on
2026-07-14 (`archive/2026-07-14-sync-safety-guardrails`, 41/41 tasks checked
with per-task evidence), yet this live copy was resurrected at 0/41: the
openconnector→integriq rename applied to the prose, the per-task evidence
notes stripped, every box reset. The machinery exists at HEAD:
`sourceConfig.deletionRatioThreshold` handling and fetch-completeness
tracking in `lib/Service/SynchronizationService.php`,
`lib/Event/SynchronizationDeletionGuardedEvent.php`, the test-run no-write
guarantee, non-persistent ad-hoc Source resolution, and eight dedicated
PHPUnit suites (`SynchronizationServiceFetchCompletenessTest`,
`SynchronizationServiceDeletionRatioGuardTest`,
`SynchronizationServiceTestRunNoWriteTest`,
`SynchronizationServiceAdHocSourceTest`, and friends).

No live `@spec` tags point into this directory.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| Fetch-completeness tracking, deletion-ratio guard with `forceDeletion` override, absolute test-run no-write guarantee, non-persistent ad-hoc Sources, deletion-guard event (all 12 tasks) | **Already shipped and archived**: `archive/2026-07-14-sync-safety-guardrails` (41/41 boxes checked), code at HEAD |
| Residual work | None — the archived twin closed every box, including tests, docs and i18n |

## Sequencing

Nothing remains to implement from this change.

## Archival

This directory is retired in place (not moved or renamed) to keep the diff
reviewable; archive it via the normal flow at the next sweep.
