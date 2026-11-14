// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A Store card names the extension app that contributed its template
 * (platform-connector-extension-kit task 4); integriq's own cards name none.
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
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

describe('the provider on a Store card', () => {
	it('names the extension app that contributed the template', () => {
		const line = card({ name: 'Example CRM', kind: 'source-template', providedBy: 'myextension' })
			.find('[data-testid="catalog-provided-by"]')

		expect(line.exists()).toBe(true)
		expect(line.text()).toBe('Provided by myextension')
	})

	it('names nobody on integriq\'s own cards', () => {
		for (const providedBy of [undefined, '', null, 7]) {
			expect(card({ name: 'Alfresco', kind: 'source-template', providedBy })
				.find('[data-testid="catalog-provided-by"]').exists()).toBe(false)
		}
	})
})
