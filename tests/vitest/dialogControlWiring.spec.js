// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The controls of the catalogue, gateway, registry lookup, import and link
 * dialogs: what a pick or a typed value changes and which request it sends,
 * the template-or-existing choice of the link dialog, and the reads that fail
 * quietly. HTTP, the catalog store and the dialogs toasts are the mocked
 * boundaries; the Nextcloud components are stubbed and emit
 * `update:modelValue` as the real ones do.
 */

import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CatalogItemDetailDialog from '@/dialogs/CatalogItemDetailDialog.vue'
import GatewayCatalogueDialog from '@/dialogs/GatewayCatalogueDialog.vue'
import ImportPreviewDialog from '@/dialogs/ImportPreviewDialog.vue'
import LinkSourceDialog from '@/dialogs/LinkSourceDialog.vue'
import RegistryLookupDialog from '@/dialogs/RegistryLookupDialog.vue'

const { get, post, store } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	store: { fetchStatus: null, instantiate: null, statusById: {} },
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@/store/catalog.js', () => ({ useCatalogStore: () => store }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			props: ['open', 'name', 'type', 'variant', 'disabled', 'modelValue', 'options', 'label', 'value'],
			emits: ['update:open', 'update:modelValue', 'click'],
			render() {
				return h('div', { class: name }, [this.$slots.default?.(), this.$slots.actions?.()])
			},
		})
	return new Proxy(
		{},
		{
			get: (_t, key) => (typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined),
			has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
		},
	)
})

globalThis.t = translate

/**
 * A dialog mounted closed and then opened, as its host does.
 *
 * @param {object} component The dialog.
 * @param {object} [props]   Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function openDialog(component, props = {}) {
	const wrapper = mount(component, {
		props: { open: false, ...props },
		global: { mocks: { t: translate } },
	})
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

/**
 * The stubbed control carrying a test id.
 *
 * @param {object} wrapper The dialog wrapper.
 * @param {string} testId  The data-testid.
 * @return {object} The control's component instance.
 */
function control(wrapper, testId) {
	const found = wrapper.find(`[data-testid="${testId}"]`)
	expect(found.exists()).toBe(true)
	return found.getCurrentComponent()
}

beforeEach(() => {
	get.mockReset()
	post.mockReset()
	get.mockResolvedValue({ data: { results: [] } })
	post.mockResolvedValue({ data: {} })
	store.fetchStatus = vi.fn().mockResolvedValue(null)
	store.instantiate = vi.fn().mockResolvedValue({})
	store.statusById = {}
})

describe('GatewayCatalogueDialog controls', () => {
	it('reads the catalogue for the standard picked in the select', async () => {
		const wrapper = await openDialog(GatewayCatalogueDialog)
		get.mockClear()

		control(wrapper, 'gateway-catalogue-standard').emit('update:modelValue', 'CORV')
		await flushPromises()

		expect(wrapper.vm.standard).toBe('CORV')
		expect(get).toHaveBeenCalledWith('/apps/integriq/api/gateways', { params: { standard: 'CORV' } })
	})

	it('shows no endpoint overview when it cannot be read', async () => {
		get.mockImplementation((url) =>
			url.endsWith('/overview')
				? Promise.reject(new Error('down'))
				: Promise.resolve({ data: { standards: [], results: [] } }),
		)
		const wrapper = mount(GatewayCatalogueDialog, { props: { open: false } })
		wrapper.vm.overview = [{ id: 'stale' }]

		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(wrapper.vm.overview).toEqual([])
		expect(wrapper.vm.error).toBe('')
	})
})

describe('RegistryLookupDialog controls', () => {
	it('searches the chosen registry for the typed query, trimmed', async () => {
		const wrapper = await openDialog(RegistryLookupDialog)
		wrapper.vm.selectedProvider = { id: 'bag', label: 'BAG' }
		await flushPromises()
		get.mockClear()
		get.mockResolvedValue({ data: { results: [] } })

		control(wrapper, 'registry-lookup-query').emit('update:modelValue', '  Dam 1  ')
		await flushPromises()
		expect(wrapper.vm.query).toBe('  Dam 1  ')

		await wrapper.vm.runSuggest()

		expect(get).toHaveBeenCalledWith('/apps/integriq/api/property-sources/bag/suggest', { params: { q: 'Dam 1' } })
		expect(wrapper.vm.searched).toBe(true)
	})
})

describe('ImportPreviewDialog controls', () => {
	it('shows a refused document\'s message on the pick step', async () => {
		const wrapper = await openDialog(ImportPreviewDialog)

		wrapper.vm.errorMessage = 'The file is not valid JSON.'
		await flushPromises()

		expect(wrapper.find('.NcNoteCard').text()).toBe('The file is not valid JSON.')
	})

	it('unblocks the import once the dangling references are acknowledged', async () => {
		const wrapper = await openDialog(ImportPreviewDialog)
		wrapper.vm.step = 'preview'
		wrapper.vm.preview = {
			...wrapper.vm.preview,
			unresolvedReferences: [{ type: 'schema', slug: 'case', field: 'register', value: 'zaken' }],
		}
		await flushPromises()
		expect(wrapper.vm.confirmBlocked).toBe(true)

		control(wrapper, 'unresolved-ack').emit('update:modelValue', true)
		await flushPromises()

		expect(wrapper.vm.unresolvedAcknowledged).toBe(true)
		expect(wrapper.vm.confirmBlocked).toBe(false)
	})
})

describe('LinkSourceDialog controls', () => {
	it('offers the connection\'s source template next to an existing source, and switches between them', async () => {
		const wrapper = await openDialog(LinkSourceDialog)
		wrapper.vm.onConnectionChanged({ id: 'c-1', app: 'zaakafhandeling', sourceTemplate: 'zgw-zaken' })
		await flushPromises()
		expect(wrapper.vm.mode).toBe('template')

		expect(wrapper.find('[data-testid="link-source-mode-template"]').text())
			.toBe('Create a source from the template zgw-zaken')
		expect(wrapper.find('[data-testid="link-source-mode-existing"]').text()).toBe('Use an existing source')

		control(wrapper, 'link-source-mode-existing').emit('update:modelValue', 'existing')
		await flushPromises()
		expect(wrapper.vm.mode).toBe('existing')

		control(wrapper, 'link-source-mode-template').emit('update:modelValue', 'template')
		await flushPromises()
		expect(wrapper.vm.mode).toBe('template')
	})

	it('keeps the choice when the dialog closes, and does not reload', async () => {
		const wrapper = await openDialog(LinkSourceDialog)
		const connection = { id: 'c-1', app: 'x', sourceTemplate: 'zgw-zaken' }
		wrapper.vm.onConnectionChanged(connection)
		get.mockClear()

		await wrapper.setProps({ open: false })
		await flushPromises()

		expect(wrapper.vm.selectedConnection).toEqual(connection)
		expect(wrapper.vm.mode).toBe('template')
		expect(get).not.toHaveBeenCalled()
	})
})

describe('CatalogItemDetailDialog actions', () => {
	it('falls back to the stored status when the live status cannot be read', async () => {
		store.fetchStatus = vi.fn().mockRejectedValue(new Error('down'))

		const wrapper = await openDialog(CatalogItemDetailDialog, { item: { id: 'item-1', title: 'ZGW' } })

		expect(store.fetchStatus).toHaveBeenCalledWith('item-1')
		expect(wrapper.vm.liveStatus).toBe(null)
		expect(wrapper.vm.statusLoading).toBe(false)
	})

	it('runs nothing for an item without an id', async () => {
		const wrapper = await openDialog(CatalogItemDetailDialog, { item: null })

		await wrapper.vm.runPrimaryAction()

		expect(store.instantiate).not.toHaveBeenCalled()
		expect(wrapper.vm.actionRunning).toBe(false)
	})
})
