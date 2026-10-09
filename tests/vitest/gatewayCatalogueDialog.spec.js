// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Statutory gateways" on the Sources page (statutory-gateways-and-frameworks,
 * tasks 1 and 8). The catalogue and the jurisdiction overview had routes and
 * no screen. These tests mount the real dialog: the standard facet asks the
 * catalogue with `standard=`, the claim is shown in the catalogue's own words,
 * an undeclared jurisdiction reads "Not declared" and never as a place, and
 * the overview downloads from the export route.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GatewayCatalogueDialog from '@/dialogs/GatewayCatalogueDialog.vue'
import { claimText, jurisdictionText } from '@/dialogs/gatewayCatalogue.js'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue', 'update:open'],
			render() {
				return h('div', { class: name, 'data-href': this.$props.href }, [
					this.$slots.default?.(),
				])
			},
		})
	return {
		NcButton: stub('NcButton', ['href']),
		NcDialog: stub('NcDialog', ['open', 'name', 'size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', [
			'modelValue',
			'options',
			'inputLabel',
			'placeholder',
			'clearable',
		]),
	}
})

const WUS = {
	id: 'digikoppeling-wus',
	label: 'Digikoppeling WUS',
	standard: 'Digikoppeling WUS',
	claim: {
		level: 'implements',
		wording: 'Implements Digikoppeling WUS; not certified.',
	},
	jurisdiction: 'NL',
	jurisdictionDeclared: true,
}
const CORV = {
	id: 'corv',
	label: 'CORV',
	standard: 'CORV',
	claim: {
		level: 'follows',
		wording: 'Follows the CORV message model; not certified.',
	},
	jurisdiction: 'unknown',
	jurisdictionDeclared: false,
}

function answer(url, config = {}) {
	if (url.endsWith('/overview')) {
		return Promise.resolve({ data: { results: [WUS, CORV] } })
	}
	const standard = config.params?.standard
	const results = standard
		? [WUS, CORV].filter((g) => g.standard === standard)
		: [WUS, CORV]
	return Promise.resolve({
		data: { standards: ['Digikoppeling WUS', 'CORV'], results },
	})
}

async function opened() {
	const wrapper = mount(GatewayCatalogueDialog, { props: { open: false } })
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

describe('gateway catalogue text', () => {
	it('uses the claim wording the catalogue carries', () => {
		expect(claimText(WUS)).toBe('Implements Digikoppeling WUS; not certified.')
	})

	it('never reads an undeclared jurisdiction as a place', () => {
		expect(jurisdictionText(CORV)).toBe('Not declared')
		expect(jurisdictionText({ jurisdiction: 'NL' })).toBe('Not declared')
		expect(jurisdictionText(WUS)).toBe('NL')
	})
})

describe('GatewayCatalogueDialog', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockImplementation(answer)
	})

	it('lists every gateway with its standard, claim and jurisdiction', async () => {
		const wrapper = await opened()
		const rows = wrapper.find('[data-testid="gateway-catalogue-rows"]')
		expect(rows.findAll('tbody tr')).toHaveLength(2)
		expect(rows.find('[data-gateway="corv"]').text()).toContain('Not declared')
		expect(rows.find('[data-gateway="digikoppeling-wus"]').text()).toContain(
			'not certified',
		)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual(
			['Digikoppeling WUS', 'CORV'],
		)
	})

	it('filters by standard through the catalogue route', async () => {
		const wrapper = await opened()
		wrapper.vm.standard = 'CORV'
		await wrapper.vm.loadCatalogue()
		await flushPromises()
		expect(get).toHaveBeenLastCalledWith('/apps/integriq/api/gateways', {
			params: { standard: 'CORV' },
		})
		expect(
			wrapper.findAll('[data-testid="gateway-catalogue-rows"] tbody tr'),
		).toHaveLength(1)
		// The facet keeps every standard, not only the chosen one.
		expect(wrapper.vm.standards).toEqual(['Digikoppeling WUS', 'CORV'])
	})

	it('shows where data goes and offers the overview as a download', async () => {
		const wrapper = await opened()
		const overview = wrapper.find('[data-testid="gateway-overview-rows"]').text()
		expect(overview).toContain('Digikoppeling WUS: NL')
		expect(overview).toContain('CORV: Not declared')
		expect(
			wrapper
				.find('[data-testid="gateway-overview-export"]')
				.attributes('data-href'),
		).toBe('/apps/integriq/api/gateways/overview/export')
	})

	it('says so when the catalogue cannot be read', async () => {
		get.mockImplementation((url) =>
			url.endsWith('/overview')
				? Promise.resolve({ data: { results: [] } })
				: Promise.reject(new Error('500')),
		)
		const wrapper = await opened()
		expect(
			wrapper.find('[data-testid="gateway-catalogue-error"]').text(),
		).toContain('could not be read')
	})
})
