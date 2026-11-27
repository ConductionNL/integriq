/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/sources-database-adapter
 *   Scenario: an administrator connects a back-office database
 *
 * An administrator opens a database source that declares the read statement
 * `zaken-sinds` and presses test: the first page of rows and the duration are
 * shown. That needs a database the instance can reach, so it runs only when
 * E2E_DB_HOST names one (with E2E_DB_NAME, E2E_DB_USER and, for PostgreSQL on
 * another port, E2E_DB_PORT); the table `zaken` is read, never written. A
 * password goes through the credential broker, so the account used here needs
 * none (trust or peer authentication on a disposable database).
 *
 * The rest holds on any instance: the panel shows where the source connects,
 * that a read-only account is enough, and a source whose database cannot be
 * reached says so instead of showing rows.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { gotoAppRoute } from './support/appRoot.ts'
import { withRequestToken } from './support/requestToken.ts'

const OR_BASE = '/index.php/apps/openregister/api/objects/integriq'

/** The keyset read every source here declares. */
const ZAKEN_SINDS = {
	name: 'zaken-sinds',
	kind: 'read',
	sql: 'SELECT id, zaaknummer FROM zaken WHERE id > :after ORDER BY id LIMIT :limit',
	parameters: [],
	pageKey: 'id',
	pageStart: 0,
}

/** The sources this run created. */
const created: string[] = []

/**
 * Create a database source through OpenRegister.
 *
 * @param request  The admin's request context.
 * @param name     A name unique to this run.
 * @param database The connection settings.
 * @return The source's id.
 */
async function seedDatabaseSource(
	request: APIRequestContext,
	name: string,
	database: Record<string, unknown>,
): Promise<string> {
	const headers = await withRequestToken(request)
	const res = await request.post(`${OR_BASE}/source`, {
		headers,
		failOnStatusCode: false,
		data: {
			name,
			description: 'Seeded by tests/e2e/database-source.spec.ts',
			type: 'database',
			location: '',
			isEnabled: true,
			database,
			statements: [ZAKEN_SINDS],
		},
	})
	expect(res.status(), `create the source: ${await res.text()}`).toBeLessThan(300)
	const body = await res.json()
	const id = String(body.id ?? body['@self']?.id ?? body.uuid ?? '')
	expect(id).not.toBe('')
	created.push(id)
	return id
}

/**
 * Open the source page and wait for the database panel.
 *
 * @param page The page.
 * @param id   The source.
 * @return The panel.
 */
async function openPanel(page: Page, id: string) {
	await gotoAppRoute(page, `/sources/${id}`)
	// CnBodySections sets its own data-testid on the section's root element,
	// which replaces the component's `database-source-panel`.
	const panel = page.getByTestId('cn-body-section-component-src-database')
	await expect(panel).toBeVisible({ timeout: 20_000 })
	return panel
}

test.describe('sources-database-adapter: the database panel on the source page', () => {
	test.afterAll(async ({ request }) => {
		const headers = await withRequestToken(request)
		for (const id of created) {
			await request.delete(`${OR_BASE}/source/${id}`, { headers, failOnStatusCode: false })
		}
	})

	test('the panel shows the connection and a source that cannot be reached says so', async ({ page }) => {
		// Port 1 on the loopback answers nothing, so the connection is refused at once.
		const id = await seedDatabaseSource(page.request, `e2e-db-unreachable-${Date.now()}`, {
			driver: 'pdo_pgsql',
			host: '127.0.0.1',
			port: 1,
			dbname: 'zaken',
			user: 'integriq_read',
			timeout: 5,
		})
		const panel = await openPanel(page, id)

		await expect(panel.getByTestId('database-source-connection')).toHaveText('Database zaken on 127.0.0.1:1 as integriq_read')
		await expect(panel.getByTestId('database-source-account-advice')).toHaveText('This source only reads. Connect with a read-only account.')
		await expect(panel.locator('[data-statement="zaken-sinds"] code')).toHaveText(ZAKEN_SINDS.sql)

		const answered = page.waitForResponse((res) => res.url().includes(`/api/sources/${id}/database/statements/zaken-sinds/test`))
		await panel.getByTestId('database-source-test-zaken-sinds').click()
		expect((await answered).status(), 'a refusal, not a crash').toBe(400)

		const alert = panel.locator('[data-statement="zaken-sinds"] [role="alert"]')
		await expect(alert).toBeVisible()
		await expect(alert).not.toHaveText('')
		await expect(panel.getByTestId('database-source-test-rows')).toHaveCount(0)
	})

	test('an administrator connects a back-office database', async ({ page }) => {
		const host = process.env.E2E_DB_HOST ?? ''
		test.skip(host === '', 'needs a reachable database with a zaken table: set E2E_DB_HOST, E2E_DB_NAME and E2E_DB_USER')

		const id = await seedDatabaseSource(page.request, `e2e-db-zaken-${Date.now()}`, {
			driver: process.env.E2E_DB_DRIVER ?? 'pdo_pgsql',
			host,
			port: Number(process.env.E2E_DB_PORT ?? 5432),
			dbname: process.env.E2E_DB_NAME ?? 'zaken',
			user: process.env.E2E_DB_USER ?? 'integriq_read',
		})
		const panel = await openPanel(page, id)

		await panel.getByTestId('database-source-test-zaken-sinds').click()

		await expect(panel.getByTestId('database-source-test-summary')).toHaveText(/^Rows on the first page: \d+, in \d+ ms\./, { timeout: 30_000 })
		await expect(panel.locator('[data-testid="database-source-test-rows"] th').first()).toHaveText('id')

		await panel.getByTestId('database-source-discover').click()
		await expect(panel.locator('[data-testid="database-source-tables"] li[data-table="zaken"]')).toBeVisible()
	})
})
