---
kind: spec-only
depends_on: []
---

# Proposal: retry-and-circuit-breaker-policies (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The retry
and circuit-breaker policies were implemented and archived on 2026-07-15
(`archive/2026-07-15-retry-and-circuit-breaker-policies`, 28/38 tasks
checked with per-task evidence), yet this live copy was resurrected at
0/38: the openconnector→integriq rename applied to the prose, the evidence
notes stripped, every box reset. The machinery exists at HEAD: the
circuit-breaker state machine in `lib/Service/CallService.php` (41
`circuitBreaker` references), `lib/Service/SyncItemDeadLetterService.php`,
`lib/Controller/SyncDeadLetterController.php`, breaker metrics in
`lib/Controller/MetricsController.php`, and the UI
(`src/components/CircuitBreakerBadge.vue`,
`src/views/Synchronization/SyncDeadLetterPage.vue`,
`src/modals/Synchronization/SyncDeadLetterDetailModal.vue` — the last two
moved from the paths the original tasks named, which is a move, not a gap).

No live `@spec` tags point into this directory.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| Per-source retry policy, circuit-breaker open/half-open/closed state machine, sync-item dead-lettering with list/detail/replay, breaker badge + reset, Prometheus breaker metrics, docs, i18n | **Already shipped and archived**: `archive/2026-07-15-retry-and-circuit-breaker-policies` (28/38 boxes checked), code at HEAD |
| Residual verification: Playwright coverage for the breaker badge and the dead-letter view, Newman for the new endpoints, UI screenshots in `docs/images/` | Open, and honestly unticked in the archived twin (live-instance testing was prohibited on the shared dev instance in that pass). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification belongs to a verification-pack-style follow-up.

## Archival

This directory is retired in place (not moved or renamed) to keep the diff
reviewable; archive it via the normal flow at the next sweep.
