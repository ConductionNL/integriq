// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Look up in a base registry" on the Sources page (registry-backed-field-source).
 *
 * The four /api/property-sources routes had no caller in src/. These tests
 * mount the real dialog and assert what it asks the registry and what it says
 * about the answer: a suggestion is resolved by its identifier before it is
 * shown as a value, a fresh read skips the cache, an unreachable registry is
 * shown with the age of the last value, and a failed resync says the previous
 * list stays.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-resolved-value-carries-its-provenance-req-rfs-003
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RegistryLookupDialog from '@/dialogs/RegistryLookupDialog.vue'
import { ageText, provenanceState, valueRows } from '@/dialogs/registryLookup.js'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue', 'update:open'],
			render() {
				return h(
					'div',
					{ class: name, onClick: () => this.$emit('click') },
					[this.$slots.default?.(), this.$slots.actions?.()],
				)
			},
		})
	return {
		NcButton: stub('NcButton', ['variant', 'disabled']),
		NcDialog: stub('NcDialog', ['open', 'name', 'size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', [
			'modelValue',
			'options',
			'inputLabel',
			'loading',
		]),
		NcTextField: stub('NcTextField', ['modelValue', 'label']),
	}
})

function t(_app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_match, key) => String(vars[key] ?? ''))
}

const PROVIDERS = [
	{
		id: 'bag',
		label: 'BAG address',
		identifier: 'pdokId',
		stalenessBudget: 86400,
		listShaped: false,
	},
	{
		id: 'selectielijst',
		label: 'Selectielijst',
		identifier: 'code',
		stalenessBudget: 604800,
		listShaped: true,
	},
]

const RESOLVED = {
	value: { straat: 'Domplein', huisnummer: '29', woonplaats: 'Utrecht' },
	provenance: {
		origin: 'source',
		provider: 'bag',
		sourceIdentifier: 'adr-0344200000123456',
		readAt: 1790000000,
		cacheAgeSeconds: 0,
		unreachable: false,
		live: true,
	},
}

/**
 * Mount the dialog open, with the BAG picked.
 *
 * @return {Promise<object>} the wrapper
 */
async function mountWithBag() {
	const wrapper = mount(RegistryLookupDialog, { props: { open: true } })
	await flushPromises()
	wrapper.vm.selectedProvider = wrapper.vm.providerOptions[0]
	await flushPromises()
	return wrapper
}

describe('what the lookup says about a value', () => {
	it('calls a value live only when the read was within its budget', () => {
		expect(provenanceState(RESOLVED.provenance, t).state).toBe('live')
		expect(
			provenanceState(
				{ ...RESOLVED.provenance, live: false, cacheAgeSeconds: 7200 },
				t,
			),
		).toEqual({ state: 'cached', text: 'Read from the registry 2 hours ago.' })
	})

	it('shows an unreachable registry with the age of the last value, or says there is none', () => {
		expect(
			provenanceState(
				{ unreachable: true, live: false, cacheAgeSeconds: 172800 },
				t,
			).text,
		).toBe(
			'The registry did not answer. This is the last value read, 2 days ago.',
		)
		expect(
			provenanceState({ unreachable: true, cacheAgeSeconds: null }, t).text,
		).toBe('The registry did not answer and nothing was read before.')
	})

	it('writes ages in the largest whole unit', () => {
		expect(ageText(45, t)).toBe('45 seconds')
		expect(ageText(600, t)).toBe('10 minutes')
	})

	it('lists a flat value as rows and nested parts as JSON', () => {
		expect(
			valueRows({ naam: 'Conduction', adres: { plaats: 'Amsterdam' } }),
		).toEqual([
			{ key: 'naam', text: 'Conduction' },
			{ key: 'adres', text: '{"plaats":"Amsterdam"}' },
		])
		expect(valueRows(null)).toEqual([])
	})
})

describe('the registry lookup dialog', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		get.mockImplementation((url) => {
			if (url.endsWith('/api/property-sources')) {
				return Promise.resolve({ data: { results: PROVIDERS } })
			}
			if (url.endsWith('/suggest')) {
				return Promise.resolve({
					data: {
						results: [
							{
								identifier: 'adr-0344200000123456',
								label: 'Domplein 29, Utrecht',
							},
						],
					},
				})
			}
			return Promise.resolve({ data: RESOLVED })
		})
	})

	it('offers the registries the instance has', async () => {
		const wrapper = await mountWithBag()
		expect(wrapper.vm.providerOptions.map((option) => option.id)).toEqual([
			'bag',
			'selectielijst',
		])
	})

	it('resolves a picked suggestion by its identifier before showing a value', async () => {
		const wrapper = await mountWithBag()
		wrapper.vm.query = 'Domplein 29'
		await wrapper.find('[data-testid="registry-lookup-search"]').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenCalledWith(
			'/apps/integriq/api/property-sources/bag/suggest',
			{
				params: { q: 'Domplein 29' },
			},
		)
		expect(
			wrapper.find('[data-testid="registry-lookup-resolved"]').exists(),
		).toBe(false)

		await wrapper
			.find('[data-testid="registry-lookup-suggestions"] .NcButton')
			.trigger('click')
		await flushPromises()

		expect(get).toHaveBeenLastCalledWith(
			'/apps/integriq/api/property-sources/bag/resolve',
			{
				params: { identifier: 'adr-0344200000123456' },
			},
		)
		const resolved = wrapper
			.find('[data-testid="registry-lookup-resolved"]')
			.text()
		expect(resolved).toContain('Domplein')
		expect(resolved).toContain('adr-0344200000123456')
		expect(
			wrapper.find('[data-testid="registry-lookup-state"]').text(),
		).toContain('just now')
	})

	it('reads the registry again, skipping the cache, when asked', async () => {
		const wrapper = await mountWithBag()
		await wrapper.vm.resolve('adr-0344200000123456', false)
		await flushPromises()

		await wrapper.find('[data-testid="registry-lookup-fresh"]').trigger('click')
		await flushPromises()

		expect(get).toHaveBeenLastCalledWith(
			'/apps/integriq/api/property-sources/bag/resolve',
			{
				params: { identifier: 'adr-0344200000123456', fresh: true },
			},
		)
	})

	it('offers a resync only for a list-shaped registry, and says a failed one kept the list', async () => {
		const wrapper = await mountWithBag()
		expect(wrapper.find('[data-testid="registry-lookup-resync"]').exists()).toBe(
			false,
		)

		wrapper.vm.selectedProvider = wrapper.vm.providerOptions[1]
		await flushPromises()
		post.mockResolvedValueOnce({
			data: {
				succeeded: false,
				changed: 0,
				message: 'The source did not answer.',
				served: 120,
			},
		})
		await wrapper.find('[data-testid="registry-lookup-resync"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/apps/integriq/api/property-sources/selectielijst/resync',
		)
		expect(
			wrapper.find('[data-testid="registry-lookup-resync-report"]').text(),
		).toContain('the previous list stays in use')
	})
})
