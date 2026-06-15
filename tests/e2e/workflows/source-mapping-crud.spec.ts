/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP, DATA-DEPENDENT e2e — full CRUD-with-PERSISTENCE for the two core
 * openconnector entities a user authors by hand: Source and Mapping.
 *
 * Unlike a page-render smoke, every leg asserts the data actually persisted:
 *   create via the real UI form  -> the ROW appears in the list (not empty-state)
 *   open the row (View)          -> the persisted values are shown
 *   edit a field                 -> the change is persisted (re-read via the OR API)
 *   delete                       -> the row is gone from the list
 *
 * openconnector resolves Sources/Mappings through OpenRegister
 * (register `openconnector`, schema `source` / `mapping`); the OR object API
 * is used only to (a) verify persistence after a UI edit and (b) clean up.
 *
 * The manifest shell renders the create form as an "Add <Entity>" modal with
 * `name *` / `description` / (`type`) fields, the list as a table, and a
 * per-row Actions menu (Test connection / View logs / View / Edit / Copy /
 * Delete) — verified live on 2026-06-10.
 *
 * PAGINATION NOTE: the entity lists are server-paginated (e.g. 41 mappings,
 * 20/page) and the in-list search box is NOT wired to a server `_search`
 * (it fires no filtering request — a known UI gap). To surface a freshly
 * created row deterministically we (a) prefix names with `zzz-` so they sort
 * last, then (b) click the Name column header twice to sort DESCENDING
 * (`_order={"name":"desc"}`), which floats our row to the top of page 1.
 */
import { test, expect, type Page } from '@playwright/test'
import {
	makeApiClient, makeRunId, find, findAll, deleteObject, cleanupByPrefix,
	idOf, type ApiClient,
} from './_fixture'

let api: ApiClient
const RUN = makeRunId()

test.beforeAll(async ({ browser, baseURL }) => {
	api = await makeApiClient(browser, baseURL!)
})

test.afterAll(async () => {
	// Best-effort: remove everything this run created (UI delete + any leftovers).
	await cleanupByPrefix(api, 'source', RUN)
	await cleanupByPrefix(api, 'mapping', RUN)
	await api.dispose()
})

/**
 * Land on the list and sort the Name column DESCENDING so a `zzz-`-prefixed
 * row floats to the top of page 1 (see PAGINATION NOTE in the file header).
 */
async function gotoIndex(page: Page, route: string): Promise<void> {
	await page.goto(`/apps/openconnector/${route}`, { waitUntil: 'domcontentloaded' })
	await page.waitForLoadState('networkidle').catch(() => {})
	await page.waitForTimeout(1_000)
	const nameHeader = page.getByRole('columnheader', { name: /name/i }).first()
	if (await nameHeader.isVisible({ timeout: 5_000 }).catch(() => false)) {
		await nameHeader.click() // asc
		await page.waitForTimeout(800)
		await nameHeader.click() // desc — floats zzz- rows to the top
		await page.waitForTimeout(1_200)
	}
}

/** Open the per-row Actions menu for the row whose text contains `name`. */
async function openRowMenu(page: Page, name: string): Promise<void> {
	const row = page.locator('tr', { hasText: name }).first()
	await expect(row, `row "${name}" must be present in the list`).toBeVisible({ timeout: 20_000 })
	await row.getByRole('button').last().click()
	await page.waitForTimeout(400)
}

/**
 * Drive one full create→row→view→edit→delete cycle through the UI, asserting
 * persistence at every step (the edit is re-read through the OR API).
 *
 * @param schema  'source' | 'mapping' — the OR schema slug for verification.
 * @param route   the in-app route segment ('sources' | 'mappings').
 * @param addBtn  accessible name of the create button.
 */
async function crudCycle(
	page: Page,
	schema: 'source' | 'mapping',
	route: string,
	addBtn: RegExp,
): Promise<void> {
	// `zzz-` prefix so the row sorts last and Name-desc floats it to page 1.
	const name = `zzz-${RUN}-${schema}-crud`
	const desc = `${RUN}-desc`

	// ---- CREATE via the UI form -------------------------------------------
	await gotoIndex(page, route)
	await page.getByRole('button', { name: addBtn }).first().click()
	const dialog = page.getByRole('dialog').first()
	await expect(dialog, 'create modal must open').toBeVisible({ timeout: 10_000 })
	await dialog.getByLabel(/name/i).first().fill(name)
	await dialog.getByLabel(/description/i).first().fill(desc).catch(() => { /* description optional */ })
	await dialog.getByRole('button', { name: /create|save/i }).first().click()
	await page.waitForTimeout(2_500)

	// ---- ASSERT the ROW appears (not empty-state) -------------------------
	await gotoIndex(page, route)
	const createdRow = page.locator('tr', { hasText: name }).first()
	await expect(createdRow, 'newly-created row must appear in the list').toBeVisible({ timeout: 15_000 })

	// Persistence cross-check via OR: exactly one object with this name exists.
	const persisted = (await findAll(api, schema, { _search: name }))
		.filter((o: Record<string, unknown>) => o.name === name)
	expect(persisted.length, `${schema} "${name}" must be persisted in OpenRegister`).toBe(1)
	const id = idOf(persisted[0])
	expect(id).toBeTruthy()

	// ---- VIEW: detail surfaces the persisted values -----------------------
	await openRowMenu(page, name)
	await page.getByRole('menuitem', { name: /^View$/ }).click()
	await page.waitForTimeout(1_500)
	const detail = (await page.locator('main').innerText()).replace(/\n+/g, ' | ')
	expect(detail, 'detail view must show the entity name').toContain(name)

	// ---- EDIT: change description, assert PERSISTED -----------------------
	await gotoIndex(page, route)
	await openRowMenu(page, name)
	await page.getByRole('menuitem', { name: /^Edit$/ }).click()
	const editDlg = page.getByRole('dialog').first()
	await expect(editDlg, 'edit modal must open').toBeVisible({ timeout: 10_000 })
	const newDesc = `${RUN}-EDITED`
	await editDlg.getByLabel(/description/i).first().fill(newDesc)
	await editDlg.getByRole('button', { name: /save|update|create/i }).first().click()
	await page.waitForTimeout(2_500)

	const afterEdit = await find(api, schema, id)
	expect(afterEdit.description, 'edited description must be persisted').toBe(newDesc)

	// ---- DELETE: row gone -------------------------------------------------
	await gotoIndex(page, route)
	await openRowMenu(page, name)
	await page.getByRole('menuitem', { name: /^Delete$/ }).click()
	await page.waitForTimeout(800)
	const confirm = page.getByRole('dialog').getByRole('button', { name: /delete|confirm|yes/i }).first()
	if (await confirm.isVisible({ timeout: 2_000 }).catch(() => false)) {
		await confirm.click()
	}
	await page.waitForTimeout(2_500)
	await gotoIndex(page, route)
	const goneRow = page.locator('tr', { hasText: name }).first()
	await expect(goneRow, 'deleted row must be gone from the list').toBeHidden({ timeout: 10_000 })

	// And gone from OR (find by id should 404 / not return it).
	const remaining = (await findAll(api, schema, { _search: name }))
		.filter((o: Record<string, unknown>) => o.name === name)
	expect(remaining.length, `${schema} "${name}" must be removed from OpenRegister`).toBe(0)
}

test.describe('Source — full CRUD with persistence', () => {
	test('create → row appears → view → edit persists → delete', async ({ page }) => {
		test.setTimeout(120_000)
		await crudCycle(page, 'source', 'sources', /add source/i)
	})
})

test.describe('Mapping — full CRUD with persistence', () => {
	test('create → row appears → view → edit persists → delete', async ({ page }) => {
		test.setTimeout(120_000)
		await crudCycle(page, 'mapping', 'mappings', /add mapping/i)
	})
})
