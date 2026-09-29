// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The subscription modal offers the flow action kind
 * (nc-events-start-or-flows task 3).
 *
 * The dispatch arm in EventService has run flows for a while, and the register
 * accepts `action.kind: flow` with `action.flowId`, but the modal only offered
 * webhook, synchronization and job, so no administrator could save a
 * subscription that starts a flow. These tests drive the real component:
 * choosing "Flow" loads the flows from OpenRegister, and picking one writes
 * exactly `{kind: 'flow', flowId}` through the dialog's updateField.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-the-subscription-modal-offers-the-flow-action-kind
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SubscriptionActionFields from '@/modals/EventSubscription/SubscriptionActionFields.vue'

const get = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get } }))

// The @nextcloud/vue barrel registers its own l10n on import, which the node
// l10n stub cannot serve. The pickers are stood in by components that keep the
// real names and the props this component binds, so the template is still
// rendered and asserted on.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props) =>
		defineComponent({ name, props, render: () => h('div', { class: name }) })
	return {
		NcSelect: stub('NcSelect', [
			'inputId',
			'inputLabel',
			'ariaLabelCombobox',
			'modelValue',
			'options',
			'loading',
			'clearable',
			'placeholder',
		]),
		NcTextField: stub('NcTextField', ['label', 'modelValue', 'type']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue', 'type']),
	}
})

/**
 * Mount the fields with a spy updateField and the given form data.
 *
 * @param {object} formData the dialog's form data
 * @return {{wrapper: object, updateField: Function}} the wrapper and the spy
 */
function mountFields(formData) {
	const updateField = vi.fn()
	const wrapper = mount(SubscriptionActionFields, {
		props: { formData, updateField },
	})
	return { wrapper, updateField }
}

describe('SubscriptionActionFields flow action', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue({
			data: { results: [{ id: 'flow-1', name: 'Archive new invoices' }] },
		})
	})

	it('offers Flow as a delivery action', () => {
		const { wrapper } = mountFields({})
		expect(wrapper.vm.kindOptions.map((option) => option.id)).toContain('flow')
	})

	it('loads the flows from OpenRegister when an existing subscription starts a flow', async () => {
		const { wrapper } = mountFields({
			action: { kind: 'flow', flowId: 'flow-1' },
		})
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/integriq/flow',
		)
		expect(get.mock.calls[0][1]).toEqual({ params: { _limit: 500 } })
		expect(wrapper.vm.flowOptions).toEqual([
			{ id: 'flow-1', label: 'Archive new invoices' },
		])
		expect(wrapper.vm.selectedFlow).toEqual({
			id: 'flow-1',
			label: 'Archive new invoices',
		})
	})

	it('writes kind flow and the picked flowId', () => {
		const { wrapper, updateField } = mountFields({ action: { kind: 'flow' } })

		wrapper.vm.onFlowPick({ id: 'flow-1', label: 'Archive new invoices' })

		expect(updateField).toHaveBeenLastCalledWith('action', {
			kind: 'flow',
			flowId: 'flow-1',
		})
	})

	it('renders a labelled flow picker for the flow kind', async () => {
		const { wrapper } = mountFields({ action: { kind: 'flow' } })
		await flushPromises()

		const picker = wrapper
			.findAllComponents({ name: 'NcSelect' })
			.find((select) => select.props('inputLabel') === 'Flow')
		expect(picker).toBeTruthy()
	})
})
