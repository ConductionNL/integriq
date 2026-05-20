/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chain E regression: end-to-end user journeys (UI-driven).
 *
 * Counterpart to the Newman API suite (folder 13 of
 * `tests/postman/openconnector.postman_collection.json`). Where Newman
 * exercises the HTTP surface, this spec drives the openconnector Vue
 * frontend — the nc-vue manifest-renderer (`CnIndexPage` /
 * `CnFormDialog`) — so a visual click-through is what creates the
 * objects. The dialog's Save handler posts to OR's
 * `/api/objects/openconnector/{schema}/*` under the hood, so a green
 * run guarantees the full UI → nc-vue → OR backend → list-refresh loop
 * is intact.
 *
 *   J1  Source        — `/sources` index → Add → fill name → Create.
 *   J2  Mapping       — `/mappings` index → Add → fill name → Create.
 *   J3  Synchronization — `/synchronizations` index → Add → fill name → Create.
 *   J4  Endpoint      — `/endpoints` index → Add → fill name → Create.
 *
 * Each journey:
 *   1. Navigates to the section's deep-link route.
 *   2. Clicks the primary "Add {schema}" button on `CnActionsBar`.
 *   3. Fills the name field in the schema-driven `CnFormDialog`.
 *   4. Clicks the primary "Create" button.
 *   5. Waits for the OR `POST` to come back 200/201.
 *   6. Asserts the newly-created row text appears in the index table.
 *   7. Cleans up by clicking through the UI: tick the row checkbox →
 *      Actions menu → "Delete selected" → confirm in CnMassDeleteDialog.
 *      Both create AND delete go through nc-vue's components against the
 *      OR backend, so the suite is end-to-end UI-driven.
 */

import { test, expect, Page } from '@playwright/test'

const NEXTCLOUD = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

const OR = '/index.php/apps/openregister/api/objects/openconnector'

/**
 * Drive a CnIndexPage create flow:
 *   - click the "Add {schema}" primary button
 *   - fill in the name field of the CnFormDialog
 *   - click Create
 *   - wait for the OR POST to return success
 *   - assert the new row text appears in the page
 */
async function createViaUi(page: Page, schemaSlug: string, schemaTitle: string, name: string) {
	// Locate and click the Add button. CnActionsBar renders the primary
	// action as `<NcButton type="primary">Add {schemaTitle}</NcButton>`
	// (label derived from schema.title).
	const addBtn = page.getByRole('button', { name: new RegExp(`Add\\s+${schemaTitle}`, 'i') })
	await expect(addBtn, `Add ${schemaTitle} button must be visible on the index page`).toBeVisible()
	await addBtn.click()

	// CnFormDialog opens as an NcDialog. Wait for the dialog role.
	const dialog = page.getByRole('dialog').first()
	await expect(dialog, 'CnFormDialog opened after clicking Add').toBeVisible()

	// Every openconnector schema exposes a top-level `name` field as the
	// title — the form renders it via NcTextField with label "Name".
	// Falling back to the first text input gives us a safety net if the
	// label text shifts.
	const nameField = dialog.getByLabel(/^Name/i).first()
	if (await nameField.count() > 0) {
		await nameField.fill(name)
	} else {
		await dialog.locator('input[type="text"]').first().fill(name)
	}

	// Click the primary action — "Create" in create-mode (resolved by
	// CnFormDialog when there's no item to edit).
	const createBtn = dialog.getByRole('button', { name: /^Create$/ })
	await expect(createBtn, 'Create button must be visible in form dialog').toBeVisible()

	// Wait for the OR POST to settle while we click. This is the bridge
	// from UI click → nc-vue store → OR backend.
	const [response] = await Promise.all([
		page.waitForResponse(r =>
			r.url().includes(`/api/objects/openconnector/${schemaSlug}`) &&
			r.request().method() === 'POST'
		, { timeout: 15_000 }),
		createBtn.click(),
	])
	expect([200, 201], `OR POST for ${schemaSlug} returned ${response.status()}`).toContain(response.status())

	// Dialog should dismiss; list re-fetches. Give the index a moment to
	// refresh, then assert the new name appears in the table.
	await expect(dialog).toBeHidden({ timeout: 10_000 })
	await expect(
		page.getByText(name).first(),
		`new ${schemaSlug} row "${name}" must appear in the refreshed index`,
	).toBeVisible({ timeout: 10_000 })
}

/**
 * Drive the mass-delete flow:
 *   - tick the row checkbox (NcDataTable renders each row's selector as
 *     a checkbox inside `getByRole('row')`)
 *   - open the Actions menu in `CnActionsBar` (NcActions, menu-name "Actions")
 *   - click "Delete selected" (label set in CnActionsBar:91-98)
 *   - confirm in `CnMassDeleteDialog` (red NcButton labelled "Delete",
 *     confirmLabel default per CnMassDeleteDialog:144)
 *   - wait for OR DELETE to settle
 *   - assert the row is gone from the table
 */
async function deleteViaUi(page: Page, schemaSlug: string, name: string) {
	// 1. Find the row and tick its checkbox. NcDataTable rows are
	// accessible <tr> with role="row" — we match by the row's
	// accessible name (contains the visible cells, so the name shows up).
	const row = page.getByRole('row', { name: new RegExp(name) }).first()
	await expect(row, `target row "${name}" must be selectable`).toBeVisible({ timeout: 10_000 })
	const rowCheckbox = row.getByRole('checkbox').first()
	await rowCheckbox.check({ force: true })

	// 2. Open the Actions menu (NcActions menu-name="Actions"; the
	// button is keyboard-focusable with that accessible name).
	await page.getByRole('button', { name: 'Actions' }).first().click()

	// 3. Click "Delete selected" inside the menu.
	const massDeleteItem = page.getByRole('menuitem', { name: /Delete selected/i })
	await expect(massDeleteItem, '"Delete selected" menu item must be enabled when a row is checked').toBeVisible()
	await massDeleteItem.click()

	// 4. CnMassDeleteDialog opens. Confirm with the destructive primary button.
	const confirmDialog = page.getByRole('dialog').filter({ hasText: /Delete Items/i }).first()
	await expect(confirmDialog, 'CnMassDeleteDialog opened').toBeVisible()
	const confirmBtn = confirmDialog.getByRole('button', { name: /^Delete$/ })

	// Wait for OR's DELETE on this schema to come back while we click.
	const [response] = await Promise.all([
		page.waitForResponse(r =>
			r.url().includes(`/api/objects/openconnector/${schemaSlug}`) &&
			r.request().method() === 'DELETE'
		, { timeout: 15_000 }),
		confirmBtn.click(),
	])
	expect([200, 202, 204], `OR DELETE for ${schemaSlug} returned ${response.status()}`).toContain(response.status())

	// 5. Dialog dismisses; row should disappear from the refreshed list.
	await expect(confirmDialog).toBeHidden({ timeout: 10_000 })
	await expect(
		page.getByText(name),
		`row "${name}" must be gone from the table after delete`,
	).toHaveCount(0, { timeout: 10_000 })
}

test.describe('UI journey J1 — visually create + delete a Source', () => {
	const name = `pw-j1-source-${Date.now()}`

	test('Add Source → Create → row appears → mass-delete → row gone', async ({ page }) => {
		await page.goto('/index.php/apps/openconnector/sources')
		await createViaUi(page, 'source', 'Source', name)
		await deleteViaUi(page, 'source', name)
	})
})

test.describe('UI journey J2 — visually create + delete a Mapping', () => {
	const name = `pw-j2-mapping-${Date.now()}`

	test('Add Mapping → Create → row appears → mass-delete → row gone', async ({ page }) => {
		await page.goto('/index.php/apps/openconnector/mappings')
		await createViaUi(page, 'mapping', 'Mapping', name)
		await deleteViaUi(page, 'mapping', name)
	})
})

test.describe('UI journey J3 — visually create + delete a Synchronization', () => {
	const name = `pw-j3-sync-${Date.now()}`

	test('Add Synchronization → Create → row appears → mass-delete → row gone', async ({ page }) => {
		await page.goto('/index.php/apps/openconnector/synchronizations')
		await createViaUi(page, 'synchronization', 'Synchronization', name)
		await deleteViaUi(page, 'synchronization', name)
	})
})

test.describe('UI journey J4 — visually create + delete an Endpoint', () => {
	const name = `pw-j4-endpoint-${Date.now()}`

	test('Add Endpoint → Create → row appears → mass-delete → row gone', async ({ page }) => {
		await page.goto('/index.php/apps/openconnector/endpoints')
		await createViaUi(page, 'endpoint', 'Endpoint', name)
		await deleteViaUi(page, 'endpoint', name)
	})
})

test.describe('UI smoke — SPA shell reachable at the deep-link routes', () => {
	for (const route of ['/', '/sources', '/endpoints', '/jobs', '/mappings', '/synchronizations', '/rules', '/cloud-events/events']) {
		test(`GET ${route} serves the Vue app`, async ({ page }) => {
			const res = await page.goto(`/index.php/apps/openconnector${route}`)
			expect(res?.status(), `${route} returned ${res?.status()}`).toBe(200)
			const html = await page.content()
			expect(html.toLowerCase()).toContain('openconnector')
		})
	}
})
