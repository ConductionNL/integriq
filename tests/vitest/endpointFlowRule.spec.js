// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction / Integriq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An endpoint rule can start a flow (automation-endpoint-flow-trigger).
 *
 * EndpointService::processFlowRule() has run a flow for a `flow` rule for a
 * while, reading the flow id from `configuration.flow` and throwing
 * "flow rule type requires configuration.flow" without one. The rule editor
 * held `flow` back because it had no form, so only a seeded rule could carry
 * it. These tests drive the real components: the picker loads the instance's
 * flows, picking one writes the bare id to `configuration.flow` (the key the
 * runtime reads), and the create dialog refuses a flow rule that names no flow.
 *
 * @spec openspec/specs/rule-editor-ui/spec.md#requirement-an-administrator-can-start-a-flow-from-an-endpoint-rule-req-aft-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RuleEditorModal from '@/modals/v2/RuleEditorModal.vue'
import FlowForm from '@/views/Rule/actionForms/FlowForm.vue'
import RuleActionConfig from '@/views/Rule/RuleActionConfig.vue'
import { ACTION_TYPES, UNDISPATCHED_ACTION_TYPES } from '@/views/Rule/ruleDraft.js'

const get = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

// The @nextcloud/vue barrel registers its own l10n on import, which the node
// l10n stub cannot serve. Each component is stood in by one that keeps the
// real name and renders its default slot, so the templates still render.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name, props = []) =>
		defineComponent({
			name,
			props,
			render() {
				return h('div', { class: name }, this.$slots.default?.())
			},
		})
	const selectProps = [
		'inputId',
		'inputLabel',
		'ariaLabelCombobox',
		'modelValue',
		'options',
		'loading',
		'clearable',
		'disabled',
		'placeholder',
	]
	return {
		NcSelect: stub('NcSelect', selectProps),
		NcButton: stub('NcButton', ['disabled', 'variant']),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', ['modelValue']),
		NcDialog: stub('NcDialog', ['name', 'size', 'noClose']),
		NcEmptyContent: stub('NcEmptyContent', ['name']),
		NcInputField: stub('NcInputField', ['modelValue', 'label']),
		NcLoadingIcon: stub('NcLoadingIcon'),
		NcNoteCard: stub('NcNoteCard', ['type']),
		NcTextArea: stub('NcTextArea', ['modelValue', 'label']),
		NcTextField: stub('NcTextField', ['modelValue', 'label']),
	}
})

// main.js installs `t` on app.config.globalProperties; the mounts carry it.
const global = { mocks: { t: (_app, text) => text } }

const FLOWS = { data: { results: [{ id: 'flow-1', name: 'Melding intake' }] } }

describe('the flow action on an endpoint rule', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue(FLOWS)
	})

	it('is offered, and is one the endpoint pipeline dispatches', () => {
		const ids = ACTION_TYPES.map((entry) => entry.id)
		expect(ids).toContain('flow')
		expect(UNDISPATCHED_ACTION_TYPES).not.toContain('flow')
	})

	it('loads the flows from OpenRegister into a labelled picker', async () => {
		const wrapper = mount(FlowForm, { props: { id: 'flow-1' }, global })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][0]).toContain(
			'/apps/openregister/api/objects/integriq/flow',
		)
		expect(get.mock.calls[0][1]).toEqual({ params: { _limit: 500 } })
		const picker = wrapper.findComponent({ name: 'NcSelect' })
		expect(picker.props('inputLabel')).toBe('Flow')
		expect(picker.props('modelValue')).toMatchObject({
			id: 'flow-1',
			label: 'Melding intake',
		})
	})

	it('writes the picked flow id to configuration.flow, keeping the other keys', async () => {
		const wrapper = mount(RuleActionConfig, {
			props: { configuration: { type: 'flow', mapping: 'm-1' }, type: 'flow' },
			global,
		})
		await flushPromises()

		const form = wrapper.findComponent(FlowForm)
		expect(form.exists()).toBe(true)
		form.vm.onPick({ id: 'flow-1', label: 'Melding intake' })

		expect(wrapper.emitted('update').at(-1)[0]).toEqual({
			type: 'flow',
			mapping: 'm-1',
			flow: 'flow-1',
		})
	})

	it('drops configuration.flow when the picker is cleared', async () => {
		const wrapper = mount(RuleActionConfig, {
			props: { configuration: { type: 'flow', flow: 'flow-1' }, type: 'flow' },
			global,
		})
		await flushPromises()

		wrapper.findComponent(FlowForm).vm.onPick(null)

		expect(wrapper.emitted('update').at(-1)[0]).toEqual({ type: 'flow' })
	})
})

describe('the rule dialog and a flow rule', () => {
	beforeEach(() => {
		get.mockReset()
		get.mockResolvedValue(FLOWS)
	})

	/**
	 * Mount the create dialog with a bound confirm and a draft that is
	 * complete except for the action's own configuration.
	 *
	 * @return {object} the wrapper
	 */
	async function mountDialog() {
		const wrapper = mount(RuleEditorModal, {
			props: { show: true, item: null, confirm: vi.fn(), close: vi.fn() },
			global,
		})
		await flushPromises()
		wrapper.vm.draft = {
			...wrapper.vm.draft,
			name: 'Meldingen starten intake',
			action: 'post',
		}
		wrapper.vm.onTypePick({ id: 'flow', label: 'Flow' })
		await flushPromises()
		return wrapper
	}

	it('refuses to save a flow rule that names no flow, with the reason', async () => {
		const wrapper = await mountDialog()

		expect(wrapper.vm.canSave).toBe(false)
		expect(wrapper.vm.flowError).toBe('Pick the flow this rule starts.')
		expect(wrapper.findComponent(FlowForm).exists()).toBe(true)
	})

	it('saves a flow rule once a flow is picked, with the id at configuration.flow', async () => {
		const wrapper = await mountDialog()

		wrapper
			.findComponent(FlowForm)
			.vm.onPick({ id: 'flow-1', label: 'Melding intake' })
		await flushPromises()

		expect(wrapper.vm.flowError).toBe('')
		expect(wrapper.vm.canSave).toBe(true)
		await wrapper.vm.onSave()
		const saved = wrapper.props('confirm').mock.calls[0][0]
		expect(saved.type).toBe('flow')
		expect(saved.configuration).toMatchObject({ type: 'flow', flow: 'flow-1' })
	})
})
