# Context Brief: endoflife-date-source

## What
A shippable **source preset for the public endoflife.date API** in openconnector: source configuration (base URL https://endoflife.date/api, JSON, no auth), a sync/mapping definition that fetches the product list (`/api/all.json`) and per-product cycle data (`/api/{product}.json`) and upserts them as OpenRegister objects (schemas: eolProduct, eolCycle with fields: product slug, cycle/version, releaseDate, eol, support, latest, lts), and a schedulable sync job. Consumed by the softwarecatalog app's `eol-feed-integration` change (sibling repo) which matches cycles to catalog module versions.

## Why (evidence)
- endoflife.date is the canonical open EOL/lifecycle source: 460+ products, public JSON API, no auth — ideal first-class source preset and fully live-testable locally.
- Conduction pattern: integration transports live in openconnector; leaf apps consume registers (established by softwarecatalog's optional CVE feed and module-vulnerability-tracking spec).
- Specter canonical feature: `endoflife-date-source` (app_slug=openconnector, should, demand 7).

## How to spec this (explore first)
Explore the openconnector repo before writing artifacts — follow ITS existing conventions for sources/synchronizations/mappings/jobs (lib/, docs/, existing openspec/specs). The deliverable should reuse openconnector's existing Source + Synchronization + Mapping machinery — this change should mostly be CONFIGURATION + seed/preset + docs + tests, NOT new engine code. If openconnector has an existing "preset/template" mechanism for sources, use it; if not, deliver an importable configuration (JSON) + a documented setup guide + a repair-step or command to install the preset, whichever matches repo conventions.

## Scope
IN: source preset for endoflife.date, mapping to eolProduct/eolCycle schemas in a dedicated register (schema definitions included), scheduled synchronization (respect API friendliness: fetch all.json then per-product lazily/limited or batched), rate limiting/backoff per openconnector conventions, live smoke test against the real API (it is public and free), unit tests for the mapping, docs page.
OUT: any softwarecatalog-side matching logic (lives in the softwarecatalog change), other feeds (OSV/NVD), UI beyond what openconnector already renders for sources/synchronizations.

## Design constraints
- Follow openconnector's own openspec/config.yaml rules and existing spec conventions (read several existing specs in openspec/specs first).
- OR DELETE is soft-delete; upserts must be idempotent across repeated syncs.
- ADR-005 i18n if any UI strings; ADR-009 tests; SPDX headers on new PHP files (docblock form).
- OpenSpec delta headers MUST be `### Requirement: <name>`.
