# Test Plan: openconnector-frontend-vue-rewrite

## Overview

D2 is a frontend-only rewrite. Test coverage focuses on:
1. Navigation and routing (all 13 manifest menu items resolve).
2. Per-resource page functionality (CnIndexPage CRUD flows for each resource).
3. Dead-code deletion regression (no broken imports after directory deletes).
4. Build and lint quality gates.

No PHPUnit or Newman tests are required (no PHP or API changes).

---

## Test Cases

### TC-001: All 13 navigation items render

- **Spec**: `spec.md#requirement-the-app-shell-must-boot-via-cnapproot-using-the-d1-manifest`
  Scenario: All 13 manifest menu items render in the nav
- **Type**: Functional (browser)
- **Command**: `/test-functional`
- **Steps**:
  1. Load `http://localhost:8080/apps/openconnector` as authenticated user.
  2. Inspect left navigation panel.
  3. Assert: Dashboard, Sources, Endpoints, Consumers, Webhooks, Mappings, Jobs,
     CloudEvents, Synchronizations, Rules are visible in the main section.
  4. Assert: Import, Documentation, Settings are visible in the settings section.

### TC-002: Sources index page renders via CnIndexPage

- **Spec**: `spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
  Scenario: Sources index page renders via CnIndexPage
- **Type**: Functional (browser)
- **Command**: `/test-functional`
- **Steps**:
  1. Navigate to `/apps/openconnector#/sources`.
  2. Assert: a list of Source objects is displayed (or an empty-state message).
  3. Assert: no `console.error` output.
  4. Assert: the component tree includes `CnIndexPage`.

### TC-003: Create source via CnIndexPage form

- **Spec**: `spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
  Scenario: Create source form opens from CnIndexPage
- **Type**: Functional (browser)
- **Command**: `/test-functional`
- **Steps**:
  1. Navigate to Sources index.
  2. Click "Add source" (or equivalent CnIndexPage action).
  3. Assert: a schema-driven form opens in a modal/panel without navigating away.
  4. Fill required fields; click Save.
  5. Assert: the new source appears in the list; toast confirms success.

### TC-004: Rules schema-driven create form

- **Spec**: `spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
  Scenario: Rules page uses schema-driven UI
- **Type**: Functional (browser)
- **Command**: `/test-functional`
- **Steps**:
  1. Navigate to Rules index.
  2. Open the create form.
  3. Assert: form fields are generated from the `rule` schema (not hardcoded Vue template).
  4. Assert: required fields match the `rule` schema definition in OpenRegister.

### TC-005: Navigation routing — clicking each menu item

- **Spec**: `spec.md#requirement-the-app-shell-must-boot-via-cnapproot-using-the-d1-manifest`
  Scenario: Navigation routes to the correct page
- **Type**: Functional (browser)
- **Command**: `/test-functional`
- **Steps**:
  1. For each of 13 nav items: click and assert the correct route path is shown in the URL bar.
  2. Assert: no 404 or "Component not found" error for any route.

### TC-006: Source store createCrudStore base surface

- **Spec**: `spec.md#requirement-all-16-crud-pinia-stores-must-be-migrated-to-createcrudstore`
  Scenario: Source store provides createCrudStore base surface
- **Type**: Functional (browser)
- **Command**: `/test-functional`
- **Steps**:
  1. Open browser devtools > Console.
  2. Run `window.__pinia.state.value.source` and confirm `list`, `current`,
     `loading`, `error` refs are present.
  3. Assert: `fetchAll()`, `create()`, `update()`, `delete()` are callable.

### TC-007: Synchronization store preserves flow-token header (ADR-011)

- **Spec**: `spec.md#requirement-all-16-crud-pinia-stores-must-be-migrated-to-createcrudstore`
  Scenario: Synchronization store preserves flow-token header
- **Type**: API
- **Command**: `/test-api`
- **Steps**:
  1. Trigger a sync from the Synchronizations page with a known flow token.
  2. Inspect the outgoing HTTP request headers in Network tab.
  3. Assert: `X-Flow-Token` header is present with the expected value.

### TC-008: No PHP files under src/

- **Spec**: `spec.md#requirement-srcontroller-and-srcmapper-dead-code-directories-must-be-deleted`
  Scenario: No PHP files remain under src/
- **Type**: Regression
- **Command**: `/test-regression`
- **Steps**:
  1. Run `find src/ -name "*.php"` in the openconnector repo root.
  2. Assert: zero results.

### TC-009: src/navigation/ is absent

- **Spec**: `spec.md#requirement-srcnavigation-must-be-deleted`
  Scenario: Navigation directory is absent post-merge
- **Type**: Regression
- **Command**: `/test-regression`
- **Steps**:
  1. Run `find src/navigation -type f`.
  2. Assert: command exits with no results (or "No such file or directory").

### TC-010: Modals.vue is absent after migration

- **Spec**: `spec.md#requirement-modalsvue-aggregator-must-be-deleted-after-all-resource-pages-migrate`
  Scenario: Modals.vue is absent post-migration
- **Type**: Regression
- **Command**: `/test-regression`
- **Steps**:
  1. Run `find src/modals -name "Modals.vue"`.
  2. Assert: zero results.

### TC-011: Sidebars are preserved

- **Spec**: `spec.md#requirement-modalsvue-aggregator-must-be-deleted-after-all-resource-pages-migrate`
  Scenario: Sidebars are preserved
- **Type**: Regression
- **Command**: `/test-regression`
- **Steps**:
  1. Run `find src/sidebars -type f -name "*.vue"`.
  2. Assert: at least one sidebar component per resource family (Source, Endpoint, Job,
     Synchronization, Mapping, Rule).

### TC-012: Settings page reachable via nav

- **Spec**: `spec.md#requirement-srcsettingsjs-entry-point-must-be-deleted`
  Scenario: Settings page is reachable via nav
- **Type**: Functional (browser)
- **Command**: `/test-functional`
- **Steps**:
  1. Click the Settings item in the settings section of the nav.
  2. Assert: router navigates to `/settings`.
  3. Assert: settings page content renders inside the CnAppRoot shell.

### TC-013: Widget bundles load in Nextcloud Dashboard

- **Spec**: `spec.md#requirement-widget-js-files-must-be-relocated-to-srcwidgets`
  Scenario: Widget bundles still load in Nextcloud Dashboard
- **Type**: Functional (browser)
- **Command**: `/test-functional`
- **Steps**:
  1. Navigate to the Nextcloud Dashboard (`/apps/dashboard`).
  2. Attempt to add the "Job Queue" and "Recent Calls" widgets.
  3. Assert: widgets appear in the widget picker and render without errors.

### TC-014: npm run lint passes

- **Spec**: `spec.md#requirement-npm-run-lint-and-npm-run-build-must-pass-cleanly-after-d2`
  Scenario: Lint passes after migration
- **Type**: Regression
- **Command**: `/test-regression`
- **Steps**:
  1. Run `npm run lint` in the openconnector directory.
  2. Assert: exit code 0, zero errors reported.

### TC-015: npm run build produces no orphan import errors

- **Spec**: `spec.md#requirement-npm-run-lint-and-npm-run-build-must-pass-cleanly-after-d2`
  Scenario: Build produces no orphan import errors
- **Type**: Regression
- **Command**: `/test-regression`
- **Steps**:
  1. Run `npm run build` in the openconnector directory.
  2. Assert: exit code 0.
  3. Assert: build output contains no "Module not found" or "Cannot find module" warnings.

### TC-016: Accessibility — Sources index page

- **Spec**: `spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
  (implied — CnIndexPage follows Nextcloud WCAG AA conventions)
- **Type**: Accessibility
- **Command**: `/test-accessibility`
- **Steps**:
  1. Navigate to Sources index page.
  2. Run axe or Lighthouse accessibility audit.
  3. Assert: no critical WCAG AA violations.

---

## Coverage Summary

| Requirement | Test Cases | Status |
|-------------|-----------|--------|
| CnAppRoot bootstrap / manifest nav | TC-001, TC-005 | Covered |
| src/Controller/ and src/Mapper/ deleted | TC-008 | Covered |
| src/navigation/ deleted | TC-009 | Covered |
| 10 resource pages on CnIndexPage | TC-002, TC-003, TC-004 | Covered (Sources + Rules; others regression-checked via TC-005) |
| 16 CRUD stores on createCrudStore | TC-006, TC-007 | Covered (Sources + Sync; others verified via TC-002..TC-004) |
| Settings route migration | TC-012 | Covered |
| Widget relocation | TC-013 | Covered |
| Modals.vue deleted; sidebars preserved | TC-010, TC-011 | Covered |
| Lint and build quality gates | TC-014, TC-015 | Covered |
| Accessibility | TC-016 | Covered (Sources only; full fleet in chain E) |

**Deliberately untested in D2:**
- Full E2E flows for all 10 resources (covered by chain E — `openconnector-comprehensive-tests`).
- PHPUnit (no PHP changes in D2).
- Newman/Postman (no API changes in D2).
- WCAG audit for all 13 pages (chain E scope).

---

## Regression Scenarios to Promote

After D2 ships, the following test cases SHOULD be promoted to persistent reusable test
scenarios (via `/test-scenario-create`) for ongoing regression value:

- TC-001 (nav completeness check) — catches future manifest regressions
- TC-002 + TC-003 (Sources CRUD via CnIndexPage) — key user flow
- TC-013 (Dashboard widgets load) — cross-app integration check
- TC-014 + TC-015 (lint + build) — already enforced by CI, but worth a scenario for
  manual re-runs
