# retrofit-2026-05-26-integration-synced-from

## Why

OpenConnector ships a bespoke "Synced from" integration leaf (`SyncedFromTab.vue`, registry id `sync-contract`) that renders the synchronization provenance chain for any OpenRegister object. Two non-trivial frontend members — the `endpoint` computed (resolves the per-object sub-resource URL) and the `fetchRows` method (loads the provenance rows, degrading quietly on failure) — are a real capability lacking a written spec; this change reverse-specs it.

## What Changes

- Document how the synced-from tab resolves its source sub-resource endpoint and fetches provenance rows (including AD-23 quiet-degrade on 5xx/network error).
- No code changes — annotation-only retrofit.

## Impact

- **Affected specs**: new capability `synced-from-tab`
- **Affected code**: `src/integration/SyncedFromTab.vue` (JSDoc `@spec` annotations only)
- **Risk**: none — comment-only.
