// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * EditMappingRuleDialog and DsoActivityMappingDialog: what each emits on
 * submit, when submitting is refused, and what the edit controls change.
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
				return h('div', { class: name, 'data-disabled': this.disabled ? 'yes' : undefined }, [
					this.$slots.default?.(),
					this.$slots.actions?.(),
				])
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
 * The stub of a named component whose text matches.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name    The component name.
 * @param {string} text    Text it contains.
 * @return {object} The component wrapper.
 */
function button(wrapper, name, text) {
	return wrapper.findAllComponents({ name }).find((c) => c.text().includes(text))
}

describe('EditMappingRuleDialog', () => {
	const rule = (props) => mount(EditMappingRuleDialog, { props, global: GLOBAL })

	it('titles and labels each kind for add and edit', () => {
		const cases = [
			[{ kind: 'mapping' }, 'Add mapping rule', 'Add rule', 'Target property', 'e.g. firstName'],
			[{ kind: 'mapping', property: 'a' }, 'Edit mapping rule', 'Save rule', 'Target property', 'e.g. firstName'],
			[{ kind: 'cast' }, 'Add cast rule', 'Add rule', 'Property', 'JSON path or property name'],
			[{ kind: 'cast', property: 'a' }, 'Edit cast rule', 'Save rule', 'Property', 'JSON path or property name'],
			[{ kind: 'unset' }, 'Add unset rule', 'Add rule', 'Property', 'JSON path or property name'],
			[{ kind: 'unset', property: 'a' }, 'Edit unset rule', 'Save rule', 'Property', 'JSON path or property name'],
		]
		for (const [props, title, submit, label, placeholder] of cases) {
			const vm = rule(props).vm
			expect([vm.dialogTitle, vm.submitLabel, vm.propertyLabel, vm.propertyPlaceholder]).toEqual([title, submit, label, placeholder])
		}
	})

	it('explains the Twig template without breaking the Vue parser', () => {
		const wrapper = rule({ kind: 'mapping' })
		expect(wrapper.vm.templatePlaceholder).toBe("{{ originalProperty }} or {{ source.field|default('-') }}")
		expect(wrapper.vm.templateHelp).toBe('A Twig template evaluated against the input object. Use {{ field }} to reference source values.')
		expect(wrapper.find('textarea').exists()).toBe(true)
	})

	it('submits a mapping with its trimmed property and template', async () => {
		const wrapper = rule({ kind: 'mapping' })
		expect(wrapper.vm.canSubmit).toBe(false)
		wrapper.vm.propertyDraft = '  firstName  '
		expect(wrapper.vm.canSubmit).toBe(false)
		await wrapper.find('textarea').setValue('{{ voornaam }}')

		await button(wrapper, 'NcButton', 'Add rule').vm.$emit('click')

		expect(wrapper.emitted('submit')).toEqual([[{ kind: 'mapping', property: 'firstName', value: '{{ voornaam }}' }]])
	})

	it('refuses a property another rule already targets', () => {
		const wrapper = rule({ kind: 'unset', existingKeys: ['taken'] })
		wrapper.vm.propertyDraft = ' taken '
		expect(wrapper.vm.propertyError).toBe('Another rule already targets this property.')
		expect(wrapper.vm.canSubmit).toBe(false)
		wrapper.vm.onSubmit()
		expect(wrapper.emitted('submit')).toBeUndefined()

		wrapper.vm.propertyDraft = '   '
		expect(wrapper.vm.propertyError).toBe('')
	})

	it('submits a cast only with a known type, chosen through the select', async () => {
		const wrapper = rule({ kind: 'cast', property: 'age', value: 'nonsense' })
		expect(wrapper.vm.castSelectValue).toEqual({ id: 'string', label: 'String' })
		expect(wrapper.vm.canSubmit).toBe(false)

		const select = wrapper.findComponent({ name: 'NcSelect' })
		await select.vm.$emit('update:modelValue', null)
		expect(wrapper.vm.valueDraft).toBe('nonsense')
		await select.vm.$emit('update:modelValue', { id: 'integer', label: 'Integer' })
		expect(wrapper.vm.castSelectValue).toEqual({ id: 'integer', label: 'Integer' })

		// The form's own submit: the dialog declares no `emits`, so the native
		// event is recorded after the component's payload; only the first counts.
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('submit')[0]).toEqual([{ kind: 'cast', property: 'age', value: 'integer' }])
	})

	it('submits an unset with the property as its value, seeded from the value when new', () => {
		const wrapper = rule({ kind: 'unset', value: 'obsolete.field' })
		expect(wrapper.vm.propertyDraft).toBe('obsolete.field')
		wrapper.vm.propertyDraft = ' renamed.field '
		wrapper.vm.onSubmit()
		expect(wrapper.emitted('submit')).toEqual([[{ kind: 'unset', property: 'renamed.field', value: 'renamed.field' }]])
	})

	it('cancels from the button and from the modal', async () => {
		const wrapper = rule({ kind: 'unset' })
		await button(wrapper, 'NcButton', 'Cancel').vm.$emit('click')
		await wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('cancel')).toHaveLength(2)
	})
})

describe('DsoActivityMappingDialog', () => {
	const VALID = {
		id: 'm1',
		imowId: 'nl.imow-gm0363.activiteit.Bouwen',
		activityName: 'Bouwen',
		caseTypes: [{ reference: 'omgevingsvergunning', title: '', department: ' Vergunningen ' }],
	}
	const dialog = (row, props = {}) => mount(DsoActivityMappingDialog, { props: { open: true, row, ...props }, global: GLOBAL })

	it('shows no problems until a save is tried, and then emits nothing for a bad draft', async () => {
		const wrapper = dialog({})
		expect(wrapper.vm.shownProblems).toEqual([])

		await button(wrapper, 'NcButton', 'Save').vm.$emit('click')

		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.vm.shownProblems).toEqual([
			'Fill in the imow-id or the activity id.',
			'Fill in the activity name.',
			'Add at least one case type with a reference.',
		])
		expect(wrapper.findAllComponents({ name: 'NcNoteCard' })).toHaveLength(3)
	})

	it('saves a valid draft as its payload', async () => {
		const wrapper = dialog(VALID)
		await button(wrapper, 'NcButton', 'Save').vm.$emit('click')

		expect(wrapper.emitted('save')).toEqual([[{
			id: 'm1',
			payload: {
				activityName: 'Bouwen',
				caseTypes: [{ reference: 'omgevingsvergunning', department: 'Vergunningen' }],
				samenloopStrategy: 'deelzaken',
				samenloopRules: [],
				isActive: true,
				imowId: 'nl.imow-gm0363.activiteit.Bouwen',
			},
		}]])
	})

	it('adds and removes case types and samenloop rules, and sets the strategies', async () => {
		const wrapper = dialog(VALID)
		await button(wrapper, 'NcButton', 'Add case type').vm.$emit('click')
		expect(wrapper.vm.draft.caseTypes).toHaveLength(2)
		await button(wrapper, 'NcButton', 'Remove').vm.$emit('click')
		expect(wrapper.vm.draft.caseTypes).toEqual([{ reference: '', title: '', department: '' }])

		await button(wrapper, 'NcButton', 'Add samenloop rule').vm.$emit('click')
		expect(wrapper.vm.draft.samenloopRules).toEqual([{ withImowId: '', strategy: 'gecombineerd' }])

		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		await selects[0].vm.$emit('update:modelValue', { id: 'gecombineerd' })
		expect(wrapper.vm.draft.samenloopStrategy).toBe('gecombineerd')
		await selects[0].vm.$emit('update:modelValue', null)
		expect(wrapper.vm.draft.samenloopStrategy).toBe('deelzaken')
		await selects[1].vm.$emit('update:modelValue', null)
		expect(wrapper.vm.draft.samenloopRules[0].strategy).toBe('deelzaken')

		const rows = wrapper.findAll('[data-testid="dso-activity-rule"]')
		expect(rows).toHaveLength(1)
		const removeRule = wrapper.findAllComponents({ name: 'NcButton' }).filter((c) => c.text() === 'Remove').at(-1)
		await removeRule.vm.$emit('click')
		expect(wrapper.vm.draft.samenloopRules).toEqual([])
	})

	it('refuses a samenloop rule without a valid imow-id', async () => {
		const wrapper = dialog({ ...VALID, samenloopRules: [{ withImowId: 'nope', strategy: 'gecombineerd' }] })
		wrapper.vm.save()
		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.vm.shownProblems).toEqual(['Every samenloop rule needs the imow-id of the other activity.'])
	})

	it('picks the strategy option by id, defaulting to deelzaken', () => {
		const wrapper = dialog({})
		expect(wrapper.vm.strategyOption('gecombineerd').id).toBe('gecombineerd')
		expect(wrapper.vm.strategyOption('other').id).toBe('deelzaken')
	})

	it('reseeds the draft and forgets the tried save when the row changes', async () => {
		const wrapper = dialog({})
		wrapper.vm.save()
		expect(wrapper.vm.tried).toBe(true)

		await wrapper.setProps({ row: { ...VALID, activityName: 'Slopen' } })
		expect(wrapper.vm.tried).toBe(false)
		expect(wrapper.vm.draft.activityName).toBe('Slopen')
	})

	it('shows the save error, disables saving while saving, and closes', async () => {
		const wrapper = dialog(VALID, { error: 'Conflict', saving: true })
		expect(wrapper.find('[data-testid="dso-activity-error"]').text()).toBe('Conflict')
		expect(button(wrapper, 'NcButton', 'Save').props('disabled')).toBe(true)

		await button(wrapper, 'NcButton', 'Cancel').vm.$emit('click')
		await wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', true)
		await wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
