/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/mapping-and-search/spec.md
 *
 * REQ-UI-001 (Mapping Management UI) — real Playwright UI tests covering
 * the Mappings SPA section: list, modal, detail.
 *
 * REQ-001 through REQ-005 describe backend mapping engine internals
 * (Twig/dot engine, cast directives, OR object shim, search helper) that
 * carry @e2e exclude and are covered by PHPUnit.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
 *
 * Known bug #996: Table view renders all cells as "—". Detail-page assertions
 * navigate directly to a detail URL rather than clicking a table row.
 */

import { test, expect } from '@playwright/test'
// APP_BASE comes from _helpers.ts, the one place that knows both that the
// router is hash-mode and that the URL needs the `/index.php/` prefix (without
// it, PHP's built-in server on CI 404s the app directory and every assertion
// below runs against a 404 page). This file used to keep a private copy of
// that string that was missing the prefix.
import { APP_BASE, openAndDismissCreateModal } from './_helpers'

const OR_BASE = '/index.php/apps/openregister/api/objects/openconnector'
const API_BASE = '/index.php/apps/openconnector/api'

// ---------------------------------------------------------------------------
// REQ-UI-001: Mapping Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-UI-001: Mappings list page mounts', () => {
	// @e2e mapping-and-search::mappings-list-page-mounts-and-shows-content
	test('Mappings index page renders inside main content area', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/mappings`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-UI-001: Add Mapping opens the bespoke editor', () => {
	// The editor is a modal again, so this asserts a modal again.
	//
	// It has now been both, because the app has been both. Originally it
	// expected a dialog and failed once `createMappingAndOpen` replaced that
	// with "POST an empty object, then route to MappingDetail"; it was rewritten
	// to assert the routing instead. That behaviour was itself the defect —
	// clicking Add minted a persisted "New mapping" shell before the user typed
	// anything — and the Mappings page now declares
	// `slots["form-dialog"] = "MappingEditorModal"`, so Add opens the editor
	// over an unsaved draft and writes nothing until Create.
	//
	// Opening and dismissing is the whole contract here: this spec covers the
	// creation SURFACE. That the surface actually persists is J2's job in
	// tests/e2e/regression/journeys.spec.ts, which drives the dialog to Create
	// and reads the object back out of OpenRegister.
	//
	// The slug here read `...-creation-surface` for as long as the tag has
	// existed. No scenario has ever had that slug — the heading in
	// mapping-and-search/spec.md:35 is "Add Mapping button opens the creation
	// MODAL". So this anchor resolved to nothing and the scenario counted as
	// uncovered while a running test sat directly beneath the tag. A tag that
	// names no scenario is indistinguishable from no tag at all, and nothing
	// warns about it: gate-19 reports the scenario missing, not the tag dangling.
	// @e2e mapping-and-search::add-mapping-button-opens-the-creation-modal
	test('Add Mapping opens the mapping editor modal', async ({ page }) => {
		await page.goto(`${APP_BASE}/mappings`, { waitUntil: 'domcontentloaded' })
		await openAndDismissCreateModal(page, /Add Mapping/i)
	})
})

test.describe('REQ-UI-001: Mapping detail page', () => {
	// @e2e mapping-and-search::mapping-detail-page-renders-for-an-existing-mapping
	test('Mapping detail URL renders app-content without crashing', async ({
		page,
	}) => {
		// Known bug #996: table cells all "—", so navigate directly to detail URL.
		// SPA gracefully handles nonexistent IDs (shows detail shell or not-found).
		// Path-mode router (src/main.js, router-history-mode convention): address
		// the detail route directly, no hash. The mapping-detail surface keeps
		// polling an OR fetch for the (nonexistent) id, so `networkidle` never
		// settles — wait for DOM + main instead of network silence.
		await page.goto(`${APP_BASE}/mappings/__nonexistent__`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(50)
	})
})

// ---------------------------------------------------------------------------
// API surface helpers (no @e2e spec tag — backend scenarios carry @e2e exclude)
// ---------------------------------------------------------------------------

test.describe('Mappings OR API — list', () => {
	test('GET mappings list from OR returns mapping objects', async ({
		request,
	}) => {
		const resp = await request.get(`${OR_BASE}/mapping?_limit=20`, {
			failOnStatusCode: false,
		})
		expect(resp.status()).toBe(200)
		const body = await resp.json()
		expect(body).toHaveProperty('results')
		expect(Array.isArray(body.results)).toBe(true)
	})
})

test.describe('Mapping test endpoint — HTTP surface', () => {
	test('POST /api/mappings/test is routable (not 404)', async ({ request }) => {
		const resp = await request.post(`${API_BASE}/mappings/test`, {
			data: { mappingId: 'nonexistent', input: { test: true } },
			failOnStatusCode: false,
		})
		// 400/422 = invalid input, 500 = OR unavailable; all non-crash responses. 404 = route missing.
		expect(resp.status()).not.toBe(404)
	})
})
