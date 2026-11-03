// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation of the markup of the two dead-letter pages (event
 * deliveries and sync items) through the bulk flow: nothing selected, rows
 * selected, a replay or discard awaiting confirmation, busy, and what each
 * bulk-bar button does.
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EventDeliveriesPage from '@/views/EventDelivery/EventDeliveriesPage.vue'
import SyncDeadLetterPage from '@/views/Synchronization/SyncDeadLetterPage.vue'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get, post } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, tag = 'div') =>
		defineComponent({
			name,
			props: ['variant', 'disabled', 'modelValue', 'label', 'type', 'size'],
			emits: ['update:modelValue'],
			render() {
				return h(
					tag,
					{ class: name, disabled: this.disabled || undefined },
					this.$slots.default?.(),
				)
			},
		})
	return {
		NcButton: stub('NcButton', 'button'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})
vi.mock('@/modals/EventDelivery/EventDeliveryDetailModal.vue', () => ({
	default: { name: 'EventDeliveryDetailModal', render: () => null },
}))
vi.mock('@/modals/Synchronization/SyncDeadLetterDetailModal.vue', () => ({
	default: { name: 'SyncDeadLetterDetailModal', render: () => null },
}))

const ROWS = [
	{ uuid: 'a', status: 'failed', subscription: 's', synchronization: 'y' },
	{ uuid: 'b', status: 'abandoned', subscription: 's', synchronization: 'y' },
]

/**
 * The rendered markup without the scoped-style ids, which follow the file.
 *
 * @param {object} wrapper the mounted page or element
 * @return {string} the markup
 */
function markup(wrapper) {
	return wrapper.html().replace(/ data-v-[0-9a-f]+=""/g, '')
}

beforeEach(() => {
	get.mockReset()
	post.mockReset()
	get.mockResolvedValue({ data: { results: ROWS } })
})

describe.each([
	['EventDeliveriesPage', EventDeliveriesPage],
	['SyncDeadLetterPage', SyncDeadLetterPage],
])('%s bulk bar', (_name, page) => {
	it('steps through the bulk flow as recorded', async () => {
		const wrapper = mount(page)
		await flushPromises()
		const bar = () => wrapper.find('[data-testid="bulk-bar"]')
		const buttons = () => bar().findAll('button')
		const steps = [['none selected', bar().exists()]]

		wrapper.vm.selected = ['a', 'b']
		await flushPromises()
		steps.push(['selected', markup(bar())])

		await buttons()[0].trigger('click')
		steps.push(['replay asked', wrapper.vm.bulkConfirm, markup(bar())])

		await buttons()[1].trigger('click')
		steps.push(['cancelled', wrapper.vm.bulkConfirm, markup(bar())])

		await buttons()[1].trigger('click')
		steps.push(['discard asked', wrapper.vm.bulkConfirm, markup(bar())])

		wrapper.vm.busy = true
		await flushPromises()
		steps.push(['busy', markup(bar())])
		wrapper.vm.busy = false
		await flushPromises()

		post.mockResolvedValue({ data: { results: { a: 'ok', b: 'ok' } } })
		await buttons()[0].trigger('click')
		await flushPromises()
		steps.push(['committed', post.mock.calls, wrapper.vm.bulkConfirm])

		expect(steps).toMatchSnapshot()
		expect(markup(wrapper)).toMatchSnapshot()
	})

	it('one selected row is counted', async () => {
		const wrapper = mount(page)
		await flushPromises()
		wrapper.vm.selected = ['a']
		wrapper.vm.bulkConfirm = 'replay'
		await flushPromises()
		expect(markup(wrapper.find('[data-testid="bulk-bar"]'))).toMatchSnapshot()
	})
})
