/*
 * SPDX-FileCopyrightText: 2026 Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (not a fallback, not a console error — this app shipped three such
 * names), an entry whose `route` names a page the app does not host renders a
 * row that goes nowhere, and `nav.includePersonalSettings: false` silently
 * removed the entry reaching the user's notification preferences.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/integriq'

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers: ADR-114 fixes the sequence and
		// openregister runs its footer at 1/2 while pipelinq runs 160/200/230.
		const seen = texts.filter((t) =>
			/Documentation|Store|Reports|roadmap/i.test(t),
		)
		expect(seen.length).toBe(4)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Store/i)
		expect(seen[2]).toMatch(/Reports/i)
		expect(seen[3]).toMatch(/roadmap/i)

		// A glyph on every row. This app's Reports entry used ApiOff,
		// ChartBoxOutline and CloudOutline before any of them were registered,
		// and gate-60 was the only thing that noticed.
		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('Reports cards the six log surfaces, five of which had no entry at all', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		// Traces WAS a main-nav entry in the Operations group; ADR-112 Decision
		// 2 says a report is a card OR an entry, never both.
		await expect(nav.locator('[data-testid="cn-nav-entry-Traces"]')).toHaveCount(
			0,
		)

		await nav.locator('[data-testid="cn-nav-entry-ReportsMenu"]').click()
		await expect(page).toHaveURL(/\/apps\/integriq\/reports(\?|$)/, {
			timeout: 15_000,
		})

		// The other five were standalone routes reachable only if you already
		// knew the URL. Carding them GIVES an entry point rather than moving
		// one, so this asserts all six arrive, not just the one that moved.
		for (const label of [
			'Traces',
			'Source logs',
			'Endpoint logs',
			'Job logs',
			'Synchronization logs',
			'Cloud event logs',
		]) {
			await expect(
				page.getByText(label, { exact: false }).first(),
			).toBeVisible({ timeout: 15_000 })
		}
	})

	test('the pages behind the cards are still routable at their own paths', async ({
		page,
	}) => {
		// Retiring Traces' menu entry must not take its route with it, and the
		// five that never had an entry must keep working for anyone holding a
		// deep link (ADR-044 Decision 5).
		for (const path of ['/traces', '/sources/logs', '/jobs/logs']) {
			await page.goto(`${APP_BASE}${path}`)
			await expect(page).toHaveURL(new RegExp(`${path}(\\?|$)`), {
				timeout: 15_000,
			})
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
		}
	})

	test('Dead letters stays in Operations, because it is a queue you act on', async ({
		page,
	}) => {
		// Deliberate asymmetry with Traces. A dead letter is work waiting for
		// someone, not a reading of what happened, so ADR-097 keeps it in the
		// main nav. If a later sweep cards it with the logs, this fails rather
		// than passing review.
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(
			nav.locator('[data-testid="cn-nav-entry-DeadLetters"]'),
		).toBeAttached({ timeout: 15_000 })
	})

	test('the settings foldout carries Personal settings and Admin settings', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached()

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]')
		await expect(admin).toBeAttached()
		await expect(admin).toHaveAttribute('href', /\/settings\/admin\/integriq$/)
	})

	test("Flows stays in the main navigation, which is this app's documented exception", async ({
		page,
	}) => {
		// ADR-110 Decision 4 keeps Flows in `main` for exactly three apps, and
		// integriq is one: it owns the connector runtime, and its /flows is the
		// working surface rather than a settings afterthought. If a future
		// change "tidies" it into the settings foldout, this fails.
		const nav = page.locator('[data-testid="cn-nav"]')
		await expect(
			nav
				.locator('.cn-app-nav__footer-list')
				.getByRole('link', { name: /^Flows$/ }),
		).toHaveCount(0)
		await expect(nav.locator('[data-testid="cn-nav-entry-Flows"]')).toBeAttached(
			{ timeout: 15_000 },
		)
	})
})
