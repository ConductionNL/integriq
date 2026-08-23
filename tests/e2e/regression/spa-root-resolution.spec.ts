/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The guard for `tests/e2e/support/appRoot.ts`.
 *
 * Since the router moved to path-based history, EVERY deep-linking spec in this
 * suite depends on one fact: which prefix `generateUrl('/apps/integriq')`
 * returned in the browser. Get it wrong and the URL falls outside the router
 * base, matches nothing, hits the `'/:pathMatch(.*)*'` catch-all and redirects
 * to the Dashboard — with a 200, no 404 and no console error.
 *
 * Six spec files used to answer that question by requesting each candidate
 * prefix and taking the first that served the SPA shell. Both prefixes serve
 * the identical shell, so the loop always returned the first one, which is the
 * WRONG one on CI. The specs that asserted a real selector failed with a
 * misleading message; the specs that asserted "something rendered" passed
 * against the Dashboard — 36 of them in `manifest-pages.spec.ts` alone.
 *
 * So this file exists to make the resolver's correctness observable rather than
 * assumed. It has three parts, and the third is the one that matters:
 *
 *   1. the resolver returns what the app itself would compute;
 *   2. a deep link through it MATCHES its route (address bar unchanged);
 *   3. ⚠️ POSITIVE CONTROL — the same deep link under the OTHER prefix is
 *      redirected to the app root. Without this, parts 1 and 2 could both be
 *      green on an instance where the distinction does not exist, and the guard
 *      would be worthless in exactly the environment it was written for.
 */

import { test, expect } from '@playwright/test'
import { resolveAppRoot, gotoAppRoute, expectRouteMatched } from '../support/appRoot'

/** A route that exists in `src/manifest.json` and needs no fixture data. */
const ROUTE = '/sources'

/** The two prefixes Nextcloud can serve this app under. */
const BOTH_PREFIXES = ['/apps/integriq', '/index.php/apps/integriq']

test.describe('SPA root resolution (path-mode router base)', () => {
	test('the resolved root is the one the app itself computes', async ({
		page,
	}) => {
		const root = await resolveAppRoot(page)

		expect(
			BOTH_PREFIXES,
			`resolved root ${root} is neither prefix Nextcloud serves this app under`,
		).toContain(root)

		// Read it a second time, independently, and require agreement.
		// `OC.generateUrl` is the exact call `src/main.js` makes to build the
		// router base, so this is the app's own answer and not a transcription
		// of it. The resolver probes on its own throwaway page and leaves this
		// one untouched, so navigate explicitly here.
		await page.goto('/index.php/apps/integriq/', {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})
		const fromApp = await page.evaluate(() => {
			const oc = (
				window as unknown as { OC?: { generateUrl?: (p: string) => string } }
			).OC
			return oc?.generateUrl?.('/apps/integriq') ?? null
		})
		expect(
			fromApp,
			'OC.generateUrl must be readable — an unreadable answer is not a pass',
		).not.toBeNull()
		expect((fromApp as string).replace(/\/+$/, '')).toBe(root)
	})

	test('a deep link through the resolved root MATCHES its route', async ({
		page,
	}) => {
		await gotoAppRoute(page, ROUTE)
		await expectRouteMatched(page, ROUTE)

		// And it is not the Dashboard. The address-bar check above already
		// proves the router matched, but naming the wrong page explicitly is
		// what makes a future failure readable.
		await expect(
			page.getByRole('heading', { name: 'Dashboard', level: 2 }),
			'the resolved root must not land on the Dashboard',
		).toHaveCount(0)
	})

	test('POSITIVE CONTROL: the same route under the OTHER prefix falls through to the app root', async ({
		page,
	}) => {
		const root = await resolveAppRoot(page)
		const wrong = BOTH_PREFIXES.find((prefix) => prefix !== root)
		expect(wrong, 'there must be a second prefix to test against').toBeTruthy()

		await page.goto(`${wrong}${ROUTE}`, {
			waitUntil: 'domcontentloaded',
			timeout: 30_000,
		})

		// The SPA must have mounted — otherwise this proves nothing about
		// routing, only that the page failed to load.
		await expect(
			page
				.locator('#app-content, [data-cy=app-content], .app-content')
				.first(),
			'the wrong prefix must still SERVE the SPA shell — that is precisely why the old probe could not tell the two apart',
		).toBeVisible({ timeout: 15_000 })

		// …and the router must have thrown the route away. If this assertion
		// ever fails, the prefix distinction has stopped mattering and
		// appRoot.ts can be simplified — do that rather than deleting this test.
		expect(
			new URL(page.url()).pathname,
			`${wrong}${ROUTE} was expected to fall through the catch-all to the app root`,
		).not.toContain(ROUTE)
	})
})
