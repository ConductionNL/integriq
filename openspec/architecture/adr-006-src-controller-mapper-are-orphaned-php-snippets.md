# ADR-006: `src/Controller/` and `src/Mapper/` are orphaned PHP snippets, not a frontend layer

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

The Vue `src/` tree contains two directories that look, at first glance, like a
frontend HTTP-adapter convention borrowed from backend naming:

- `src/Controller/JobLogController.php` (36 lines)
- `src/Mapper/JobLogMapper.php` (28 lines)

Static inspection shows these are **not** active frontend code. Each file starts
with a bare PHPDoc comment and a method body — no `<?php`, no `namespace`, no
`class` declaration. They are plain-text PHP code fragments, most likely
generated or hand-pasted as implementation scaffolding during an earlier sprint
and never integrated. No Vue component, composable, or store file imports from
either path:

```
grep -r "Controller\|import.*Mapper" src/ --include="*.vue" --include="*.ts" \
     --include="*.js"
# → (no output)
```

The actual frontend HTTP-adapter layer is the **Pinia store modules** under
`src/store/modules/`. Each module hard-codes its own `apiEndpoint` string and
uses the browser `fetch()` API directly (see ADR-001 for the rationale for
keeping per-resource stores rather than adopting `createObjectStore`).

The fragment in `src/Mapper/JobLogMapper.php` also contains a raw SQL query
(`SELECT * FROM *PREFIX*openconnector_job_logs WHERE job_id = ?`) that
bypasses Nextcloud's `QBMapper`/`IQueryBuilder` abstraction and is
MySQL-only — a separate concern captured in ADR-009.

## Decision

The files in `src/Controller/` and `src/Mapper/` are dead code. They MUST NOT
be promoted to a real frontend adapter layer. If the frontend ever needs a
typed HTTP-client class separate from the Pinia store, it MUST follow the
`@conduction/nextcloud-vue` `createCrudStore` pattern introduced in chain D2
(`openconnector-frontend-vue-rewrite`), not re-invent a PHP-style
Controller/Mapper naming.

The orphaned files can be deleted once chain D2 is underway; they are kept
as-is for now to avoid spurious diff noise.

## Consequences

- A developer discovering `src/Controller/JobLogController.php` must NOT
  assume there is a frontend Controller/Mapper convention for openconnector.
  The real HTTP-call layer is the store modules; read ADR-001.
- Code-review tooling that checks for PHP files under `src/` should flag these
  as candidates for removal, not as intended architecture.
- Chain D2 (`openconnector-frontend-vue-rewrite`) will replace the per-store
  fetch calls with `createCrudStore` from `@conduction/nextcloud-vue`; the
  orphaned files do not block D2 but should be cleaned up in that PR.
- Cross-reference: ADR-001 (domain-specific Pinia stores) — the legitimate
  frontend HTTP layer.
- Cross-reference: `openspec/changes/openconnector-frontend-vue-rewrite/README.md`
  — chain D2 that introduces `createCrudStore` and `CnIndexPage`.

## Evidence

- `src/Controller/JobLogController.php:1-36` — PHP fragment with no class
  declaration; not importable from Vue code.
- `src/Mapper/JobLogMapper.php:1-28` — PHP fragment; raw SQL at line 12
  bypasses `IQueryBuilder`.
- `src/store/modules/source.ts:8` — `apiEndpoint = '/index.php/apps/openconnector/api/sources'`
  — the actual frontend HTTP-adapter layer.
- `src/store/modules/source.ts:71-79` — `fetch(endpoint, { method: 'GET' })`
  called directly in the store; no separate Controller class.
- `openspec/changes/openconnector-frontend-vue-rewrite/README.md:3` —
  "Integrate Thijn PR stack 719-810 refactoring 10 resource pages onto
  nextcloud-vue conventions (`CnIndexPage` `CnStatsPanel` `createCrudStore`)."
