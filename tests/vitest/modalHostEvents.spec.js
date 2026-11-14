// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The modal host as the row actions use it: each modal-bus event opens its
 * modal with the payload it carried, the modal's close puts it back to its
 * empty state, an unmounted host no longer listens, `?link=1` on the App
 * connections page opens the link dialog pre-filtered and drops `link` from
 * the URL, and linking a source reloads that page's list.
 *
 * @spec openspec/specs/app-shell-and-logs-ui/spec.md#requirement-shared-runtest-modal-for-row-actions-req-shellui-004
 * @spec openspec/specs/connection-registry/spec.md#scenario-the-link-query-opens-the-dialog-pre-filtered
 */
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

/**
 * Mock one child modal as a stub that shows its props and can close.
 *
 * @param {string} path The module path, relative to this spec.
 * @param {string} name The component name.
 * @param {Array<string>} props Its props.
 */
function stubModal(path, name, props) {
	vi.doMock(path, async () => {
		const { defineComponent, h } = await import('vue')
		return {
			default: defineComponent({
				name,
				props: ['open', ...props],
				emits: ['close', 'linked'],
				render() {
					return h('div', { 'data-modal': name, 'data-open': String(this.open) })
				},
			}),
		}
	})
}

stubModal('../../src/modals/v2/TestMappingModal.vue', 'TestMappingModal', ['mapping'])
stubModal('../../src/modals/v2/TestSourceModal.vue', 'TestSourceModal', ['source'])
stubModal('../../src/modals/v2/AddEndpointRuleModal.vue', 'AddEndpointRuleModal', ['endpoint'])
stubModal('../../src/modals/v2/RunActionModal.vue', 'RunActionModal', ['target', 'mode', 'item'])
stubModal('../../src/modals/Directory/DirectoryRunModal.vue', 'DirectoryRunModal', ['source', 'mode'])
stubModal('../../src/modals/Subscription/SubscriptionSigningModal.vue', 'SubscriptionSigningModal', ['subscription'])
stubModal('../../src/dialogs/CatalogItemDetailDialog.vue', 'CatalogItemDetailDialog', ['item'])
stubModal('../../src/dialogs/ImportPreviewDialog.vue', 'ImportPreviewDialog', [])
stubModal('../../src/dialogs/ExportConfigurationDialog.vue', 'ExportConfigurationDialog', [])
stubModal('../../src/modals/PromotePreviewModal.vue', 'PromotePreviewModal', [])
stubModal('../../src/dialogs/RegistryLookupDialog.vue', 'RegistryLookupDialog', [])
stubModal('../../src/dialogs/GatewayCatalogueDialog.vue', 'GatewayCatalogueDialog', [])
stubModal('../../src/dialogs/OpenApiImportDialog.vue', 'OpenApiImportDialog', [])
stubModal('../../src/dialogs/LinkSourceDialog.vue', 'LinkSourceDialog', ['app'])

const bus = await import('../../src/handlers/modalBus.js')
const { default: ModalHost } = await import('../../src/modals/v2/ModalHost.vue')

let wrapper

/**
 * Mount the host on a route.
 *
 * @param {object} route The current route (`name`, `query`).
 * @return {object} The wrapper and its router double.
 */
function mountHost(route = { name: 'Dashboard', query: {} }) {
	const router = { replace: vi.fn() }
	wrapper = mount(ModalHost, { global: { mocks: { $route: route, $router: router } } })
	return { wrapper, router }
}

/**
 * The stub of one modal.
 *
 * @param {string} name The component name.
 * @return {object} The stub's wrapper.
 */
const modal = (name) => wrapper.getComponent({ name })

afterEach(() => {
	wrapper?.unmount()
	wrapper = undefined
})

const cases = [
	[bus.EVENT_OPEN_TEST_MAPPING, 'TestMappingModal', { mapping: { id: 'm1' } }, { mapping: null }],
	[bus.EVENT_OPEN_TEST_SOURCE, 'TestSourceModal', { source: { id: 's1' } }, { source: null }],
	[bus.EVENT_OPEN_ADD_ENDPOINT_RULE, 'AddEndpointRuleModal', { endpoint: { id: 'e1' } }, { endpoint: null }],
	[bus.EVENT_OPEN_RUN_ACTION, 'RunActionModal', { target: 'synchronization', mode: 'test', item: { id: 'y1' } }, { target: '', mode: '', item: null }],
	[bus.EVENT_OPEN_DIRECTORY_RUN, 'DirectoryRunModal', { source: { id: 'd1' }, mode: 'preview' }, { source: null, mode: 'run' }],
	[bus.EVENT_OPEN_SUBSCRIPTION_SIGNING, 'SubscriptionSigningModal', { subscription: { id: 'sub1' } }, { subscription: null }],
	[bus.EVENT_OPEN_CATALOG_ITEM_DETAIL, 'CatalogItemDetailDialog', { item: { id: 'c1' } }, { item: null }],
	[bus.EVENT_OPEN_CONFIGURATION_IMPORT, 'ImportPreviewDialog', {}, {}],
	[bus.EVENT_OPEN_CONFIGURATION_EXPORT, 'ExportConfigurationDialog', {}, {}],
	[bus.EVENT_OPEN_PROMOTION, 'PromotePreviewModal', {}, {}],
	[bus.EVENT_OPEN_REGISTRY_LOOKUP, 'RegistryLookupDialog', {}, {}],
	[bus.EVENT_OPEN_GATEWAY_CATALOGUE, 'GatewayCatalogueDialog', {}, {}],
	[bus.EVENT_OPEN_OPENAPI_IMPORT, 'OpenApiImportDialog', {}, {}],
	[bus.EVENT_OPEN_LINK_SOURCE, 'LinkSourceDialog', { app: 'dossiq' }, { app: '' }],
]

describe('ModalHost', () => {
	it.each(cases)('%s opens %s with its payload, and its close empties it again', async (event, name, payload, empty) => {
		mountHost()
		expect(modal(name).props('open')).toBe(false)

		bus.modalBus.emit(event, payload)
		await wrapper.vm.$nextTick()
		expect(modal(name).props('open')).toBe(true)
		for (const [key, value] of Object.entries(payload)) {
			expect(modal(name).props(key)).toEqual(value)
		}

		modal(name).vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(modal(name).props('open')).toBe(false)
		for (const [key, value] of Object.entries(empty)) {
			expect(modal(name).props(key)).toEqual(value)
		}
	})

	it('falls back to empty values when an event carries no payload', async () => {
		mountHost()
		bus.modalBus.emit(bus.EVENT_OPEN_RUN_ACTION)
		bus.modalBus.emit(bus.EVENT_OPEN_DIRECTORY_RUN)
		bus.modalBus.emit(bus.EVENT_OPEN_LINK_SOURCE)
		await wrapper.vm.$nextTick()

		expect(modal('RunActionModal').props()).toMatchObject({ open: true, target: '', mode: '', item: null })
		expect(modal('DirectoryRunModal').props()).toMatchObject({ open: true, source: null, mode: 'run' })
		expect(modal('LinkSourceDialog').props()).toMatchObject({ open: true, app: '' })
	})

	it('stops listening once unmounted', async () => {
		mountHost()
		const vm = wrapper.vm
		wrapper.unmount()
		wrapper = undefined

		for (const [event, , payload] of cases) {
			bus.modalBus.emit(event, payload)
		}
		expect(vm.testMapping.open).toBe(false)
		expect(vm.runAction.open).toBe(false)
		expect(vm.linkSource.open).toBe(false)
	})

	it('opens the link dialog for ?link=1 on App connections and drops link from the URL', () => {
		const { router } = mountHost({ name: 'AppConnections', query: { link: '1', app: 'pipelinq', status: 'down' } })

		expect(modal('LinkSourceDialog').props()).toMatchObject({ open: true, app: 'pipelinq' })
		expect(router.replace).toHaveBeenCalledWith({ query: { app: 'pipelinq', status: 'down' } })
	})

	it('ignores ?link=1 on other pages, and link values other than 1', () => {
		const other = mountHost({ name: 'Sources', query: { link: '1' } })
		expect(modal('LinkSourceDialog').props('open')).toBe(false)
		expect(other.router.replace).not.toHaveBeenCalled()
		wrapper.unmount()

		const zero = mountHost({ name: 'AppConnections', query: { link: '0', app: ['a', 'b'] } })
		expect(modal('LinkSourceDialog').props('open')).toBe(false)
		expect(zero.router.replace).not.toHaveBeenCalled()
	})

	it('opens the link dialog unfiltered when the app is not a single value', () => {
		mountHost({ name: 'AppConnections', query: { link: '1', app: ['a', 'b'] } })

		expect(modal('LinkSourceDialog').props('app')).toBe('')
	})

	it('reloads the App connections list after a link, and does nothing elsewhere', () => {
		vi.spyOn(Date, 'now').mockReturnValue(1700000000000)
		const onPage = mountHost({ name: 'AppConnections', query: { app: 'pipelinq' } })
		modal('LinkSourceDialog').vm.$emit('linked')
		expect(onPage.router.replace).toHaveBeenCalledWith({ query: { app: 'pipelinq', _linked: '1700000000000' } })
		wrapper.unmount()

		const elsewhere = mountHost({ name: 'Sources', query: {} })
		modal('LinkSourceDialog').vm.$emit('linked')
		expect(elsewhere.router.replace).not.toHaveBeenCalled()
		vi.restoreAllMocks()
	})
})
