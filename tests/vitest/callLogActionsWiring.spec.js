// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The call-log toolbar and row actions mounted: each button opens its modal,
 * a modal's close shuts it again, and a replay or a hand-fired call refreshes
 * the surrounding log page. The modals are stand-ins that expose their props
 * and events; the component library is stubbed as in callLogReplay.spec.js.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-a-failed-call-is-replayed-from-the-screen-singly-and-in-bulk-req-ocd-002
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import CallLogActions from '@/components/callLog/CallLogActions.vue'
import CallLogRowActions from '@/components/callLog/CallLogRowActions.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') =>
		defineComponent({
			name,
			props: ['closeAfterClick', 'ariaLabel'],
			emits: ['click'],
			render() {
				return h(
					tag,
					{ class: name, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		})
	return {
		NcButton: stub('NcButton', 'button'),
		NcActions: stub('NcActions'),
		NcActionButton: stub('NcActionButton', 'button'),
	}
})

vi.mock('@/modals/CallLog/CallBulkReplayModal.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name: 'CallBulkReplayModal',
			props: ['open'],
			emits: ['replayed', 'close'],
			render: () => h('div'),
		}),
	}
})
vi.mock('@/modals/CallLog/CallFireModal.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name: 'CallFireModal',
			props: ['open'],
			emits: ['fired', 'close'],
			render: () => h('div'),
		}),
	}
})
vi.mock('@/modals/CallLog/CallReplayModal.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name: 'CallReplayModal',
			props: ['open', 'callId'],
			emits: ['replayed', 'close'],
			render: () => h('div'),
		}),
	}
})

/**
 * Mount a component inside a CnLogsPage stand-in whose refresh is a spy.
 *
 * @param {object} component The component under test.
 * @param {object} props Its props.
 * @return {{wrapper: object, refresh: Function}} The page wrapper and spy.
 */
function mountInLogsPage(component, props = {}) {
	const refresh = vi.fn()
	const Page = defineComponent({
		name: 'CnLogsPage',
		methods: { refresh },
		render: () => h(component, props),
	})
	return { wrapper: mount(Page), refresh }
}

describe('the call log toolbar', () => {
	it('opens and closes the bulk replay modal', async () => {
		const { wrapper } = mountInLogsPage(CallLogActions)
		const modal = () => wrapper.findComponent({ name: 'CallBulkReplayModal' })
		expect(modal().props('open')).toBe(false)
		await wrapper.find('[data-testid="call-log-bulk-replay"]').trigger('click')
		expect(modal().props('open')).toBe(true)
		expect(wrapper.findComponent({ name: 'CallFireModal' }).props('open')).toBe(
			false,
		)
		await modal().vm.$emit('close')
		expect(modal().props('open')).toBe(false)
	})

	it('opens and closes the fire-by-hand modal', async () => {
		const { wrapper } = mountInLogsPage(CallLogActions)
		const modal = () => wrapper.findComponent({ name: 'CallFireModal' })
		await wrapper.find('[data-testid="call-log-fire"]').trigger('click')
		expect(modal().props('open')).toBe(true)
		await modal().vm.$emit('close')
		expect(modal().props('open')).toBe(false)
	})

	it('refreshes the log page after a bulk replay and after a fired call', async () => {
		const { wrapper, refresh } = mountInLogsPage(CallLogActions)
		await wrapper
			.findComponent({ name: 'CallBulkReplayModal' })
			.vm.$emit('replayed')
		expect(refresh).toHaveBeenCalledTimes(1)
		await wrapper.findComponent({ name: 'CallFireModal' }).vm.$emit('fired')
		expect(refresh).toHaveBeenCalledTimes(2)
	})

	it('labels both buttons', () => {
		const { wrapper } = mountInLogsPage(CallLogActions)
		expect(wrapper.text()).toContain('Replay failed calls')
		expect(wrapper.text()).toContain('Fire a call by hand')
	})
})

describe('the call log row actions', () => {
	it('hand the call id to the replay modal and open it from the menu', async () => {
		const { wrapper } = mountInLogsPage(CallLogRowActions, {
			row: { '@self': { id: 'c-1' }, direction: 'outbound' },
		})
		const modal = () => wrapper.findComponent({ name: 'CallReplayModal' })
		expect(modal().props('callId')).toBe('c-1')
		expect(modal().props('open')).toBe(false)
		await wrapper.find('[data-testid="call-log-row-replay"]').trigger('click')
		expect(modal().props('open')).toBe(true)
		await modal().vm.$emit('close')
		expect(modal().props('open')).toBe(false)
	})

	it('refresh the log page after a replay', async () => {
		const { wrapper, refresh } = mountInLogsPage(CallLogRowActions, {
			row: { id: 'c-1' },
		})
		await wrapper.findComponent({ name: 'CallReplayModal' }).vm.$emit('replayed')
		expect(refresh).toHaveBeenCalledTimes(1)
	})

	it('render nothing for an inbound call', () => {
		const { wrapper } = mountInLogsPage(CallLogRowActions, {
			row: { id: 'c-1', direction: 'inbound' },
		})
		expect(wrapper.find('.callLogRowActions').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'CallReplayModal' }).exists()).toBe(
			false,
		)
	})
})
