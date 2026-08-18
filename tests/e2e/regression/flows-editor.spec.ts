/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Flows surface regression — the ADR-096 index page and the consolidated
 * flow editor, as this app renders them.
 *
 * Two defects this spec pins down, both found live on 2026-08-18:
 *
 *   - `/flows` was the one page in the app that looked like a different
 *     product: the deprecated `CnFlowIndexPage` bare table, with none of
 *     `CnIndexPage`'s chrome. It is now an ordinary index page, and the
 *     "New flow" button renders — `CnIndexPage`'s `#header-actions` slot
 *     was documented from the start and wired to nothing, so the button
 *     shipped into the void while the page looked fine.
 *
 *   - "New flow" rendered an empty-state note instead of the editor. It now
 *     renders the SAME builder as an existing flow, holding only the seeded
 *     manual-trigger start node, with the toolbar on the canvas.
 *
 * @spec openspec/specs/flow-orchestration/spec.md#REQ-017
 */
import { expect, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { expectRouteMatched, gotoAppRoute } from '../support/appRoot.ts'

const RUN_ID = `e2e-ocflow-${Date.now().toString(36)}`

// Whether the INSTALLED library carries the consolidated editor (toolbar +
// seeded start node). Feature-detected on the source the bundle was built
// from, not on a version number: the transition window installs 2.3.x from
// npm while dev instances may run a synced pre-release tree, and a version
// string cannot tell those apart. Self-clears on the lockfile bump.
const NEW_EDITOR = (() => {
	try {
		return fs
			.readFileSync(
				path.resolve(
					__dirname,
					'../../../node_modules/@conduction/nextcloud-vue/src/components/CnFlowDetail/CnFlowDetail.vue',
				),
				'utf8',
			)
			.includes('cn-flow-detail__toolbar')
	} catch {
		return false
	}
})()

// Flows persist in OpenRegister's one flow store; cleanup goes to its API.
// `OCS-APIRequest` is what lets an API call through the CSRF check that a
// browser-session request would otherwise trip (see openregister's
// flow-engine.spec.ts for the long form of this note).
const API_HEADERS = {
	'OCS-APIRequest': 'true',
	Authorization: `Basic ${Buffer.from(
		`${process.env.NC_ADMIN_USER || 'admin'}:${process.env.NC_ADMIN_PASS || 'admin'}`,
	).toString('base64')}`,
}

test.use({ extraHTTPHeaders: { ...API_HEADERS } })

test.describe('the Flows surface', () => {
	test('the list is an ordinary index page with a New flow action (ADR-096)', async ({
		page,
	}) => {
		// `gotoAppRoute`, not a literal path: on a stack without pretty URLs
		// the router's base is `/index.php/apps/openconnector`, and a literal
		// `/apps/openconnector/flows` mounts the SPA whose router then cannot
		// match the path — the catch-all lands it on the Dashboard, which is
		// exactly what this spec's first CI run photographed.
		await gotoAppRoute(page, '/flows')
		await expectRouteMatched(page, '/flows')

		// CnIndexPage chrome, not the deprecated bespoke table.
		await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 20000 })
		await expect(page.getByRole('button', { name: 'New flow' })).toBeVisible({
			timeout: 15000,
		})
	})

	test('a new flow is the SAME editor holding only a starting point', async ({
		page,
	}) => {
		test.skip(
			!NEW_EDITOR,
			'requires the flow-editor consolidation (@conduction/nextcloud-vue ≥ 2.4) — self-clears on the lockfile bump',
		)
		await gotoAppRoute(page, '/flows/new')
		await expectRouteMatched(page, '/flows/new')

		// The toolbar is the editor's identity — the actions that concern the
		// graph, on the graph.
		const toolbar = page.getByRole('toolbar', { name: 'Flow editor' })
		await expect(toolbar).toBeVisible({ timeout: 20000 })
		await expect(toolbar.getByRole('button', { name: 'Save' })).toBeVisible()
		// The engine runs the STORED flow, so an unsaved one cannot run.
		await expect(toolbar.getByRole('button', { name: 'Run' })).toBeDisabled()

		// The seeded start node — never the "No steps yet" empty state that
		// made creating look like a different product from editing.
		await expect(
			page.locator('.cn-flow-detail__node', {
				hasText: 'When someone runs it',
			}),
		).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('No steps yet')).toHaveCount(0)

		// The palette offers the catalogue; an in-flight catalogue must not be
		// reported as an unreadable one (the failure text used to show on
		// every first paint of this route).
		await expect(
			page.locator('.cn-flow-sidebar__palette-item').first(),
		).toBeVisible({ timeout: 15000 })
		await expect(page.getByText('could not be read')).toHaveCount(0)
	})

	test('saving a new flow swaps the route to the minted id', async ({
		page,
		request,
	}) => {
		test.skip(
			!NEW_EDITOR,
			'requires the flow-editor consolidation (@conduction/nextcloud-vue ≥ 2.4) — self-clears on the lockfile bump',
		)
		await gotoAppRoute(page, '/flows/new')
		await expectRouteMatched(page, '/flows/new')

		const toolbar = page.getByRole('toolbar', { name: 'Flow editor' })
		await expect(toolbar.getByRole('button', { name: 'Save' })).toBeEnabled({
			timeout: 20000,
		})

		// Name the flow after this run so a failed cleanup is identifiable.
		await page.getByRole('tab', { name: 'Flow' }).click()
		await page.getByLabel('Name').first().fill(`${RUN_ID} minted`)

		await toolbar.getByRole('button', { name: 'Save' }).click()

		// `replace`, not `push`: Back must still mean "the page before the
		// editor", and a reload must not land on `new` again.
		await expect(page).not.toHaveURL(/\/flows\/new$/, { timeout: 15000 })
		const minted = page.url().match(/\/flows\/([0-9a-f-]{36})/)?.[1]
		expect(minted, `minted id in ${page.url()}`).toBeTruthy()

		// Run is the observable difference between stored and unsaved.
		await expect(toolbar.getByRole('button', { name: 'Run' })).toBeEnabled({
			timeout: 15000,
		})

		// This suite cleans up what it mints.
		const del = await request.delete(`/apps/openregister/api/flows/${minted}`)
		expect(del.status()).toBe(200)
	})
})
