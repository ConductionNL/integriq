/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-079/080 navigation rework: the MERGED dead-letter operations surface.
 *
 * OpenConnector used to ship two dead-letter pages with identical operator
 * verbs over two different tables — `EventDeliveries` (`event_message`,
 * /api/events/dead-letter) and `SyncDeadLetters` (`sync_item_dead_letter`,
 * /api/sync-dead-letter). Both rendered the SAME EmailAlertOutline glyph, so
 * the navigation showed one icon twice for what looked like two unrelated
 * things. They are now one entry under Operations at `/dead-letters`, with a
 * queue switch.
 *
 * This is a NAVIGATION merge, not a rewrite: the two queues stay separate
 * underneath (different schemas, different admin-only endpoints, different
 * replay semantics) and `DeadLettersPage` delegates to the two existing
 * components. So what this spec proves is exactly that — the shell mounts, the
 * switch swaps which component is mounted, and the legacy routes still land
 * their callers on the queue their old bookmark meant.
 *
 * The per-queue operator behaviour (replay/discard state guards, admin gate,
 * 409 matrix, bulk outcomes) is unchanged and stays covered by
 * dead-letter-replay.spec.ts plus the PHPUnit suites.
 *
 * Cross-ref:
 * - openspec/specs/dead-letter-replay/spec.md
 * - src/views/Operations/DeadLettersPage.vue
 * - src/views/EventDelivery/EventDeliveriesPage.vue
 * - src/views/Synchronization/SyncDeadLetterPage.vue
 */

import { test, expect, type Page } from '@playwright/test'

const ROOT_CANDIDATES = ['/apps/openconnector', '/index.php/apps/openconnector']
let _root: string | null = null

/**
 * Resolve the SPA root the same way the sibling regression specs do: apache
 * dev containers serve `/apps/openconnector`, the `php -S` CI install serves
 * the `/index.php/` form.
 *
 * @param page The Playwright page.
 * @return The serving root path.
 */
async function rootUrl(page: Page): Promise<string> {
	if (_root) return _root
	for (const candidate of ROOT_CANDIDATES) {
		const res = await page.request.get(`${candidate}/sources`, { failOnStatusCode: false })
		if (res.ok() && (await res.text()).includes('openconnector-main.js')) {
			_root = candidate
			return candidate
		}
	}
	throw new Error('Neither /apps nor /index.php form serves the openconnector SPA shell')
}

test.describe('ADR-080 — merged Dead letters operations surface', () => {

	test('DeadLetters page mounts at /dead-letters with both queues offered', async ({ page }) => {
		const root = await rootUrl(page)
		// ⚠️ Hash-mode router (createWebHashHistory, src/main.js) — without the
		// `#` this serves the SPA shell and renders the Dashboard instead.
		await page.goto(`${root}/#/dead-letters`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		await expect(
			page.locator('[data-testid="dead-letters-queue-events"]'),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.locator('[data-testid="dead-letters-queue-sync"]'),
		).toBeVisible()

		// Events is the default queue, and it is the one actually MOUNTED —
		// asserting only the button would pass even if neither surface rendered.
		await expect(page.locator('[data-testid="dead-letters-events"]')).toBeVisible()
		await expect(page.locator('[data-testid="dead-letters-sync"]')).toHaveCount(0)
	})

	test('switching queue swaps the mounted surface and reflects it in the URL', async ({ page }) => {
		const root = await rootUrl(page)
		await page.goto(`${root}/#/dead-letters`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		await page.locator('[data-testid="dead-letters-queue-sync"]').click()

		// The queues are different backends, so switching must UNMOUNT one and
		// MOUNT the other — not merely refilter a single list.
		await expect(page.locator('[data-testid="dead-letters-sync"]')).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('[data-testid="dead-letters-events"]')).toHaveCount(0)

		await expect(
			page.locator('[data-testid="dead-letters-queue-sync"]'),
		).toHaveAttribute('aria-selected', 'true')
		expect(page.url()).toContain('queue=sync')
	})

	test('the two legacy routes each land on the queue their bookmark meant', async ({ page }) => {
		const root = await rootUrl(page)

		await page.goto(`${root}/#/cloud-events/deliveries`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})
		await expect(page.locator('[data-testid="dead-letters-events"]')).toBeVisible({ timeout: 15_000 })
		expect(page.url()).toContain('/dead-letters')

		await page.goto(`${root}/#/synchronizations/dead-letters`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})
		await expect(page.locator('[data-testid="dead-letters-sync"]')).toBeVisible({ timeout: 15_000 })
		expect(page.url()).toContain('queue=sync')
	})
})
