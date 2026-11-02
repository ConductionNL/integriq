// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the three call log modals' state handling: loading,
 * replaying, firing, the outcome sentences and the error text of every
 * failure, with axios mocked under the real callLogApi.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CallBulkReplayModal from '@/modals/CallLog/CallBulkReplayModal.vue'
import CallFireModal from '@/modals/CallLog/CallFireModal.vue'
import CallReplayModal from '@/modals/CallLog/CallReplayModal.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			inheritAttrs: false,
			emits: ['click', 'close', 'update:modelValue'],
			render() {
				return h('div', { class: name }, this.$slots.default?.())
			},
		})
	return {
		NcModal: stub('NcModal'),
		NcButton: stub('NcButton'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
		NcTextArea: stub('NcTextArea'),
	}
})

const SERVER_ERROR = { response: { data: { error: 'srv' } } }
const never = () => new Promise(() => {})

beforeEach(() => {
	get.mockReset()
	post.mockReset()
})

describe('bulk replay', () => {
	const ROWS = {
		data: {
			results: [
				{ '@self': { id: 'c1' }, statusCode: 500 },
				{ statusCode: 502 },
				{ id: 'c3', statusCode: 404 },
			],
		},
	}

	it('starts empty and idle', () => {
		const wrapper = mount(CallBulkReplayModal, { props: { open: false } })
		expect(wrapper.vm.$data).toEqual({
			loading: false,
			busy: false,
			error: '',
			calls: [],
			selected: [],
			outcomes: {},
			summary: '',
		})
	})

	it('clears the previous run while loading', async () => {
		get.mockReturnValue(never())
		const wrapper = mount(CallBulkReplayModal, { props: { open: false } })
		Object.assign(wrapper.vm, { error: 'e', outcomes: { a: 1 }, summary: 's' })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.outcomes).toEqual({})
		expect(wrapper.vm.summary).toBe('')
	})

	it('selects every failed call that has an id', async () => {
		get.mockResolvedValue(ROWS)
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()
		expect(wrapper.vm.calls).toHaveLength(3)
		expect(wrapper.vm.selected).toEqual(['c1', 'c3'])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('a failed load empties the list', async () => {
		get.mockResolvedValueOnce(ROWS)
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()
		get.mockRejectedValue(SERVER_ERROR)
		await wrapper.vm.load()
		expect(wrapper.vm.calls).toEqual([])
		expect(wrapper.vm.selected).toEqual([])
		expect(wrapper.vm.error).toBe('The failed calls could not be loaded.')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('a replay keys outcomes by call and sums them up', async () => {
		get.mockResolvedValue(ROWS)
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()
		wrapper.vm.error = 'old'
		const result = {
			succeeded: 2,
			failed: 1,
			items: [
				{ call: 'c1', succeeded: true, statusCode: 200 },
				{ call: 7, succeeded: false, detail: 'x' },
			],
		}
		post.mockResolvedValue({ data: result })
		await wrapper.vm.replay()
		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/calls/replay',
			{ dryRun: false, calls: ['c1', 'c3'] },
		)
		expect(wrapper.vm.outcomes).toEqual({
			c1: result.items[0],
			7: result.items[1],
		})
		expect(wrapper.vm.summary).toBe('2 sent, 1 failed.')
		expect(wrapper.emitted('replayed')).toEqual([[result]])
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('a replay without items or counts says zero', async () => {
		get.mockResolvedValue(ROWS)
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()
		post.mockResolvedValue({ data: null })
		await wrapper.vm.replay()
		expect(wrapper.vm.outcomes).toEqual({})
		expect(wrapper.vm.summary).toBe('0 sent, 0 failed.')
		expect(wrapper.emitted('replayed')).toEqual([[null]])
	})

	it('a replay is busy until it answers', async () => {
		get.mockResolvedValue(ROWS)
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()
		post.mockReturnValue(never())
		wrapper.vm.replay()
		expect(wrapper.vm.busy).toBe(true)
	})

	it.each([
		[SERVER_ERROR, 'srv'],
		[new Error('net'), 'The replay could not be started.'],
	])('a failed replay says why (%#)', async (err, text) => {
		get.mockResolvedValue(ROWS)
		const wrapper = mount(CallBulkReplayModal, { props: { open: true } })
		await flushPromises()
		post.mockRejectedValue(err)
		await wrapper.vm.replay()
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.vm.summary).toBe('')
		expect(wrapper.emitted('replayed')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
	})
})

describe('single replay', () => {
	const PREVIEW = {
		data: { request: {}, versions: { recorded: '3', differ: true } },
	}

	/**
	 * A replay modal for one call.
	 *
	 * @return {object} the wrapper
	 */
	function replayModal() {
		return mount(CallReplayModal, { props: { open: true, callId: 'c1' } })
	}

	it('clears the previous outcome while loading', async () => {
		get.mockReturnValue(never())
		const wrapper = mount(CallReplayModal, {
			props: { open: false, callId: 'c1' },
		})
		Object.assign(wrapper.vm, { error: 'e', outcome: { a: 1 } })
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.outcome).toBeNull()
	})

	it('does not load without a call', async () => {
		mount(CallReplayModal, { props: { open: true, callId: '' } })
		await flushPromises()
		expect(get).not.toHaveBeenCalled()
	})

	it('preselects the recorded mapping version', async () => {
		get.mockResolvedValue(PREVIEW)
		const wrapper = replayModal()
		await flushPromises()
		expect(wrapper.vm.preview).toEqual(PREVIEW.data)
		expect(wrapper.vm.mappingVersion).toBe('3')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('a preview without versions selects none', async () => {
		get.mockResolvedValue({ data: null })
		const wrapper = replayModal()
		await flushPromises()
		expect(wrapper.vm.mappingVersion).toBe('')
	})

	it.each([
		[SERVER_ERROR, 'srv'],
		[new Error('net'), 'This call could not be loaded.'],
	])('a failed preview says why (%#)', async (err, text) => {
		get.mockResolvedValueOnce(PREVIEW)
		const wrapper = replayModal()
		await flushPromises()
		get.mockRejectedValue(err)
		await wrapper.vm.load()
		expect(wrapper.vm.preview).toBeNull()
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.vm.loading).toBe(false)
	})

	it.each([
		[true, 0],
		[false, 1],
	])('dry run %s emits %s times', async (dryRun, emits) => {
		get.mockResolvedValue(PREVIEW)
		const wrapper = replayModal()
		await flushPromises()
		wrapper.vm.error = 'old'
		const item = { call: 'c1', succeeded: true }
		post.mockResolvedValue({ data: { items: [item] } })
		await wrapper.vm.run(dryRun)
		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/calls/c1/replay',
			{ dryRun, mappingVersion: '3' },
		)
		expect(wrapper.vm.outcome).toEqual(item)
		expect(wrapper.emitted('replayed') ?? []).toHaveLength(emits)
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('a replay without items has no outcome', async () => {
		get.mockResolvedValue(PREVIEW)
		const wrapper = replayModal()
		await flushPromises()
		wrapper.vm.outcome = { old: true }
		post.mockResolvedValue({ data: {} })
		await wrapper.vm.run(false)
		expect(wrapper.vm.outcome).toBeNull()
	})

	it('a replay is busy until it answers', async () => {
		get.mockResolvedValue(PREVIEW)
		const wrapper = replayModal()
		await flushPromises()
		post.mockReturnValue(never())
		wrapper.vm.run(false)
		expect(wrapper.vm.busy).toBe(true)
	})

	it.each([
		[SERVER_ERROR, 'srv'],
		[new Error('net'), 'The replay could not be started.'],
	])('a failed replay says why (%#)', async (err, text) => {
		get.mockResolvedValue(PREVIEW)
		const wrapper = replayModal()
		await flushPromises()
		post.mockRejectedValue(err)
		await wrapper.vm.run(false)
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.emitted('replayed')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
	})

	it.each([
		[null, ''],
		[
			{ sent: false, succeeded: true },
			'Dry run: this is what would be sent. Nothing was sent.',
		],
		[
			{ sent: true, succeeded: true, mappingVersion: '4', statusCode: 202 },
			'Replayed under mapping version 4. The partner answered 202.',
		],
		[
			{ succeeded: true, statusCode: 200 },
			'Replayed under mapping version -. The partner answered 200.',
		],
		[{ sent: false, succeeded: false, detail: 'd' }, 'The replay failed: d'],
		[{ succeeded: 'yes' }, 'The replay failed: '],
	])('outcome %j reads %j', async (outcome, text) => {
		get.mockResolvedValue(PREVIEW)
		const wrapper = replayModal()
		await flushPromises()
		wrapper.vm.outcome = outcome
		expect(wrapper.vm.outcomeText).toBe(text)
	})
})

describe('firing by hand', () => {
	const SOURCES = {
		data: {
			results: [
				{ '@self': { id: 5 }, id: 9, name: 'Named' },
				{ id: 7, title: 'Titled' },
				{ id: 6, name: 'Both', title: 'Other' },
				{ uuid: 'u-1', id: 8 },
			],
		},
	}

	/**
	 * A fire modal with its sources loaded and one picked.
	 *
	 * @return {Promise<object>} the wrapper
	 */
	async function ready() {
		get.mockResolvedValue(SOURCES)
		const wrapper = mount(CallFireModal, { props: { open: true } })
		await flushPromises()
		wrapper.vm.selectedSource = wrapper.vm.sourceOptions[0]
		return wrapper
	}

	it('maps the sources it loads', async () => {
		const wrapper = await ready()
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/integriq/source',
			{ params: { _limit: 500 } },
		)
		expect(wrapper.vm.sourceOptions).toEqual([
			{ id: '5', label: 'Named' },
			{ id: '7', label: 'Titled' },
			{ id: '6', label: 'Both' },
			{ id: '8', label: '8' },
		])
		expect(wrapper.vm.sourcesLoading).toBe(false)
	})

	it('a source with no id at all keeps the uuid', async () => {
		get.mockResolvedValue({ data: { results: [{ uuid: 'u-2' }] } })
		const wrapper = mount(CallFireModal, { props: { open: true } })
		await flushPromises()
		expect(wrapper.vm.sourceOptions).toEqual([{ id: 'u-2', label: 'undefined' }])
	})

	it('a list that is not an array reads as empty', async () => {
		get.mockResolvedValue({ data: { results: 'x' } })
		const wrapper = mount(CallFireModal, { props: { open: true } })
		await flushPromises()
		expect(wrapper.vm.sourceOptions).toEqual([])
		expect(wrapper.vm.error).toBe('')
	})

	it('shows the sources as loading until they answer', () => {
		get.mockReturnValue(never())
		const wrapper = mount(CallFireModal, { props: { open: true } })
		expect(wrapper.vm.sourcesLoading).toBe(true)
	})

	it('a failed source list empties it', async () => {
		get.mockRejectedValue(SERVER_ERROR)
		const wrapper = mount(CallFireModal, { props: { open: true } })
		await flushPromises()
		expect(wrapper.vm.sourceOptions).toEqual([])
		expect(wrapper.vm.error).toBe('The sources could not be loaded.')
		expect(wrapper.vm.sourcesLoading).toBe(false)
	})

	it('does not fire without a source', async () => {
		get.mockResolvedValue(SOURCES)
		const wrapper = mount(CallFireModal, { props: { open: true } })
		await flushPromises()
		await wrapper.vm.fire(false)
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('does not fire a broken body', async () => {
		const wrapper = await ready()
		wrapper.vm.body = '{'
		await wrapper.vm.fire(false)
		expect(post).not.toHaveBeenCalled()
	})

	it.each([
		['', { method: 'PUT', endpoint: '/x' }],
		['{"a":1}', { method: 'PUT', endpoint: '/x', body: { a: 1 } }],
		['null', { method: 'PUT', endpoint: '/x', body: null }],
	])('body %j sends %j', async (body, request) => {
		const wrapper = await ready()
		Object.assign(wrapper.vm, {
			method: 'PUT',
			endpoint: '  /x ',
			body,
			error: 'old',
		})
		const outcome = { sent: true, succeeded: true }
		post.mockResolvedValue({ data: outcome })
		await wrapper.vm.fire(false)
		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/integriq/api/calls/fire',
			{ target: '5', request, dryRun: false },
		)
		expect(wrapper.vm.outcome).toEqual(outcome)
		expect(wrapper.emitted('fired')).toEqual([[outcome]])
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('a dry run does not announce a fired call', async () => {
		const wrapper = await ready()
		post.mockResolvedValue({ data: { sent: false, succeeded: true } })
		await wrapper.vm.fire(true)
		expect(post.mock.calls[0][1].dryRun).toBe(true)
		expect(wrapper.emitted('fired')).toBeUndefined()
	})

	it('firing is busy until it answers', async () => {
		const wrapper = await ready()
		post.mockReturnValue(never())
		wrapper.vm.fire(false)
		expect(wrapper.vm.busy).toBe(true)
	})

	it.each([
		[SERVER_ERROR, 'srv'],
		[new Error('net'), 'The call could not be fired.'],
	])('a failed call says why (%#)', async (err, text) => {
		const wrapper = await ready()
		post.mockRejectedValue(err)
		await wrapper.vm.fire(false)
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.emitted('fired')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
	})

	it.each([
		[null, ''],
		[
			{ sent: false, succeeded: true },
			'Dry run: this is what would be sent. Nothing was sent.',
		],
		[
			{ sent: true, succeeded: true, statusCode: 201 },
			'Sent. The partner answered 201, and the call is in the log.',
		],
		[{ succeeded: false, detail: 'd' }, 'The call failed: d'],
		[{ sent: false, succeeded: false }, 'The call failed: '],
	])('outcome %j reads %j', async (outcome, text) => {
		const wrapper = await ready()
		wrapper.vm.outcome = outcome
		expect(wrapper.vm.outcomeText).toBe(text)
	})
})
