// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The v2 action modals: RunActionModal, TestSourceModal, AddEndpointRuleModal
 * and TestMappingModal. What each sends, what it shows afterwards, and what
 * reopening resets.
 */

import { translate } from '@nextcloud/l10n'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { http, toasts } = vi.hoisted(() => ({
	http: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
	toasts: [],
}))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.push(['error', text]),
	showSuccess: (text) => toasts.push(['success', text]),
}))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			props: ['modelValue', 'disabled', 'type', 'variant'],
			emits: ['click', 'update:modelValue', 'close'],
			render() {
				return h(
					'div',
					{
						class: name,
						'data-type': this.type,
						'data-disabled': this.disabled ? 'yes' : undefined,
						onClick: () => this.$emit('click'),
					},
					[this.$slots.icon?.(), this.$slots.default?.()],
				)
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

const RunActionModal = (await import('@/modals/v2/RunActionModal.vue')).default
const TestSourceModal = (await import('@/modals/v2/TestSourceModal.vue')).default
const AddEndpointRuleModal = (await import('@/modals/v2/AddEndpointRuleModal.vue')).default
const TestMappingModal = (await import('@/modals/v2/TestMappingModal.vue')).default

const GLOBAL = { mocks: { t: translate, n: (app, one) => one } }

/**
 * Mount closed, then open, so the open watcher runs as it does in the app.
 *
 * @param {object} component The modal.
 * @param {object} props     Its props.
 * @param {object} extra     Extra global options.
 * @return {Promise<object>} The wrapper.
 */
async function opened(component, props, extra = {}) {
	const wrapper = mount(component, {
		props: { ...props, open: false },
		global: { ...GLOBAL, ...extra },
	})
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	for (const fn of Object.values(http)) {
		fn.mockReset()
	}
	toasts.length = 0
})

describe('RunActionModal', () => {
	it('renders nothing for an unknown target', async () => {
		const wrapper = await opened(RunActionModal, { target: 'nope', mode: 'run', item: { id: 1 } })
		expect(wrapper.html()).not.toContain('cn-run-action')
	})

	it('seeds a locked option on and keeps it on', async () => {
		const wrapper = await opened(RunActionModal, { target: 'job', mode: 'test', item: { id: 7, name: 'Nightly' } })
		expect(wrapper.vm.values).toEqual({ forceRun: true })
		expect(wrapper.text()).toContain('Nightly')
		const option = wrapper.vm.options[0]
		expect(wrapper.vm.isOptionDisabled(option)).toBe(true)
		wrapper.vm.setOption(option, false)
		expect(wrapper.vm.values.forceRun).toBe(true)
	})

	it('posts the run with its switches and shows the job result', async () => {
		http.post.mockResolvedValueOnce({ data: { level: 'INFO', executionTime: 12.4, jobClass: 'X' } })
		const wrapper = await opened(RunActionModal, { target: 'job', mode: 'run', item: { id: 7 } })
		wrapper.vm.setOption(wrapper.vm.options[0], true)

		await wrapper.vm.fire()
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/jobs/run/7', { forceRun: true })
		expect(wrapper.vm.step).toBe('result')
		expect(wrapper.vm.sections.map((s) => s.id)).toEqual(['run'])
		expect(wrapper.vm.formattedPayload).toContain('"jobClass": "X"')
	})

	it('re-routes a synchronization run in test mode and hides force deletion', async () => {
		http.post.mockResolvedValueOnce({ data: null })
		const wrapper = await opened(RunActionModal, { target: 'synchronization', mode: 'run', item: { id: 's1' } })
		expect(wrapper.vm.options.map((o) => o.key)).toEqual(['test', 'force', 'forceDeletion'])

		wrapper.vm.setOption(wrapper.vm.options[0], true)
		expect(wrapper.vm.options.map((o) => o.key)).toEqual(['test', 'force'])

		await wrapper.vm.fire()
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/synchronizations/s1/test', { force: false })
		expect(wrapper.vm.payload).toBeNull()
	})

	it('disables force deletion for an incremental synchronization and says why', async () => {
		const wrapper = await opened(RunActionModal, { target: 'synchronization', mode: 'run', item: { id: 's1', syncMode: 'incremental' } })
		const option = wrapper.vm.options.find((o) => o.key === 'forceDeletion')

		expect(wrapper.vm.isOptionDisabled(option)).toBe(true)
		expect(wrapper.vm.noteTypeFor(option)).toBe('info')
		expect(wrapper.vm.noteFor(option)).toContain('incremental mode')
		wrapper.vm.setOption(option, true)
		expect(wrapper.vm.values.forceDeletion).toBe(false)

		const full = await opened(RunActionModal, { target: 'synchronization', mode: 'run', item: { id: 's1' } })
		const fullOption = full.vm.options.find((o) => o.key === 'forceDeletion')
		expect(full.vm.noteTypeFor(fullOption)).toBe('warning')
		expect(full.vm.noteFor(fullOption)).toContain('deletion-ratio guard')
	})

	it('shows the server error and hides sections, logs and retry', async () => {
		http.post.mockRejectedValueOnce({ response: { data: { message: 'Source down' } } })
		const wrapper = await opened(RunActionModal, { target: 'job', mode: 'run', item: { id: 7 } })

		await wrapper.vm.fire()
		await flushPromises()

		expect(wrapper.vm.status).toEqual({ type: 'error', text: 'Source down' })
		expect(wrapper.vm.sections).toEqual([])
		expect(wrapper.vm.logsLink).toBeNull()
		expect(wrapper.vm.retryAction).toBeNull()
	})

	it('falls back through error fields to a generic sentence', () => {
		const wrapper = mount(RunActionModal, { props: { open: false }, global: GLOBAL })
		expect(wrapper.vm.extractError({ response: { data: { error: 'E' } } })).toBe('E')
		expect(wrapper.vm.extractError({ message: 'network' })).toBe('network')
		expect(wrapper.vm.extractError(undefined)).toBe('The request failed.')
	})

	it('sends nothing for a row without an id', async () => {
		const wrapper = await opened(RunActionModal, { target: 'job', mode: 'run', item: {} })
		expect(wrapper.text()).toContain('This row has no id, so it cannot be run.')
		await wrapper.vm.fire()
		expect(http.post).not.toHaveBeenCalled()
		expect(wrapper.vm.step).toBe('options')
	})

	it('offers a force-deletion retry when the ratio guard stopped the run, and runs again with it', async () => {
		http.post.mockResolvedValueOnce({ data: { result: { objects: { deletionGuard: { guarded: true, reason: 'ratio_threshold_exceeded' } } } } })
		const wrapper = await opened(RunActionModal, { target: 'synchronization', mode: 'run', item: { id: 's1' } })
		await wrapper.vm.fire()
		await flushPromises()

		const retry = wrapper.vm.retryAction
		expect(retry).toEqual({ label: 'Run again with force deletion', values: { forceDeletion: true } })

		wrapper.vm.runAgain(retry.values)
		expect(wrapper.vm.step).toBe('options')
		expect(wrapper.vm.payload).toBeNull()
		expect(wrapper.vm.values).toEqual({ test: false, force: false, forceDeletion: true })
	})

	it('reopening resets the switches and the result', async () => {
		http.post.mockResolvedValueOnce({ data: {} })
		const wrapper = await opened(RunActionModal, { target: 'job', mode: 'run', item: { id: 7 } })
		wrapper.vm.setOption(wrapper.vm.options[0], true)
		await wrapper.vm.fire()
		await flushPromises()

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.step).toBe('options')
		expect(wrapper.vm.values).toEqual({ forceRun: false })
		expect(wrapper.vm.payload).toBeNull()
	})

	it('closes and navigates to the logs, swallowing only a duplicate navigation', async () => {
		const push = vi.fn().mockImplementationOnce(() => Promise.reject(Object.assign(new Error('dup'), { name: 'NavigationDuplicated' })))
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const wrapper = await opened(RunActionModal, { target: 'job', mode: 'run', item: { id: 7 } }, { mocks: { ...GLOBAL.mocks, $router: { push } } })

		const link = wrapper.vm.logsLink
		expect(link).not.toBeNull()
		wrapper.vm.openLogs()
		await flushPromises()

		expect(push).toHaveBeenCalledWith(link)
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(warn).not.toHaveBeenCalled()

		push.mockImplementationOnce(() => Promise.reject(new Error('other')))
		wrapper.vm.openLogs()
		await flushPromises()
		expect(warn).toHaveBeenCalledTimes(1)
		warn.mockRestore()
	})
})

describe('TestSourceModal', () => {
	it('posts the request in lower-case type, leaving out a blank body', async () => {
		http.post.mockResolvedValueOnce({ data: { response: { statusCode: 200, body: '{"a":1}' } } })
		const wrapper = await opened(TestSourceModal, { source: { uuid: 'u1', name: 'Kadaster' } })
		expect(wrapper.text()).toContain('Kadaster')
		wrapper.vm.endpoint = '/ping'
		wrapper.vm.body = '   '
		wrapper.vm.type = 'XML'

		await wrapper.vm.runTest()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/integriq/api/sources/test/u1', { method: 'GET', endpoint: '/ping', type: 'xml' })
		expect(wrapper.vm.isSuccess).toBe(true)
		expect(wrapper.vm.hasResult).toBe(true)
		expect(toasts).toEqual([['success', 'Test completed.']])
	})

	it('sends a body when there is one and reads a non-2xx as not a success', async () => {
		http.post.mockResolvedValueOnce({ data: { response: { statusCode: 404 } } })
		const wrapper = await opened(TestSourceModal, { source: { '@self': { id: 's9' } } })
		wrapper.vm.method = 'POST'
		wrapper.vm.body = '{"x":1}'

		await wrapper.vm.runTest()

		expect(http.post.mock.calls[0]).toEqual(['/index.php/apps/integriq/api/sources/test/s9', { method: 'POST', endpoint: '', type: 'json', body: '{"x":1}' }])
		expect(wrapper.vm.isSuccess).toBe(false)
	})

	it('reports an error answer, an empty answer and a thrown request', async () => {
		const wrapper = await opened(TestSourceModal, { source: { id: 3 } })

		http.post.mockResolvedValueOnce({ data: { error: 'Timeout' } })
		await wrapper.vm.runTest()
		expect(wrapper.vm.runError).toBe('Timeout')

		http.post.mockResolvedValueOnce({ data: {} })
		await wrapper.vm.runTest()
		expect(wrapper.vm.runError).toBe('The source test returned no response data.')

		http.post.mockRejectedValueOnce({ response: { data: { message: 'Bad gateway' } } })
		await wrapper.vm.runTest()
		expect(wrapper.vm.runError).toBe('Source test failed: Bad gateway')

		http.post.mockRejectedValueOnce({})
		await wrapper.vm.runTest()
		expect(wrapper.vm.runError).toBe('Source test failed')
		expect(wrapper.vm.running).toBe(false)
		expect(toasts.map(([kind]) => kind)).toEqual(['error', 'error', 'error', 'error'])
	})

	it('runs nothing without a source id, and reopening resets the form', async () => {
		const wrapper = await opened(TestSourceModal, { source: null })
		expect(wrapper.vm.canRun).toBe(false)
		await wrapper.vm.runTest()
		expect(http.post).not.toHaveBeenCalled()

		wrapper.vm.method = 'DELETE'
		wrapper.vm.endpoint = '/x'
		wrapper.vm.response = { statusCode: 500 }
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect([wrapper.vm.method, wrapper.vm.type, wrapper.vm.endpoint, wrapper.vm.response]).toEqual(['GET', 'JSON', '', null])
	})

	it('formats durations and prettifies bodies', () => {
		const wrapper = mount(TestSourceModal, { props: { open: false }, global: GLOBAL })
		expect(wrapper.vm.formatMs(null)).toBe('—')
		expect(wrapper.vm.formatMs(12.6)).toBe('13 ms')
		expect(wrapper.vm.prettify('')).toBe('—')
		expect(wrapper.vm.prettify('{"a":1}')).toBe('{\n  "a": 1\n}')
		expect(wrapper.vm.prettify('not json')).toBe('not json')
		expect(wrapper.vm.prettify({ b: 2 })).toBe('{\n  "b": 2\n}')
		const loop = {}
		loop.self = loop
		expect(wrapper.vm.prettify(loop)).toBe('[object Object]')
	})
})

describe('AddEndpointRuleModal', () => {
	it('loads the rules not yet on the endpoint', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [{ id: 1, name: 'Auth' }, { uuid: 'r2', title: 'Rate' }, { id: 3 }] } })
		const wrapper = await opened(AddEndpointRuleModal, { endpoint: { id: 'e1', name: 'Orders', rules: [{ id: 1 }, null] } })

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/rule', { params: { _limit: 500 } })
		expect(wrapper.vm.availableRules).toEqual([{ id: 'r2', label: 'Rate' }, { id: '3', label: 3 }])
		expect(wrapper.vm.loadingRules).toBe(false)
		expect(wrapper.text()).toContain('Orders')
	})

	it('accepts a bare list and says when the rules cannot be loaded', async () => {
		http.get.mockResolvedValueOnce({ data: [{ id: 5, name: 'Five' }] })
		const wrapper = await opened(AddEndpointRuleModal, { endpoint: { id: 'e1' } })
		expect(wrapper.vm.availableRules).toEqual([{ id: '5', label: 'Five' }])

		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		http.get.mockRejectedValueOnce(new Error('down'))
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(wrapper.vm.error).toBe('Failed to load available rules.')
		warn.mockRestore()
	})

	it('saves the existing and new rule ids merged without duplicates', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [] } })
		http.patch.mockResolvedValueOnce({ data: {} })
		const wrapper = await opened(AddEndpointRuleModal, { endpoint: { uuid: 'e2', rules: ['1', { uuid: 'r2' }] } })
		expect(wrapper.vm.canSave).toBe(false)
		wrapper.vm.selectedRules = [{ id: 'r2' }, { id: 9 }]

		await wrapper.vm.onSave()

		expect(http.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/integriq/endpoint/e2', { rules: ['1', 'r2', '9'] })
		expect(wrapper.vm.success).toBe(true)
		expect(toasts).toEqual([['success', 'Rule(s) added to endpoint.']])
	})

	it('reports a failed save and saves nothing without a selection or an id', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = await opened(AddEndpointRuleModal, { endpoint: { id: 'e1' } })
		await wrapper.vm.onSave()
		expect(http.patch).not.toHaveBeenCalled()

		wrapper.vm.selectedRules = [{ id: 1 }]
		http.patch.mockRejectedValueOnce({ response: { data: { message: 'Locked' } } })
		await wrapper.vm.onSave()
		expect(wrapper.vm.error).toBe('Failed to add rule to endpoint: Locked')
		expect(wrapper.vm.saving).toBe(false)
		expect(toasts).toEqual([['error', 'Failed to add rule to endpoint: Locked']])

		const anonymous = await opened(AddEndpointRuleModal, { endpoint: {} })
		anonymous.vm.selectedRules = [{ id: 1 }]
		await anonymous.vm.onSave()
		expect(http.patch).toHaveBeenCalledTimes(1)
	})
})

describe('TestMappingModal', () => {
	const PANEL = {
		name: 'MappingResultPanel',
		props: ['mapping', 'inputObject', 'auto'],
		methods: { run: vi.fn(), reset() {} },
		template: '<div class="panel" />',
	}

	it('names the mapping and runs only with a mapping and input', async () => {
		const wrapper = await opened(TestMappingModal, { mapping: { name: 'Zaak naar case' } }, { stubs: { MappingResultPanel: PANEL } })
		expect(wrapper.text()).toContain('Testing mapping: Zaak naar case')
		expect(wrapper.vm.canRun).toBe(true)
		expect(wrapper.findComponent({ name: 'MappingResultPanel' }).props('auto')).toBe(false)

		wrapper.vm.inputJson = '   '
		expect(wrapper.vm.canRun).toBe(false)

		const none = await opened(TestMappingModal, { mapping: null }, { stubs: { MappingResultPanel: PANEL } })
		expect(none.vm.canRun).toBe(false)
		expect(none.text()).not.toContain('Testing mapping')
	})

	it('delegates the run to the result panel and clears the input error on reopen', async () => {
		PANEL.methods.run.mockClear()
		const wrapper = await opened(TestMappingModal, { mapping: { name: 'm' } }, { stubs: { MappingResultPanel: PANEL } })

		wrapper.vm.runTest()
		expect(PANEL.methods.run).toHaveBeenCalledTimes(1)

		wrapper.vm.inputError = 'bad json'
		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.inputError).toBe('')

		wrapper.vm.onClose()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
