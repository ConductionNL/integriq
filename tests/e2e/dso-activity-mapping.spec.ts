/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md
 * (REQ-DSO-012, administrators maintain the activity table).
 *
 * What an administrator does here: open the DSO activities section on the
 * admin settings page, add a row with two case types, edit it, deactivate it,
 * and map an activity that arrived on a verzoek no row maps.
 *
 * The matching at intake is covered by PHPUnit through DsoIngestService.
 *
 * The text fields are located by their test id directly. NcTextField in
 * @nextcloud/vue 9 passes `data-testid` through to its <input>, so the id
 * already names the input and `.locator('input')` below it finds nothing.
 * First run on a live instance: 2026-10-04 (task 6.1).
 */

import { expect, test } from '@playwright/test'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
// One imow-id per run: the test leaves its deactivated row behind, and a fixed
// id would make the row filter match two rows on the next run.
const IMOW_MILIEU = `nl.imow-gm0000.activiteit.E2eMilieu${Date.now().toString(36)}`
const IMOW_UNMAPPED = 'nl.imow-gm0000.activiteit.E2eUnmapped'

test.describe('dso activity mapping', () => {
	// @e2e dso-omgevingsloket::add-a-row-with-two-zaaktypen
	test('add a row with two case types, edit it, deactivate it', async ({
		page,
	}) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.getByTestId('admin-dso-activities-section')
		await expect(section).toBeVisible({ timeout: 20_000 })

		await section.getByTestId('admin-dso-activities-add').click()
		const dialog = page.getByTestId('dso-activity-dialog')
		await dialog.getByTestId('dso-activity-name').fill('E2E milieu')
		await dialog.getByTestId('dso-activity-imow-id').fill(IMOW_MILIEU)
		await dialog
			.getByTestId('dso-activity-case-type-reference')
			.first()
			.fill('E2E-MILIEU')
		await dialog.getByTestId('dso-activity-add-case-type').click()
		await dialog
			.getByTestId('dso-activity-case-type-reference')
			.nth(1)
			.fill('E2E-BOUWEN')
		await dialog.getByTestId('dso-activity-save').click()

		const row = section
			.getByTestId('admin-dso-activities-row')
			.filter({ hasText: IMOW_MILIEU })
		await expect(row).toContainText('E2E-MILIEU, E2E-BOUWEN', {
			timeout: 20_000,
		})

		await row.getByTestId('admin-dso-activities-edit').click()
		await dialog
			.getByTestId('dso-activity-case-type-reference')
			.nth(1)
			.fill('E2E-BOUWEN-2')
		await dialog.getByTestId('dso-activity-save').click()
		await expect(row).toContainText('E2E-BOUWEN-2', { timeout: 20_000 })

		await row.getByTestId('admin-dso-activities-toggle').click()
		await expect(row).toContainText('No', { timeout: 20_000 })
	})

	// @e2e dso-omgevingsloket::an-unmapped-activity-can-be-mapped-from-the-list
	test('an unmapped activity opens the add dialog prefilled', async ({
		page,
		request,
	}) => {
		const seed = await request.post(`${OR_BASE}/dso_verzoek`, {
			failOnStatusCode: false,
			data: {
				verzoekId: `e2e-unmapped-${Date.now()}`,
				status: 'mapped',
				receivedAt: new Date().toISOString(),
				activityUnmapped: true,
				mappedCaseTypes: [],
				mappedActivities: [
					{
						imowId: IMOW_UNMAPPED,
						activityId: 'E2E-0000-Unmapped',
						activityName: 'E2E unmapped',
						mapped: false,
					},
				],
			},
		})
		expect(seed.status(), 'seeding a verzoek must succeed').toBeLessThan(300)

		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const unmapped = page
			.getByTestId('admin-dso-unmapped-row')
			.filter({ hasText: IMOW_UNMAPPED })
		await expect(unmapped).toBeVisible({ timeout: 20_000 })

		await unmapped.getByTestId('admin-dso-unmapped-map').click()
		const dialog = page.getByTestId('dso-activity-dialog')
		await expect(dialog.getByTestId('dso-activity-imow-id')).toHaveValue(
			IMOW_UNMAPPED,
		)
		await expect(dialog.getByTestId('dso-activity-activity-id')).toHaveValue(
			'E2E-0000-Unmapped',
		)
		await expect(dialog.getByTestId('dso-activity-name')).toHaveValue(
			'E2E unmapped',
		)
	})
})
