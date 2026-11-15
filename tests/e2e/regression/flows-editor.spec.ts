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
import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { expectRouteMatched, gotoAppRoute } from '../support/appRoot.ts'

/**
 * Reveal a flow action, opening the sidebar's Actions menu when it is closed.
 *
 * `CnFlowSidebar` hands `flowActions` to NcAppSidebar's `#secondary-actions`
 * slot, which renders each as an `NcActionButton` inside an `NcActions` MENU.
 * The items are not in the DOM until the menu is opened, so a direct click on
 * one waits out its whole budget and reads as a missing control.
 *
 * The trigger's accessible name has moved more than once, so several are tried
 * and the failure names every one attempted rather than reporting the last.
 *
 * @param {Page} page The page.
 * @param {Locator} item The action to reveal.
 * @return {Promise<void>} Resolves once the item is visible.
 */
async function openFlowActionsMenu(page: Page, item: Locator): Promise<void> {
	if (await item.isVisible().catch(() => false)) {
		return
	}

	const triggers = [
		page.getByRole('button', { name: 'Flow actions' }),
		// The sidebar's own Actions button, scoped. CnFlowSidebar puts the
		// 'Flow actions' aria-label on the NcActions WRAPPER, and NcAppSidebar
		// wraps that slot in an NcActions of its own, so the button a user
		// clicks is named plainly 'Actions'.
		page.locator('.app-sidebar-header__menu button'),
		page.getByRole('button', {
			name: /^(Actions|Open actions menu|More actions)$/i,
		}),
	]

	for (const trigger of triggers) {
		// Visible only, and a bounded click. The flow canvas renders three more
		// buttons named 'Actions' that are never painted, so the unscoped
		// name match finds four and `.first()` picks a hidden one. Clicking a
		// hidden element waits for actionability, and with no timeout that wait
		// is the whole 60s test budget: the run then reports 'Target page,
		// context or browser has been closed' from the NEXT call, which reads
		// as a hung editor rather than as the wrong button.
		const candidate = trigger.filter({ visible: true }).first()
		if ((await candidate.count()) === 0) {
			continue
		}

		await candidate.click({ timeout: 5000 }).catch(() => {})
		if (await item.isVisible().catch(() => false)) {
			return
		}
	}

	throw new Error(
		'could not open the flow Actions menu: tried "Flow actions", '
			+ '"Actions"/"Open actions menu"/"More actions", and the sidebar '
			+ 'header menu button, and the item never appeared',
	)
}

const RUN_ID = `e2e-ocflow-${Date.now().toString(36)}`

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
		// the router's base is `/index.php/apps/integriq`, and a literal
		// `/apps/integriq/flows` mounts the SPA whose router then cannot
		// match the path — the catch-all lands it on the Dashboard, which is
		// exactly what this spec's first CI run photographed.
		await gotoAppRoute(page, '/flows')
		await expectRouteMatched(page, '/flows')

		// CnIndexPage chrome, not the deprecated bespoke table.
		await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 20000 })
		// A link, not a button: CnFlowsPage renders New flow as an NcButton
		// with `:to`, which is a router link to /flows/new.
		await expect(page.getByRole('link', { name: 'New flow' })).toBeVisible({
			timeout: 15000,
		})
	})

	test('a new flow is the SAME editor holding only a starting point', async ({
		page,
	}) => {
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

		// The step catalogue is offered from the toolbar. Since nextcloud-vue
		// 2.40.0 the palette is CnFlowStepPickerModal, opened by the toolbar's
		// "add a step" button, and `.cn-flow-sidebar__palette-item` no longer
		// renders anywhere (this app resolves 2.65.0). openregister's
		// tests/e2e/ci/flow-controls.spec.ts asserts the same button. An
		// in-flight catalogue must still not be reported as an unreadable one
		// (the failure text used to show on every first paint of this route).
		await expect(page.locator('[data-testid="flow-add-step"]')).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByText('could not be read')).toHaveCount(0)
	})

	test('saving a new flow swaps the route to the minted id', async ({
		page,
		request,
	}) => {
		await gotoAppRoute(page, '/flows/new')
		await expectRouteMatched(page, '/flows/new')

		const toolbar = page.getByRole('toolbar', { name: 'Flow editor' })
		await expect(toolbar.getByRole('button', { name: 'Save' })).toBeEnabled({
			timeout: 20000,
		})

		// Name the flow after this run so a failed cleanup is identifiable.
		//
		// 🔴 THERE IS NO FLOW TAB. The sidebar registers exactly two tabs,
		// Steps and Runs, and the flow's own fields live in
		// `CnFlowSettingsModal` behind the sidebar's Actions menu. This is
		// already true in the 2.39.0 this app resolves; it is not a 2.40.0
		// change.
		//
		// This is what the job was red on: a 60s timeout waiting for
		// `getByRole('tab', { name: 'Flow' })`, which reads as a hung editor
		// rather than as a control that does not exist. The page snapshot in
		// the trace shows the tablist with its two tabs, which is the fastest
		// way to settle a question like this.
		const editAction = page.locator('[data-testid="flow-action-edit"]')
		await openFlowActionsMenu(page, editAction)
		await editAction.click()

		const settings = page.locator('[data-testid="flow-settings-modal"]')
		await expect(settings).toBeVisible({ timeout: 15000 })
		// By LABEL, not by the testid's descendant: `data-testid` is a
		// fallthrough attribute on `NcTextField`, so whether it lands on the
		// wrapper or on the input itself is that component's business, and
		// `[data-testid=…] input` finds nothing if it lands on the input.
		await settings.getByLabel('Name', { exact: true }).fill(`${RUN_ID} minted`)

		// Dismiss the dialog before touching the toolbar. CnFlowSettingsModal is
		// an NcDialog and stays open until something closes it, and NcDialog's
		// `.modal-wrapper` covers the page: Playwright reported the toolbar Save
		// as "visible, enabled and stable" and then retried the click 96 times
		// against `<div class="modal-wrapper"> from <div role="dialog"
		// aria-modal="true">` until the 60s budget ran out. An overlay eating a
		// click reads as a hung editor, which is how this spec was misdiagnosed
		// before.
		// Click the dialog's own Close button. Escape does not dismiss it: the
		// previous attempt pressed Escape and then watched `toBeHidden` resolve
		// the dialog as visible 24 times over 10s. The page snapshot in that
		// trace lists the control by name, `button "Close"`, which is the
		// fastest way to settle a question like this.
		await settings.getByRole('button', { name: 'Close' }).click()
		await expect(settings).toBeHidden({ timeout: 10000 })

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
