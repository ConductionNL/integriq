// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tier on a Store card (connectors-catalogue-expansion task 5,
 * REQ-CCX-003 and REQ-CCX-004): a generated template says it is generated
 * and from which snapshot, a curated one says it was checked, an adapter
 * shows neither.
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-generated-saas-templates-come-from-a-pinned-directory-and-a-reviewed-allow-list-req-ccx-003
 */
import { translate } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import CatalogItemCard from '@/components/CatalogItemCard.vue'

vi.mock('../../src/handlers/modalBus.js', () => ({
	EVENT_OPEN_CATALOG_ITEM_DETAIL: 'open',
	modalBus: { emit: vi.fn() },
}))

// The card reads the app-wide `t` the way the app provides it.
globalThis.t = translate

/**
 * Mount a card on a catalog_item.
 *
 * @param {object} object The catalog_item.
 * @return {object} The wrapper.
 */
function card(object) {
	return mount(CatalogItemCard, {
		props: { object },
		global: { mocks: { t: translate } },
	})
}

describe('the tier on a Store card', () => {
	it('marks a generated template with its snapshot date', () => {
		const wrapper = card({
			name: 'Slack',
			kind: 'source-template',
			tier: 'generated',
			snapshotDate: '2026-09-29',
		})
		const tier = wrapper.find('[data-testid="catalog-tier"]')

		expect(tier.exists()).toBe(true)
		expect(tier.text()).toBe('Generated from the API directory of 2026-09-29')
	})

	it('marks a curated template as checked', () => {
		const wrapper = card({
			name: 'Alfresco',
			kind: 'source-template',
			tier: 'curated',
			verifiedAgainst: 'https://docs.alfresco.com/',
		})

		expect(wrapper.find('[data-testid="catalog-tier"]').text()).toBe(
			'Checked against a published interface',
		)
	})

	it('shows no tier on an adapter', () => {
		expect(
			card({ name: 'PDOK', kind: 'adapter', tier: 'adapter' })
				.find('[data-testid="catalog-tier"]')
				.exists(),
		).toBe(false)
	})
})
