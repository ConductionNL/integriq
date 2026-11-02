// @vitest-environment jsdom

import { translate } from '@nextcloud/l10n'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the dialogs that close through NcDialog's open state
 * (catalog item detail, export, link source, registry lookup, DSO activity
 * mapping, gateway catalogue), plus the catalog item detail and the link
 * source dialog in full: markup, mechanism texts, the primary action, and
 * loading and saving a source link with every failure.
 *
 * @spec openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CatalogItemDetailDialog from '@/dialogs/CatalogItemDetailDialog.vue'
import DsoActivityMappingDialog from '@/dialogs/DsoActivityMappingDialog.vue'
import ExportConfigurationDialog from '@/dialogs/ExportConfigurationDialog.vue'
import GatewayCatalogueDialog from '@/dialogs/GatewayCatalogueDialog.vue'
import LinkSourceDialog from '@/dialogs/LinkSourceDialog.vue'
import RegistryLookupDialog from '@/dialogs/RegistryLookupDialog.vue'

const { get, post, store } = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	store: { fetchStatus: null, instantiate: null, statusById: {} },
}))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@/store/catalog.js', () => ({ useCatalogStore: () => store }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			props: [
				'open',
				'name',
				'type',
				'variant',
				'disabled',
				'modelValue',
				'options',
			],
			emits: ['update:open'],
			render() {
				return h(
					'div',
					{ class: name, 'data-name': this.name, 'data-type': this.type },
					[this.$slots.default?.(), this.$slots.actions?.()],
				)
			},
		})
	return new Proxy(
		{},
		{
			get: (_t, key) =>
				typeof key === 'string' && /^Nc/.test(key) ? stub(key) : undefined,
			has: (_t, key) => typeof key === 'string' && /^Nc/.test(key),
		},
	)
})

globalThis.t = translate

/**
 * The rendered markup without scoped-style ids and HTML comments.
 *
 * @param {object} wrapper the mounted dialog
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/\n?\s*<!--[\s\S]*?-->/g, '')
}

/**
 * Mount a dialog closed, then open it.
 *
 * @param {object} component the dialog
 * @param {object} props extra props
 * @return {Promise<object>} the wrapper
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

beforeEach(() => {
	get.mockReset()
	post.mockReset()
	get.mockResolvedValue({ data: { results: [] } })
	post.mockResolvedValue({ data: {} })
	store.fetchStatus = vi.fn().mockResolvedValue(null)
	store.instantiate = vi.fn().mockResolvedValue({})
	store.statusById = {}
})

describe.each([
	['CatalogItemDetailDialog', CatalogItemDetailDialog],
	['ExportConfigurationDialog', ExportConfigurationDialog],
	['LinkSourceDialog', LinkSourceDialog],
	['RegistryLookupDialog', RegistryLookupDialog],
	['DsoActivityMappingDialog', DsoActivityMappingDialog],
	['GatewayCatalogueDialog', GatewayCatalogueDialog],
])('%s closing', (_name, component) => {
	it('closes when NcDialog closes, not when it opens', async () => {
		const wrapper = await openDialog(component)
		const dialog = wrapper.findComponent({ name: 'NcDialog' })
		dialog.vm.$emit('update:open', true)
		const afterOpen = (wrapper.emitted('close') || []).length
		dialog.vm.$emit('update:open', false)
		expect([afterOpen, (wrapper.emitted('close') || []).length]).toEqual([0, 1])
	})
})

describe('CatalogItemDetailDialog', () => {
	const ITEM = {
		'@self': { id: 'i1' },
		name: 'Catalog item',
		mechanism: 'mock-seeded',
		status: 'dormant',
		standards: ['ZGW', 'StUF'],
		sourceTemplateSlug: 'tpl',
	}

	it.each([
		['a dormant seeded item', ITEM],
		[
			'an item without standards',
			{ ...ITEM, standards: 'x', status: 'available' },
		],
		['a flag-gated item', { ...ITEM, mechanism: 'flag-gated', flagKey: 'f' }],
	])('renders %s as recorded', async (_label, item) => {
		const wrapper = await openDialog(CatalogItemDetailDialog, { item })
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it.each(['flag-gated', 'mock-seeded', 'always-available', 'other', undefined])(
		'labels mechanism %s',
		async (mechanism) => {
			const wrapper = await openDialog(CatalogItemDetailDialog, {
				item: { ...ITEM, mechanism },
			})
			expect([
				wrapper.vm.mechanism,
				wrapper.vm.mechanismLabel,
			]).toMatchSnapshot()
		},
	)

	it.each([
		['a source', () => Promise.resolve({ type: 'source' })],
		['a flag', () => Promise.resolve({ type: 'flag' })],
		[
			'a refusal',
			() => Promise.reject({ response: { data: { error: 'srv' } } }),
		],
		['a failure', () => Promise.reject(new Error('net'))],
		['a bare failure', () => Promise.reject({})],
	])('runs the primary action with %s as recorded', async (_label, answer) => {
		const wrapper = await openDialog(CatalogItemDetailDialog, { item: ITEM })
		store.instantiate = vi.fn(answer)
		store.statusById = { i1: { status: 'available' } }
		await wrapper.vm.runPrimaryAction()
		expect({
			calls: store.instantiate.mock.calls,
			liveStatus: wrapper.vm.liveStatus,
			successMessage: wrapper.vm.successMessage,
			errorMessage: wrapper.vm.errorMessage,
			actionRunning: wrapper.vm.actionRunning,
		}).toMatchSnapshot()
	})
})

describe('LinkSourceDialog', () => {
	const CONNECTIONS = {
		data: {
			results: [
				{
					id: 'c1',
					app: 'dossiq',
					title: 'Cases',
					declaration: { sourceTemplate: 'zgw' },
				},
				{ id: 'c2', app: 'pipelinq', title: 'Leads', source: 's9' },
				{ uuid: 'c3', app: 'dossiq', title: 'Docs' },
			],
		},
	}
	const SOURCES = {
		data: {
			results: [
				{ id: 's1', name: 'One' },
				{ uuid: 's2', title: 'Two' },
				{ id: 's3' },
				{ id: 's4', name: 'Named', title: 'Titled' },
			],
		},
	}

	/**
	 * Answer the two lists the dialog loads.
	 *
	 * @param {Function} connections the connections answer
	 * @param {Function} sources the sources answer
	 * @return {void}
	 */
	function answerLists(connections, sources) {
		get.mockImplementation((url) =>
			url.includes('app_connection') ? connections() : sources(),
		)
	}

	it.each([
		[
			'both lists',
			() => Promise.resolve(CONNECTIONS),
			() => Promise.resolve(SOURCES),
		],
		[
			'empty answers',
			() => Promise.resolve({ data: {} }),
			() => Promise.resolve({ data: null }),
		],
		[
			'a refused list',
			() => Promise.reject({ response: { data: { error: 'srv' } } }),
			() => Promise.resolve(SOURCES),
		],
		[
			'a failed list',
			() => Promise.resolve(CONNECTIONS),
			() => Promise.reject(new Error('net')),
		],
		['a bare failure', () => Promise.reject({}), () => Promise.resolve(SOURCES)],
	])('loads %s as recorded', async (_label, connections, sources) => {
		answerLists(connections, sources)
		const wrapper = await openDialog(LinkSourceDialog, { app: 'dossiq' })
		const vm = wrapper.vm
		expect({
			requests: get.mock.calls,
			connections: vm.connections,
			sourceOptions: vm.sourceOptions,
			appOptions: vm.appOptions,
			connectionOptions: vm.connectionOptions,
			errorMessage: vm.errorMessage,
			loading: vm.loading,
		}).toMatchSnapshot()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it.each([
		[
			'an existing source',
			'existing',
			() =>
				Promise.resolve({
					data: { probe: { ok: true }, connection: { id: 'c3' } },
				}),
		],
		['the template', 'template', () => Promise.resolve({ data: {} })],
		[
			'a refused link',
			'existing',
			() => Promise.reject({ response: { data: { error: 'srv' } } }),
		],
		['a failed link', 'existing', () => Promise.reject(new Error('net'))],
		['a bare failure', 'template', () => Promise.reject({})],
	])('saves %s as recorded', async (_label, mode, answer) => {
		answerLists(
			() => Promise.resolve(CONNECTIONS),
			() => Promise.resolve(SOURCES),
		)
		const wrapper = await openDialog(LinkSourceDialog)
		const vm = wrapper.vm
		vm.onConnectionChanged(vm.connections[1])
		vm.onSourceChanged(vm.sourceOptions[0])
		vm.onModeChanged(mode)
		vm.errorMessage = 'old'
		post.mockImplementation(answer)
		const saving = vm.save()
		const during = vm.saving
		await saving
		expect({
			during,
			calls: post.mock.calls,
			probe: vm.probe,
			linked: vm.linked,
			emitted: wrapper.emitted('linked') ?? null,
			errorMessage: vm.errorMessage,
			saving: vm.saving,
		}).toMatchSnapshot()
	})

	it('filters and picks as recorded', async () => {
		answerLists(
			() => Promise.resolve(CONNECTIONS),
			() => Promise.resolve(SOURCES),
		)
		const wrapper = await openDialog(LinkSourceDialog)
		const vm = wrapper.vm
		const steps = []
		vm.onConnectionChanged(vm.connections[0])
		steps.push([vm.mode, vm.canSave])
		vm.onAppChanged('pipelinq')
		steps.push([
			vm.appFilter,
			vm.selectedConnection,
			vm.connectionOptions.length,
		])
		vm.onConnectionChanged(null)
		vm.onSourceChanged(null)
		steps.push([vm.mode, vm.canSave, vm.selectedSource])
		vm.onAppChanged('')
		steps.push([vm.appFilter, vm.connectionOptions.length])
		expect(JSON.parse(JSON.stringify(steps))).toMatchSnapshot()
	})
})
