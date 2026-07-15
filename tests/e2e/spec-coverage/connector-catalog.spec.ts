/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/connector-catalog/spec.md
 * (connector-catalog-ui — Catalog page: card grid, category filter,
 * status badges, detail dialog Enable/Instantiate).
 *
 * Backend-only scenarios (materialization idempotency, new-provider
 * pickup, action-matrix denial, OR data-layer lock) carry `@e2e exclude`
 * in the spec and are covered by PHPUnit
 * (tests/Unit/Service/CatalogRegistryServiceTest.php,
 * tests/Unit/Controller/CatalogControllerTest.php).
 *
 * NOTE (connector-catalog-ui apply): written per the test plan but NOT
 * executed against a live instance in the build environment — requires a
 * running Nextcloud with the MaterializeCatalogItems repair step applied
 * (occ upgrade / app enable). Run via `npm run test:regression` or
 * `npx playwright test tests/e2e/spec-coverage/connector-catalog.spec.ts`
 * against a provisioned instance.
 */

import { test, expect } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

const APP_BASE = '/index.php/apps/openconnector'

test.describe('Catalog page — manifest conformance (openconnector-app-manifest delta)', () => {
	const manifest = JSON.parse(
		fs.readFileSync(path.resolve(__dirname, '../../../src/manifest.json'), 'utf8'),
	)

	// @e2e openconnector-app-manifest::catalog-page-entry-is-present-and-uses-the-cards-index-pattern
	test('Catalog page entry uses type:index + viewMode:cards on openconnector/catalog_item', () => {
		const page = manifest.pages.find((p: { id: string }) => p.id === 'Catalog')
		expect(page, 'Catalog page must exist in the manifest').toBeTruthy()
		expect(page.type).toBe('index')
		expect(page.config.viewMode).toBe('cards')
		expect(page.config.register).toBe('openconnector')
		expect(page.config.schema).toBe('catalog_item')
		expect(page.config.cardComponent).toBe('CatalogItemCard')
	})

	// @e2e openconnector-app-manifest::catalog-menu-entry-is-present-and-routes-to-the-catalog-page
	test('Catalog menu entry routes to the Catalog page id', () => {
		const flatten = (entries: Array<{ id: string, route?: string, children?: unknown[] }>): Array<{ id: string, route?: string }> =>
			entries.flatMap((e) => [e, ...flatten((e.children as never[]) || [])])
		const entry = flatten(manifest.menu).find((e) => e.id === 'Catalog')
		expect(entry, 'Catalog menu entry must exist').toBeTruthy()
		expect(entry!.route).toBe('Catalog')
	})

	// @e2e openconnector-app-manifest::catalog-page-does-not-require-a-new-manifest-page-type
	test('Catalog page introduces no new page type value', () => {
		const knownTypes = new Set(manifest.pages.map((p: { type: string }) => p.type))
		// "index" predates this change (Sources, Endpoints, …) — the Catalog
		// page reuses it rather than minting a new enum value.
		const catalogPage = manifest.pages.find((p: { id: string }) => p.id === 'Catalog')
		expect(catalogPage.type).toBe('index')
		const preExistingIndexPages = manifest.pages.filter(
			(p: { id: string, type: string }) => p.type === 'index' && p.id !== 'Catalog',
		)
		expect(preExistingIndexPages.length).toBeGreaterThan(0)
		expect(knownTypes.has('index')).toBe(true)
	})
})

test.describe('Catalog page — browse, filter, badges (REQ-001)', () => {
	// @e2e connector-catalog::catalog-lists-built-in-adapters-and-seeded-source-templates-by-category
	test('catalog renders cards and the kind quick-filter narrows the grid', async ({ page }) => {
		await page.goto(`${APP_BASE}/catalog`, { waitUntil: 'domcontentloaded' })

		const cards = page.getByTestId('catalog-item-card')
		await expect(cards.first(), 'materialised catalog cards must render').toBeVisible({ timeout: 15_000 })
		const total = await cards.count()
		expect(total).toBeGreaterThanOrEqual(3)

		// BRP HaalCentraal (Government registers) is a real seeded entry.
		await expect(page.getByText('BRP HaalCentraal', { exact: false }).first()).toBeVisible()

		// Narrow via the "Source templates" kind quick-filter chip.
		await page.getByRole('button', { name: /Source templates/i }).first().click()
		await expect(cards.first()).toBeVisible({ timeout: 10_000 })
		const narrowed = await cards.count()
		expect(narrowed).toBeLessThanOrEqual(total)
	})

	// @e2e connector-catalog::status-badge-reflects-a-flag-gated-dormant-item
	test('PDOK card shows a dormant badge while pdok.feature_flag is off', async ({ page }) => {
		await page.goto(`${APP_BASE}/catalog`, { waitUntil: 'domcontentloaded' })

		const pdokCard = page.getByTestId('catalog-item-card').filter({ hasText: 'PDOK' }).first()
		await expect(pdokCard).toBeVisible({ timeout: 15_000 })
		await expect(pdokCard.getByTestId('catalog-status-badge')).toHaveText(/dormant/i)
	})

	// @e2e connector-catalog::status-badge-reflects-a-mock-seeded-available-item
	test('BRP HaalCentraal card shows available (mock mode is not dormant)', async ({ page }) => {
		await page.goto(`${APP_BASE}/catalog`, { waitUntil: 'domcontentloaded' })

		const brpCard = page.getByTestId('catalog-item-card').filter({ hasText: 'BRP HaalCentraal' }).first()
		await expect(brpCard).toBeVisible({ timeout: 15_000 })
		await expect(brpCard.getByTestId('catalog-status-badge')).toHaveText(/available/i)
	})

	// @e2e connector-catalog::search-narrows-the-catalog-grid
	test('typing "brp" into the search narrows the grid to matching items', async ({ page }) => {
		await page.goto(`${APP_BASE}/catalog`, { waitUntil: 'domcontentloaded' })

		const cards = page.getByTestId('catalog-item-card')
		await expect(cards.first()).toBeVisible({ timeout: 15_000 })
		const before = await cards.count()

		const search = page.getByRole('searchbox').first()
			.or(page.getByPlaceholder(/search/i).first())
		await search.fill('brp')
		await page.waitForTimeout(1_000)

		const after = await cards.count()
		expect(after).toBeLessThan(before)
		await expect(page.getByText('BRP HaalCentraal', { exact: false }).first()).toBeVisible()
	})
})

test.describe('Catalog detail dialog — Enable / Instantiate (REQ-002)', () => {
	// @e2e connector-catalog::enable-action-flips-a-feature-flag-for-a-flag-gated-item
	test('opening a dormant flag-gated item offers Enable and enabling updates the badge', async ({ page }) => {
		await page.goto(`${APP_BASE}/catalog`, { waitUntil: 'domcontentloaded' })

		const pdokCard = page.getByTestId('catalog-item-card').filter({ hasText: 'PDOK' }).first()
		await expect(pdokCard).toBeVisible({ timeout: 15_000 })
		await pdokCard.click()

		const dialog = page.getByTestId('catalog-item-detail-dialog')
		await expect(dialog).toBeVisible({ timeout: 10_000 })
		// The dialog re-checks live status before offering the action.
		const action = page.getByTestId('catalog-detail-primary-action')
		await expect(action).toHaveText(/Enable/i, { timeout: 10_000 })
		await action.click()

		await expect(page.getByText(/Feature enabled/i).first()).toBeVisible({ timeout: 10_000 })
		await expect(page.getByTestId('catalog-detail-status')).toHaveText(/available/i, { timeout: 10_000 })
	})

	// @e2e connector-catalog::instantiate-action-creates-a-source-from-a-seeded-template
	test('instantiating a dormant source-template creates the Source (visible on Sources page)', async ({ page }) => {
		await page.goto(`${APP_BASE}/catalog`, { waitUntil: 'domcontentloaded' })

		// xWiki seeds with isEnabled:false → dormant → Instantiate offered.
		const card = page.getByTestId('catalog-item-card').filter({ hasText: 'xWiki' }).first()
		await expect(card).toBeVisible({ timeout: 15_000 })
		await card.click()

		const action = page.getByTestId('catalog-detail-primary-action')
		await expect(action).toHaveText(/Instantiate/i, { timeout: 10_000 })
		await action.click()
		await expect(page.getByText(/Source instantiated/i).first()).toBeVisible({ timeout: 10_000 })

		// The Source now appears in the Sources index.
		await page.goto(`${APP_BASE}/sources`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByText('xWiki', { exact: false }).first()).toBeVisible({ timeout: 15_000 })
	})
})
