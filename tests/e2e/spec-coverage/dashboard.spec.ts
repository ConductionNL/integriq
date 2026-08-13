/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Genuine behavioral UI coverage for the openconnector Dashboard page.
 *
 * The Dashboard (manifest type "dashboard") renders KPI stats-block
 * widgets (Sources / Mappings / etc.) plus chart widgets (outgoing calls,
 * job executions, synchronization runs) behind a date-range picker. These
 * tests navigate to it via the real nav-click, assert the heading and the
 * KPI/chart surface render, and that no openconnector-origin error or 5xx
 * occurs.
 */
import { test } from '@playwright/test'
import {
	navTo,
	trackErrors,
	assertNoAppErrors,
	expectHeading,
	APP_BASE,
} from './_helpers'

test.describe('Dashboard — index surface', () => {
	// @e2e openconnector-comprehensive-tests::dashboard-page-mounts
	test('Dashboard renders heading and KPI widgets via nav-click', async ({
		page,
	}) => {
		const sink = trackErrors(page)
		await navTo(page, 'Dashboard', '/apps/openconnector')
		await expectHeading(page, /^Dashboard$/)

		// KPI stats-block widgets render inside main as clickable stat links
		// labelled "<Title> <count>" (e.g. "Sources 0", "Mappings 0"). They
		// load asynchronously after the route settles, so wait for the first
		// KPI link to paint before counting.
		const firstKpi = page
			.locator('main')
			.getByRole('link', {
				name: /Sources|Mappings|Synchronizations|Jobs|Endpoints/i,
			})
			.first()
		await test
			.expect(
				firstKpi,
				'a KPI stat-block widget link should render in the dashboard',
			)
			.toBeVisible({ timeout: 20_000 })

		assertNoAppErrors(sink)
	})

	// @e2e openconnector-comprehensive-tests::dashboard-charts-render
	test('Dashboard chart widgets render their headings', async ({ page }) => {
		const sink = trackErrors(page)
		// The dashboard's chart widgets poll their data sources, so `networkidle`
		// never settles and the goto can hit the test timeout. Wait for the DOM,
		// then assert on the chart heading itself (the real signal).
		await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
		// Chart widget headings from the dashboard manifest config.
		const chart = page
			.getByRole('heading', {
				name: /Outgoing calls|Job executions|Synchronization runs/i,
			})
			.first()
		await test
			.expect(chart, 'a chart widget heading should be visible')
			.toBeVisible({ timeout: 20_000 })
		assertNoAppErrors(sink)
	})
})
