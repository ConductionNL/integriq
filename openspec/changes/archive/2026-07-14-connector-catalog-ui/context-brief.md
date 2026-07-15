# Context Brief: connector-catalog-ui
Source: Specter deep-research 2026-07-14 (insights #1256, #1265). VERIFY every code claim against HEAD before writing artifacts.

## Problem
Discovery and day-2 ops are API-only. Seeded connectors (PDOK etc.) sit dormant behind feature flags with no browsable surface; configuration export/import (OpenAPI JSON, slug translation, credential redaction via ConfigurationHandlers) exists with NO UI. Every competitor leads with a catalog/template gallery (n8n 600+ templates; Workato tens of thousands of recipes) — the #1 onboarding device.

## Current state (verify at HEAD)
- Configuration groups bundling sources/endpoints/mappings/rules/jobs/syncs; export/import API endpoints (find exact routes in appinfo/routes.php + ConfigurationController).
- Seeds: PDOK sources behind pdok.feature_flag; in-flight seed changes (BRP, KVK, xWiki, messaging) will add more.
- UI: manifest v2, 26 pages; src/manifest.json; FeaturesRoadmap page exists (look at its pattern for a catalog-like page).
- Source types enum: json/xml/soap/ftp/sftp (+rest/wms/wfs seeded).

## In scope
1. Catalog page (new manifest page "Catalog"): browsable cards of (a) connector types/adapters available (from a registry of adapter metadata: name, category, standards, status incl. feature-flagged/dormant), (b) seeded source templates, (c) importable configuration templates. Search + category filter. Detail modal with description + "Enable"/"Instantiate" action (creates the Source/Configuration from seed, respecting feature flags + action matrix authorization).
2. Configuration import/export UI: export a configuration group to file (redacted) from the UI; import with preview (what will be created/updated, slug collisions) + confirmation; surface redacted-credential placeholders needing re-entry after import.
3. Adapter metadata registry: PHP-side registry (attribute or service-based) describing each built-in adapter/connector for the catalog — single source, no hardcoded frontend list.
4. Tests: PHP unit for registry + import preview; vitest for catalog store; Playwright e2e for catalog browse + import flow (e2e-coverage gate).
## Out of scope
- Full environments/promotion with credential re-binding (deferred until source-broker-credentials lands).
- Community template marketplace (remote fetch) — local/seeded only.

## Constraints
- Use nc-vue Cn* primitives (CnIndexPage/CnDataTable/cards) — NO nc-vue library changes; follow manifest-v2 typed pages where possible (#814 wants LESS custom pages, so prefer typed primitives; custom page only if unavoidable — mind hydra custom-widget-ratchet gate).
- Specs: new capability spec connector-catalog; delta to configuration-export-import (UI scenarios).
