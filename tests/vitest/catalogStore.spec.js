/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the catalog Pinia store (src/store/catalog.js,
 * connector-catalog-ui):
 *   • filteredItems narrows by category facet and free-text search
 *     (name OR description, case-insensitive) — REQ-001;
 *   • categories lists distinct sorted categories with no hardcoding;
 *   • fetchStatus / instantiate hit the catalog endpoints — REQ-002.
 *
 * NOTE: lives under tests/vitest/ (not src/store/catalog.spec.js as the
 * tasks.md sketch suggested) because vitest.config.js only includes
 * tests/vitest/** and explicitly excludes src/**.
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const get = vi.fn()
const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => get(...a),
		post: (...a) => post(...a),
	},
}))

import { useCatalogStore } from '../../src/store/catalog.js'

const ITEMS = [
	{
		name: 'PDOK WMS',
		description: 'Dutch geo platform',
		category: 'Geo / Maps',
		status: 'dormant',
	},
	{
		name: 'BRP HaalCentraal',
		description: 'basisregistratie personen lookup',
		category: 'Government registers',
		status: 'available',
	},
	{
		name: 'KvK Handelsregister',
		description: 'chamber of commerce register',
		category: 'Government registers',
		status: 'dormant',
	},
	{
		name: 'S3 object storage',
		description: 'data infra adapter',
		category: 'Data infrastructure',
		status: 'available',
	},
]

describe('catalog store (connector-catalog-ui)', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useCatalogStore()
		store.items = [...ITEMS]
		get.mockReset()
		post.mockReset()
	})

	// @spec openspec/specs/connector-catalog/spec.md#scenario-catalog-lists-built-in-adapters-and-seeded-source-templates-by-category
	it('category filter narrows to only matching items', () => {
		store.setCategoryFilter('Government registers')

		const names = store.filteredItems.map((i) => i.name)
		expect(names).toEqual(['BRP HaalCentraal', 'KvK Handelsregister'])
	})

	// @spec openspec/specs/connector-catalog/spec.md#scenario-search-narrows-the-catalog-grid
	it('search matches name case-insensitively', () => {
		store.setSearchTerm('brp')

		expect(store.filteredItems).toHaveLength(1)
		expect(store.filteredItems[0].name).toBe('BRP HaalCentraal')
	})

	// @spec openspec/specs/connector-catalog/spec.md#scenario-search-narrows-the-catalog-grid
	it('search also matches the description field', () => {
		store.setSearchTerm('geo platform')

		expect(store.filteredItems).toHaveLength(1)
		expect(store.filteredItems[0].name).toBe('PDOK WMS')
	})

	// @spec openspec/specs/connector-catalog/spec.md#scenario-search-narrows-the-catalog-grid
	it('search and category filter compose (AND semantics)', () => {
		store.setCategoryFilter('Government registers')
		store.setSearchTerm('kvk')

		expect(store.filteredItems).toHaveLength(1)
		expect(store.filteredItems[0].name).toBe('KvK Handelsregister')
	})

	// @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
	it('clearing the filters restores the full set', () => {
		store.setCategoryFilter('Geo / Maps')
		store.setSearchTerm('pdok')
		store.setCategoryFilter(null)
		store.setSearchTerm('')

		expect(store.filteredItems).toHaveLength(ITEMS.length)
	})

	// @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
	it('categories getter lists distinct sorted categories', () => {
		expect(store.categories).toEqual([
			'Data infrastructure',
			'Geo / Maps',
			'Government registers',
		])
	})

	// @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
	it('fetchStatus hits the live status endpoint and caches by id', async () => {
		const status = {
			id: 'abc',
			status: 'available',
			mechanism: 'flag-gated',
			flagKey: 'pdok.feature_flag',
		}
		get.mockResolvedValueOnce({ data: status })

		const result = await store.fetchStatus('abc')

		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openconnector/api/catalog/items/abc/status',
		)
		expect(result).toEqual(status)
		expect(store.statusById.abc).toEqual(status)
	})

	// @spec openspec/specs/connector-catalog/spec.md#scenario-instantiate-action-creates-a-source-from-a-seeded-template
	it('instantiate POSTs to the instantiate endpoint and refreshes status', async () => {
		post.mockResolvedValueOnce({
			data: { created: true, type: 'source', id: 'uuid-1', action: 'enabled' },
		})
		get.mockResolvedValueOnce({ data: { id: 'abc', status: 'available' } })

		const result = await store.instantiate('abc')

		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/openconnector/api/catalog/items/abc/instantiate',
			{},
		)
		expect(result.created).toBe(true)
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openconnector/api/catalog/items/abc/status',
		)
	})

	// @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
	it('fetchItems reads the OR generic object endpoint (no bespoke list route)', async () => {
		get.mockResolvedValueOnce({ data: { results: ITEMS } })

		const items = await store.fetchItems()

		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/openconnector/catalog_item',
			{ params: { _limit: 500 } },
		)
		expect(items).toHaveLength(ITEMS.length)
		expect(store.loading).toBe(false)
	})
})
