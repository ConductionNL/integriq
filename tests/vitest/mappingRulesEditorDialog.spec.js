// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MappingRulesEditor's dialog round trip: which seed each Add/Edit opens the
 * rule dialog with, which keys it reports as taken, and the full collection
 * each dialog submit emits (create, update, rename, unset dedupe). Also the
 * prop watchers that re-seed the drag drafts and the opt-in Options tab.
 *
 * @spec openspec/specs/mapping-editor-ui/spec.md
 */
import { translate } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MappingRulesEditor from '@/views/wrappers/MappingRulesEditor.vue'

vi.mock('@nextcloud/vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		NcButton: define({
			name: 'NcButton',
			props: ['variant', 'disabled'],
			emits: ['click'],
			render() {
				return render(
					'button',
					{
						class: 'NcButton',
						disabled: this.disabled || undefined,
						onClick: () => this.$emit('click'),
					},
					this.$slots.default?.(),
				)
			},
		}),
		NcCheckboxRadioSwitch: define({
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue', 'disabled', 'type'],
			emits: ['update:modelValue'],
			render() {
				return render('label', { class: 'NcCheckboxRadioSwitch' }, this.$slots.default?.())
			},
		}),
	}
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		CnTabs: define({
			name: 'CnTabs',
			render() {
				return render('div', { class: 'CnTabs' }, this.$slots.default?.())
			},
		}),
		CnTab: define({
			name: 'CnTab',
			props: ['title'],
			render() {
				return render('section', { class: 'CnTab', 'data-title': this.title }, [
					this.$slots.title?.(),
					this.$slots.default?.(),
				])
			},
		}),
	}
})

vi.mock('@/components/mapping/RulesTable.vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		default: define({
			name: 'RulesTable',
			props: ['rows', 'propertyLabel', 'valueLabel', 'saving'],
			emits: ['update:rows', 'reorder', 'edit', 'delete'],
			render() {
				return render('table', { class: 'RulesTable' })
			},
		}),
	}
})
vi.mock('@/dialogs/EditMappingRuleDialog.vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		default: define({
			name: 'EditMappingRuleDialog',
			props: ['kind', 'property', 'value', 'existingKeys'],
			emits: ['cancel', 'submit'],
			render() {
				return render('div', { class: 'EditMappingRuleDialog' })
			},
		}),
	}
})

const RulesTable = { name: 'RulesTable' }
const EditMappingRuleDialog = { name: 'EditMappingRuleDialog' }

const RULES = {
	mappingRules: { a: '{{ x }}', b: '{{ y }}' },
	castRules: { n: 'integer', m: 'boolean' },
	unsetRules: ['p', 'q'],
	passThrough: true,
}

/**
 * Mount the editor with the table and dialog stubbed.
 *
 * @param {object} props editor props
 * @return {object} the wrapper
 */
function mountEditor(props = RULES) {
	return mount(MappingRulesEditor, {
		props,
		global: {
			mocks: { t: translate },
		},
	})
}

/**
 * Click the footer button with the given label.
 *
 * @param {object} wrapper the editor
 * @param {string} label the button text
 * @return {Promise<void>}
 */
async function clickButton(wrapper, label) {
	const button = wrapper
		.findAll('button.NcButton')
		.find((b) => b.text() === label)
	await button.trigger('click')
}

/**
 * The props the open rule dialog received.
 *
 * @param {object} wrapper the editor
 * @return {object} kind, property, value, existingKeys
 */
function dialogProps(wrapper) {
	const dialog = wrapper.findComponent(EditMappingRuleDialog)
	expect(dialog.exists()).toBe(true)
	return { ...dialog.props() }
}

/**
 * The RulesTable for one tab (mapping, cast, unset in DOM order).
 *
 * @param {object} wrapper the editor
 * @param {number} index table position
 * @return {object} the table wrapper
 */
function table(wrapper, index) {
	return wrapper.findAllComponents(RulesTable)[index]
}

describe('MappingRulesEditor rule dialog', () => {
	it('opens no dialog until asked', () => {
		expect(mountEditor().findComponent(EditMappingRuleDialog).exists()).toBe(false)
	})

	it.each([
		['Add mapping rule', 'mapping', '', ['a', 'b']],
		['Add cast rule', 'cast', 'string', ['n', 'm']],
		['Add unset rule', 'unset', '', ['p', 'q']],
	])('%s opens a create dialog with every key taken', async (label, kind, value, keys) => {
		const wrapper = mountEditor()
		await clickButton(wrapper, label)
		expect(dialogProps(wrapper)).toEqual({
			kind,
			property: null,
			value,
			existingKeys: keys,
		})
	})

	it('edits a mapping rule with its own key not counted as taken', async () => {
		const wrapper = mountEditor()
		table(wrapper, 0).vm.$emit('edit', 'b')
		await wrapper.vm.$nextTick()
		expect(dialogProps(wrapper)).toEqual({
			kind: 'mapping',
			property: 'b',
			value: '{{ y }}',
			existingKeys: ['a'],
		})
	})

	it('edits a cast rule seeded with its cast type', async () => {
		const wrapper = mountEditor()
		table(wrapper, 1).vm.$emit('edit', 'n')
		await wrapper.vm.$nextTick()
		expect(dialogProps(wrapper)).toEqual({
			kind: 'cast',
			property: 'n',
			value: 'integer',
			existingKeys: ['m'],
		})
	})

	it('falls back to the kind default for a key the rules lack', async () => {
		const wrapper = mountEditor()
		table(wrapper, 1).vm.$emit('edit', 'ghost')
		await wrapper.vm.$nextTick()
		expect(dialogProps(wrapper).value).toBe('string')
		table(wrapper, 0).vm.$emit('edit', 'ghost')
		await wrapper.vm.$nextTick()
		expect(dialogProps(wrapper).value).toBe('')
	})

	it('edits an unset entry with the name as its value', async () => {
		const wrapper = mountEditor()
		table(wrapper, 2).vm.$emit('edit', 'q')
		await wrapper.vm.$nextTick()
		expect(dialogProps(wrapper)).toEqual({
			kind: 'unset',
			property: 'q',
			value: 'q',
			existingKeys: ['p'],
		})
	})

	it('closes on cancel without emitting', async () => {
		const wrapper = mountEditor()
		await clickButton(wrapper, 'Add mapping rule')
		wrapper.findComponent(EditMappingRuleDialog).vm.$emit('cancel')
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent(EditMappingRuleDialog).exists()).toBe(false)
		expect(wrapper.emitted('update-mapping')).toBeUndefined()
	})

	it('appends a created mapping rule and closes', async () => {
		const wrapper = mountEditor()
		await clickButton(wrapper, 'Add mapping rule')
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'mapping', property: 'c', value: '{{ z }}' })
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update-mapping')).toEqual([
			[{ a: '{{ x }}', b: '{{ y }}', c: '{{ z }}' }],
		])
		expect(wrapper.findComponent(EditMappingRuleDialog).exists()).toBe(false)
	})

	it('renames a mapping rule, dropping the old key', async () => {
		const wrapper = mountEditor()
		table(wrapper, 0).vm.$emit('edit', 'a')
		await wrapper.vm.$nextTick()
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'mapping', property: 'renamed', value: 'v' })
		expect(wrapper.emitted('update-mapping')).toEqual([
			[{ b: '{{ y }}', renamed: 'v' }],
		])
	})

	it('updates a mapping rule in place when the key is kept', async () => {
		const wrapper = mountEditor()
		table(wrapper, 0).vm.$emit('edit', 'a')
		await wrapper.vm.$nextTick()
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'mapping', property: 'a', value: 'new' })
		expect(wrapper.emitted('update-mapping')).toEqual([[{ a: 'new', b: '{{ y }}' }]])
	})

	it('renames and creates cast rules', async () => {
		const wrapper = mountEditor()
		table(wrapper, 1).vm.$emit('edit', 'n')
		await wrapper.vm.$nextTick()
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'cast', property: 'k', value: 'float' })
		await clickButton(wrapper, 'Add cast rule')
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'cast', property: 'z', value: 'array' })
		expect(wrapper.emitted('update-cast')).toEqual([
			[{ m: 'boolean', k: 'float' }],
			[{ n: 'integer', m: 'boolean', z: 'array' }],
		])
	})

	it('renames an unset entry in place', async () => {
		const wrapper = mountEditor()
		table(wrapper, 2).vm.$emit('edit', 'p')
		await wrapper.vm.$nextTick()
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'unset', property: 'r', value: 'r' })
		expect(wrapper.emitted('update-unset')).toEqual([[['r', 'q']]])
	})

	it('appends a new unset entry and drops a duplicate', async () => {
		const wrapper = mountEditor()
		await clickButton(wrapper, 'Add unset rule')
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'unset', property: 'r', value: 'r' })
		await clickButton(wrapper, 'Add unset rule')
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'unset', property: 'p', value: 'p' })
		expect(wrapper.emitted('update-unset')).toEqual([[['p', 'q', 'r']], [['p', 'q']]])
	})

	it('appends when the renamed unset entry is no longer listed', async () => {
		const wrapper = mountEditor()
		table(wrapper, 2).vm.$emit('edit', 'p')
		await wrapper.vm.$nextTick()
		await wrapper.setProps({ unsetRules: ['q'] })
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'unset', property: 'r', value: 'r' })
		expect(wrapper.emitted('update-unset')).toEqual([[['q', 'r']]])
	})

	it('emits nothing for an unknown kind but still closes', async () => {
		const wrapper = mountEditor()
		await clickButton(wrapper, 'Add mapping rule')
		wrapper
			.findComponent(EditMappingRuleDialog)
			.vm.$emit('submit', { kind: 'other', property: 'x', value: 'y' })
		await wrapper.vm.$nextTick()
		expect(Object.keys(wrapper.emitted()).filter((e) => e.startsWith('update-'))).toEqual([])
		expect(wrapper.findComponent(EditMappingRuleDialog).exists()).toBe(false)
	})
})

describe('MappingRulesEditor drafts and options', () => {
	it('re-seeds every draft when the parent persists new rules', async () => {
		const wrapper = mountEditor()
		await wrapper.setProps({
			mappingRules: { only: 't' },
			castRules: { c: 'float' },
			unsetRules: ['u'],
		})
		expect(table(wrapper, 0).props('rows')).toEqual([{ key: 'only', value: 't' }])
		expect(table(wrapper, 1).props('rows')).toEqual([{ key: 'c', value: 'float' }])
		expect(table(wrapper, 2).props('rows')).toEqual(['u'])
	})

	it('copies the unset prop so a drag cannot mutate it', () => {
		const unsetRules = ['p', 'q']
		const wrapper = mountEditor({ ...RULES, unsetRules })
		expect(table(wrapper, 2).props('rows')).not.toBe(unsetRules)
		expect(table(wrapper, 2).props('rows')).toEqual(unsetRules)
	})

	it('points to the host control without the Options tab', () => {
		const wrapper = mountEditor({ ...RULES, passThrough: false })
		expect(wrapper.find('.cn-rules-editor__warning').text()).toBe(
			'Enable pass-through above to add unset rules.',
		)
		expect(wrapper.findAll('.CnTab')).toHaveLength(3)
	})

	it('points to the Options tab and forwards its switch', async () => {
		const wrapper = mountEditor({ ...RULES, passThrough: false, showOptionsTab: true })
		expect(wrapper.find('.cn-rules-editor__warning').text()).toBe(
			'Enable pass-through in the Options tab to add unset rules.',
		)
		const tabs = wrapper.findAll('.CnTab')
		expect(tabs).toHaveLength(4)
		expect(tabs[3].attributes('data-title')).toBe('Options')
		const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.props('modelValue')).toBe(false)
		toggle.vm.$emit('update:modelValue', true)
		expect(wrapper.emitted('update-pass-through')).toEqual([[true]])
	})

	it('shows no warning while pass-through is on', () => {
		expect(mountEditor().find('.cn-rules-editor__warning').exists()).toBe(false)
	})
})
