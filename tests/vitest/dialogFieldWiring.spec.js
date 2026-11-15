// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edit fields of the DSO activity and mapping rule dialogs: a value typed
 * into each field lands in the draft the dialog submits. The Nextcloud
 * components are stubbed; the stub emits `update:modelValue` as the real
 * field does.
 */

import { translate } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const stub = (name) =>
		defineComponent({
			name,
			props: ['modelValue', 'disabled', 'options', 'open', 'name', 'label', 'error', 'helperText'],
			emits: ['click', 'update:modelValue', 'update:open', 'close'],
			render() {
				return h('div', { class: name }, [this.$slots.default?.(), this.$slots.actions?.()])
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

const EditMappingRuleDialog = (await import('@/dialogs/EditMappingRuleDialog.vue')).default
const DsoActivityMappingDialog = (await import('@/dialogs/DsoActivityMappingDialog.vue')).default

const GLOBAL = { mocks: { t: translate, n: (app, one) => one } }

/**
 * Type a value into the stubbed field carrying a test id.
 *
 * @param {object} wrapper The dialog wrapper.
 * @param {string} testId  The field's data-testid.
 * @param {*}      value   The new value.
 * @param {number} [index] Which of several fields with that id.
 * @return {Promise<void>}
 */
async function type(wrapper, testId, value, index = 0) {
	const fields = wrapper.findAll(`[data-testid="${testId}"]`)
	expect(fields.length).toBeGreaterThan(index)
	fields[index].getCurrentComponent().emit('update:modelValue', value)
	await wrapper.vm.$nextTick()
}

describe('DsoActivityMappingDialog fields', () => {
	const dialog = (row) => mount(DsoActivityMappingDialog, { props: { open: true, row }, global: GLOBAL })

	it('writes the activity id, case type name, rule imow-id, active switch and note into the draft', async () => {
		const wrapper = dialog({
			caseTypes: [{ reference: 'zt-1', title: 'Old', department: 'VTH' }],
			samenloopRules: [{ withImowId: 'imow-old', strategy: 'deelzaken' }],
			isActive: true,
		})

		await type(wrapper, 'dso-activity-activity-id', 'act-42')
		await type(wrapper, 'dso-activity-case-type-title', 'Omgevingsvergunning bouwen')
		await type(wrapper, 'dso-activity-rule-imow-id', 'nl.imow-gm0363.activiteit.Slopen')
		await type(wrapper, 'dso-activity-active', false)
		await type(wrapper, 'dso-activity-note', 'Checked with VTH')

		const draft = wrapper.vm.draft
		expect(draft.activityId).toBe('act-42')
		expect(draft.caseTypes[0].title).toBe('Omgevingsvergunning bouwen')
		expect(draft.caseTypes[0].reference).toBe('zt-1')
		expect(draft.samenloopRules[0].withImowId).toBe('nl.imow-gm0363.activiteit.Slopen')
		expect(draft.isActive).toBe(false)
		expect(draft.note).toBe('Checked with VTH')

		await type(wrapper, 'dso-activity-name', 'Bouwen')
		await type(wrapper, 'dso-activity-imow-id', 'nl.imow-gm0363.activiteit.Bouwen')
		wrapper.vm.save()
		expect(wrapper.emitted('save')).toEqual([[{
			id: null,
			payload: {
				activityName: 'Bouwen',
				caseTypes: [{ reference: 'zt-1', title: 'Omgevingsvergunning bouwen', department: 'VTH' }],
				samenloopStrategy: 'deelzaken',
				samenloopRules: [{ withImowId: 'nl.imow-gm0363.activiteit.Slopen', strategy: 'deelzaken' }],
				isActive: false,
				imowId: 'nl.imow-gm0363.activiteit.Bouwen',
				activityId: 'act-42',
				note: 'Checked with VTH',
			},
		}]])
	})

	it('writes each case type\'s name into its own line', async () => {
		const wrapper = dialog({
			caseTypes: [{ reference: 'zt-1', title: 'A' }, { reference: 'zt-2', title: 'B' }],
		})

		await type(wrapper, 'dso-activity-case-type-title', 'Second', 1)

		expect(wrapper.vm.draft.caseTypes.map((c) => c.title)).toEqual(['A', 'Second'])
	})
})

describe('EditMappingRuleDialog fields', () => {
	it('writes the typed property into the property draft the rule is saved with', async () => {
		const wrapper = mount(EditMappingRuleDialog, { props: { kind: 'unset' }, global: GLOBAL })
		const field = wrapper.findAllComponents({ name: 'NcTextField' })[0]

		field.vm.$emit('update:modelValue', '  person.bsn  ')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.propertyDraft).toBe('  person.bsn  ')

		wrapper.vm.onSubmit()

		expect(wrapper.emitted('submit')).toEqual([[{ kind: 'unset', property: 'person.bsn', value: 'person.bsn' }]])
	})
})
