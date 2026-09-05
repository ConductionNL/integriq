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

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_BASE = '/index.php/apps/integriq'

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * ⚠️ On a FRESH instance CnSetupWizard opens over the app and its modal
 * intercepts pointer events, so every nav click resolves its locator and then
 * times out after 30s — a failure that reads like the navigation is broken.
 * Tests that navigate by URL pass, which is what makes this so easy to miss:
 * only the click-through tests fail, and only on a clean install.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]')
	if ((await modal.count()) === 0) {
		return
	}
	await modal.first().getByRole('button', { name: 'Close' }).click()
	await expect(modal).toHaveCount(0, { timeout: 15_000 })
}

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
		await dismissSetupWizard(page)
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

		await nav
			.locator('[data-testid="cn-nav-entry-ReportsMenu"] a')
			.first()
			.click()
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

	test('Sync runs sits inside Operations, not loose at the end of the main list', async ({
		page,
	}) => {
		// It declared no `section` and `order: 96`. Ninety-something is the
		// FOOTER band (Documentation 90, Store 92, Reports 95, roadmap 100), so
		// with no section it rendered in the MAIN list after every group: the
		// only main-section leaf outside a group. gate-107 checks the five
		// chrome items and never looks at this, which is why it shipped.
		const nav = page.locator('[data-testid="cn-nav"]')
		const entry = nav.locator('[data-testid="cn-nav-entry-SynchronizationRuns"]')
		await expect(entry).toBeAttached({ timeout: 15_000 })

		// Inside the Operations group, not a sibling of it.
		await expect(
			nav.locator(
				'[data-testid="cn-nav-entry-OperationsGroup"] [data-testid="cn-nav-entry-SynchronizationRuns"]',
			),
		).toBeAttached()
	})

	test('the Connections group reads English, including the subscriptions leaf', async ({
		page,
	}) => {
		// `Abonnementen` was the only Dutch label in an English menu, and
		// l10n/en.json mapped it to itself so the English UI said it too.
		// `Subscriptions` was already in the catalogue with a Dutch value.
		const nav = page.locator('[data-testid="cn-nav"]')
		const entry = nav.locator(
			'[data-testid="cn-nav-entry-NotificatiesAbonnementen"]',
		)
		await expect(entry).toBeAttached({ timeout: 15_000 })
		await expect(entry).toContainText(/Subscriptions/i)
		await expect(entry).not.toContainText(/Abonnementen/i)
	})

	test('Reports cards Operational health, and the page renders its six charts', async ({
		page,
	}) => {
		// The six existing cards answer "what happened to this one thing".
		// Nothing aggregated them, so "how is it going" had no page at all.
		await page.goto(`${APP_BASE}/reports`, { waitUntil: 'domcontentloaded' })
		await dismissSetupWizard(page)

		const card = page.getByText('Operational health', { exact: false }).first()
		await expect(card).toBeVisible({ timeout: 15_000 })
		await card.click()

		await expect(page).toHaveURL(/\/reports\/operational-health(\?|$)/, {
			timeout: 15_000,
		})

		// Titles, not data: a seeded instance may legitimately have no failed
		// run. What must not happen is a widget silently not mounting.
		for (const title of [
			'Sync run outcomes',
			'Trace outcomes',
			'Event delivery outcomes',
			'Failed sync runs per day',
			'Dead letters by phase',
			'Busiest entry points',
		]) {
			await expect(page.getByText(title, { exact: false }).first()).toBeVisible(
				{ timeout: 15_000 },
			)
		}
	})

	test('the dashboard opens on what is broken, not on how much ran', async ({
		page,
	}) => {
		// It used to open with six counts and six volume charts and say nothing
		// about anything being wrong, while circuitBreakerState, the dead-letter
		// queues and trace status were all already stored.
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		await dismissSetupWizard(page)

		for (const title of [
			'Failed sync runs',
			'Dead letters waiting',
			'Undelivered events',
			'Open circuit breakers',
		]) {
			await expect(page.getByText(title, { exact: false }).first()).toBeVisible(
				{ timeout: 30_000 },
			)
		}
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
		await expect(admin.locator('a').first()).toHaveAttribute(
			'href',
			/\/settings\/admin\/integriq$/,
		)
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
