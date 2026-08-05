/*
 * SPDX-FileCopyrightText: 2026 OpenConnector Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Visual-regression baselines for OpenConnector's key surfaces (GAP-5).
 *
 * Run:    npx playwright test --project visual
 * Update: npx playwright test --project visual --update-snapshots
 *
 * Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
 * See _visual-helpers.ts for the platform-rendering caveat.
 */
import { test } from '@playwright/test'
import { shootSurface } from './_visual-helpers'

const APP = '/index.php/apps/openconnector'

test.describe('OpenConnector — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}/#/`, 'dashboard.png')
	})
})
