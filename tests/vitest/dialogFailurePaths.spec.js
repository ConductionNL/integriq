// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The registry lookup, configuration import and configuration export dialogs
 * (src/dialogs/), mounted for real with axios, the router and the Nextcloud
 * components replaced. The other specs follow the path where every request
 * succeeds; these follow the ones where a request fails, an answer is empty
 * or the operator has not chosen yet, and assert what each dialog asks the
 * server and what it says back.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const { showSuccess } = vi.hoisted(() => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess, showError: vi.fn() }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			emits: ['click', 'update:modelValue', 'update:open', 'keyup'],
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
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue']),
		NcDialog: stub('NcDialog', ['open', 'name', 'size']),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcSelect: stub('NcSelect', [
			'modelValue',
			'options',
			'inputLabel',
			'loading',
			'placeholder',
			'label',
		]),
		NcTextField: stub('NcTextField', ['modelValue', 'label']),
	}
})

// The import and export dialogs call the global t() Nextcloud installs.
globalThis.t = (_app, text, vars = {}) =>
	text.replace(/\{(\w+)\}/g, (_match, key) => String(vars[key] ?? ''))

const RegistryLookupDialog = (await import('@/dialogs/RegistryLookupDialog.vue')).default
const ImportPreviewDialog = (await import('@/dialogs/ImportPreviewDialog.vue')).default
const ExportConfigurationDialog = (await import('@/dialogs/ExportConfigurationDialog.vue')).default
const { setRouter } = await import('@/handlers/routerRef.js')

const API = '/apps/integriq/api/property-sources'

const PROVIDERS = [
	{ id: 'bag', label: 'BAG address', listShaped: false },
	{ id: 'selectielijst', listShaped: true },
]

/**
 * A rejected request carrying the server's error body.
 *
 * @param {string} message The error the server returned.
 * @return {Promise<never>} The rejection.
 */
function serverError(message) {
	return Promise.reject({ response: { data: { error: message } } })
}

/**
 * The text of every error note the wrapper shows.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {string[]} The texts.
 */
function errorNotes(wrapper) {
	return wrapper
		.findAllComponents({ name: 'NcNoteCard' })
		.filter((note) => note.props('type') === 'error')
		.map((note) => note.text())
}

/**
 * Mount a dialog whose template uses the t() the app installs as a global property.
 *
 * @param {object} component The dialog.
 * @param {boolean} open Whether it starts open.
 * @return {object} The wrapper.
 */
function mountGlobalT(component, open) {
	return mount(component, { props: { open }, global: { mocks: { t: globalThis.t } } })
}

beforeEach(() => {
	get.mockReset()
	post.mockReset()
	showSuccess.mockReset()
})

describe('registry lookup when the registry does not cooperate', () => {
	/**
	 * Mount the dialog open with the given provider list answer, and pick a provider.
	 *
	 * @param {number} index Which provider to pick.
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountPicked(index = 0) {
		get.mockResolvedValueOnce({ data: { results: PROVIDERS } })
		const wrapper = mount(RegistryLookupDialog, { props: { open: true } })
		await flushPromises()
		wrapper.vm.selectedProvider = wrapper.vm.providerOptions[index]
		await flushPromises()
		get.mockReset()
		return wrapper
	}

	it('labels a provider without a label by its id', async () => {
		const wrapper = await mountPicked(1)
		expect(wrapper.vm.providerOptions.map((option) => option.label)).toEqual([
			'BAG address',
			'selectielijst',
		])
	})

	it('offers no registries when the list cannot be loaded, and stops loading', async () => {
		get.mockRejectedValueOnce(new Error('offline'))
		const wrapper = mount(RegistryLookupDialog, { props: { open: true } })
		await flushPromises()

		expect(get).toHaveBeenCalledWith(API)
		expect(wrapper.vm.providerOptions).toEqual([])
		expect(wrapper.vm.loadingProviders).toBe(false)
	})

	it('loads the registries only once the dialog is opened', async () => {
		const wrapper = mount(RegistryLookupDialog, { props: { open: false } })
		await flushPromises()
		expect(get).not.toHaveBeenCalled()

		get.mockResolvedValueOnce({ data: { results: PROVIDERS } })
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(get).toHaveBeenCalledWith(API)
		expect(wrapper.vm.providerOptions).toHaveLength(2)

		await wrapper.setProps({ open: false })
		await flushPromises()
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('does not search for a blank query', async () => {
		const wrapper = await mountPicked()
		wrapper.vm.query = '   '
		await wrapper.vm.runSuggest()
		expect(get).not.toHaveBeenCalled()
		expect(wrapper.vm.searched).toBe(false)
	})

	it('says the registry found nothing when the search is empty', async () => {
		const wrapper = await mountPicked()
		get.mockResolvedValueOnce({ data: { results: [] } })
		wrapper.vm.query = 'Nowhere 1'
		await wrapper.vm.runSuggest()
		await flushPromises()

		expect(wrapper.text()).toContain('The registry found nothing for this search.')
		expect(wrapper.find('[data-testid="registry-lookup-suggestions"]').exists()).toBe(false)
	})

	it('shows the server error of a failed search, else a generic one', async () => {
		const wrapper = await mountPicked()
		get.mockImplementationOnce(() => serverError('Search quota exceeded'))
		wrapper.vm.query = 'Domplein'
		await wrapper.vm.runSuggest()
		await flushPromises()
		expect(wrapper.find('[data-testid="registry-lookup-error"]').text()).toBe(
			'Search quota exceeded',
		)
		expect(wrapper.vm.suggestions).toEqual([])
		expect(wrapper.vm.busy).toBe(false)

		get.mockRejectedValueOnce(new Error('network'))
		await wrapper.vm.runSuggest()
		await flushPromises()
		expect(wrapper.vm.error).toBe('The search failed.')
	})

	it('does not resolve without an identifier', async () => {
		const wrapper = await mountPicked()
		await wrapper.vm.resolve('', false)
		await wrapper.vm.resolve(undefined, true)
		expect(get).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('shows the server error of a failed lookup, else a generic one', async () => {
		const wrapper = await mountPicked()
		get.mockImplementationOnce(() => serverError('Unknown address'))
		await wrapper.vm.resolve('adr-1', false)
		await flushPromises()
		expect(get).toHaveBeenCalledWith(`${API}/bag/resolve`, {
			params: { identifier: 'adr-1' },
		})
		expect(wrapper.vm.error).toBe('Unknown address')
		expect(wrapper.vm.resolved).toBeNull()

		get.mockRejectedValueOnce(new Error('network'))
		await wrapper.vm.resolve('adr-1', true)
		await flushPromises()
		expect(wrapper.vm.error).toBe('The lookup failed.')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('marks an unreachable registry as a warning and a cached value as info', async () => {
		const wrapper = await mountPicked()
		get.mockResolvedValueOnce({
			data: {
				value: 'x',
				provenance: { unreachable: true, live: false, cacheAgeSeconds: 60 },
			},
		})
		await wrapper.vm.resolve('adr-1', false)
		await flushPromises()
		expect(wrapper.vm.stateNoteType).toBe('warning')
		expect(
			wrapper.findComponent('[data-testid="registry-lookup-state"]').props('type'),
		).toBe('warning')

		get.mockResolvedValueOnce({
			data: {
				value: 'x',
				provenance: { unreachable: false, live: false, cacheAgeSeconds: 7200 },
			},
		})
		await wrapper.vm.resolve('adr-1', false)
		await flushPromises()
		expect(wrapper.vm.stateNoteType).toBe('info')
	})

	it('reports a successful resync with the number of changed entries', async () => {
		const wrapper = await mountPicked(1)
		post.mockResolvedValueOnce({ data: { succeeded: true, changed: 4 } })
		await wrapper.find('[data-testid="registry-lookup-resync"]').trigger('click')
		await flushPromises()

		const report = wrapper.findComponent('[data-testid="registry-lookup-resync-report"]')
		expect(report.props('type')).toBe('success')
		expect(report.text()).toBe('Resynced. 4 entries changed.')

		post.mockResolvedValueOnce({ data: { succeeded: true } })
		await wrapper.vm.runResync()
		await flushPromises()
		expect(wrapper.vm.resyncText).toBe('Resynced. 0 entries changed.')
	})

	it('falls back to the error field when a failed resync has no message', async () => {
		const wrapper = await mountPicked(1)
		post.mockResolvedValueOnce({ data: { succeeded: false, error: 'Timeout' } })
		await wrapper.vm.runResync()
		await flushPromises()
		expect(wrapper.vm.resyncText).toBe(
			'The resync failed, so the previous list stays in use: Timeout',
		)
	})

	it('shows the server error of a resync request that fails, else a generic one', async () => {
		const wrapper = await mountPicked(1)
		post.mockImplementationOnce(() => serverError('Not allowed'))
		await wrapper.vm.runResync()
		await flushPromises()
		expect(post).toHaveBeenCalledWith(`${API}/selectielijst/resync`)
		expect(wrapper.vm.error).toBe('Not allowed')
		expect(wrapper.vm.resyncReport).toBeNull()

		post.mockRejectedValueOnce(new Error('network'))
		await wrapper.vm.runResync()
		await flushPromises()
		expect(wrapper.vm.error).toBe('The resync failed.')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('clears the previous search and value when another registry is picked', async () => {
		const wrapper = await mountPicked()
		wrapper.vm.query = 'Domplein'
		wrapper.vm.suggestions = [{ identifier: 'a', label: 'A' }]
		wrapper.vm.searched = true
		wrapper.vm.resolved = { value: 'x', provenance: {} }
		wrapper.vm.resyncReport = { succeeded: true }
		wrapper.vm.error = 'old'

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit(
			'update:modelValue',
			wrapper.vm.providerOptions[1],
		)
		await flushPromises()

		expect(wrapper.vm.query).toBe('')
		expect(wrapper.vm.suggestions).toEqual([])
		expect(wrapper.vm.searched).toBe(false)
		expect(wrapper.vm.resolved).toBeNull()
		expect(wrapper.vm.resyncReport).toBeNull()
		expect(wrapper.vm.error).toBe('')
	})

	it('asks the host to close when the dialog closes or Close is pressed', async () => {
		const wrapper = await mountPicked()
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', true)
		expect(wrapper.emitted('close')).toBeUndefined()

		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('configuration import when the file or the server does not cooperate', () => {
	afterEach(() => {
		setRouter(null)
	})

	/**
	 * Choose a file in the dialog's file input.
	 *
	 * @param {object} wrapper The mounted dialog.
	 * @param {string|null} content The file's text, or null for no file.
	 * @return {Promise<void>}
	 */
	async function choose(wrapper, content) {
		const files = content === null ? [] : [{ text: () => Promise.resolve(content) }]
		await wrapper.vm.onFileChosen({ target: { files } })
		await flushPromises()
	}

	it('does nothing when no file was chosen', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		await choose(wrapper, null)
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.vm.step).toBe('pick')
	})

	it('refuses a file that is not JSON without asking the server', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		await choose(wrapper, '{not json')

		expect(post).not.toHaveBeenCalled()
		expect(wrapper.vm.document).toBeNull()
		expect(errorNotes(wrapper)).toEqual(['The selected file is not valid JSON'])
	})

	it('stays on the file step and shows why when the preview fails', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		post.mockImplementationOnce(() => serverError('Unsupported version'))
		await choose(wrapper, '{"version":9}')

		expect(post).toHaveBeenCalledWith(
			'/apps/integriq/api/configurations/import/preview',
			{ document: { version: 9 } },
		)
		expect(wrapper.vm.step).toBe('pick')
		expect(errorNotes(wrapper)).toEqual(['Preview failed: Unsupported version'])
	})

	it('shows the preview buckets, collisions, dangling references and credentials', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		post.mockResolvedValueOnce({
			data: {
				creates: [{ type: 'source', slug: 'kvk' }],
				collisions: [{ type: 'mapping', slug: 'm1', reason: 'exists' }],
				unresolvedReferences: [
					{ type: 'endpoint', slug: 'e1', field: 'source', value: 'gone' },
				],
				credentialsNeedingReentry: [{ slug: 'kvk', fields: ['apikey', 'secret'] }],
			},
		})
		await choose(wrapper, '{"a":1}')

		expect(wrapper.vm.step).toBe('preview')
		expect(wrapper.find('[data-testid="preview-creates"]').text()).toContain('kvk')
		expect(wrapper.find('[data-testid="preview-collisions"]').text()).toContain(
			'm1 —',
		)
		expect(wrapper.find('[data-testid="preview-unresolved"]').text()).toContain(
			'source = "gone"',
		)
		expect(wrapper.find('[data-testid="preview-credentials"]').text()).toContain(
			'kvk: apikey, secret',
		)
		expect(wrapper.vm.confirmBlocked).toBe(true)
	})

	it('does not import while dangling references are unacknowledged', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		post.mockResolvedValueOnce({
			data: { unresolvedReferences: [{ type: 'e', slug: 's', field: 'f', value: 'v' }] },
		})
		await choose(wrapper, '{"a":1}')
		post.mockReset()

		await wrapper.vm.confirmImport()
		expect(post).not.toHaveBeenCalled()

		wrapper.vm.unresolvedAcknowledged = true
		post.mockResolvedValueOnce({ data: {} })
		await wrapper.vm.confirmImport()
		await flushPromises()
		expect(post).toHaveBeenCalledWith('/apps/integriq/api/configurations/import', {
			document: { a: 1 },
			confirmed: true,
		})
		expect(wrapper.vm.step).toBe('done')
	})

	it('does not import before a document was previewed', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		await wrapper.vm.confirmImport()
		expect(post).not.toHaveBeenCalled()
	})

	it('keeps the preview and shows why when the import fails', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		post.mockResolvedValueOnce({ data: { creates: [] } })
		await choose(wrapper, '{"a":1}')
		post.mockRejectedValueOnce(new Error('Gateway timeout'))
		await wrapper.find('[data-testid="confirm-import"]').trigger('click')
		await flushPromises()

		expect(wrapper.vm.step).toBe('preview')
		expect(wrapper.vm.importRunning).toBe(false)
		expect(errorNotes(wrapper)).toEqual(['Import failed: Gateway timeout'])
	})

	it('lists the credentials to re-enter after import and opens Sources', async () => {
		const push = vi.fn(() => Promise.reject(new Error('navigation aborted')))
		setRouter({ push })
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		post.mockResolvedValueOnce({ data: {} })
		await choose(wrapper, '{"a":1}')
		post.mockResolvedValueOnce({
			data: { credentialsNeedingReentry: [{ slug: 'kvk', fields: ['apikey'] }] },
		})
		await wrapper.vm.confirmImport()
		await flushPromises()

		expect(wrapper.find('[data-testid="import-success"]').exists()).toBe(true)
		const summary = wrapper.find('[data-testid="import-credentials-summary"]')
		expect(summary.text()).toContain('kvk: apikey')

		await summary.find('.NcButton').trigger('click')
		await flushPromises()
		expect(push).toHaveBeenCalledWith({ name: 'Sources' })
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('still closes from the summary when no router is registered', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, true)
		wrapper.vm.goToSources()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('starts over at the file step each time it is opened', async () => {
		const wrapper = mountGlobalT(ImportPreviewDialog, false)
		wrapper.vm.step = 'done'
		wrapper.vm.document = { a: 1 }
		wrapper.vm.unresolvedAcknowledged = true
		wrapper.vm.errorMessage = 'old'

		await wrapper.setProps({ open: true })

		expect(wrapper.vm.step).toBe('pick')
		expect(wrapper.vm.document).toBeNull()
		expect(wrapper.vm.unresolvedAcknowledged).toBe(false)
		expect(wrapper.vm.errorMessage).toBe('')
	})
})

describe('configuration export before a group is chosen', () => {
	it('lists the groups on open, labelled by title, name or id, and forgets the old choice', async () => {
		const wrapper = mountGlobalT(ExportConfigurationDialog, false)
		wrapper.vm.selected = { id: 'old', label: 'Old' }
		get.mockResolvedValueOnce({
			data: {
				results: [
					{ uuid: 'u1', title: 'Production' },
					{ id: 7, name: 'Staging' },
					{ uuid: 'u3' },
					{ id: 9 },
				],
			},
		})
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/apps/openregister/api/configurations')
		expect(wrapper.vm.selected).toBeNull()
		expect(wrapper.vm.options).toEqual([
			{ id: 'u1', label: 'Production' },
			{ id: 7, label: 'Staging' },
			{ id: 'u3', label: 'u3' },
			{ id: 9, label: '9' },
		])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('says there are no groups when the answer is empty', async () => {
		const wrapper = mountGlobalT(ExportConfigurationDialog, false)
		get.mockResolvedValueOnce({ data: {} })
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(wrapper.vm.options).toEqual([])
		expect(wrapper.text()).toContain('No configuration groups found.')
	})

	it('shows why the groups could not be loaded', async () => {
		const wrapper = mountGlobalT(ExportConfigurationDialog, false)
		get.mockImplementationOnce(() => serverError('Forbidden'))
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(errorNotes(wrapper)).toEqual(['Could not load configuration groups: Forbidden'])

		get.mockRejectedValueOnce({})
		await wrapper.vm.fetchConfigurations()
		expect(wrapper.vm.errorMessage).toBe('Could not load configuration groups')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('takes the chosen group from the select, and exports nothing without one', async () => {
		const wrapper = mountGlobalT(ExportConfigurationDialog, false)
		const select = wrapper.findComponent({ name: 'NcSelect' })

		select.vm.$emit('update:modelValue', { id: 'u1', label: 'Production' })
		expect(wrapper.vm.selected).toEqual({ id: 'u1', label: 'Production' })

		select.vm.$emit('update:modelValue', undefined)
		expect(wrapper.vm.selected).toBeNull()

		await wrapper.vm.runExport()
		expect(post).not.toHaveBeenCalled()
		expect(showSuccess).not.toHaveBeenCalled()
	})
})
