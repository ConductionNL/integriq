// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Catalog store (connector-catalog-ui) — Pinia Options API store backing
// the Catalog page's detail dialog and the client-side filter logic the
// vitest suite asserts (REQ-001 search/category narrowing).
//
// The Catalog GRID itself is fetched and paginated by CnIndexPage's own
// self-fetch against OR's `/api/objects/openconnector/catalog_item`
// endpoint (ADR-022) — this store deliberately does NOT duplicate that
// list state. What lives here:
//   - fetchItems(): a lightweight one-shot list read used by the export
//     dialog and unit tests (same OR endpoint, no pagination state),
//   - filteredItems: the search + category narrowing getter (REQ-001),
//   - fetchStatus(id): the live per-item status re-check the detail
//     dialog performs before offering an action (REQ-002),
//   - instantiate(id): the Enable/Instantiate action call (REQ-002).

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useCatalogStore = defineStore('openconnector-catalog', {
	state: () => ({
		/** @type {Array<object>} catalog_item objects (OR shape). */
		items: [],
		/** @type {string} Free-text search term (name/description match). */
		searchTerm: '',
		/** @type {string|null} Active category facet, null = all. */
		categoryFilter: null,
		/** @type {boolean} items fetch in flight. */
		loading: false,
		/** @type {object} Live status per item id: { [id]: {status, mechanism, flagKey} }. */
		statusById: {},
	}),

	getters: {
		/**
		 * Search + category narrowed items (REQ-001 scenarios: category
		 * filter narrows to matching cards; search matches name or
		 * description, case-insensitive).
		 *
		 * @param {object} state Store state.
		 * @return {Array<object>} The narrowed subset.
		 * @spec openspec/specs/connector-catalog/spec.md#scenario-search-narrows-the-catalog-grid
		 */
		filteredItems(state) {
			const term = (state.searchTerm || '').trim().toLowerCase()
			return state.items.filter((item) => {
				if (state.categoryFilter && item.category !== state.categoryFilter) {
					return false
				}
				if (term === '') {
					return true
				}
				const name = (item.name || '').toLowerCase()
				const description = (item.description || '').toLowerCase()
				return name.includes(term) || description.includes(term)
			})
		},

		/**
		 * Distinct category values present in the loaded items, sorted —
		 * feeds a category facet UI without hardcoding any category.
		 *
		 * @param {object} state Store state.
		 * @return {Array<string>} Sorted distinct categories.
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
		 */
		categories(state) {
			return [
				...new Set(state.items.map((item) => item.category).filter(Boolean)),
			].sort()
		},
	},

	actions: {
		/**
		 * One-shot list read of catalog_item objects via OR's generic
		 * object endpoint (ADR-022 — no bespoke list route exists).
		 *
		 * @return {Promise<Array<object>>} The fetched items.
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
		 */
		async fetchItems() {
			this.loading = true
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/openconnector/catalog_item',
				)
				const { data } = await axios.get(url, { params: { _limit: 500 } })
				this.items = data?.results || []
				return this.items
			} finally {
				this.loading = false
			}
		},

		/**
		 * Live status re-check for one catalog item — called by the detail
		 * dialog before rendering the Enable/Instantiate action so a stale
		 * materialised card never offers an action already taken (REQ-002).
		 *
		 * @param {string} id catalog_item object id.
		 * @return {Promise<object>} `{id, status, mechanism, flagKey}`.
		 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
		 */
		async fetchStatus(id) {
			const url = generateUrl(
				`/apps/openconnector/api/catalog/items/${id}/status`,
			)
			const { data } = await axios.get(url)
			this.statusById = { ...this.statusById, [id]: data }
			return data
		},

		/**
		 * Enable (flag-gated) or Instantiate (source-backed) a catalog item.
		 * Server side is gated by ADR-023 `catalog.instantiate` plus the
		 * `source` schema's data-layer admin lock — this call surfaces the
		 * 403/409 outcomes to the dialog untouched.
		 *
		 * @param {string} id catalog_item object id.
		 * @return {Promise<object>} `{created, type, id, action}`.
		 * @spec openspec/specs/connector-catalog/spec.md#scenario-instantiate-action-creates-a-source-from-a-seeded-template
		 */
		async instantiate(id) {
			const url = generateUrl(
				`/apps/openconnector/api/catalog/items/${id}/instantiate`,
			)
			const { data } = await axios.post(url, {})
			// The action changed the item's live state — refresh its status.
			await this.fetchStatus(id).catch(() => {})
			return data
		},

		/**
		 * Setter for the search term (kept as an action so components and
		 * tests share one mutation path).
		 *
		 * @param {string} term New search term.
		 * @return {void}
		 */
		setSearchTerm(term) {
			this.searchTerm = term || ''
		},

		/**
		 * Setter for the category facet; pass null to clear.
		 *
		 * @param {string|null} category Category to filter to.
		 * @return {void}
		 */
		setCategoryFilter(category) {
			this.categoryFilter = category || null
		},
	},
})
