/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Find an item in the Store.
 *
 * The Store is a manifest index page over `catalog_item` (src/manifest.json,
 * page `Store`): a card grid that shows 20 of the 85 items per page, with the
 * search in the "Search and columns" sidebar rather than above the grid. A
 * spec that looks for an item on the first page only finds the first twenty,
 * so every Store spec searches first.
 */

import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'
import { APP_BASE } from '../spec-coverage/_helpers.ts'

/**
 * Open the Store and narrow it to the items matching a term.
 *
 * @param page The page to drive.
 * @param term The search term.
 * @return Nothing; the grid shows only the matching cards afterwards.
 */
export async function searchStore(page: Page, term: string): Promise<void> {
	if (!page.url().includes('/store')) {
		await page.goto(`${APP_BASE}/store`, { waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('catalog-item-card').first()).toBeVisible({
			timeout: 30_000,
		})
	}

	const search = page.getByPlaceholder('Type to search…')
	if (!(await search.isVisible())) {
		await page.getByRole('button', { name: 'Search and columns' }).click()
	}

	// Wait for the search the grid sends, not for a count: the count is
	// zero for a term with no item, which is a result some specs assert.
	const searched = page.waitForResponse((resp) => {
		const url = resp.url()
		// A space may travel as `+` or as `%20`; compare the decoded query.
		return (
			url.includes('/catalog_item')
			&& decodeURIComponent(url.replace(/\+/g, ' ')).includes(term)
		)
	})
	await search.fill(term)
	await searched
}
