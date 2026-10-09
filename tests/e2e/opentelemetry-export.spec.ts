/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md
 * (REQ-OTEL-005, export is configured by an administrator).
 *
 * What an administrator does here: open the integriq admin settings, switch
 * trace export on with a collector address and a sampling share, and save.
 * An address that is not https is refused unless it is marked as internal.
 *
 * The export itself (a POST to the collector from a background job) is
 * covered by PHPUnit: a browser cannot see a cron job's outbound call.
 */

import { expect, test } from '@playwright/test'

const CONFIG_URL = '/index.php/apps/integriq/api/admin/otel'
const ADMIN_SETTINGS_URL = '/index.php/settings/admin/integriq'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('opentelemetry export settings', () => {
	test.afterEach(async ({ request }) => {
		await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { enabled: false, endpoint: '', samplingRatio: 0.1 },
		})
	})

	// @e2e execution-trace::an-administrator-switches-export-on
	test('an administrator switches export on', async ({ page, request }) => {
		await page.goto(ADMIN_SETTINGS_URL, { waitUntil: 'domcontentloaded' })
		const section = page.getByTestId('admin-otel-section')
		await expect(section).toBeVisible({ timeout: 20_000 })

		await page
			.getByTestId('admin-otel-endpoint')
			.locator('input')
			.fill('https://otel.example.org:4318')
		await page.getByTestId('admin-otel-sampling').locator('input').fill('10')
		await page
			.getByTestId('admin-otel-enabled')
			.locator('input')
			.check({ force: true })
		await page.getByTestId('admin-otel-save').click()

		await expect
			.poll(
				async () => {
					const stored = await request.get(CONFIG_URL, {
						headers: HEADERS,
					})
					return stored.json()
				},
				{ timeout: 10_000 },
			)
			.toMatchObject({
				enabled: true,
				endpoint: 'https://otel.example.org:4318',
				samplingRatio: 0.1,
			})
	})

	test('a plain http collector is refused unless marked internal', async ({
		request,
	}) => {
		const refused = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: { enabled: true, endpoint: 'http://collector:4318' },
		})
		expect(refused.status()).toBe(400)

		const internal = await request.put(CONFIG_URL, {
			failOnStatusCode: false,
			headers: HEADERS,
			data: {
				enabled: true,
				endpoint: 'http://collector:4318',
				allowLocal: true,
			},
		})
		expect(internal.status()).toBe(200)
	})
})
