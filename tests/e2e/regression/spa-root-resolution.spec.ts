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
 *   3. BOTH prefixes resolve the route.
 *
 * ⚠️ Part 3 used to be the opposite claim — a POSITIVE CONTROL asserting the
 * OTHER prefix was redirected to the app root — and it was right at the time.
 * The router base came from `generateUrl('/apps/integriq')`, which returns only
 * the form the instance is configured for, so a visitor who arrived on the other
 * form fell outside the base and was swallowed to the Dashboard.
 *
 * `routerBase()` now derives the base from `window.location.pathname`, so it
 * always matches the URL the visitor actually arrived on and both forms work.
 * That fix is what made the old control fail, exactly as its own closing comment
 * predicted: "if this assertion ever fails, the prefix distinction has stopped
 * mattering".
 *
 * The guard was inverted rather than deleted, because it still has a job. It no
 * longer proves the resolver picks the right prefix; it proves the swallowed
 * deep link cannot come back. That matters beyond this suite: these URLs are
 * pasted into tickets and mails by people who never see which form their client
 * produced.
 *
 * `appRoot.ts` is therefore no longer load-bearing for correctness — either
 * prefix would now do — but it is still correct, still used by six spec files,
 * and simplifying it is a separate change from proving the bug is gone.
 */

import { expect, test } from '@playwright/test'
import {
	expectRouteMatched,
	gotoAppRoute,
	resolveAppRoot,
} from '../support/appRoot.ts'

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

	test('BOTH prefixes resolve the route — the distinction no longer exists', async ({
		page,
	}) => {
		// This was a POSITIVE CONTROL asserting the OTHER prefix fell through to
		// the app root. It was correct when the router base came from
		// `generateUrl('/apps/integriq')`, which returns only the form the
		// instance is configured for, leaving the other form outside the base.
		//
		// `routerBase()` now derives the base from `window.location.pathname`, so
		// it always matches the URL the visitor actually arrived on and BOTH forms
		// resolve. The old control failed against that fix, exactly as its own
		// closing comment predicted it would: "if this assertion ever fails, the
		// prefix distinction has stopped mattering".
		//
		// So the assertion is inverted rather than deleted. The guard still has a
		// job — it is now what proves the swallowed deep link cannot come back.
		for (const prefix of BOTH_PREFIXES) {
			await page.goto(`${prefix}${ROUTE}`, {
				waitUntil: 'domcontentloaded',
				timeout: 30_000,
			})

			// The SPA must have mounted — otherwise this proves nothing about
			// routing, only that the page failed to load.
			await expect(
				page
					.locator('#app-content, [data-cy=app-content], .app-content')
					.first(),
				`${prefix}${ROUTE} must serve the SPA shell`,
			).toBeVisible({ timeout: 15_000 })

			// …and the router must have KEPT the route. A redirect to the app
			// root is the silent deep-link swallow this app already paid for once.
			expect(
				new URL(page.url()).pathname,
				`${prefix}${ROUTE} was redirected to the app root — the deep link was swallowed`,
			).toContain(ROUTE)

			await expect(
				page.getByRole('heading', { name: 'Dashboard', level: 2 }),
				`${prefix}${ROUTE} landed on the Dashboard`,
			).toHaveCount(0)
		}
	})
})
