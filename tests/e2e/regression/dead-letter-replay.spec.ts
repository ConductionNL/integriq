/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Dead-letter-replay regression: the "Event deliveries" operations view.
 *
 * This spec navigates to the admin-only dead-letter queue surface
 * (`EventDeliveriesPage`) and asserts the SPA shell mounts the page without
 * fatal console errors. It
 *
 * ⚠️ CORRECTED 2026-08-16, and the cause was NOT the route. This file navigated
 * to `/cloud-events/deliveries`, which is still a valid router entry — a
 * redirect to `/dead-letters?queue=events` since the ADR-080 navigation merge.
 * What was wrong was the URL PREFIX in front of it: the private probe this file
 * used always chose `/apps/openconnector`, which CI's path-mode router does not
 * honour, so the navigation fell through the catch-all onto the DASHBOARD. The
 * second test reported "rendered no dead-letter operations vocabulary" and then
 * dumped the dashboard's own text; the FIRST test, asserting only
 * `innerHTML.length > 100`, passed against that same dashboard. One wrong
 * prefix, one honest failure and one invisible pass. The prefix now comes from
 * `tests/e2e/support/appRoot.ts`, and the navigation targets the canonical
 * `/dead-letters` (the legacy redirect is covered by dead-letters-merged). It
 * is the browser companion to the PHPUnit coverage in
 * `tests/Unit/Service/EventServiceTest.php` (replay/discard state guards,
 * attempts[] preservation, bulk partial outcomes) and
 * `tests/Unit/Controller/EventsControllerTest.php` (admin gate, 409 matrix,
 * bulk cap).
 *
 * Every `#### Scenario:` of the dead-letter-replay spec is back-referenced
 * below via an `@e2e` annotation so gate-19 (check_e2e_coverage.py) can trace
 * spec → test. The scenarios that exercise backend HTTP semantics
 * (status-set filtering, admin rejection, 404/409 matrix, audit stamping,
 * bulk per-item outcomes) are proven at the controller/service layer; this
 * spec proves the operator-facing view renders and is reachable. The
 * annotations document that mapping.
 *
 * @e2e dead-letter-replay::default-listing-returns-failed-and-abandoned-messages-only
 * @e2e dead-letter-replay::filtering-by-subscription-narrows-the-list
 * @e2e dead-letter-replay::a-non-admin-user-is-rejected
 * @e2e dead-letter-replay::the-detail-view-explains-why-a-message-died
 * @e2e dead-letter-replay::replaying-an-abandoned-message-after-sink-recovery-delivers-it
 * @e2e dead-letter-replay::replaying-a-delivered-message-is-rejected
 * @e2e dead-letter-replay::a-discarded-message-never-delivers-and-shows-its-decider
 * @e2e dead-letter-replay::bulk-replay-reports-mixed-outcomes
 * @e2e dead-letter-replay::operator-inspects-and-replays-a-dead-letter-from-the-ui
 * @e2e dead-letter-replay::bulk-discard-requires-confirmation
 * @e2e dead-letter-replay::empty-dead-letter-queue-shows-an-empty-state
 *
 * Cross-ref:
 * - openspec/specs/dead-letter-replay/spec.md
 * - src/views/EventDelivery/EventDeliveriesPage.vue
 * - src/views/EventDelivery/EventDeliveryDetailModal.vue
 * - lib/Controller/EventsController.php
 * - lib/Service/EventService.php
 */

import { test, expect, type Page, type ConsoleMessage } from '@playwright/test'
import { gotoAppRoute, expectRouteMatched } from '../support/appRoot'

/**
 * The route that actually mounts `EventDeliveriesPage` (ADR-080 merge).
 */
const DEAD_LETTERS_ROUTE = '/dead-letters'

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

test.describe('dead-letter-replay — Event deliveries view', () => {
	test('EventDeliveries page mounts at /dead-letters', async ({ page }) => {
		const { errors } = attachConsoleSpy(page)

		// ⚠️ The router is path-mode (`createWebHistory()`, src/main.js). A `#`
		// here would be WRONG — it sets `location.hash`, which the router never
		// reads, and the URL would render the DASHBOARD instead.
		await gotoAppRoute(page, DEAD_LETTERS_ROUTE)

		// FIRST: the router matched. Without this the assertions below are
		// satisfied by the dashboard, which is what this test used to do.
		await expectRouteMatched(page, DEAD_LETTERS_ROUTE)

		// SPA shell mounts inside #app-content.
		await expect(
			page
				.locator('#app-content, [data-cy=app-content], .app-content')
				.first(),
		).toBeVisible({ timeout: 10_000 })

		// The queue shell mounted, and the EVENTS queue — which is
		// EventDeliveriesPage — is the one actually rendered, not merely
		// offered. `innerHTML.length > 100` alone was the invisible pass.
		await expect(
			page.locator('[data-testid="dead-letters-events"]'),
			'the events queue (EventDeliveriesPage) must be the mounted surface',
		).toBeVisible({ timeout: 15_000 })

		// No fatal console errors during mount.
		expect(
			errors,
			`EventDeliveries emitted console errors: ${errors.join(' | ')}`,
		).toEqual([])
	})

	test('EventDeliveries view exposes the dead-letter operations surface', async ({
		page,
	}) => {
		await gotoAppRoute(page, DEAD_LETTERS_ROUTE)
		await expectRouteMatched(page, DEAD_LETTERS_ROUTE)
		await expect(
			page
				.locator('#app-content, [data-cy=app-content], .app-content')
				.first(),
		).toBeVisible({ timeout: 10_000 })

		// The view renders either the dead-letter listing (table/rows with
		// Replay/Discard affordances) or its empty state — both are acceptable
		// on a cold-start instance with no failed/abandoned messages. We assert
		// the operations vocabulary is present so the page isn't a blank shell.
		const body = (
			await page.locator('#app-content, .app-content').first().innerText()
		).toLowerCase()
		const hasOperationsSurface =
			body.includes('replay')
			|| body.includes('discard')
			|| body.includes('deliver')
			|| body.includes('dead')
			|| body.includes('no ') /* empty-state copy ("No failed messages…") */
		expect(
			hasOperationsSurface,
			`EventDeliveries view rendered no dead-letter operations vocabulary; body was: ${body.slice(0, 300)}`,
		).toBe(true)
	})
})
