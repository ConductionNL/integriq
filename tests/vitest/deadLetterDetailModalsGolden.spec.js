// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the two dead-letter detail modals (event deliveries
 * and sync items): the rendered detail, the attempt timeline, the payload
 * viewer and the confirmed replay or discard with its toasts and events.
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-inspection-req-dlr-008
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EventDeliveryDetailModal from '@/modals/EventDelivery/EventDeliveryDetailModal.vue'
import SyncDeadLetterDetailModal from '@/modals/Synchronization/SyncDeadLetterDetailModal.vue'

const { post, showError, showSuccess } = vi.hoisted(() => ({
	post: vi.fn(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: { post } }))
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') =>
		defineComponent({
			name,
			props: ['variant', 'disabled', 'labelId'],
			emits: ['close'],
			render() {
				return h(
					tag,
					{ class: name, disabled: this.disabled },
					this.$slots.default?.(),
				)
			},
		})
	return { NcModal: stub('NcModal'), NcButton: stub('NcButton', 'button') }
})

const MODALS = [
	{
		name: 'event delivery',
		component: EventDeliveryDetailModal,
		prop: 'message',
		url: '/index.php/apps/integriq/api/events/dead-letter',
		done: { replay: 'Message replayed', discard: 'Message discarded' },
		actable: ['failed', 'abandoned'],
	},
	{
		name: 'sync item',
		component: SyncDeadLetterDetailModal,
		prop: 'entry',
		url: '/index.php/apps/integriq/api/sync-dead-letter',
		done: { replay: 'Item replayed', discard: 'Item discarded' },
		actable: ['failed'],
	},
]

const ROW = {
	uuid: 'u-1',
	id: 4,
	status: 'failed',
	error: 'boom',
	actionKind: 'n8n',
	nextcloudEvent: true,
	replayedBy: 'alice',
	replayedAt: '2026-11-01',
	discardedBy: 'bob',
	discardedAt: '2026-11-02',
	attempts: [
		{ at: 't1', statusCode: 502 },
		{ at: 't2', error: 'timeout' },
		{ at: 't3' },
	],
	payload: { a: 1, nested: { b: [1, 2] } },
}

/**
 * Mount one modal open on a row.
 *
 * @param {object} modal the modal case
 * @param {object|null} row the dead-letter row
 * @return {object} the wrapper
 */
function mountOn(modal, row) {
	return mount(modal.component, { props: { open: true, [modal.prop]: row } })
}

/**
 * The rendered markup without the scoped-style ids, which follow the file.
 *
 * @param {object} wrapper the mounted modal
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/ data-v-[0-9a-f]+=""/g, '')
}

beforeEach(() => {
	post.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
})

describe.each(MODALS)('$name detail', (modal) => {
	it('renders the full detail', () => {
		expect(markup(mountOn(modal, ROW))).toMatchSnapshot()
	})

	it('renders an empty row', () => {
		const wrapper = mountOn(modal, { status: 'replayed' })
		expect(markup(wrapper)).toMatchSnapshot()
		expect(wrapper.vm.attempts).toEqual([])
		expect(wrapper.vm.prettyPayload).toBe('{}')
	})

	it('renders nothing but the actions without a row', () => {
		const wrapper = mountOn(modal, null)
		expect(markup(wrapper)).toMatchSnapshot()
		expect(wrapper.vm.attempts).toEqual([])
	})

	it('ignores attempts that are not a list', () => {
		expect(mountOn(modal, { attempts: 'x' }).vm.attempts).toEqual([])
	})

	it('shows a payload JSON cannot print as text', () => {
		const payload = { toJSON: () => 1n }
		expect(mountOn(modal, { payload }).vm.prettyPayload).toBe('[object Object]')
		expect(mountOn(modal, { payload: 'raw' }).vm.prettyPayload).toBe('"raw"')
	})

	it.each(['failed', 'abandoned', 'replayed', undefined])(
		'status %s decides whether it can act',
		(status) => {
			expect(mountOn(modal, { status }).vm.canAct).toBe(
				modal.actable.includes(status),
			)
		},
	)

	it.each(['replay', 'discard'])(
		'a confirmed %s posts, toasts and closes',
		async (verb) => {
			const wrapper = mountOn(modal, ROW)
			wrapper.vm.confirming = verb
			let resolve
			post.mockReturnValue(new Promise((r) => (resolve = r)))
			const done = wrapper.vm.commit()
			expect(wrapper.vm.busy).toBe(true)
			resolve({})
			await done
			expect(post).toHaveBeenCalledWith(`${modal.url}/u-1/${verb}`)
			expect(showSuccess).toHaveBeenCalledWith(modal.done[verb])
			expect(showError).not.toHaveBeenCalled()
			expect(wrapper.emitted('changed')).toHaveLength(1)
			expect(wrapper.emitted('close')).toHaveLength(1)
			expect(wrapper.vm.busy).toBe(false)
			expect(wrapper.vm.confirming).toBeNull()
		},
	)

	it('falls back to the numeric id', async () => {
		const wrapper = mountOn(modal, { id: 9, status: 'failed' })
		wrapper.vm.confirming = 'replay'
		post.mockResolvedValue({})
		await wrapper.vm.commit()
		expect(post).toHaveBeenCalledWith(`${modal.url}/9/replay`)
	})

	it.each([
		[null, { uuid: 'u-1' }],
		['replay', {}],
		['replay', null],
	])('does nothing without a verb or an id (%s)', async (verb, row) => {
		const wrapper = mountOn(modal, row)
		wrapper.vm.confirming = verb
		await wrapper.vm.commit()
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.confirming).toBe(verb)
	})

	it.each([
		[
			'replay',
			{ response: { data: { error: 'srv' } }, message: 'm' },
			'Replay failed: srv',
		],
		['discard', { message: 'net' }, 'Discard failed: net'],
		['replay', {}, 'Replay failed'],
	])('a failed %s says why', async (verb, err, text) => {
		const wrapper = mountOn(modal, ROW)
		wrapper.vm.confirming = verb
		post.mockRejectedValue(err)
		await wrapper.vm.commit()
		await flushPromises()
		expect(showError).toHaveBeenCalledWith(text)
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.emitted('changed')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.confirming).toBeNull()
	})

	it('the buttons step through the confirmation', async () => {
		const wrapper = mountOn(modal, ROW)
		const buttons = () => wrapper.findAll('button')
		expect(buttons().map((b) => b.text())).toEqual(['Replay', 'Discard'])
		await buttons()[1].trigger('click')
		expect(wrapper.vm.confirming).toBe('discard')
		expect(buttons().map((b) => b.text())).toEqual(['Confirm', 'Cancel'])
		await buttons()[1].trigger('click')
		expect(wrapper.vm.confirming).toBeNull()
	})
})
