# Tasks

## 1. Planner (read-safe) — DONE (PR #226)

- [x] 1.1 Classify inline fields via `_render: false` raw reads
- [x] 1.2 Provider map (apikey/password/secret/jwt); authenticationConfig → manual review
- [x] 1.3 `planAll()` clean gate + secret-free plan

## 2. Executor (writing)

- [x] 2.1 Mint organisation-scoped credential, allowedApps pinned to openconnector
- [x] 2.2 Verify round-trip via `resolveInjectable(..., actingOrganisationId)` before nulling
- [x] 2.3 Write nested `configuration.authentication.<field>` ref + null the inline value
- [x] 2.4 Per-source / per-field isolation; commit the working copy only on a persisted save
- [x] 2.5 Empty organisation → blocked, never minted at personal scope
- [x] 2.6 Idempotent (fresh raw re-classification; already-migrated skipped)
- [x] 2.7 Fail closed (rewrite nothing) when the broker lacks mint() or the 4-arg resolveInjectable()
- [x] 2.8 Secret-free logging throughout

## 3. Command

- [x] 3.1 Real-run mode drives the executor; `--dry-run` drives the planner
- [x] 3.2 `--json` machine output; per-source/per-field outcomes (migrated/failed/blocked/skipped)
- [x] 3.3 Re-report the true Phase D gate into appconfig after a real run

## 4. Tests

- [x] 4.1 Happy path: nested ref written + inline nulled (proven by raw re-read)
- [x] 4.2 Failed verify does not null; source stays callable (mutation guard)
- [x] 4.3 Failed mint / failed save leave the inline secret intact
- [x] 4.4 Empty organisation blocked, never minted at personal scope
- [x] 4.5 Idempotency (second run no-op)
- [x] 4.6 No secret reaches the logger
- [x] 4.7 resolveInjectable asserted with actingOrganisationId = source org
- [x] 4.8 Fail-closed on an old/absent broker

## 5. Phase D (separate change — NOT here)

- [ ] 5.1 Remove `apikey`/`secret`/`password`/`jwt`/`authenticationConfig` from the source schema once the gate is clean
