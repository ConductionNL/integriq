---
kind: spec-only
depends_on: []
---

# Proposal: cdc-incremental-sync (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. CDC-style
incremental synchronization was implemented and archived on 2026-07-15
(`archive/2026-07-15-cdc-incremental-sync`, 16/24 tasks checked with
per-task evidence), yet this live copy was resurrected at 0/24: the
openconnector→integriq rename applied to the prose, the evidence notes
stripped, every box reset. The machinery exists at HEAD: the incremental
sync mode, cursor tracking and full-resync fallback in
`lib/Service/SynchronizationService.php` (23 incremental/CDC references),
the `reset-cursor` handling in
`lib/Controller/SynchronizationsController.php`, and the `syncMode`
configuration keys documented in the register descriptor.

No live `@spec` tags point into this directory.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| Incremental sync mode with change cursor, cursor persistence and reset endpoint, full-resync fallback, deletion-detection interplay with the guardrails, SPA sync-mode fields | **Already shipped and archived**: `archive/2026-07-15-cdc-incremental-sync` (16/24 boxes checked), code at HEAD |
| Residual verification: browser test for the sync-mode fields + reset-cursor action, Newman for `reset-cursor`, feature docs, screenshot | Open, and honestly unticked in the archived twin (no live instance in that pass). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification and docs belong to a verification-pack-style
follow-up.

## Archival

This directory is retired in place (not moved or renamed) to keep the diff
reviewable; archive it via the normal flow at the next sweep.
