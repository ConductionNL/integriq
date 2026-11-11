// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The kind pill on a Store card: the three known kinds by their label, any
 * other kind as it is, and nothing for a card without a kind.
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001
 */
import { translate } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import CatalogItemCard from '@/components/CatalogItemCard.vue'

vi.mock('../../src/handlers/modalBus.js', () => ({
	EVENT_OPEN_CATALOG_ITEM_DETAIL: 'open',
	modalBus: { emit: vi.fn() },
}))

globalThis.t = translate

/**
 * The kind pill text of a card on a catalog_item.
 *
 * @param {object} object The catalog_item.
 * @return {string} The pill text.
 */
function kindPill(object) {
	const wrapper = mount(CatalogItemCard, {
		props: { object },
		global: { mocks: { t: translate } },
	})
	return wrapper.find('.oc-catalog-card__chip--kind').text()
}

describe('the kind pill on a Store card', () => {
	it.each([
		['adapter', 'Adapter'],
		['source-template', 'Source template'],
		['configuration-template', 'Configuration template'],
		['flow-template', 'flow-template'],
		['', ''],
		[undefined, ''],
		[null, ''],
	])('shows kind %s as "%s"', (kind, label) => {
		expect(kindPill({ name: 'X', kind })).toBe(label)
	})
})
