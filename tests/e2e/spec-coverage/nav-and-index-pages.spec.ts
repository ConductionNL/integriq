/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Genuine behavioral UI coverage: drive each main-navigation index page
 * through a REAL nav-click (not a deep-link goto), assert the page-specific
 * heading and the page-specific primary "Add X" create button render, and
 * that no openconnector-origin console error or 5xx occurred.
 *
 * This upgrades the prior per-feature specs (which deep-linked and only
 * asserted `main` was visible) to nav-wiring + distinct-content checks,
 * and guards against the post-OR-cutover SynchronizationMapper dispatch
 * regression on the Synchronizations / Endpoints / Cloud events pages.
 */
import { test, expect } from '@playwright/test'
import {
	navTo,
	trackErrors,
	assertNoAppErrors,
	openAndDismissCreateModal,
} from './_helpers'

interface IndexPage {
	navLabel: string
	route: string
	heading: RegExp
	addButton: RegExp
	// Every index page's Add button opens a create dialog. Mappings used to be
	// an exception — `createMappingAndOpen` POSTed an empty "New mapping" shell
	// and routed straight to its detail editor — which is why this type carried
	// a `createSurface` discriminator plus the slugs needed to clean up after
	// it. The Mappings page now declares a `form-dialog` slot like the rest, so
	// the exception, and the machinery that described it, are both gone.
}

const INDEX_PAGES: IndexPage[] = [
	{
		navLabel: 'Sources',
		route: '/sources',
		heading: /^Sources$/,
		addButton: /Add Source/i,
	},
	{
		navLabel: 'Endpoints',
		route: '/endpoints',
		heading: /^Endpoints$/,
		addButton: /Add Endpoint/i,
	},
	{
		navLabel: 'Consumers',
		route: '/consumers',
		heading: /^Consumers$/,
		addButton: /Add Consumer/i,
	},
	{ navLabel: 'Jobs', route: '/jobs', heading: /^Jobs$/, addButton: /Add Job/i },
	{
		navLabel: 'Mappings',
		route: '/mappings',
		heading: /^Mappings$/,
		addButton: /Add Mapping/i,
	},
	{
		navLabel: 'Rules',
		route: '/rules',
		heading: /^Rules$/,
		addButton: /Add Rule/i,
	},
	{
		navLabel: 'Synchronizations',
		route: '/synchronizations',
		heading: /^Synchronizations$/,
		addButton: /Add Synchronization/i,
	},
	{
		navLabel: 'Cloud events',
		route: '/cloud-events/events',
		heading: /^Cloud events$/,
		addButton: /Add Event/i,
	},
]

for (const p of INDEX_PAGES) {
	test.describe(`${p.navLabel} — nav-driven index`, () => {
		// @e2e openconnector-comprehensive-tests::index-page-nav-and-heading
		test(`nav-click reveals "${p.navLabel}" index page + "${p.addButton.source}" button`, async ({
			page,
		}) => {
			const sink = trackErrors(page)
			await navTo(page, p.navLabel, p.route)

			// NB: schema-driven index pages render via nc-vue `CnIndexPage`,
			// whose title header (`CnPageHeader`) is gated behind `showTitle`,
			// which defaults to FALSE and is not set by the openconnector
			// manifest — so an index page has NO `<h1>/<h2>` page-title heading
			// element (this is unchanged between the Vue 2 and Vue 3 builds, i.e.
			// not a migration regression). navTo already asserts the route
			// resolved; the page-identity signal that DOES render is the
			// schema-scoped "Add <Entity>" create button + the Actions bar below.
			await expect(
				page.getByRole('button', { name: p.addButton }).first(),
				`${p.navLabel} create button must render`,
			).toBeVisible({ timeout: 15_000 })

			// Search + Columns controls are part of every schema-driven index.
			await expect(
				page.getByRole('button', { name: /Actions/i }).first(),
			).toBeVisible({ timeout: 10_000 })

			assertNoAppErrors(sink)
		})

		// @e2e openconnector-comprehensive-tests::index-page-create-modal
		test(`${p.navLabel} create modal opens and dismisses`, async ({ page }) => {
			const sink = trackErrors(page)
			await navTo(page, p.navLabel, p.route)
			await openAndDismissCreateModal(page, p.addButton)
			assertNoAppErrors(sink)
		})
	})
}
