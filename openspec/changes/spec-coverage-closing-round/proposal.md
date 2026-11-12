---
kind: spec
---

# Proposal: spec-coverage-closing-round

## Summary

Row `sync-create` says a synchronization copies records into a register on a schedule. Its recorded requirement (synchronization-engine REQ-001) covers direction and routing, not the schedule. Integriq already runs a synchronization from a job: a job whose `jobClass` is `SynchronizationAction` runs the synchronization named in its arguments on each cron pass. This change writes that down. No code changes.

## Why

Closing round of 12 November 2026 (decisions 386 to 396): every capability row records a requirement that says what the row says.

| row | capability | requirement |
|---|---|---|
| `sync-create` | synchronization-engine | REQ-SCB-012 |

Evidence: `lib/Service/Job/SynchronizationAction.php` run() reads `synchronizationId` and calls the synchronization; `lib/Service/JobService.php` resolves `jobClass` from the container and skips a disabled job unless the run is forced.
