/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Dead-letter-replay regression: the "Event deliveries" operations view.
 *
 * This spec navigates (HASH-mode router — src/main.js `mode: 'hash'`, so the
 * route MUST be a hash fragment: `/apps/openconnector/#/cloud-events/deliveries`;
 * the path form silently lands on the Dashboard) to the admin-only dead-letter
 * queue surface (`EventDeliveriesPage`, custom manifest page) and asserts the
 * page's REAL surface renders: the "Event deliveries" heading, the status
 * filter, and either the deliveries table (`data-testid="deliveries-table"`)
 * or the genuine empty state (`data-testid="empty-state"` with its
 * "No dead-lettered event deliveries" copy).
 *
 * It is the browser companion to the PHPUnit coverage in
 * `tests/Unit/Service/EventServiceTest.php` (replay/discard state guards,
 * attempts[] preservation, bulk partial outcomes) and
 * `tests/Unit/Controller/EventsControllerTest.php` (admin gate, 409 matrix,
 * bulk cap).
 *
 * @e2e scoping (gate-19, honest coverage): only the scenarios this spec
 * GENUINELY exercises in the browser are tagged below. The backend-semantics
 * scenarios (status-set filtering, admin rejection, 404/409 matrix, audit
 * stamping, bulk per-item outcomes, replay-after-recovery) are proven at the
 * controller/service layer and carry `@e2e exclude <reason>` in
 * openspec/specs/dead-letter-replay/spec.md instead of vacuous tags here.
 *
 * Cross-ref:
 * - openspec/specs/dead-letter-replay/spec.md
 * - src/views/EventDelivery/EventDeliveriesPage.vue
 * - src/modals/EventDelivery/EventDeliveryDetailModal.vue
 * - lib/Controller/EventsController.php
 * - lib/Service/EventService.php
 */

import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'

// Resolve the SPA root the same way manifest-pages.spec.ts does: apache dev
// containers serve `/apps/openconnector`, the `php -S` CI install serves the
// `/index.php/` form. Probe once with a HEAD-ish GET.
const ROOT_CANDIDATES = ['/apps/openconnector', '/index.php/apps/openconnector']
let _root: string | null = null
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

const IGNORED_CONSOLE_PATTERNS: RegExp[] = [
	/Deprecation/i,
	/Slow network is detected/i,
	/favicon/i,
	/the resource at .* was preloaded using link preload but not used/i,
	/Error fetching OpenConnector settings/i,
	/Failed to load resource:.*Not Found/i,
	/Failed to load user status/i,
	/user_status/i,
	/the server responded with a status of 500/i,
]

function attachConsoleSpy(page: Page): { errors: string[] } {
	const errors: string[] = []
	page.on('console', (msg: ConsoleMessage) => {
		const text = msg.text()
		if (IGNORED_CONSOLE_PATTERNS.some((rx) => rx.test(text))) return
		if (msg.type() === 'error') errors.push(text)
	})
	page.on('pageerror', (err) => {
		errors.push(`pageerror: ${err.message}`)
	})
	return { errors }
}

/** Navigate to the EventDeliveries page via its HASH deep-link. */
async function gotoDeliveries(page: Page): Promise<void> {
	const root = await rootUrl(page)
	await page.goto(`${root}/#/cloud-events/deliveries`, {
		waitUntil: 'domcontentloaded',
		timeout: 30_000,
	})
	// The custom page's own heading is the mount proof — not the generic
	// #app-content shell (which is present on EVERY route, dashboard included).
	await expect(
		page.getByRole('heading', { name: /^Event deliveries$/ }).first(),
		'EventDeliveriesPage heading must render (proves the hash route resolved to the custom page, not the Dashboard)',
	).toBeVisible({ timeout: 15_000 })
}

test.describe('dead-letter-replay — Event deliveries view', () => {

	test('EventDeliveries page mounts at #/cloud-events/deliveries with its real surface', async ({ page }) => {
		const { errors } = attachConsoleSpy(page)

		await gotoDeliveries(page)

		// The status filter is part of the page header (NcSelect with
		// input-label "Status") — a stable, page-specific control.
		await expect(
			page.getByText('Status', { exact: true }).first(),
			'Status filter label must render in the EventDeliveries header',
		).toBeVisible({ timeout: 10_000 })

		// The page resolves to exactly one of its two real states after load:
		// the dead-letter table or the explicit empty state (both carry
		// data-testids in EventDeliveriesPage.vue).
		await expect(
			page.locator('[data-testid="deliveries-table"], [data-testid="empty-state"]').first(),
			'EventDeliveries must render either the deliveries table or its empty state',
		).toBeVisible({ timeout: 15_000 })

		// No fatal console errors during mount.
		expect(
			errors,
			`EventDeliveries emitted console errors: ${errors.join(' | ')}`,
		).toEqual([])
	})

	// @e2e dead-letter-replay::empty-dead-letter-queue-shows-an-empty-state
	test('empty dead-letter queue shows the explicit empty state; a populated queue shows Replay/Discard affordances', async ({ page }) => {
		await gotoDeliveries(page)

		// Ground-truth the queue contents via the same admin endpoint the page
		// consumes, so the assertion branch is deterministic — not vacuous.
		// (The /index.php/ form always routes, htaccess or not — same pattern
		// as workflows/_fixture.ts.)
		const res = await page.request.get(
			'/index.php/apps/openconnector/api/events/dead-letter?status=failed,abandoned',
			{ failOnStatusCode: false, headers: { 'OCS-APIRequest': 'true' } },
		)
		// A non-OK ground truth must fail loudly — treating it as "empty
		// queue" silently flips this test into the wrong branch.
		expect(res.ok(), `dead-letter listing endpoint must answer the admin session (got ${res.status()})`).toBeTruthy()
		const body = await res.json().catch(() => ({}))
		const queued: Array<Record<string, unknown>> = body.results ?? []

		if (queued.length === 0) {
			// Scenario: empty dead-letter queue shows an empty state — assert
			// the REAL empty-state element and its copy, not a substring grab.
			const empty = page.locator('[data-testid="empty-state"]')
			await expect(empty, 'empty queue must render the explicit empty state').toBeVisible({ timeout: 15_000 })
			await expect(empty).toHaveText(/No dead-lettered event deliveries/)
			// And no dead-letter table is pretending to have rows.
			await expect(page.locator('[data-testid="deliveries-table"]')).toHaveCount(0)
		} else {
			// Populated queue: the table renders with one row per message and
			// selecting a row reveals the bulk Replay/Discard affordances.
			const table = page.locator('[data-testid="deliveries-table"]')
			await expect(table, 'populated queue must render the deliveries table').toBeVisible({ timeout: 15_000 })
			// The table paginates and the UI's default status filter may not
			// match the ground-truth query exactly — an exact row-count
			// equality is deploy-fragile. At least one dead-lettered row must
			// render for the affordance branch to be meaningful.
			expect(await table.locator('tbody tr').count()).toBeGreaterThan(0)
			// Tick the first row's checkbox — the bulk bar must appear with
			// the Replay/Discard verbs (pure frontend state, no mutation).
			// NcCheckboxRadioSwitch keeps the real <input> visually hidden —
			// a plain .check() never lands the click, so force it.
			await table.locator('tbody tr').first().getByRole('checkbox').first().check({ force: true })
			const bulkBar = page.locator('[data-testid="bulk-bar"]')
			await expect(bulkBar, 'selecting a row must reveal the bulk action bar').toBeVisible({ timeout: 10_000 })
			await expect(bulkBar.getByRole('button', { name: /Replay selected/i })).toBeVisible()
			await expect(bulkBar.getByRole('button', { name: /Discard selected/i })).toBeVisible()
		}
	})

})
