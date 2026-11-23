/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * connectors-digipoort-sbr-filing, REQ-DPS-004: an administrator opens the
 * Digipoort filings page and checks a filing, which shows the statuses
 * delivered and accepted with their times.
 *
 * The demo filing for Adviesbureau Kade B.V. is on-demand demo data, so an
 * install seeds no filing. This spec seeds the same filing through
 * OpenRegister's objects API, with its own delivery reference, so the page
 * under test is the production one. The record is removed at the end.
 *
 * The scenarios carrying `@e2e exclude` in the spec (the delivery itself,
 * the status polling and the refusals) are covered by PHPUnit with a
 * scripted log provider and are deliberately not restated.
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-an-administrator-sees-every-filing-and-its-status-req-dps-004
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_BASE, assertNoAppErrors, trackErrors } from './spec-coverage/_helpers.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

/**
 * Seed one accepted SBR filing, shaped as the demo filing.
 *
 * @param request The request fixture.
 * @param deliveryReference The delivery reference this record carries.
 * @return The record's OpenRegister id.
 */
async function seedFiling(request: APIRequestContext, deliveryReference: string): Promise<string> {
	const resp = await request.post(`${OR_BASE}/sbr_filing`, {
		failOnStatusCode: false,
		data: {
			objectUri: '/objects/vat-return/00000000-0000-0000-0000-0000000000a3',
			objectType: 'VATReturn',
			sourceApp: 'shillinq',
			filingType: 'ob-aangifte',
			recipient: 'belastingdienst',
			payloadFileUri: '/admin/files/Belastingaangiften/Adviesbureau Kade BV OB 2026-Q3.xbrl',
			identityNumber: '001234567B01',
			identityType: 'Fi',
			deliveryReference,
			status: 'accepted',
			statusHistory: [
				{ status: 'delivered', code: '', description: '', at: '2026-10-28T10:14:02+01:00', errors: [] },
				{ status: 'accepted', code: '500', description: 'Verwerking gelukt', at: '2026-10-28T10:24:37+01:00', errors: [] },
			],
			errors: [],
			attempts: [{ at: '2026-10-28T10:14:02+01:00', error: '' }],
			channelStatusCount: 2,
		},
	})
	expect(resp.status(), 'seeding an SBR filing must succeed').toBeLessThan(300)
	const body = await resp.json()

	return String(body.id ?? body.uuid)
}

test.describe('Digipoort filings', () => {
	test('an administrator checks a filing', async ({ page, request }) => {
		const reference = `LOG-SBR-E2E-${Date.now()}`
		const id = await seedFiling(request, reference)

		try {
			const sink = trackErrors(page)
			await page.goto(`${APP_BASE}/messages/digipoort`, { waitUntil: 'domcontentloaded' })

			const row = page.getByRole('row').filter({ hasText: reference }).first()
			await expect(row).toBeVisible({ timeout: 20_000 })
			await expect(row, 'the row names the recipient').toContainText(/belastingdienst/i)
			await expect(row, 'the row carries the current status').toContainText(/accepted/i)

			await row.click()
			await expect(
				page.getByText('Verwerking gelukt').first(),
				'the detail shows what the recipient answered',
			).toBeVisible({ timeout: 20_000 })
			await expect(page.getByText(/delivered/i).first(), 'the detail lists the delivered status').toBeVisible()
			await expect(page.getByText(/10:14/).first(), 'the delivered status carries its time').toBeVisible()
			await expect(page.getByText(/10:24/).first(), 'the accepted status carries its time').toBeVisible()

			assertNoAppErrors(sink)
		} finally {
			await request.delete(`${OR_BASE}/sbr_filing/${id}`, { failOnStatusCode: false })
		}
	})
})
