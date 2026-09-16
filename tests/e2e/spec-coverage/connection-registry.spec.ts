/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/connection-registry/specs/connection-registry/spec.md
 * The browser half of REQ-CONN-006 (the App connections overview) and
 * REQ-CONN-007 (Add integration and the link a source dialog).
 *
 * The backend scenarios (sync, resolver, reports, health job, link endpoint)
 * carry `@e2e exclude` in the spec and are proven by PHPUnit:
 * ConnectionRegistryServiceTest, ConnectionStatusResolverTest,
 * ConnectionProbeServiceTest, ConnectionEventListenersTest,
 * ConnectionHealthJobTest and ConnectionsControllerTest.
 *
 * NOT RUN in the PR that added it: the nightly Playwright run owns it. It needs
 * an instance where integriq's SyncConnectionDeclarations step has run, and it
 * asserts on page structure, not on which apps happen to declare connections.
 */
import { expect, test } from '@playwright/test'
import { appDialog } from '../support/dialogs.ts'
import { APP_BASE, assertNoAppErrors, navTo, trackErrors } from './_helpers.ts'

test.describe('App connections overview (connection-registry)', () => {
	// @e2e connection-registry::the-overview-lists-connection-rows-with-their-app
	test('the overview shows the connection columns, app included', async ({
		page,
	}) => {
		const sink = trackErrors(page)
		await navTo(page, 'App connections', '/connections')

		for (const column of [
			'App',
			'Connection',
			'Status',
			'Status message',
			'Last checked',
			'Settings',
		]) {
			await expect(
				page
					.getByRole('columnheader', {
						name: new RegExp(`^\\s*${column}\\s*$`, 'i'),
					})
					.first(),
				`column "${column}" must render`,
			).toBeVisible({ timeout: 15_000 })
		}

		assertNoAppErrors(sink)
	})

	// @e2e connection-registry::the-overview-offers-no-free-form-row
	test('the overview offers no Add button', async ({ page }) => {
		const sink = trackErrors(page)
		await navTo(page, 'App connections', '/connections')

		await expect(
			page.getByRole('button', { name: /Actions/i }).first(),
		).toBeVisible({ timeout: 15_000 })
		await expect(page.getByRole('button', { name: /^\s*Add\b/i })).toHaveCount(0)

		assertNoAppErrors(sink)
	})
})

test.describe('Add integration (connection-registry)', () => {
	// @e2e connection-registry::add-integration-opens-the-dialog
	test('Add integration opens the link a source dialog, without a key field', async ({
		page,
	}) => {
		const sink = trackErrors(page)
		await navTo(page, 'App connections', '/connections')

		await page
			.getByRole('button', { name: /Actions/i })
			.first()
			.click()
		await page
			.getByRole('menuitem', { name: /Add integration/i })
			.first()
			.click()

		const dialog = appDialog(page)
		await expect(dialog).toBeVisible({ timeout: 10_000 })
		await expect(
			dialog.getByTestId('link-source-dialog').or(dialog),
		).toContainText('Add integration')
		await expect(dialog.getByRole('combobox').first()).toBeVisible()
		// No free-form rows: the dialog has no text field to type a new connection key.
		await expect(dialog.getByRole('textbox', { name: /key/i })).toHaveCount(0)

		await dialog.getByRole('button', { name: /Cancel/i }).click()
		await expect(dialog).toBeHidden({ timeout: 10_000 })

		assertNoAppErrors(sink)
	})

	// @e2e connection-registry::the-link-query-opens-the-dialog-pre-filtered
	test('?app=dossiq&link=1 opens the dialog pre-filtered and drops link from the URL', async ({
		page,
	}) => {
		const sink = trackErrors(page)
		await page.goto(`${APP_BASE}/connections?app=dossiq&link=1`, {
			waitUntil: 'domcontentloaded',
		})

		const dialog = appDialog(page)
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await expect(dialog.getByTestId('link-source-app').or(dialog)).toContainText(
			'dossiq',
		)
		await expect
			.poll(() => new URL(page.url()).searchParams.has('link'), {
				timeout: 10_000,
			})
			.toBe(false)
		expect(new URL(page.url()).searchParams.get('app')).toBe('dossiq')

		assertNoAppErrors(sink)
	})
})
