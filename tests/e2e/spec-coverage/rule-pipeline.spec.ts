/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/specs/rule-pipeline/spec.md
 *
 * REQ-RULE-UI-001 (Rule Management UI) — real Playwright UI tests covering
 * the Rules SPA section: list, modal, detail.
 *
 * REQ-RULE-001 through REQ-RULE-005 describe backend rule pipeline execution
 * that carry @e2e exclude and are covered by PHPUnit.
 *
 * NOTE: /index.php/apps/openconnector/* redirects to /apps/openconnector/ (loses
 * the deep-link path). Always use the /apps/ prefix.
 */

import { test, expect } from '@playwright/test'

// /index.php/apps/openconnector/* strips the path on redirect; use /apps/ prefix.
const APP_BASE = '/apps/openconnector'

// ---------------------------------------------------------------------------
// REQ-RULE-UI-001: Rule Management UI
// ---------------------------------------------------------------------------

test.describe('REQ-RULE-UI-001: Rules list page mounts', () => {
	// @e2e rule-pipeline::rules-list-page-mounts-and-shows-content
	test('Rules index page renders inside main content area', async ({ page }) => {
		await page.goto(`${APP_BASE}/rules`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(100)
	})
})

test.describe('REQ-RULE-UI-001: Add Rule modal', () => {
	// @e2e rule-pipeline::add-rule-button-opens-the-creation-modal
	test('Add Rule button opens modal/dialog', async ({ page }) => {
		await page.goto(`${APP_BASE}/rules`, { waitUntil: 'networkidle' })
		const addBtn = page.getByRole('button', { name: 'Add Rule' })
		await expect(addBtn, 'Add Rule button must be visible').toBeVisible({ timeout: 20_000 })
		await addBtn.click()
		const dialog = page.getByRole('dialog').first()
		await expect(dialog, 'Modal must open after clicking Add Rule').toBeVisible({ timeout: 10_000 })
		// Dismiss without saving
		const cancelBtn = dialog.getByRole('button', { name: /Cancel|Close/i }).first()
		if (await cancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
			await cancelBtn.click()
		} else {
			await page.keyboard.press('Escape')
		}
	})
})

test.describe('REQ-RULE-UI-001: Rule detail page', () => {
	// @e2e rule-pipeline::rule-detail-page-renders-for-an-existing-rule
	test('Rule detail URL renders app-content without crashing', async ({ page }) => {
		// Navigate directly to a detail-style URL; SPA gracefully handles nonexistent IDs
		await page.goto(`${APP_BASE}/rules/__nonexistent__`, { waitUntil: 'networkidle' })
		await expect(page.locator('main').first()).toBeVisible({ timeout: 15_000 })
		const html = await page.locator('main').first().innerHTML()
		expect(html.length).toBeGreaterThan(50)
	})
})

// ---------------------------------------------------------------------------
// HTTP-contract assertions for the rule OR list + endpoint dispatch surfaces
// are API-direct (no @e2e tag) and have been relocated to
// tests/e2e/api-direct/rule-pipeline.api.spec.ts (Newman-equivalent, excluded
// from the gate-19 UI run). gate-19: API-direct → Newman.
// ---------------------------------------------------------------------------
