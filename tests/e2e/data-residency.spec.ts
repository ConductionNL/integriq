/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md
 *
 * What an administrator does on the outbound destinations page: reach it
 * from the Connections menu, record where a partner sits, see the verdict
 * read "EU only" once every destination is in the EU, and find EU-only mode
 * refused, naming the source, while one destination has no residency.
 *
 * The page lists every enabled source on the instance and every call-log host
 * of the last 30 days that no source covers. On a shared instance other specs
 * leave enabled sources behind, so the "EU only" scenario first records an EU
 * country on every source the page lists; a call-log host without a source
 * cannot be given a country, and when one is listed that scenario is marked as
 * expected to fail with the reason. The scenarios marked `@e2e exclude` in the
 * spec are covered by PHPUnit.
 *
 * Base URL and container come from the environment (tests/e2e/playwright.config.ts).
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE, navTo } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const API_BASE = `${APP_BASE}/api`
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * A destination row as GET /api/egress/destinations answers it.
 */
interface Destination {
	sourceId: string | null
	name?: string
	host?: string
	country?: string | null
	zone: string
}

/**
 * Seed one enabled source.
 *
 * @param request The Playwright request context.
 * @param name A name unique to the calling test.
 * @param host The host the source calls.
 * @return The source's id.
 */
async function seedSource(request: APIRequestContext, name: string, host: string): Promise<string> {
	const resp = await request.post(`${OR_BASE}/source`, {
		failOnStatusCode: false,
		headers: HEADERS,
		data: {
			name,
			description: 'Seeded by tests/e2e/data-residency.spec.ts',
			type: 'api',
			isEnabled: true,
			location: `https://${host}/api`,
		},
	})
	expect(resp.status(), await resp.text()).toBeLessThan(300)
	const body = (await resp.json()) as Record<string, unknown>

	return String(body.id ?? (body['@self'] as Record<string, string>)?.id)
}

/**
 * Record a residency on a source through the endpoint the dialog uses.
 *
 * @param request The Playwright request context.
 * @param id The source.
 * @param country The ISO country code.
 */
async function setResidency(request: APIRequestContext, id: string, country: string): Promise<void> {
	const resp = await request.put(`${API_BASE}/sources/${id}/residency`, {
		failOnStatusCode: false,
		headers: HEADERS,
		data: { country, evidence: 'e2e: verwerkersovereenkomst 2026-03, art. 4' },
	})
	expect(resp.status(), await resp.text()).toBe(200)
}

/**
 * The destinations the page lists.
 *
 * @param request The Playwright request context.
 * @return The rows.
 */
async function destinations(request: APIRequestContext): Promise<Destination[]> {
	const resp = await request.get(`${API_BASE}/egress/destinations`, { headers: HEADERS })
	expect(resp.status()).toBe(200)

	return ((await resp.json()) as { destinations: Destination[] }).destinations
}

/**
 * Open the destinations page from the Connections menu.
 *
 * @param page The page.
 */
async function openFromMenu(page: Page): Promise<void> {
	await navTo(page, 'Outbound destinations', '/egress-destinations')
	await expect(page.getByTestId('egress-destinations-page')).toBeVisible()
}

test.describe.serial('data residency', () => {
	const stamp = Date.now()
	let partner = ''

	test.beforeAll(async ({ request }) => {
		partner = await seedSource(request, `E2E zgw-zaken ${stamp}`, `zaken-${stamp}.e2e.invalid`)
	})

	test.afterAll(async ({ request }) => {
		// Switch the mode off again so no other spec's outbound call is refused.
		await request.put(`${API_BASE}/egress/eu-only`, { failOnStatusCode: false, headers: HEADERS, data: { enabled: false } })
	})

	test('an administrator records where a partner sits', async ({ page }) => {
		// @e2e connections-declare-data-residency/data-residency::an-administrator-records-where-a-partner-sits
		await openFromMenu(page)
		const row = page.getByTestId('egress-table').getByRole('row').filter({ hasText: `E2E zgw-zaken ${stamp}` })
		await row.getByTestId('egress-set-residency').click()

		const dialog = page.getByTestId('source-residency-dialog')
		await expect(dialog).toBeVisible()
		await dialog.getByTestId('source-residency-country').click()
		await page.getByRole('option', { name: /Netherlands|Nederland|NL/ }).first().click()
		await dialog.getByTestId('source-residency-evidence').locator('input, textarea').first().fill('verwerkersovereenkomst 2026-03, art. 4')
		await dialog.getByTestId('source-residency-save').click()
		await expect(dialog).toBeHidden()

		const mine = (await destinations(page.request)).find((d) => d.sourceId === partner)
		expect(mine?.country).toBe('NL')
		expect(mine?.zone).toBe('eu')
		await expect(row).toContainText('NL')
	})

	test('every destination is in the EU', async ({ page }) => {
		// @e2e connections-declare-data-residency/data-residency::every-destination-is-in-the-eu
		const rows = await destinations(page.request)
		const unsourced = rows.filter((d) => d.sourceId === null)
		test.fail(unsourced.length > 0, `The call log names ${unsourced.length} host(s) no source covers; they cannot be given a country.`)
		const countries = ['NL', 'DE', 'FR']
		let index = 0
		for (const row of rows) {
			if (row.sourceId !== null && row.zone !== 'eu') {
				await setResidency(page.request, row.sourceId, countries[index++ % countries.length])
			}
		}

		await openFromMenu(page)
		await expect(page.getByTestId('egress-verdict')).toContainText('EU only')
		for (const row of await destinations(page.request)) {
			expect(row.zone, `${row.name ?? row.host} is in the EU`).toBe('eu')
		}
	})

	test('the mode cannot be switched on over a gap', async ({ page }) => {
		// @e2e connections-declare-data-residency/data-residency::the-mode-cannot-be-switched-on-over-a-gap
		const gap = `E2E no residency ${stamp}`
		await seedSource(page.request, gap, `gap-${stamp}.e2e.invalid`)

		await openFromMenu(page)
		await expect(page.getByTestId('egress-verdict')).toContainText('Not proven')
		await page.getByTestId('egress-eu-only').click()

		await expect(page.getByTestId('egress-error')).toContainText(gap)
		await expect(page.getByTestId('egress-eu-only').locator('input')).not.toBeChecked()
		const mode = await page.request.get(`${API_BASE}/egress/destinations`, { headers: HEADERS })
		expect(((await mode.json()) as { euOnly: boolean }).euOnly).toBe(false)
	})
})
