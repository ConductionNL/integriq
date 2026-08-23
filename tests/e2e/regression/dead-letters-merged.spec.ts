/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-079/080 navigation rework: the MERGED dead-letter operations surface.
 *
 * Integriq used to ship two dead-letter pages with identical operator
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

import { test, expect } from '@playwright/test'
import { gotoAppRoute, expectRouteMatched } from '../support/appRoot'

/**
 * The merged operations surface.
 *
 * ⚠️ This file used to resolve the URL prefix itself, by requesting each
 * candidate and taking the first that served the SPA shell. Both candidates
 * serve the identical shell, so it always chose `/apps/integriq` — the
 * prefix CI's path-mode router does NOT honour — and every navigation below
 * fell through the catch-all onto the Dashboard. See
 * tests/e2e/support/appRoot.ts, which asks `OC.generateUrl` instead.
 */
const DEAD_LETTERS_ROUTE = '/dead-letters'

test.describe('ADR-080 — merged Dead letters operations surface', () => {
	test('DeadLetters page mounts at /dead-letters with both queues offered', async ({
		page,
	}) => {
		// ⚠️ Path-mode router (createWebHistory, src/main.js) — a `#` here
		// would be WRONG: it would serve the SPA shell and land on the
		// Dashboard, since the router never reads location.hash.
		await gotoAppRoute(page, DEAD_LETTERS_ROUTE)
		await expectRouteMatched(page, DEAD_LETTERS_ROUTE)

		await expect(
			page.locator('[data-testid="dead-letters-queue-events"]'),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.locator('[data-testid="dead-letters-queue-sync"]'),
		).toBeVisible()

		// Events is the default queue, and it is the one actually MOUNTED —
		// asserting only the button would pass even if neither surface rendered.
		await expect(
			page.locator('[data-testid="dead-letters-events"]'),
		).toBeVisible()
		await expect(page.locator('[data-testid="dead-letters-sync"]')).toHaveCount(
			0,
		)
	})

	test('switching queue swaps the mounted surface and reflects it in the URL', async ({
		page,
	}) => {
		await gotoAppRoute(page, DEAD_LETTERS_ROUTE)
		await expectRouteMatched(page, DEAD_LETTERS_ROUTE)

		await page.locator('[data-testid="dead-letters-queue-sync"]').click()

		// The queues are different backends, so switching must UNMOUNT one and
		// MOUNT the other — not merely refilter a single list.
		await expect(page.locator('[data-testid="dead-letters-sync"]')).toBeVisible({
			timeout: 15_000,
		})
		await expect(
			page.locator('[data-testid="dead-letters-events"]'),
		).toHaveCount(0)

		await expect(
			page.locator('[data-testid="dead-letters-queue-sync"]'),
		).toHaveAttribute('aria-selected', 'true')
		expect(page.url()).toContain('queue=sync')
	})

	test('the two legacy routes each land on the queue their bookmark meant', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/cloud-events/deliveries')
		await expect(
			page.locator('[data-testid="dead-letters-events"]'),
		).toBeVisible({ timeout: 15_000 })
		expect(page.url()).toContain('/dead-letters')

		await gotoAppRoute(page, '/synchronizations/dead-letters')
		await expect(page.locator('[data-testid="dead-letters-sync"]')).toBeVisible({
			timeout: 15_000,
		})
		expect(page.url()).toContain('queue=sync')
	})
})
