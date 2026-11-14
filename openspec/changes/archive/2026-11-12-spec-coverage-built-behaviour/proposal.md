---
kind: spec
---

# Proposal: spec-coverage-built-behaviour

## Summary

Eleven capability rows in `openspec/parity/capabilities.json` describe behaviour that integriq already has, but no requirement says it. This change writes those requirements down, so each row can record the requirement it is built against. No code changes.

## Why

The fleet spec-coverage pass of 11 November 2026 (decisions 386 and 387) asks for an open spec for every capability. These rows cited a spec dir whose scenarios do not state what the row says.

| row | capability | requirement |
|---|---|---|
| `src-expressions` | http-call-engine | REQ-SCB-001 |
| `src-secrets-writeonly` | source-credential-custody | REQ-SCB-002 |
| `src-encrypt` | source-credential-custody | REQ-SCB-003 |
| `gw-path-params` | endpoint-runtime | REQ-SCB-004 |
| `gw-public` | endpoint-runtime | REQ-SCB-005 |
| `gw-hot-reload` | endpoint-runtime | REQ-SCB-006 |
| `map-reuse` | mapping-and-search | REQ-SCB-007 |
| `map-unset` | mapping-and-search | REQ-SCB-008 |
| `sync-conditions` | synchronization-engine | REQ-SCB-009 |
| `obs-dashboard` | logs-and-statistics | REQ-SCB-010 |
| `obs-health-page` | logs-and-statistics | REQ-SCB-011 |

Each requirement is read from the row's recorded evidence (file and line) in the matrix.

## What changes

- Delta specs that add the requirements above to six main specs.
- `built.requirement` recorded on each row in `capabilities.json`.

## Impact

Specs only. `lib/` and `src/` are not touched.
