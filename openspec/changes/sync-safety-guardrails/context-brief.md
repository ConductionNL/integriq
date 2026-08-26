# Context Brief: sync-safety-guardrails
Source: Specter deep-research 2026-07-14 (insights #1244). VERIFY every code claim against HEAD before writing artifacts.

## Problem (CRITICAL — data loss)
The synchronization engine mass-deletes previously synced objects when a source misbehaves. Open issues (ConductionNL/integriq GitHub, all 2026-05-27):
- #1000: source error during fetch → deleteInvalidObjects removes ALL synced objects
- #1001: mid-pagination failure → partial result set treated as authoritative → mass deletion
- #1002: HTTP 429 rate-limit → same mass deletion
- #1017: proposes defensive guard: abort-on-fetch-failure + deletion threshold before cleanup
- #1016: re-syncs create DUPLICATE objects instead of matching existing SynchronizationContracts by originId
- #1008: test-runs persist contracts despite advertised no-write guarantee
- #1009: sync auto-creates + enables a persistent Source from an arbitrary location string
- #1010 (related): per-page write amplification + unbounded memory in fetchAllPagesOptimized
Field corroboration: #732 cleanup joined legacy openregister_objects table and deleted live objects post magic-table cutover. The only organic customers (WOO publishing pipelines: Vaals, Berkelland, Zwolle) depend on safe sync.

## Current state (verify in lib/Service/SynchronizationService.php and related)
- deleteInvalidObjects() removes target objects whose contracts weren't touched in the current run — no distinction between "source says gone" and "fetch failed".
- Pagination fetch (fetchAllPagesOptimized, ReactPHP parallel) can partially succeed.
- SynchronizationContracts keyed by originId exist; re-sync matching reportedly broken (#1016).
- Test/dry-run mode exists on synchronizations (test-run endpoints).

## In scope
1. Fetch-failure detection: any page fetch error, non-2xx, or rate-limit → mark run "incomplete"; deleteInvalidObjects MUST NOT run on incomplete result sets.
2. Deletion-ratio guard: configurable per-synchronization threshold (default e.g. 10%); if the run would delete more than threshold of existing contracts, abort deletion, log a warning-level sync log, emit a notification/event; explicit force flag to override.
3. originId contract matching: on re-sync, look up existing contract by (synchronization, originId) before creating a new target object; heal duplicates path documented.
4. Test-run no-write guarantee: dry-run persists NO contracts, NO objects, NO sources.
5. No auto-created persistent Sources from ad-hoc locations: ad-hoc fetches use transient config.
6. Unit + integration tests reproducing #1000/#1001/#1002/#1016 scenarios (source stub returning errors/partial pages).
## Out of scope
- Retry/backoff/circuit breaker (separate change retry-and-circuit-breaker-policies).
- fetchAllPagesOptimized memory/streaming rework (#1010) — note as follow-up.

## Constraints
- All entities persist as OpenRegister objects (openconnector-direct-or-usage spec); no app-local tables.
- Controller → Service → Mapper layering (ADR-008). Modify synchronization-engine spec via delta.
- Existing spec: openspec/specs/synchronization-engine — write spec DELTAS against it.
